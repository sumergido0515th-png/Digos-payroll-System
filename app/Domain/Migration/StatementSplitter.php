<?php
/**
 * ============================================================================
 * StatementSplitter - Splits a SQL file into individual statements.
 *
 * Pure: text in, array of statements out. No database, no filesystem, no
 * configuration. That is what makes it unit-testable, and the reason it lives
 * here rather than inside tools/migrate.php.
 *
 * A naive explode(';') corrupts any statement containing a semicolon inside a
 * string literal - which the seed data in migration 0001 does.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Migration;

final class StatementSplitter
{
    /**
     * Splits SQL on top-level semicolons, ignoring semicolons inside quoted
     * strings, backtick-quoted identifiers, line comments (-- and #) and
     * block comments. Comments are stripped; empty statements are dropped.
     *
     * @return string[] trimmed statements, in file order
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;                          // active ' " or ` delimiter, or null

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($quote !== null) {
                $current .= $char;

                // Backslash escapes apply inside strings but not inside
                // backtick-quoted identifiers.
                if ($char === '\\' && $quote !== '`' && $next !== '') {
                    $current .= $next;
                    $i++;
                    continue;
                }

                if ($char === $quote) {
                    if ($next === $quote) {     // doubled delimiter: an escaped quote
                        $current .= $next;
                        $i++;
                    } else {
                        $quote = null;          // string closed
                    }
                }
                continue;
            }

            // Line comment: skip to end of line, keeping the newline as a separator.
            if (($char === '-' && $next === '-') || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $current .= "\n";
                continue;
            }

            // Block comment: skip through the closing delimiter.
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === ';') {
                if (trim($current) !== '') $statements[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') $statements[] = trim($current);

        return $statements;
    }
}
