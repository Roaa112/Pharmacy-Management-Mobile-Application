<?php

namespace App\Modules\DataTransfer\Import;

/**
 * What one record of an import file (one row, or the rows of one product) would do: create or
 * update which record, with which data, and what is wrong with it. Errors stop the record;
 * warnings are reported and the record goes ahead.
 */
final class GroupPlan
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const SKIP = 'skip';

    /** @var list<array{row: int, column: string, message: string, value: string}> */
    public array $errors = [];

    /** @var list<array{row: int, column: string, message: string, value: string}> */
    public array $warnings = [];

    public ?string $action = null;

    public ?string $identifier = null;

    /** Whatever the entity needs to persist it (validated values, the matched model…). */
    public array $payload = [];

    /** @param  array<int, array<string, string>>  $rows  row number => values */
    public function __construct(public readonly array $rows)
    {
    }

    public function firstRow(): int
    {
        return (int) array_key_first($this->rows);
    }

    public function error(?int $row, string $column, string $message, $value = ''): self
    {
        $this->errors[] = ['row' => $row ?? $this->firstRow(), 'column' => $column, 'message' => $message, 'value' => (string) $value];

        return $this;
    }

    public function warning(?int $row, string $column, string $message, $value = ''): self
    {
        $this->warnings[] = ['row' => $row ?? $this->firstRow(), 'column' => $column, 'message' => $message, 'value' => (string) $value];

        return $this;
    }

    public function failed(): bool
    {
        return $this->errors !== [];
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }
}
