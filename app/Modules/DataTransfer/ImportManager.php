<?php

namespace App\Modules\DataTransfer;

use App\Jobs\ProcessImport;
use App\Jobs\ValidateImport;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\ImportRun;
use App\Modules\DataTransfer\Entities\DataEntity;
use App\Modules\DataTransfer\Import\GroupPlan;
use App\Modules\DataTransfer\Import\ImportContext;
use App\Modules\DataTransfer\Support\Spreadsheet;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports: upload → validate (preview, writes nothing) → confirm → process → reports.
 *
 * The file is read as a stream, one record at a time (memory stays flat for any size). Each
 * record (a row, or the rows of one product) is checked again at processing time against the
 * data as it is then, and written in its own transaction: a failing record is rolled back
 * completely and reported, the others go ahead. Large files run on the queue; small ones
 * (pharmacy.data_transfer.sync_max_bytes) right away.
 *
 * Every row-level problem goes to the error report (CSV: row, severity, column, message, value).
 */
class ImportManager
{
    public const MAX_UPLOAD_KB = 20480;

    private const SAMPLE_ERRORS = 100;

    public function start(Admin $admin, DataEntity $entity, UploadedFile $file, string $mode): ImportRun
    {
        if (!$entity->canImport()) {
            throw new DomainException("{$entity->label()} cannot be imported.");
        }
        if (!in_array($mode, $entity->modes(), true)) {
            throw new DomainException("{$entity->label()} cannot be imported in this mode.");
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $format = $extension === 'xlsx' ? 'xlsx' : 'csv';
        $path = $file->storeAs('imports', Str::uuid().'.'.$format, 'local');

        $run = ImportRun::create([
            'entity' => $entity->key(),
            'mode' => $mode,
            'format' => $format,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'file_path' => $path,
            'status' => ImportRun::PENDING,
            'admin_id' => $admin->id,
        ]);

        AdminAuditLog::record('data.import_upload', $run, ['entity' => $entity->key(), 'mode' => $mode, 'file' => $run->original_name, 'bytes' => $file->getSize()]);

        $this->queue(new ValidateImport($run->id), (int) $file->getSize());

        return $run->fresh();
    }

    /** Preview: what the file would do. Writes nothing but the run's counts and the error report. */
    public function validate(ImportRun $run): ImportRun
    {
        if ($run->status !== ImportRun::PENDING) {
            return $run;
        }

        return $this->guarded($run, function () use ($run) {
            $stats = $this->pass($run, dryRun: true);
            $run->forceFill([
                'total_rows' => $stats['rows'],
                'valid_rows' => $stats['valid_rows'],
                'invalid_rows' => $stats['invalid_rows'],
                'warning_count' => $stats['warnings'],
                'to_create' => $stats['create'],
                'to_update' => $stats['update'],
                'errors_path' => $stats['errors_path'],
                'summary' => $stats['summary'],
                'validated_at' => now(),
            ])->save();

            return $run;
        });
    }

    public function confirm(ImportRun $run, Admin $admin): ImportRun
    {
        $run = DB::transaction(function () use ($run) {
            $run = ImportRun::lockForUpdate()->findOrFail($run->id);

            if (!$run->awaitingConfirmation()) {
                throw new DomainException('This import is not waiting for confirmation.');
            }
            if ($run->valid_rows === 0) {
                throw new DomainException('There is nothing valid to import: fix the file and upload it again.');
            }

            $run->forceFill(['status' => ImportRun::PROCESSING, 'started_at' => now()])->save();

            return $run;
        });

        AdminAuditLog::record('data.import_confirm', $run, ['entity' => $run->entity, 'rows' => $run->valid_rows]);
        $this->queue(new ProcessImport($run->id), (int) Storage::disk('local')->size($run->file_path));

        return $run->fresh();
    }

    public function process(ImportRun $run): ImportRun
    {
        if ($run->status !== ImportRun::PROCESSING) {
            return $run;
        }

        return $this->guarded($run, function () use ($run) {
            $stats = $this->pass($run, dryRun: false);
            $run->refresh();
            $failed = $stats['invalid_rows'];
            $succeeded = $stats['created'] + $stats['updated'] + $stats['skipped'];

            $run->forceFill([
                'status' => $run->status === ImportRun::CANCELLED ? ImportRun::CANCELLED
                    : ($failed === 0 ? ImportRun::COMPLETED : ($succeeded > 0 ? ImportRun::COMPLETED_WITH_ERRORS : ImportRun::FAILED)),
                'total_rows' => $stats['rows'],
                'valid_rows' => $stats['valid_rows'],
                'invalid_rows' => $failed,
                'failed_rows' => $failed,
                'warning_count' => $stats['warnings'],
                'created_count' => $stats['created'],
                'updated_count' => $stats['updated'],
                'errors_path' => $stats['errors_path'],
                'results_path' => $stats['results_path'],
                'summary' => array_merge($run->summary ?? [], ['processed' => $stats['summary']]),
                'completed_at' => now(),
            ])->save();

            AdminAuditLog::record('data.import', $run, [
                'entity' => $run->entity, 'mode' => $run->mode, 'file' => $run->original_name, 'status' => $run->status,
                'rows' => $stats['rows'], 'created' => $stats['created'], 'updated' => $stats['updated'], 'failed' => $failed,
            ], $run->admin_id);

            return $run;
        });
    }

    public function cancel(ImportRun $run, Admin $admin): ImportRun
    {
        DB::transaction(function () use ($run) {
            $run = ImportRun::lockForUpdate()->findOrFail($run->id);
            if (!in_array($run->status, [ImportRun::PENDING, ImportRun::PROCESSING], true)) {
                throw new DomainException('This import has already finished.');
            }
            $run->forceFill(['status' => ImportRun::CANCELLED, 'completed_at' => now()])->save();
        });

        AdminAuditLog::record('data.import_cancel', $run, ['entity' => $run->entity]);

        return $run->fresh();
    }

    /**
     * One pass over the file. Records are groups of consecutive rows with the same group key.
     *
     * @return array<string, mixed>
     */
    private function pass(ImportRun $run, bool $dryRun): array
    {
        $entity = $run->entityDefinition() ?? throw new DomainException("Unknown entity {$run->entity}.");
        $path = Storage::disk('local')->path($run->file_path);
        $context = new ImportContext($run->mode, $run->admin_id, $run->id, $dryRun);

        $headers = Spreadsheet::headers($path, $run->format);
        if ($headers === []) {
            throw new DomainException('The file is empty or its first row has no column names.');
        }
        if ($missing = array_diff($entity->requiredHeaders(), $headers)) {
            throw new DomainException('Missing columns: '.implode(', ', $missing).'. Download the template for the expected columns.');
        }
        $ignored = array_values(array_diff(array_filter($headers), $entity->exportableColumns()));

        $errorsPath = "imports/{$run->id}-".($dryRun ? 'preview' : 'run').'-errors.csv';
        $resultsPath = $dryRun ? null : "imports/{$run->id}-results.csv";
        $errors = $this->openCsv($errorsPath, ['row', 'severity', 'column', 'message', 'value']);
        $results = $resultsPath ? $this->openCsv($resultsPath, ['rows', 'identifier', 'action', 'status', 'message']) : null;

        $stats = ['rows' => 0, 'valid_rows' => 0, 'invalid_rows' => 0, 'warnings' => 0, 'create' => 0, 'update' => 0,
            'created' => 0, 'updated' => 0, 'skipped' => 0, 'records' => 0, 'invalid_records' => 0];
        $summary = ['duplicates' => 0, 'relationship_errors' => 0, 'ignored_columns' => $ignored, 'sample_errors' => [], 'sample_warnings' => []];
        $seenGroups = [];
        $group = [];
        $groupKey = null;

        $flush = function () use (&$group, &$groupKey, &$seenGroups, &$stats, &$summary, $entity, $context, $dryRun, $errors, $results, $run) {
            if ($group === []) {
                return true;
            }

            $plan = new GroupPlan($group);
            if ($groupKey !== null && $groupKey !== '' && isset($seenGroups[$groupKey])) {
                $plan->error(null, 'product_code', "The rows of this record must be next to each other (it already appeared on row {$seenGroups[$groupKey]}).", $groupKey);
            } else {
                if ($groupKey !== null && $groupKey !== '') {
                    $seenGroups[$groupKey] = $plan->firstRow();
                }
                $this->runPlan($entity, $plan, $context, $dryRun);
            }

            $this->record($plan, $stats, $summary, $errors, $results);
            $group = [];

            // Stop between records if the import was cancelled meanwhile.
            return $dryRun || $stats['records'] % 100 !== 0 || $run->fresh()->status !== ImportRun::CANCELLED;
        };

        $continue = true;
        foreach (Spreadsheet::rows($path, $run->format) as $number => $row) {
            $key = $entity->groupKey($row);
            if ($group !== [] && ($key === null || $key !== $groupKey)) {
                if (!($continue = $flush())) {
                    break;
                }
            }
            $groupKey = $key;
            $group[$number] = $row;
        }
        if ($continue) {
            $flush();
        }

        fclose($errors);
        $results && fclose($results);

        return $stats + [
            'errors_path' => $errorsPath,
            'results_path' => $resultsPath,
            'summary' => $summary + ['records' => $stats['records'], 'invalid_records' => $stats['invalid_records']],
        ];
    }

    private function runPlan(DataEntity $entity, GroupPlan $plan, ImportContext $context, bool $dryRun): void
    {
        try {
            $entity->validate($plan, $context);

            if (!$dryRun && !$plan->failed() && $plan->action !== GroupPlan::SKIP) {
                // One transaction per record: a failure leaves nothing of it behind.
                DB::transaction(fn () => $entity->persist($plan, $context), 3);
            }
        } catch (DomainException $e) {
            $plan->error(null, '', $e->getMessage());
        } catch (Throwable $e) {
            Log::warning('Import record failed', ['run' => $context->runId, 'row' => $plan->firstRow(), 'error' => $e->getMessage()]);
            $plan->error(null, '', 'Could not be saved: '.Str::limit($e->getMessage(), 200));
        }
    }

    private function record(GroupPlan $plan, array &$stats, array &$summary, $errors, $results): void
    {
        $stats['records']++;
        $stats['rows'] += $plan->rowCount();
        $stats['warnings'] += count($plan->warnings);

        if ($plan->failed()) {
            $stats['invalid_rows'] += $plan->rowCount();
            $stats['invalid_records']++;
        } else {
            $stats['valid_rows'] += $plan->rowCount();
            match ($plan->action) {
                GroupPlan::CREATE => [$stats['create']++, $stats['created']++],
                GroupPlan::UPDATE => [$stats['update']++, $stats['updated']++],
                default => $stats['skipped']++,
            };
        }

        foreach ([['error', $plan->errors], ['warning', $plan->warnings]] as [$severity, $items]) {
            foreach ($items as $item) {
                fputcsv($errors, [$item['row'], $severity, $item['column'], $item['message'], $item['value']]);
                $sample = $severity === 'error' ? 'sample_errors' : 'sample_warnings';
                if (count($summary[$sample]) < self::SAMPLE_ERRORS) {
                    $summary[$sample][] = $item;
                }
                if ($severity === 'error' && str_starts_with($item['message'], 'Duplicate')) {
                    $summary['duplicates']++;
                } elseif ($severity === 'error' && preg_match('/(_id|_name|_sku|_code)$/', $item['column']) && str_contains($item['message'], 'exist')) {
                    $summary['relationship_errors']++;
                }
            }
        }

        if ($results) {
            $rows = array_keys($plan->rows);
            fputcsv($results, [count($rows) > 1 ? min($rows).'-'.max($rows) : $rows[0], $plan->identifier, $plan->action,
                $plan->failed() ? 'failed' : 'ok', $plan->failed() ? implode(' ', array_column($plan->errors, 'message')) : '']);
        }
    }

    /** @return resource */
    private function openCsv(string $relative, array $header)
    {
        $absolute = Storage::disk('local')->path($relative);
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        $handle = fopen($absolute, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $header);

        return $handle;
    }

    /** A failure of the whole file (unreadable, wrong columns) fails the run with its reason. */
    private function guarded(ImportRun $run, callable $work): ImportRun
    {
        try {
            return $work();
        } catch (Throwable $e) {
            if (!$e instanceof DomainException) {
                Log::error('Import failed', ['run' => $run->id, 'error' => $e->getMessage()]);
            }
            $run->forceFill(['status' => ImportRun::FAILED, 'failure_message' => Str::limit($e->getMessage(), 990), 'completed_at' => now(),
                'validated_at' => $run->validated_at ?? now()])->save();
            AdminAuditLog::record('data.import_failed', $run, ['entity' => $run->entity, 'error' => Str::limit($e->getMessage(), 300)], $run->admin_id);

            return $run;
        }
    }

    private function queue(object $job, int $bytes): void
    {
        $bytes <= (int) config('pharmacy.data_transfer.sync_max_bytes', 512 * 1024) ? dispatch_sync($job) : dispatch($job);
    }
}
