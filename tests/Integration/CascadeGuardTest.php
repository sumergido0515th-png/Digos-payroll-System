<?php
/**
 * ============================================================================
 * CascadeGuardTest - Every destructive foreign key pointing at a table a user
 * can delete from must be answered for, in the delete endpoint or here.
 *
 * WHY THIS EXISTS
 *   Twice now the delete guards have fallen behind the schema. Migration 0009
 *   turned references into real foreign keys and left apiDeleteOffice and
 *   apiDeleteFunction destroying history (fixed 2026-07-29). Phases 3, 5 and 7
 *   then added TravelOrders, BioExemptions and Suspensions hanging off
 *   Employees by ON DELETE CASCADE, and apiDeleteEmployee - which had been
 *   taught about Contracts and DtrDays - never learned about those three
 *   (fixed 2026-08-29).
 *
 *   Both times the code was correct when written and was made wrong by a later
 *   migration, silently, with nothing failing. That is a structural problem
 *   and a point fix does not solve it: the third occurrence is already
 *   possible the next time someone writes ON DELETE CASCADE.
 *
 *   So this reads the real schema rather than the migration text - what the
 *   database will actually do is the only thing that matters here - and pairs
 *   every CASCADE and SET NULL onto a deletable parent with either a mention
 *   of the child table in that endpoint, or an entry below saying why losing
 *   those rows is intended.
 *
 * WHAT COUNTS AS DESTRUCTIVE
 *   CASCADE deletes the child rows. SET NULL keeps them and erases the link,
 *   which is worse: it is invisible. The July defect was a SET NULL blanking
 *   FunctionCode on approved payrolls, erasing which appropriation paid them.
 *   RESTRICT is safe by construction - the database refuses - so it is not
 *   checked here. DeleteGuardTest covers turning that refusal into words.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Tests\Architecture\SourceTree;
use PHPUnit\Framework\TestCase;

final class CascadeGuardTest extends TestCase
{
    /**
     * Destructive foreign keys answered somewhere other than by naming the
     * child table in the endpoint, keyed "childtable.Column".
     *
     * Two kinds of entry, and the reason says which: the rows are meant to go,
     * or the refusal lives somewhere this test cannot see - a repository
     * method rather than an inline count.
     *
     * This is a decision ledger, not a to-do list. Anything not here has to be
     * visibly refused by the endpoint itself.
     *
     * @var array<string, string>
     */
    private const ACCOUNTED_FOR = [
        // Employees
        'employeesensitive.EmployeeID' =>
            'The restricted tier is the employee\'s own data and has no meaning without '
            . 'them - that is what 0015 separated it for.',
        'memorandumemployees.EmployeeID' =>
            'A membership list. The memorandum survives minus one name, which is exactly '
            . 'what removing an employee from it means.',
        'attachmentcoverage.EmployeeID' =>
            'Per-employee-per-date, so it cannot exist without the DtrDays rows '
            . 'apiDeleteEmployee already refuses on.',

        // Memorandum. The chain columns are refused by MemorandumRepo, which
        // lists the referencing control numbers rather than counting them, so
        // no SELECT in the endpoint names these.
        'memorandum.SupersedesID' =>
            'Refused by MemorandumRepo::referencedBy(), which names the memoranda that '
            . 'would lose the link rather than counting them.',
        'memorandum.AmendsID' =>
            'Refused by MemorandumRepo::referencedBy() with SupersedesID and RevokedByID.',
        'memorandum.RevokedByID' =>
            'Refused by MemorandumRepo::referencedBy() with SupersedesID and AmendsID.',
        'memorandumemployees.MemoID' =>
            'The other half of the same membership list: deleting the memorandum removes '
            . 'who it named, which is not history of its own.',

        // Attachments
        'attachmentcoverage.AttachmentID' =>
            'Coverage records which dates an attachment covered. Without the attachment '
            . 'it documents nothing.',

        // Payroll. apiDeletePayroll refuses anything but DRAFT, and a draft has
        // never been through pre-audit or printed officially, so neither table
        // can hold rows for it.
        'printlog.PayrollNo' =>
            'Only a DRAFT payroll can be deleted, and a draft has never printed - '
            . 'apiDeletePayroll is what holds this true.',
        'suspensions.PayrollNo' =>
            'Only a DRAFT payroll can be deleted, and suspensions are raised at '
            . 'pre-audit, which a draft has not reached.',
        'payroll.SupplementsPayrollNo' =>
            'The supplemental chain, which nothing writes yet - Phase 8 cut the amendment '
            . 'flow. Revisit with it rather than guarding a column with no writer.',

        // Users
        'scopegrants.UserEmail' =>
            'An account\'s scope grants are the account. Keeping them after it is gone '
            . 'would leave access rows naming nobody.',
    ];

    /**
     * Endpoints that delete rows a user can reach, mapped to the file holding
     * them. Derived from the source rather than listed, so a new apiDelete* is
     * covered the day it is written.
     */
    protected function setUp(): void
    {
        if (!TestDatabase::isAvailable()) {
            $this->markTestSkipped(
                'No test database reachable. Set DB_HOST/DB_NAME/DB_USER/DB_PASS and run '
                . 'php tools/migrate.php first.');
        }
    }

    public function testEveryDestructiveCascadeOntoADeletableParentIsAnsweredFor(): void
    {
        $unanswered = [];

        foreach (self::deleteEndpoints() as $function => $endpoint) {
            foreach ($endpoint['parents'] as $parent) {
                foreach (self::destructiveChildrenOf($parent) as $child) {
                    if (isset(self::ACCOUNTED_FOR[$child['key']])) continue;
                    if (self::endpointAnswersFor($endpoint['source'], $parent, $child)) continue;

                    $unanswered[] = sprintf(
                        "  %s() deletes from %s; %s.%s is ON DELETE %s and is never mentioned",
                        $function, $parent, $child['table'], $child['column'], $child['rule']);
                }
            }
        }

        $this->assertSame([], $unanswered,
            "A delete endpoint destroys rows it never mentions:\n" . implode("\n", $unanswered)
            . "\n\nEither refuse the delete while those rows exist - referenceGuard() in "
            . "app/Master.php is the shape - or add the foreign key to ACCOUNTED_FOR in "
            . "this test with the reason it is meant to happen. A silent SET NULL is the "
            . "one that will not be noticed until an audit asks.");
    }

    /**
     * The ledger has to describe reality. An entry for a foreign key that no
     * longer exists is a decision about nothing, and it hides the fact that
     * the schema moved underneath it.
     */
    public function testTheIntendedLossLedgerHasNoStaleEntries(): void
    {
        $live = [];
        foreach (self::destructiveForeignKeys() as $fk) $live[$fk['key']] = true;

        foreach (array_keys(self::ACCOUNTED_FOR) as $key) {
            $this->assertArrayHasKey($key, $live,
                "ACCOUNTED_FOR names $key, which is no longer a destructive foreign key. "
                . 'Remove it - a ledger of decisions about foreign keys that do not exist '
                . 'is how the next one gets missed.');
        }
    }

    /* ====================================================================== */

    /**
     * Whether the endpoint visibly accounts for one child foreign key.
     *
     * Naming the child table is normally enough, because every guard here
     * counts its rows in a SELECT. A self-referencing key is the exception and
     * needs the column: the endpoint always names its own table in the DELETE,
     * so a table-name match would pass Departments -> Departments without
     * anyone having thought about it. That is exactly what it did.
     *
     * @param array{table: string, column: string} $child
     */
    private static function endpointAnswersFor(string $source, string $parent, array $child): bool
    {
        if ($child['table'] === $parent) {
            return stripos($source, $child['column']) !== false;
        }

        return stripos($source, $child['table']) !== false
            || stripos($source, $child['column']) !== false;
    }

    /**
     * Every apiDelete* function, with its body and the tables it deletes from.
     *
     * @return array<string, array{source: string, parents: string[]}>
     */
    private static function deleteEndpoints(): array
    {
        $out = [];

        foreach (SourceTree::phpFiles() as $file) {
            $src = SourceTree::readCode($file);

            // Function bodies run to the first closing brace in column one,
            // which is the convention every module here follows.
            preg_match_all(
                '/^function (apiDelete\w+)\s*\([^)]*\)[^{]*\{(.*?)^\}/ms', $src, $m, PREG_SET_ORDER);

            foreach ($m as $fn) {
                preg_match_all('/DELETE\s+FROM\s+`?(\w+)`?/i', $fn[2], $tables);
                if (!$tables[1]) continue;

                $out[$fn[1]] = [
                    'source' => $fn[2],
                    'parents' => array_values(array_unique(array_map('strtolower', $tables[1]))),
                ];
            }
        }

        return $out;
    }

    /**
     * Destructive foreign keys pointing at one table.
     *
     * @return list<array{table: string, column: string, rule: string, key: string}>
     */
    private static function destructiveChildrenOf(string $parent): array
    {
        return array_values(array_filter(
            self::destructiveForeignKeys(),
            fn(array $fk) => $fk['parent'] === strtolower($parent)));
    }

    /**
     * Every CASCADE and SET NULL in the schema, read from the live database.
     *
     * @return list<array{parent: string, table: string, column: string, rule: string, key: string}>
     */
    private static function destructiveForeignKeys(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $rows = TestDatabase::connect()->query(
            "SELECT LOWER(kcu.REFERENCED_TABLE_NAME) AS parent,
                    LOWER(rc.TABLE_NAME)             AS child,
                    kcu.COLUMN_NAME                  AS col,
                    rc.DELETE_RULE                   AS rule
               FROM information_schema.REFERENTIAL_CONSTRAINTS rc
               JOIN information_schema.KEY_COLUMN_USAGE kcu
                 ON kcu.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
                AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
              WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
                AND rc.DELETE_RULE IN ('CASCADE', 'SET NULL')")->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'parent' => $r['parent'],
                'table' => $r['child'],
                'column' => $r['col'],
                'rule' => $r['rule'],
                'key' => $r['child'] . '.' . $r['col'],
            ];
        }

        return $cache = $out;
    }
}
