<?php
/**
 * ============================================================================
 * Helpers.php - Shared helpers: envelopes, validation, formatting,
 * identifiers and amount-in-words. Mirrors src/Utilities.gs.
 * ============================================================================
 */

declare(strict_types=1);

/** Standard success envelope. */
function ok(mixed $data = null, string $message = ''): array
{
    return ['ok' => true, 'data' => $data, 'message' => $message];
}

/** Standard failure envelope. */
function fail(string $message): array
{
    return ['ok' => false, 'data' => null, 'message' => $message];
}

/** Coerces any input to a float, defaulting to zero. */
function num(mixed $n): float
{
    if ($n === null || $n === '' || $n === false) return 0.0;
    $v = (float) str_replace(',', '', (string) $n);
    return is_finite($v) ? $v : 0.0;
}

/** Rounds to two decimals (centavos). */
function round2(mixed $n): float
{
    return round(num($n), 2);
}

/** Formats money: "12,345.00". */
function money(mixed $n): string
{
    return number_format(round2($n), 2);
}

/** Formats a date string/DateTime for display (MM/DD/YYYY by default). */
function fmtDate(mixed $d, string $format = 'm/d/Y'): string
{
    if (!$d) return '';
    try {
        $dt = $d instanceof DateTimeInterface ? $d : new DateTime((string) $d);
        return $dt->format($format);
    } catch (Throwable) {
        return (string) $d;
    }
}

/** Builds "LAST, First M. Suffix" from an employee record. */
function fullName(array $e): string
{
    $mid = !empty($e['MiddleName']) ? ' ' . strtoupper(substr((string)$e['MiddleName'], 0, 1)) . '.' : '';
    $sfx = !empty($e['Suffix']) ? ' ' . $e['Suffix'] : '';
    return trim(strtoupper((string)($e['LastName'] ?? '')) . ', ' . ($e['FirstName'] ?? '') . $mid . $sfx);
}

/** HTML-escapes a value for server-rendered output (print module). */
function esc(mixed $s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Generates a short collision-resistant id, e.g. "EMP-LQ2K8F-482". */
function newId(string $prefix): string
{
    return $prefix . '-' . strtoupper(base_convert((string) (int) (microtime(true) * 1000), 10, 36))
        . '-' . random_int(100, 999);
}

/**
 * Throws when a required field is missing/blank.
 *
 * Arrays are checked for emptiness rather than cast. Casting one to a string
 * yields "Array" - which is not empty, so an empty list of payroll lines or DTR
 * days would have passed this check while emitting a warning. That went
 * unnoticed until Phase 3B required its first array field.
 */
function requireFields(array $p, array $fields): void
{
    $missing = array_filter($fields, function ($f) use ($p) {
        if (!isset($p[$f])) return true;
        return is_array($p[$f]) ? $p[$f] === [] : trim((string) $p[$f]) === '';
    });

    if ($missing) {
        throw new RuntimeException('Required field(s) missing: ' . implode(', ', $missing));
    }
}

/**
 * A payload reduced to what belongs in the audit log.
 *
 * An uploaded image arrives as an inline data: URL, and the log stores the
 * first 400 characters of the payload - which would be 400 characters of
 * base64 telling a reviewer nothing, in place of the fields that matter.
 */
function auditSummary(array $payload): array
{
    foreach ($payload as $key => $value) {
        if (is_string($value) && str_starts_with($value, 'data:')) {
            $payload[$key] = '<inline data, ' . strlen($value) . ' bytes>';
        }
    }
    return $payload;
}

/** Validates an email address shape. */
function isEmail(mixed $email): bool
{
    return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL);
}

/** Case-insensitive contains test across fields (live search). */
function rowMatches(array $row, array $fields, ?string $term): bool
{
    if ($term === null || $term === '') return true;
    $t = mb_strtolower($term);
    foreach ($fields as $f) {
        if (mb_stripos((string)($row[$f] ?? ''), $t) !== false) return true;
    }
    return false;
}

/** QR code image URL for printed payrolls (api.qrserver.com, no API key). */
function qrUrl(string $text, int $size = 84): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
        . '&data=' . urlencode($text);
}

/* ==========================================================================
 * Amount in words (Philippine payroll wording)
 * ======================================================================== */

/** "TWELVE THOUSAND ... PESOS AND 50/100" */
function amountInWords(float $amount): string
{
    $pesos = (int) floor($amount);
    $centavos = (int) round(($amount - $pesos) * 100);
    $words = $pesos === 0 ? 'ZERO' : strtoupper(numberWords($pesos));
    return $words . ' PESOS AND ' . str_pad((string) $centavos, 2, '0', STR_PAD_LEFT) . '/100';
}

/** Recursive integer-to-English converter (up to billions). */
function numberWords(int $n): string
{
    static $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight',
        'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen',
        'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    static $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy',
        'Eighty', 'Ninety'];

    if ($n < 20) return $ones[$n];
    if ($n < 100) return $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
    if ($n < 1000) return $ones[intdiv($n, 100)] . ' Hundred' . ($n % 100 ? ' ' . numberWords($n % 100) : '');
    if ($n < 1000000) return numberWords(intdiv($n, 1000)) . ' Thousand' . ($n % 1000 ? ' ' . numberWords($n % 1000) : '');
    if ($n < 1000000000) return numberWords(intdiv($n, 1000000)) . ' Million' . ($n % 1000000 ? ' ' . numberWords($n % 1000000) : '');
    return numberWords(intdiv($n, 1000000000)) . ' Billion' . ($n % 1000000000 ? ' ' . numberWords($n % 1000000000) : '');
}

/* ==========================================================================
 * Field aliasing
 * ==========================================================================
 * MySQL reserves FUNCTION, so tables store `FunctionName`; the frontend was
 * built against the field name `Function`. These two mappers translate at
 * the API boundary so the UI is reused unchanged.
 */

/** DB row -> API shape: expose FunctionName as Function. */
function aliasFunctionOut(array $row): array
{
    if (array_key_exists('FunctionName', $row)) {
        $row['Function'] = $row['FunctionName'];
        unset($row['FunctionName']);
    }
    return $row;
}

/** API payload -> DB shape: accept Function and store as FunctionName. */
function aliasFunctionIn(array $p): array
{
    if (array_key_exists('Function', $p)) {
        $p['FunctionName'] = $p['Function'];
        unset($p['Function']);
    }
    return $p;
}
