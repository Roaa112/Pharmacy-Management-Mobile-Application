<?php

namespace App\Modules\DataTransfer;

use App\Jobs\RunExport;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\ExportRun;
use App\Modules\DataTransfer\Entities\DataEntity;
use App\Modules\DataTransfer\Support\Column;
use App\Modules\DataTransfer\Support\Spreadsheet;
use Closure;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Exports: the entity's rows streamed into a CSV / XLSX file (lazyById, 500 at a time: memory stays
 * flat for any size), with the columns and filters asked for. Headers are column keys, so the
 * file of an importable entity can be imported again. Small exports run at once; large ones
 * (pharmacy.data_transfer.sync_max_rows) on the queue. Every export is audited.
 */
class ExportManager
{
    public function start(Admin $admin, DataEntity $entity, string $format, array $filters, array $columns): ExportRun
    {
        if (!$entity->canExport()) {
            throw new DomainException("{$entity->label()} cannot be exported.");
        }
        if (!in_array($format, Spreadsheet::FORMATS, true)) {
            throw new DomainException('Choose CSV or XLSX.');
        }

        $known = $entity->exportableColumns();
        $columns = array_values(array_intersect($known, $columns)) ?: array_keys(array_filter($entity->columns(), fn (Column $c) => $c->defaultExport));
        // Only the entity's own filters, as plain values: nothing else from the request reaches the query.
        $filters = array_filter(array_intersect_key($filters, $entity->filters()), fn ($v) => is_scalar($v) && $v !== '');

        $run = ExportRun::create([
            'entity' => $entity->key(), 'format' => $format, 'filters' => $filters, 'columns' => $columns,
            'status' => ExportRun::PENDING, 'admin_id' => $admin->id,
        ]);

        $estimate = $entity->exportQuery($filters)->count();
        $job = new RunExport($run->id);
        $estimate <= (int) config('pharmacy.data_transfer.sync_max_rows', 5000) ? dispatch_sync($job) : dispatch($job);

        return $run->fresh();
    }

    public function run(ExportRun $run): ExportRun
    {
        if ($run->status !== ExportRun::PENDING) {
            return $run;
        }

        $entity = $run->entityDefinition();
        $run->forceFill(['status' => ExportRun::PROCESSING, 'started_at' => now()])->save();
        $relative = "exports/{$run->id}-".Str::random(8).".{$run->format}";
        $absolute = Storage::disk('local')->path($relative);

        try {
            if (!is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0755, true);
            }

            $written = Spreadsheet::write($absolute, $run->format, ['Data' => $this->rows($entity, $run->filters ?? [], $run->columns)]);

            $run->forceFill(['status' => ExportRun::COMPLETED, 'file_path' => $relative, 'row_count' => max(0, $written - 1), 'completed_at' => now()])->save();
        } catch (Throwable $e) {
            Log::error('Export failed', ['run' => $run->id, 'error' => $e->getMessage()]);
            @unlink($absolute);
            $run->forceFill(['status' => ExportRun::FAILED, 'failure_message' => Str::limit($e->getMessage(), 990), 'completed_at' => now()])->save();
        }

        AdminAuditLog::record('data.export', $run, [
            'entity' => $run->entity, 'format' => $run->format, 'filters' => $run->filters, 'columns' => $run->columns,
            'records' => $run->row_count, 'status' => $run->status, 'sensitive' => $entity->sensitive(),
        ], $run->admin_id);

        return $run;
    }

    /** Header, then every record's rows in primary-key order, one chunk in memory at a time. */
    private function rows(DataEntity $entity, array $filters, array $columns): \Generator
    {
        yield $columns;

        $query = $entity->exportQuery($filters);
        foreach ($query->lazyById(500, $query->getModel()->getQualifiedKeyName(), $query->getModel()->getKeyName()) as $model) {
            foreach ($entity->exportRows($model) as $row) {
                yield array_map(fn (string $column) => ($value = $row[$column] ?? null) instanceof Closure ? $value() : $value, $columns);
            }
        }
    }
}
