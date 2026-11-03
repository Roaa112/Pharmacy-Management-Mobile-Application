<?php

namespace App\Modules\DataTransfer\Entities;

use App\Modules\DataTransfer\Import\GroupPlan;
use App\Modules\DataTransfer\Import\ImportContext;
use App\Modules\DataTransfer\Support\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One business entity of the import & export centre: its columns, who may move it, how it is
 * exported, and (when it can be imported) how a record is validated and written.
 *
 * Imports run in two passes over the file: validate() for the preview (never writes), then, after
 * confirmation, validate() again on the current data and persist() for each valid record inside
 * its own transaction, so one bad record never leaves half of itself behind.
 */
abstract class DataEntity
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /** @return array<string, Column> */
    abstract public function columns(): array;

    abstract public function exportPermission(): ?string;

    public function importPermission(): ?string
    {
        return null;
    }

    public function canImport(): bool
    {
        return $this->importPermission() !== null;
    }

    public function canExport(): bool
    {
        return $this->exportPermission() !== null;
    }

    /** Import modes this entity accepts. */
    public function modes(): array
    {
        return ['create', 'update', 'upsert'];
    }

    /** How records are matched on import (for the screen and the template). */
    public function matchDescription(): string
    {
        return '';
    }

    /** Sensitive: personal or financial data (export is audited with this flag, never by default). */
    public function sensitive(): bool
    {
        return false;
    }

    /**
     * Export filters: key => [label, type (text|select|date|number|boolean), options].
     *
     * @return array<string, array{0: string, 1: string, 2?: array}>
     */
    public function filters(): array
    {
        return [];
    }

    /** Rows to export, ordered by primary key (streamed with lazyById). */
    abstract public function exportQuery(array $filters): Builder;

    /**
     * The file rows of one record (most entities: one; a product: one per size).
     *
     * @return iterable<array<string, mixed>>
     */
    public function exportRows(Model $model): iterable
    {
        yield $this->exportRow($model);
    }

    public function exportRow(Model $model): array
    {
        throw new LogicException(static::class.' must define exportRow() or exportRows().');
    }

    /** Example rows for the template (keys = column keys). */
    public function examples(): array
    {
        return [];
    }

    /** Extra lines for the template's instructions sheet. */
    public function notes(): array
    {
        return [];
    }

    /**
     * The rows that make one record share this key (products: product_code). Null: each row is
     * its own record. Rows of one record must be next to each other in the file.
     */
    public function groupKey(array $row): ?string
    {
        return null;
    }

    public function validate(GroupPlan $plan, ImportContext $context): void
    {
        throw new LogicException($this->label().' cannot be imported.');
    }

    public function persist(GroupPlan $plan, ImportContext $context): void
    {
        throw new LogicException($this->label().' cannot be imported.');
    }

    /** Columns an import file must have (new records need them). */
    public function requiredHeaders(): array
    {
        return array_keys(array_filter($this->columns(), fn (Column $c) => $c->required));
    }

    public function importableColumns(): array
    {
        return array_keys(array_filter($this->columns(), fn (Column $c) => $c->importable));
    }

    public function exportableColumns(): array
    {
        return array_keys($this->columns());
    }

    // ---- helpers for the entities

    protected static function value(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    protected static function list(?string $value): array
    {
        return $value === null ? [] : array_values(array_filter(array_map('trim', explode('|', $value)), fn ($v) => $v !== ''));
    }

    /** Laravel validation of one row; messages added to the plan. Returns the validated data. */
    protected function check(GroupPlan $plan, int $row, array $data, array $rules): array
    {
        $validator = validator($data, $rules);

        foreach ($validator->errors()->messages() as $column => $messages) {
            foreach ($messages as $message) {
                $plan->error($row, $column, $message, is_scalar($data[$column] ?? null) ? $data[$column] : '');
            }
        }

        return $validator->errors()->isEmpty() ? $validator->validated() : array_intersect_key($data, $rules);
    }

    protected static function bool(?string $value, bool $default = true): bool
    {
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'y', 'active'], true);
    }
}
