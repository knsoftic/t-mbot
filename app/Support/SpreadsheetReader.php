<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Reads the rows of a CSV / TXT file or the first sheet of an XLSX workbook
 * as plain strings. Small and dependency-free: enough for contact lists.
 */
final class SpreadsheetReader
{
    /**
     * @return list<list<string>> At most $limit + 1 rows (so callers can detect "too many").
     */
    public static function rows(string $path, string $extension, int $limit): array
    {
        return match (strtolower($extension)) {
            'csv', 'txt' => self::csv($path, $limit + 1),
            'xlsx' => self::xlsx($path, $limit + 1),
            default => throw new InvalidArgumentException('Upload a .csv, .txt or .xlsx file.'),
        };
    }

    /**
     * @return list<list<string>>
     */
    private static function csv(string $path, int $max): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The file could not be read.');
        }

        $first = (string) fgets($handle);
        rewind($handle);

        // Pick the separator used on the first line.
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();
        $rows = [];

        while (count($rows) < $max && ($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $cells = array_map(fn ($cell) => trim((string) $cell), $cells);

            if (count($rows) === 0 && isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
            }

            $rows[] = $cells;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    private static function xlsx(string $path, int $max): array
    {
        if (! class_exists(ZipArchive::class) || ! class_exists(XMLReader::class)) {
            throw new RuntimeException('Excel files need the PHP zip and xmlreader extensions on the server. Save the sheet as CSV and upload that instead.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('This does not look like a valid .xlsx file.');
        }

        try {
            $shared = self::sharedStrings((string) $zip->getFromName('xl/sharedStrings.xml'));
            $sheet = $zip->getFromName(self::firstSheetPath($zip));

            if ($sheet === false) {
                throw new RuntimeException('The workbook has no readable sheet.');
            }

            return self::sheetRows($sheet, $shared, $max);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<string>
     */
    private static function sharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        $reader = XMLReader::XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $strings = [];
        $current = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $current = '';
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't' && $current !== null) {
                $current .= $reader->readString();
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
                $strings[] = (string) $current;
                $current = null;
            }
        }

        return $strings;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $sheet)
            && preg_match('/<Relationship\b[^>]*\bId="'.preg_quote($sheet[1], '/').'"[^>]*\bTarget="([^"]+)"/', $rels, $target)) {
            $file = ltrim($target[1], '/');

            return str_starts_with($file, 'xl/') ? $file : 'xl/'.$file;
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @param  list<string>  $shared
     * @return list<list<string>>
     */
    private static function sheetRows(string $xml, array $shared, int $max): array
    {
        $reader = XMLReader::XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $rows = [];
        $row = null;
        $column = 0;
        $type = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                $row = [];
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c' && $row !== null) {
                $column = self::columnIndex((string) $reader->getAttribute('r'), count($row));
                $type = $reader->getAttribute('t');
            } elseif ($reader->nodeType === XMLReader::ELEMENT && in_array($reader->localName, ['v', 't'], true) && $row !== null) {
                $value = $reader->readString();
                $value = match ($type) {
                    's' => $shared[(int) $value] ?? '',
                    default => $value,
                };

                // Large integers (user ids) may be written in scientific notation.
                if (is_numeric($value) && stripos($value, 'e') !== false) {
                    $value = sprintf('%.0f', (float) $value);
                }

                $row[$column] = ($row[$column] ?? '').trim((string) $value);
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row' && $row !== null) {
                if ($row !== []) {
                    $filled = array_fill(0, max(array_keys($row)) + 1, '');
                    $rows[] = array_values(array_replace($filled, $row));
                }

                $row = null;

                if (count($rows) >= $max) {
                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * "C12" → 2 (zero-based); falls back to the next column.
     */
    private static function columnIndex(string $reference, int $fallback): int
    {
        if (! preg_match('/^([A-Z]+)/', $reference, $m)) {
            return $fallback;
        }

        $index = 0;

        foreach (str_split($m[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
