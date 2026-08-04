<?php
/**
 * ============================================================================
 * ImportRepo.php - The transaction a bulk import runs inside.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;

/**
 * Runs the whole of one import as a single unit of work.
 *
 * This repository holds no queries of its own, which makes it look thin - but
 * `DB::` may only appear in `app/Repo/` (`tests/Architecture/DatabaseAccessTest`),
 * and `app/Import.php` needs a transaction, so the boundary is what this file
 * is for. Reaching for `DB::tx()` directly from the import module would have
 * grown the grandfathered allowlist that is only ever allowed to shrink.
 *
 * The transaction itself is the point. An import applies one write per row
 * through the ordinary save functions; without it, a file whose ninetieth row
 * is rejected leaves eighty-nine employees created and no record of a failure
 * on the other eleven. Half an office's staff loaded is worse than none,
 * because the operator's natural next move is to fix the file and import it
 * again - and the rows that did land the first time are already there.
 */
final class ImportRepo
{
    /**
     * Applies $work inside a transaction, rolling back if anything throws.
     *
     * Note for anyone extending this: MySQL commits implicitly on DDL, so
     * nothing inside may alter the schema or the rollback becomes a no-op. An
     * import only ever inserts and updates rows, which is safe.
     */
    public static function transactional(callable $work): mixed
    {
        return DB::tx($work);
    }
}
