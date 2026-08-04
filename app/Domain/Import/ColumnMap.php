<?php
/**
 * ============================================================================
 * ColumnMap.php - Matches the column headings somebody actually typed onto
 * the field names this system stores.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Import;

/**
 * Works out which column of an uploaded file feeds which field.
 *
 * This is the "adaptive" half of the importer. A spreadsheet kept by an office
 * says "Last Name", "SURNAME" or "Apelyido"; the database column is `LastName`.
 * Requiring the file to be renamed to match the schema would mean every office
 * reformats its own records before importing, which is the manual step the
 * import is meant to remove.
 *
 * Pure: headers and a field specification in, a mapping out. The proposal is
 * always shown for confirmation before anything is written - a guess that is
 * never reviewed is just a silent error, and this one is allowed to be wrong
 * precisely because the preview screen exists.
 */
final class ColumnMap
{
    /** Below this score a guess is offered as a suggestion, not applied. */
    public const CONFIDENT = 70;

    /**
     * Proposes field <= column for every field in the spec.
     *
     * @param string[] $headers            column headings, in file order
     * @param array<string,string[]> $spec field => accepted spellings
     * @param array<string,int|string> $override field => column index chosen by hand
     *
     * @return array<string,array{column:int|null, header:string, score:int, confident:bool}>
     */
    public static function propose(array $headers, array $spec, array $override = []): array
    {
        $normalised = array_map([self::class, 'normalise'], $headers);

        // Scored as field x column first, then assigned best-first, so that two
        // fields competing for one column resolve in favour of the better match
        // rather than in whichever order the spec happens to list them.
        // "Employee No" against EmployeeNo and EmployeeID is exactly that case.
        $candidates = [];
        foreach ($spec as $field => $aliases) {
            foreach ($normalised as $column => $header) {
                if ($header === '') continue;
                $score = self::score($header, $field, $aliases);
                if ($score > 0) $candidates[] = [$score, $field, $column];
            }
        }

        usort($candidates, fn($a, $b) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);

        $byField = [];
        $usedColumns = [];
        foreach ($candidates as [$score, $field, $column]) {
            if (isset($byField[$field]) || isset($usedColumns[$column])) continue;
            $byField[$field] = ['column' => $column, 'score' => $score];
            $usedColumns[$column] = true;
        }

        $map = [];
        foreach (array_keys($spec) as $field) {
            $chosen = $byField[$field] ?? null;

            // A hand-picked column always wins, including the empty string,
            // which is how the preview screen says "do not import this field".
            if (array_key_exists($field, $override)) {
                $picked = $override[$field];
                $chosen = ($picked === '' || $picked === null)
                    ? null
                    : ['column' => (int) $picked, 'score' => 100];
            }

            $column = $chosen['column'] ?? null;
            if ($column !== null && !isset($headers[$column])) $column = null;

            $map[$field] = [
                'column' => $column,
                'header' => $column === null ? '' : $headers[$column],
                'score' => $column === null ? 0 : (int) $chosen['score'],
                'confident' => $column !== null && $chosen['score'] >= self::CONFIDENT,
            ];
        }

        return $map;
    }

    /**
     * How well one heading matches one field, 0-100.
     *
     * Only an exact match - on the field name itself or on one of its listed
     * spellings - ever reaches CONFIDENT. Everything below is a suggestion the
     * preview screen marks for review, and that ceiling is deliberate: the
     * approximate matches are the ones that look right and are not.
     *
     * "Rate" inside "Rate 2024" is the case. It is a containment hit worth
     * offering, because the column may well be the rate; it is also exactly how
     * last year's figures get imported as this year's. Scored on how much of
     * the longer string the match accounts for, and capped below the confidence
     * line so it is always shown rather than assumed.
     */
    private static function score(string $header, string $field, array $aliases): int
    {
        $best = 0;

        foreach (array_merge([$field], $aliases) as $candidate) {
            $target = self::normalise($candidate);
            if ($target === '') continue;

            if ($header === $target) return 100;

            if (str_contains($header, $target) || str_contains($target, $header)) {
                $shorter = min(strlen($header), strlen($target));
                $longer = max(strlen($header), strlen($target));
                $best = max($best, (int) round(40 + 29 * ($shorter / $longer)));
                continue;
            }

            // Tolerates a typo or a plural, and nothing more: the distance is
            // capped at a third of the word so "Position" cannot match "Contact".
            $distance = levenshtein($header, $target);
            $allowed = (int) floor(max(strlen($header), strlen($target)) / 3);
            if ($distance > 0 && $distance <= $allowed) {
                $best = max($best, (int) round(69 - 10 * $distance));
            }
        }

        return $best;
    }

    /**
     * Reduces a heading to comparable letters and digits.
     *
     * Spaces, underscores, punctuation and case all vary between offices and
     * none of them carries meaning here. A trailing "(optional)" or a unit in
     * brackets is dropped for the same reason - "Daily Rate (PHP)" is the daily
     * rate.
     */
    public static function normalise(string $header): string
    {
        $header = preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $header) ?? $header;
        $header = mb_strtolower(trim($header), 'UTF-8');

        return preg_replace('/[^a-z0-9]+/', '', $header) ?? '';
    }

    /**
     * The columns no field claimed.
     *
     * Surfaced in the preview so an unrecognised heading is visible rather than
     * quietly dropped - a column called "Rate 2024" that nothing matched is
     * usually a mapping the operator wants to make by hand, not a column they
     * meant to leave out.
     *
     * @param array<string,array{column:int|null}> $map
     * @return array<int,string>
     */
    public static function unmatched(array $headers, array $map): array
    {
        $claimed = array_filter(array_column($map, 'column'), fn($c) => $c !== null);

        $spare = [];
        foreach ($headers as $index => $header) {
            if ($header !== '' && !in_array($index, $claimed, true)) $spare[$index] = $header;
        }

        return $spare;
    }
}
