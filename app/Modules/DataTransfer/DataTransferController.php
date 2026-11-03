<?php

namespace App\Modules\DataTransfer;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\ExportRun;
use App\Models\ImportRun;
use App\Modules\DataTransfer\Support\Spreadsheet;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Import & export centre. The route needs some import or export permission; every action checks
 * the permission of its own entity (EntityRegistry::authorize), and history lists only entities
 * the admin may move.
 */
class DataTransferController extends Controller
{
    public function __construct(private ImportManager $imports, private ExportManager $exports)
    {
    }

    public function index(Request $request)
    {
        $admin = auth('admin')->user();
        $importable = EntityRegistry::importable($admin);
        $exportable = EntityRegistry::exportable($admin);
        $visible = array_unique([...array_keys($importable), ...array_keys($exportable)]);

        return view('dashboard.data.index', [
            'importable' => $importable,
            'exportable' => $exportable,
            'entities' => EntityRegistry::all(),
            'selectedExport' => $exportable[$request->query('export')] ?? reset($exportable) ?: null,
            'recentImports' => ImportRun::with('admin:id,name')->whereIn('entity', array_keys($importable))->latest('id')->limit(5)->get(),
            'recentExports' => ExportRun::with('admin:id,name')->whereIn('entity', $visible)->latest('id')->limit(5)->get(),
            'modes' => ImportRun::MODES,
        ]);
    }

    public function template(Request $request, string $entity)
    {
        $definition = EntityRegistry::get($entity);
        EntityRegistry::authorize(auth('admin')->user(), $definition, 'import');
        $format = $request->query('format') === 'csv' ? 'csv' : 'xlsx';

        return response()->download(app(TemplateBuilder::class)->build($definition, $format), "{$entity}-import-template.{$format}")->deleteFileAfterSend();
    }

    public function upload(Request $request)
    {
        $data = $request->validate([
            'entity' => ['required', 'string', Rule::in(array_keys(EntityRegistry::all()))],
            'mode' => ['required', Rule::in(array_keys(ImportRun::MODES))],
            // The content is checked by the reader itself (a file it cannot parse fails the import).
            'file' => ['required', 'file', 'max:'.ImportManager::MAX_UPLOAD_KB, 'extensions:csv,txt,xlsx',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream'],
        ]);

        $entity = EntityRegistry::get($data['entity']);
        EntityRegistry::authorize(auth('admin')->user(), $entity, 'import');

        try {
            $run = $this->imports->start(auth('admin')->user(), $entity, $request->file('file'), $data['mode']);
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.data.imports.show', $run);
    }

    public function imports(Request $request)
    {
        $admin = auth('admin')->user();
        $runs = ImportRun::with('admin:id,name')->whereIn('entity', array_keys(EntityRegistry::importable($admin)))
            ->when($request->query('entity'), fn ($q, $e) => $q->where('entity', $e))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate(25)->withQueryString();

        return view('dashboard.data.imports', ['runs' => $runs, 'entities' => EntityRegistry::importable($admin)]);
    }

    public function showImport(ImportRun $importRun)
    {
        $entity = EntityRegistry::get($importRun->entity);
        EntityRegistry::authorize(auth('admin')->user(), $entity, 'import');

        return view('dashboard.data.import', ['run' => $importRun->load('admin:id,name'), 'entity' => $entity, 'modes' => ImportRun::MODES]);
    }

    public function confirm(ImportRun $importRun)
    {
        EntityRegistry::authorize(auth('admin')->user(), EntityRegistry::get($importRun->entity), 'import');

        try {
            $run = $this->imports->confirm($importRun, auth('admin')->user());
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.data.imports.show', $run)->with('success', $run->isFinished()
            ? 'Import finished.' : 'Import started: it runs in the background; refresh this page to follow it.');
    }

    public function cancelImport(ImportRun $importRun)
    {
        EntityRegistry::authorize(auth('admin')->user(), EntityRegistry::get($importRun->entity), 'import');

        try {
            $this->imports->cancel($importRun, auth('admin')->user());
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Import cancelled. Records already written stay.');
    }

    /** original | errors | results */
    public function importFile(ImportRun $importRun, string $kind)
    {
        EntityRegistry::authorize(auth('admin')->user(), EntityRegistry::get($importRun->entity), 'import');

        [$path, $name] = match ($kind) {
            'original' => [$importRun->file_path, $importRun->original_name],
            'errors' => [$importRun->errors_path, "import-{$importRun->id}-errors.csv"],
            'results' => [$importRun->results_path, "import-{$importRun->id}-results.csv"],
        };

        abort_unless($path && Storage::disk('local')->exists($path), 404);
        AdminAuditLog::record('data.import_download', $importRun, ['file' => $kind]);

        return Storage::disk('local')->download($path, $name);
    }

    public function export(Request $request)
    {
        $data = $request->validate([
            'entity' => ['required', 'string', Rule::in(array_keys(EntityRegistry::all()))],
            'format' => ['required', Rule::in(Spreadsheet::FORMATS)],
            'filters' => ['nullable', 'array'],
            // Values are checked by the entity (ExportManager keeps only its own filters, as scalars).
            'filters.*' => ['nullable'],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string', 'max:60'],
        ]);

        $entity = EntityRegistry::get($data['entity']);
        EntityRegistry::authorize(auth('admin')->user(), $entity, 'export');

        try {
            $run = $this->exports->start(auth('admin')->user(), $entity, $data['format'], $data['filters'] ?? [], $data['columns'] ?? []);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return $run->status === ExportRun::COMPLETED
            ? redirect()->route('dashboard.data.exports.index')->with('success', "Export ready: {$run->row_count} rows.")->with('download', $run->id)
            : redirect()->route('dashboard.data.exports.index')->with($run->status === ExportRun::FAILED ? 'error' : 'success',
                $run->status === ExportRun::FAILED ? 'The export failed: '.$run->failure_message : 'Export started in the background; it will appear here when ready.');
    }

    public function exports(Request $request)
    {
        $admin = auth('admin')->user();
        $runs = ExportRun::with('admin:id,name')->whereIn('entity', array_keys(EntityRegistry::exportable($admin)))
            ->when($request->query('entity'), fn ($q, $e) => $q->where('entity', $e))
            ->latest('id')->paginate(25)->withQueryString();

        return view('dashboard.data.exports', ['runs' => $runs, 'entities' => EntityRegistry::exportable($admin)]);
    }

    public function download(ExportRun $exportRun)
    {
        EntityRegistry::authorize(auth('admin')->user(), EntityRegistry::get($exportRun->entity), 'export');
        abort_unless($exportRun->status === ExportRun::COMPLETED && $exportRun->file_path && Storage::disk('local')->exists($exportRun->file_path), 404);

        AdminAuditLog::record('data.export_download', $exportRun, ['entity' => $exportRun->entity, 'records' => $exportRun->row_count]);

        return Storage::disk('local')->download($exportRun->file_path, $exportRun->fileName());
    }
}
