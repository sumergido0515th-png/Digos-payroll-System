<?php
/**
 * ============================================================================
 * Import.php - Bulk master-data import from a spreadsheet, a web table or a
 * JSON file. Preview first, commit second.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Domain\Import\ColumnMap;
use Digos\Domain\Import\EntitySpec;
use Digos\Domain\Import\SourceTable;
use Digos\Repo\ImportRepo;

/**
 * Rows shown back on the preview screen. The whole file is validated either
 * way; this only limits what travels to the browser, because a 5,000-row
 * response is slower to render than the import takes to run.
 */
const IMPORT_PREVIEW_ROWS = 25;

/**
 * The importable record types this caller may actually write.
 *
 * `data.import` gets you to the screen; the entity's own permission decides
 * what you may put through it. Both are checked, and the list is filtered
 * rather than offered-and-refused for the same reason the Pag-IBIG print
 * button is hidden from roles without `employee.sensitive`: an option that
 * always fails teaches nothing.
 */
function apiGetImportTypes(array $p, array $user): array
{
    $types = [];
    foreach (EntitySpec::all() as $entity => $spec) {
        if (!hasPermission($user, $spec['permission'])) continue;

        $types[] = [
            'entity' => $entity,
            'label' => $spec['label'],
            'required' => $spec['required'],
            'fields' => array_keys($spec['fields']),
        ];
    }

    return [
        'types' => $types,
        'maxBytes' => SourceTable::MAX_BYTES,
        'maxRows' => SourceTable::MAX_ROWS,
    ];
}

/**
 * Reads the file, proposes a column mapping and validates every row - without
 * writing anything.
 *
 * Payload: {entity, data: "data:...;base64,...", fileName, mapping: {Field: columnIndex}}
 *
 * The preview is not a convenience. The column mapping is a guess made from
 * whatever somebody typed in a heading row, and a guess nobody reviews is just
 * a silent error with extra steps - so the guess is always shown, always
 * correctable, and never applied in the same request that produced it.
 */
function apiPreviewImport(array $p, array $user): array
{
    $plan = importPlan($p, $user);

    $invalid = array_values(array_filter($plan['rows'], fn($r) => $r['errors'] !== []));

    return [
        'entity' => $plan['entity'],
        'label' => $plan['spec']['label'],
        'format' => $plan['format'],
        'sheet' => $plan['sheet'],
        'headers' => $plan['headers'],
        'mapping' => $plan['mapping'],
        'unmatched' => $plan['unmatched'],
        'missingRequired' => $plan['missingRequired'],
        'duplicateKeys' => $plan['duplicateKeys'],
        'total' => count($plan['rows']),
        'validCount' => count($plan['rows']) - count($invalid),
        'invalidCount' => count($invalid),
        // Errors first: with 400 rows and 3 problems, a preview that shows the
        // first 25 rows in file order shows 25 rows that are fine.
        'rows' => array_slice(array_merge($invalid,
            array_values(array_filter($plan['rows'], fn($r) => $r['errors'] === []))),
            0, IMPORT_PREVIEW_ROWS),
    ];
}

/**
 * Applies a previewed import.
 *
 * The file is sent again rather than held between the two calls - api.php is
 * stateless and there is nowhere to park an upload that would not become its
 * own cleanup problem. It is re-parsed and re-validated here, so the commit
 * never trusts the browser's account of what the preview said.
 *
 * Every row goes through the entity's ordinary save function. Nothing here
 * writes a row itself, which is what keeps an import from becoming a way past
 * the validation, the scope check and the audit entry that a record typed into
 * the form goes through.
 */
function apiCommitImport(array $p, array $user): array
{
    $plan = importPlan($p, $user);
    $spec = $plan['spec'];

    requirePermission($user, $spec['permission']);

    if ($plan['missingRequired']) {
        throw new RuntimeException('These required columns are not mapped: '
            . implode(', ', $plan['missingRequired']) . '. Set them on the preview screen and retry.');
    }

    $invalid = array_filter($plan['rows'], fn($r) => $r['errors'] !== []);

    // Refusing by default rather than importing what happens to be clean. A
    // partial import is the state that is hardest to get out of: the operator
    // corrects the file and imports it again, and the rows that already landed
    // are silently updated rather than created, so the counts never reconcile.
    if ($invalid && empty($p['skipInvalid'])) {
        $first = reset($invalid);
        throw new RuntimeException(count($invalid) . ' of ' . count($plan['rows'])
            . ' rows have problems - for example row ' . $first['line'] . ': ' . implode('; ', $first['errors'])
            . '. Fix the file and retry, or choose "Import the valid rows only".');
    }

    $usable = array_values(array_filter($plan['rows'], fn($r) => $r['errors'] === []));
    if (!$usable) throw new RuntimeException('There are no valid rows to import.');

    $save = $spec['save'];
    $created = 0;
    $updated = 0;
    $keys = [];

    // All or nothing. A save that throws here is not a validation problem the
    // preview could have caught - a clashing employee number, a foreign key
    // with nothing to point at - and carrying on past one means guessing that
    // the rest of the file is unaffected by whatever caused it.
    ImportRepo::transactional(function () use ($usable, $save, $user, &$created, &$updated, &$keys) {
        foreach ($usable as $row) {
            try {
                $result = $save($row['values'], $user);
            } catch (Throwable $e) {
                throw new RuntimeException('Row ' . $row['line'] . ' (' . $row['key'] . ') was rejected: '
                    . $e->getMessage() . ' Nothing was imported.', 0, $e);
            }

            if (!empty($result['created'])) $created++;
            if (!empty($result['updated'])) $updated++;
            if ($row['key'] !== '') $keys[] = $row['key'];
        }
    });

    // Written explicitly, in addition to api.php's automatic entry for this
    // route. The automatic one records the attempt - who, when, which entity,
    // and an inline file it cannot summarise - and survives a failure. This one
    // records the outcome, which is the part an auditor asks about later: how
    // many records this created, how many it overwrote, and which ones.
    writeLog($user['Email'], 'IMPORT_DATA', $spec['label'], mb_substr(sprintf(
        '%s: %d created, %d updated from %s file (%s)',
        $spec['label'], $created, $updated, $plan['format'], implode(', ', $keys)), 0, 400));

    return [
        'entity' => $plan['entity'],
        'created' => $created,
        'updated' => $updated,
        'skipped' => count($invalid),
        'total' => count($plan['rows']),
    ];
}

/* ==========================================================================
 * The shared plan
 * ======================================================================== */

/**
 * Parses, maps and validates an upload - the identical work preview and commit
 * both need, so that the second can never disagree with the first.
 */
function importPlan(array $p, array $user): array
{
    requireFields($p, ['entity', 'data']);

    $spec = EntitySpec::get((string) $p['entity']);
    $table = SourceTable::parse(importBytes((string) $p['data']), (string) ($p['fileName'] ?? ''));

    $override = is_array($p['mapping'] ?? null) ? $p['mapping'] : [];
    $mapping = ColumnMap::propose($table['headers'], $spec['fields'], $override);

    $missingRequired = array_values(array_filter($spec['required'],
        fn($field) => ($mapping[$field]['column'] ?? null) === null));

    $rows = [];
    $seen = [];
    $duplicateKeys = [];

    foreach ($table['rows'] as $index => $cells) {
        // The header occupies line 1, so a problem reported against "row 14" is
        // the row numbered 14 in the spreadsheet the operator is looking at.
        $line = $index + 2;
        $values = $spec['defaults'];
        $errors = [];

        foreach ($mapping as $field => $column) {
            if ($column['column'] === null) continue;

            $raw = $cells[$column['column']] ?? '';
            try {
                $coerced = EntitySpec::coerce($field, $raw);
                if ($coerced !== '') $values[$field] = $coerced;
            } catch (Throwable $e) {
                $errors[] = 'column "' . $column['header'] . '": ' . $e->getMessage();
            }
        }

        foreach ($spec['required'] as $field) {
            if (in_array($field, $missingRequired, true)) continue;
            if (trim((string) ($values[$field] ?? '')) === '') $errors[] = $field . ' is blank';
        }

        $key = (string) ($values[$spec['key']] ?? '');

        // Two rows carrying one key is not a database error - the second would
        // simply update the first - so nothing downstream would report it, and
        // the operator would be left with one record where they counted two.
        if ($key !== '') {
            if (isset($seen[$key])) {
                $errors[] = $spec['key'] . ' "' . $key . '" also appears on row ' . $seen[$key];
                if (!in_array($key, $duplicateKeys, true)) $duplicateKeys[] = $key;
            } else {
                $seen[$key] = $line;
            }
        }

        $rows[] = ['line' => $line, 'key' => $key, 'values' => $values, 'errors' => $errors];
    }

    return [
        'entity' => (string) $p['entity'],
        'spec' => $spec,
        'format' => $table['format'],
        'sheet' => $table['sheet'],
        'headers' => $table['headers'],
        'mapping' => $mapping,
        'unmatched' => ColumnMap::unmatched($table['headers'], $mapping),
        'missingRequired' => $missingRequired,
        'duplicateKeys' => $duplicateKeys,
        'rows' => $rows,
    ];
}

/**
 * Recovers the uploaded bytes from the inline payload.
 *
 * The browser reads the file and sends it as a data: URL because api.php speaks
 * JSON only - the same route apiUploadImageSetting takes. The declared media
 * type is ignored on purpose: SourceTable decides the format from the content,
 * and a name or a MIME type is only ever a claim.
 */
function importBytes(string $data): string
{
    $payload = preg_match('~^data:[^,]*,~', $data, $prefix)
        ? substr($data, strlen($prefix[0]))
        : $data;

    $binary = base64_decode($payload, true);
    if ($binary === false || $binary === '') {
        throw new RuntimeException('The file could not be read. Choose it again and retry.');
    }

    return $binary;
}
