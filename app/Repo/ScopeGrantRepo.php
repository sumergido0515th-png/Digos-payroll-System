<?php
/**
 * ============================================================================
 * ScopeGrantRepo - The only place ScopeGrants is read.
 *
 * Grants are loaded once per user per request and cached. Every scoped query
 * needs them, a list screen issues several, and re-reading the same handful of
 * rows for each one buys nothing. The cache lives for one request, so a grant
 * revoked mid-session takes effect on the next one.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;

final class ScopeGrantRepo
{
    /** @var array<string, array<int, array<string, mixed>>> keyed by user email */
    private static array $cache = [];

    /**
     * Every grant held by this user, live or not.
     *
     * Filtering by role, access right and validity window is
     * ScopePredicate::applicable()'s job, not SQL's - it is pure, so the rules
     * are testable without a database, and the clock is passed in rather than
     * read. Doing it here would move the decision into a query nobody can
     * fixture.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forUser(string $email): array
    {
        if (isset(self::$cache[$email])) return self::$cache[$email];

        return self::$cache[$email] = DB::rows(
            'SELECT GrantID, UserEmail, RoleCode, OfficeCode, FunctionCode,
                    EmploymentTypeCode, FiscalYear, CanRead, CanWrite,
                    ValidFrom, ValidTo
               FROM ScopeGrants
              WHERE UserEmail = ?
              ORDER BY GrantedAt',
            [$email]);
    }

    /**
     * Every grant in the system, newest first, for the administration screen.
     *
     * Deliberately unscoped: this is the table that decides scope, so scoping
     * it by itself would let a grant hide the grant that created it. Reaching
     * it needs `scope.manage`, which only an administrator holds.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return DB::rows(
            'SELECT g.*, u.FullName AS UserName, o.OfficeName
               FROM ScopeGrants g
               LEFT JOIN Users u ON u.Email = g.UserEmail
               LEFT JOIN Offices o ON o.OfficeCode = g.OfficeCode
              ORDER BY g.GrantedAt DESC, g.GrantID');
    }

    /** One grant by id, or null. */
    public static function find(string $grantId): ?array
    {
        return DB::row('SELECT * FROM ScopeGrants WHERE GrantID = ?', [$grantId]);
    }

    /** Inserts or updates a grant. Returns its id. */
    public static function save(array $record, ?string $grantId = null): string
    {
        if ($grantId !== null && self::find($grantId)) {
            DB::update('ScopeGrants', $record, 'GrantID', $grantId);
            self::forget();
            return $grantId;
        }

        $record['GrantID'] = $grantId ?: \newId('SG');
        DB::insert('ScopeGrants', $record);
        self::forget();
        return $record['GrantID'];
    }

    public static function delete(string $grantId): int
    {
        $deleted = DB::exec('DELETE FROM ScopeGrants WHERE GrantID = ?', [$grantId]);
        self::forget();
        return $deleted;
    }

    /** How many live read grants an email holds - used to refuse the last one. */
    public static function countFor(string $email): int
    {
        return (int) DB::scalar(
            'SELECT COUNT(*) FROM ScopeGrants WHERE UserEmail = ? AND CanRead = 1', [$email]);
    }

    /** Drops the per-request cache. Tests grant and re-read within one process. */
    public static function forget(?string $email = null): void
    {
        if ($email === null) {
            self::$cache = [];
            return;
        }
        unset(self::$cache[$email]);
    }
}
