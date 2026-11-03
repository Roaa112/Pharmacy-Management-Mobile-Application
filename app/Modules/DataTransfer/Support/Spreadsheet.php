<?php

namespace App\Modules\DataTransfer\Support;

use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

/**
 * Streaming CSV / XLSX reading and writing (OpenSpout): one row in memory at a time, whatever
 * the file size. Only the first sheet of a workbook is data; templates put their instructions
 * and examples on the following sheets.
 */
final class Spreadsheet
{
    public const FORMATS = ['csv', 'xlsx'];

    /**
     * Data rows keyed by normalized header ("Variant SKU" → variant_sku), with their 1-based row
     * number in the file. Blank rows are skipped.
     *
     * @return Generator<int, array<string, string>> row number => values
     */
    public static function rows(string $path, string $format, ?array &$headers = null): Generator
    {
        $reader = $format === 'xlsx' ? new XlsxReader() : new CsvReader();
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = null;
                $number = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $values = array_map([self::class, 'cell'], $row->toArray());

                    if ($headers === null) {
                        $headers = array_map([self::class, 'normalizeHeader'], $values);
                        continue;
                    }
                    if (implode('', $values) === '') {
                        continue;
                    }

                    $assoc = [];
                    foreach ($headers as $i => $header) {
                        if ($header !== '') {
                            $assoc[$header] = $values[$i] ?? '';
                        }
                    }

                    yield $number => $assoc;
                }

                break; // the first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    /** Only the header row (for checking a file's columns before anything else). */
    public static function headers(string $path, string $format): array
    {
        $headers = [];
        foreach (self::rows($path, $format, $headers) as $_) {
            break;
        }

        return $headers ?? [];
    }

    /**
     * Write a file: $sheets is [name => iterable of rows (list of scalars)]; the first row of each
     * sheet is written bold. CSV files take the first sheet only (with a UTF-8 BOM for Excel).
     */
    public static function write(string $path, string $format, array $sheets): int
    {
        if (!in_array($format, self::FORMATS, true)) {
            throw new RuntimeException("Unsupported format {$format}.");
        }

        $writer = $format === 'xlsx' ? new XlsxWriter() : new CsvWriter();
        $writer->openToFile($path);
        $bold = (new Style())->setFontBold();
        $written = 0;
        $first = true;

        try {
            foreach ($sheets as $name => $rows) {
                if ($writer instanceof XlsxWriter) {
                    ($first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent())->setName(substr((string) $name, 0, 31));
                } elseif (!$first) {
                    break;
                }

                $header = true;
                foreach ($rows as $row) {
                    $values = array_map([self::class, 'scalar'], array_values($row));
                    $writer->addRow($header && $writer instanceof XlsxWriter ? Row::fromValues($values, $bold) : Row::fromValues($values));
                    $header = false;
                    $written++;
                }
                $first = false;
            }
        } finally {
            $writer->close();
        }

        return $written;
    }

    public static function normalizeHeader($value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value); // BOM

        return trim(preg_replace('/[^a-z0-9]+/', '_', $value), '_');
    }

    private static function cell($value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        $value = trim((string) $value);

        // Undo the protection scalar() adds on export, so exported files import unchanged.
        return strlen($value) > 1 && $value[0] === "'" && in_array($value[1], ['=', '+', '-', '@'], true) ? substr($value, 1) : $value;
    }

    private static function scalar($value)
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 1 : 0,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) => implode('|', $value),
            // A leading = + - @ makes spreadsheet apps evaluate the cell (CSV/formula injection).
            is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) && !is_numeric($value) => "'".$value,
            default => $value,
        };
    }
}
