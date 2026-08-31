<?php
/**
 * ============================================================================
 * Csv - rows, and the filters that produced them, as an RFC 4180 file.
 *
 * The whole of the export deliverable that belongs in the pure layer. Getting
 * the RIGHT rows is search()'s job and is already proven by FilterScopeTest;
 * this class only ever receives rows a caller may already see, and turns them
 * into text. It never queries anything and never sees $user - the disclosure
 * question is answered before a single byte of CSV is built.
 *
 * "Active filters printed in header" is the phase's own wording for the
 * export task, so the first two lines of every file describe what produced
 * it. That header is itself a CSV row rather than a raw comment line, which
 * is what lets a filter value that happens to look like a formula go through
 * the same neutralisation as an ordinary cell.
 *
 * Pure: no DB::, no session, no clock, no file I/O.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Query;

final class Csv
{
    /**
     * @param array<int, array<string, mixed>> $rows already scoped and filtered
     * @param string[] $filterLines from FilterSpec::describe(), already human-readable
     */
    public static function render(array $rows, array $filterLines, string $title): string
    {
        $lines = [];
        $lines[] = self::row(["$title export"]);
        $lines[] = self::row([$filterLines ? 'Filters: ' . implode('; ', $filterLines) : 'Filters: none']);
        $lines[] = '';

        if (!$rows) {
            $lines[] = self::row(['No matching rows.']);
            return implode("\r\n", $lines) . "\r\n";
        }

        // Whatever columns the query actually returned, in the order it
        // returned them - the same columns a reader of the on-screen list
        // already sees, rather than a second column list kept in step with it
        // by hand.
        $columns = array_keys($rows[0]);
        $lines[] = self::row($columns);
        foreach ($rows as $row) {
            $lines[] = self::row(array_map(fn(string $c) => $row[$c] ?? '', $columns));
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /** @param array<int, mixed> $fields */
    private static function row(array $fields): string
    {
        return implode(',', array_map([self::class, 'field'], $fields));
    }

    /**
     * One CSV cell, quoted per RFC 4180 and neutralised against formula
     * injection.
     *
     * A cell opening with =, +, -, @, a tab or a carriage return is a live
     * formula the moment a spreadsheet opens the file, and this export
     * carries free text nobody has reviewed for that - Remarks, Particulars,
     * Subject, a search term echoed back into the filter line. Prefixing a
     * single quote is the standard mitigation: a spreadsheet reads the cell
     * as text: a plain CSV reader sees the same characters either way.
     */
    private static function field(mixed $value): string
    {
        $value = (string) $value;

        if (preg_match('/^[=+\-@\t\r]/', $value)) $value = "'" . $value;

        if (preg_match('/[",\r\n]/', $value)) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
