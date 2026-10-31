<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Checks column names that come from the query string (sortBy, filter keys) against the
 * table's real columns. An unknown column would otherwise reach the SQL and fail on MySQL.
 */
final class TableColumns
{
    /** @var array<string, list<string>> */
    private static array $columns = [];

    public static function has(string $table, mixed $column): bool
    {
        if (!is_string($column) || $column === '') {
            return false;
        }

        self::$columns[$table] ??= Schema::getColumnListing($table);

        return in_array($column, self::$columns[$table], true);
    }

    public static function orDefault(string $table, mixed $column, string $default = 'id'): string
    {
        return self::has($table, $column) ? $column : $default;
    }
}
