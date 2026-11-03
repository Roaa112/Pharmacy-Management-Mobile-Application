<?php

namespace App\Modules\DataTransfer;

use App\Models\ImportRun;
use App\Modules\DataTransfer\Entities\DataEntity;
use App\Modules\DataTransfer\Support\Column;
use App\Modules\DataTransfer\Support\Spreadsheet;

/**
 * Downloadable import templates, generated from the entity's column definitions (never typed by
 * hand, so they cannot drift from what the importer accepts).
 *
 * XLSX: "Data" (the headers to fill; the importer reads only this sheet), "Examples" and
 * "Instructions" (each column: required, description, accepted values, example; match rules and
 * notes). CSV: the headers and the example rows (replace the examples with your data).
 */
class TemplateBuilder
{
    public function build(DataEntity $entity, string $format): string
    {
        $headers = $entity->importableColumns();
        $examples = array_map(fn (array $example) => array_map(fn ($key) => $example[$key] ?? '', $headers), $entity->examples());
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.'.$format;

        $sheets = $format === 'xlsx'
            ? ['Data' => [$headers], 'Examples' => [$headers, ...$examples], 'Instructions' => $this->instructions($entity)]
            : ['Data' => [$headers, ...$examples]];

        Spreadsheet::write($path, $format, $sheets);

        return $path;
    }

    private function instructions(DataEntity $entity): array
    {
        $rows = [['Column', 'Required', 'Description', 'Accepted values', 'Example']];

        foreach ($entity->columns() as $key => $column) {
            /** @var Column $column */
            if ($column->importable) {
                $rows[] = [$key, $column->required ? 'Required (new records)' : 'Optional', $column->label.($column->description ? ': '.$column->description : ''), $column->accepted ?? '', $column->example];
            }
        }

        $rows[] = [];
        $rows[] = ['How records are matched', $entity->matchDescription()];
        $rows[] = ['Import modes', implode(', ', array_map(fn ($m) => ImportRun::MODES[$m].' ('.$m.')', $entity->modes()))];
        $rows[] = ['Export-only columns', implode(', ', array_keys(array_filter($entity->columns(), fn (Column $c) => !$c->importable))) ?: '—'];
        foreach ($entity->notes() as $note) {
            $rows[] = ['Note', $note];
        }
        $rows[] = ['Note', 'Fill the "Data" sheet: it is the only sheet read. The first row must keep the column names.'];

        return $rows;
    }
}
