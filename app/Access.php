<?php
/**
 * ============================================================================
 * Access.php - Scope grant administration.
 *
 * Migration 0016 seeds one wildcard grant per administrator so nobody is
 * locked out at cutover. Everything after that was an INSERT by hand until
 * this module existed - which meant the control that decides who sees which
 * office's payroll had no audit trail of its own administration, and a new
 * user could not be given access without database credentials.
 *
 * No DB:: here. Every query goes through Digos\Repo\ScopeGrantRepo, so this
 * module stays off the legacy allowlist in
 * tests/Architecture/DatabaseAccessTest.php rather than adding to it.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Domain\Access\ScopeEntity;
use Digos\Repo\ScopeGrantRepo;

/** Every grant, with the holder's name and office resolved for display. */
function apiListScopeGrants(array $p, array $user): array
{
    $rows = ScopeGrantRepo::all();

    if (!empty($p['UserEmail'])) {
        $rows = array_values(array_filter($rows, fn($g) => $g['UserEmail'] === $p['UserEmail']));
    }
    if (!empty($p['search'])) {
        $rows = array_values(array_filter($rows, fn($g) => rowMatches(
            $g, ['UserEmail', 'UserName', 'RoleCode', 'OfficeCode', 'OfficeName', 'Remarks'],
            $p['search'])));
    }

    $today = date('Y-m-d');
    return array_map(function ($g) use ($today) {
        $g['IsLive'] = (empty($g['ValidFrom']) || $g['ValidFrom'] <= $today)
            && (empty($g['ValidTo']) || $g['ValidTo'] >= $today);
        $g['Covers'] = scopeGrantSummary($g);
        return $g;
    }, $rows);
}

/**
 * Creates or updates a grant.
 *
 * Blank means wildcard on every dimension, which is how "all offices" is
 * expressed - so a blank form is the widest grant in the system, not the
 * narrowest. That is worth knowing before saving one, and the summary the list
 * screen shows says so in words.
 */
function apiSaveScopeGrant(array $p, array $user): array
{
    requireFields($p, ['UserEmail']);
    if (!isEmail($p['UserEmail'])) {
        throw new RuntimeException('Invalid email address: ' . $p['UserEmail']);
    }

    $canRead = !empty($p['CanRead']);
    $canWrite = !empty($p['CanWrite']);
    if (!$canRead && !$canWrite) {
        throw new RuntimeException(
            'A grant has to allow reading or writing, otherwise it does nothing.');
    }

    $from = ($p['ValidFrom'] ?? '') ?: null;
    $to = ($p['ValidTo'] ?? '') ?: null;
    if ($from && $to && $from > $to) {
        throw new RuntimeException('Valid To must fall on or after Valid From.');
    }

    if (!empty($p['RoleCode']) && !isset(PERMISSIONS[$p['RoleCode']])) {
        throw new RuntimeException('Unknown role: ' . $p['RoleCode']);
    }

    $record = [
        'UserEmail' => $p['UserEmail'],
        'RoleCode' => ($p['RoleCode'] ?? '') ?: null,
        'OfficeCode' => ($p['OfficeCode'] ?? '') ?: null,
        'FunctionCode' => ($p['FunctionCode'] ?? '') ?: null,
        'EmploymentTypeCode' => ($p['EmploymentTypeCode'] ?? '') ?: null,
        'FiscalYear' => ($p['FiscalYear'] ?? '') !== '' ? (int) num($p['FiscalYear']) : null,
        'CanRead' => $canRead ? 1 : 0,
        'CanWrite' => $canWrite ? 1 : 0,
        'ValidFrom' => $from,
        'ValidTo' => $to,
        // Who issued this, which is the column that was never written while
        // grants could only be created in SQL.
        'GrantedBy' => $user['Email'],
        'Remarks' => $p['Remarks'] ?? '',
    ];

    $id = ScopeGrantRepo::save($record, ($p['GrantID'] ?? '') ?: null);
    return ['GrantID' => $id, 'Covers' => scopeGrantSummary($record)];
}

/**
 * Revokes a grant.
 *
 * Refuses to remove the last read grant of the person doing it. Deny-by-default
 * means revoking your own last grant locks you out of the screen you would need
 * to undo it, and the only way back is the database - the position this whole
 * module exists to get out of.
 */
function apiDeleteScopeGrant(array $p, array $user): array
{
    requireFields($p, ['GrantID']);

    $grant = ScopeGrantRepo::find($p['GrantID']);
    if (!$grant) throw new RuntimeException('Grant not found: ' . $p['GrantID']);

    if ($grant['UserEmail'] === $user['Email']
        && (int) $grant['CanRead'] === 1
        && ScopeGrantRepo::countFor($user['Email']) <= 1) {
        throw new RuntimeException(
            'This is your own last remaining access grant. Removing it would lock you out '
            . 'of this screen, so another administrator has to do it.');
    }

    return ['deleted' => ScopeGrantRepo::delete($p['GrantID'])];
}

/**
 * What a grant covers, in words.
 *
 * NULL-means-wildcard reads correctly in SQL and badly on a screen: a row of
 * blanks looks like an incomplete record rather than "everything". Somebody
 * approving a grant should be able to see that they are about to hand over the
 * whole city.
 */
function scopeGrantSummary(array $grant): string
{
    $parts = [];
    foreach ([
        'OfficeCode' => 'office',
        'FunctionCode' => 'fund',
        'EmploymentTypeCode' => 'employment type',
        'FiscalYear' => 'fiscal year',
    ] as $column => $label) {
        $value = $grant[$column] ?? null;
        if ($value !== null && trim((string) $value) !== '') {
            $parts[] = $label . ' ' . $value;
        }
    }

    $scope = $parts ? implode(', ', $parts) : 'EVERYTHING - all offices, funds and years';

    $rights = [];
    if (!empty($grant['CanRead'])) $rights[] = 'read';
    if (!empty($grant['CanWrite'])) $rights[] = 'write';

    return implode('/', $rights) . ': ' . $scope;
}

/** The dimensions a grant can narrow, for the form. */
function apiGetScopeDimensions(array $p, array $user): array
{
    return ['dimensions' => ScopeEntity::DIMENSIONS, 'entities' => ScopeEntity::names()];
}
