<?php

namespace App\Modules\DataTransfer\Support;

/**
 * One column of an entity's files. The key is the header in every file (exports use keys, not
 * labels, so an export can be imported again).
 */
final class Column
{
    public function __construct(
        public readonly string $label,
        public readonly string $description = '',
        public readonly bool $required = false,      // required on import (for new records)
        public readonly ?string $accepted = null,    // accepted values / format, for the template
        public readonly string $example = '',
        public readonly bool $importable = true,     // false: export only, ignored on import
        public readonly bool $defaultExport = true,  // offered ticked in the export form
    ) {
    }

    public static function make(string $label, string $description = '', bool $required = false, ?string $accepted = null, string $example = ''): self
    {
        return new self($label, $description, $required, $accepted, $example);
    }

    public static function exportOnly(string $label, string $description = '', bool $defaultExport = true): self
    {
        return new self($label, $description, false, null, '', false, $defaultExport);
    }
}
