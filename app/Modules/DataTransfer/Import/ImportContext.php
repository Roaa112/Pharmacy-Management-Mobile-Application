<?php

namespace App\Modules\DataTransfer\Import;

/**
 * State shared by the records of one import pass: the mode, who runs it, and what the file
 * already used (so a SKU or code twice in the same file is caught, not only against the
 * database). Holds identifiers only, never rows, so memory stays small for large files.
 */
final class ImportContext
{
    /** @var array<string, array<string, int>> kind => value => first row */
    private array $seen = [];

    public function __construct(
        public readonly string $mode,
        public readonly ?int $adminId,
        public readonly int $runId,
        public readonly bool $dryRun,
    ) {
    }

    public function allowsCreate(): bool
    {
        return in_array($this->mode, ['create', 'upsert'], true);
    }

    public function allowsUpdate(): bool
    {
        return in_array($this->mode, ['update', 'upsert'], true);
    }

    /** Returns the row that already used $value for $kind in this file, or null (and records it). */
    public function claim(string $kind, ?string $value, int $row): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = mb_strtolower($value);
        if (isset($this->seen[$kind][$key])) {
            return $this->seen[$kind][$key];
        }

        $this->seen[$kind][$key] = $row;

        return null;
    }
}
