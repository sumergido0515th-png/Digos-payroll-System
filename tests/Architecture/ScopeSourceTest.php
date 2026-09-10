<?php
/**
 * ============================================================================
 * ScopeSourceTest.php - The signed-in user's home office must never decide
 * access.
 *
 * Migration 0016 kept Users.OfficeCode when ScopeGrants superseded it, as the
 * user's home office for display, and named its own weakness in the same
 * breath: "Users.OfficeCode STAYS, as the user's home office for display, and
 * is never read for scope. Nothing enforces that but this comment and the fact
 * that the gateway does not look at it."
 *
 * This is that enforcement. The column is exactly the shape that looks usable
 * for a scope decision - a single office code, sitting on the user row every
 * api* function already has in hand - so reaching for it is a plausible
 * shortcut rather than a far-fetched one. It is also the shape that does not
 * work: one office where a user may hold several grants, no expiry, no
 * read/write distinction, and no record of who granted it.
 *
 * The answer to "may this user see this row?" is always ScopeGrants, applied
 * by Digos\Repo\ScopeGateway. If a later session finds itself reaching for the
 * user's own OfficeCode to answer it, the reach is the bug.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ScopeSourceTest extends TestCase
{
    /**
     * The only place the signed-in user's own OfficeCode may be read.
     *
     * app/Auth.php passes it into the session payload as `officeCode`, which
     * is display data: the Users screen renders and edits the column
     * (views/settings.php), and nothing decides anything from it. Like
     * DatabaseAccessTest's grandfather list, this may only shrink - an entry
     * added here is a deliberate decision that a second display read is worth
     * having, not a way past a failing test.
     */
    private const DISPLAY_ONLY_READS = [
        'app/Auth.php',
    ];

    /**
     * Matches the user array's own OfficeCode - `$user['OfficeCode']` - and
     * not ScopeGrants.OfficeCode, Payroll.OfficeCode or any other table's,
     * which are legitimately read all over the tree. `$user` is the parameter
     * name every api* function receives it under, per public/api.php's
     * dispatcher.
     */
    private const USER_OFFICE_CODE = '/\$user\s*\[\s*[\'"]OfficeCode[\'"]\s*\]/';

    public function testTheUsersOwnOfficeCodeIsReadOnlyForDisplay(): void
    {
        $offenders = [];

        foreach (SourceTree::phpFiles() as $file) {
            if (in_array($file, self::DISPLAY_ONLY_READS, true)) continue;

            // Code only. A docblock explaining that scope never reads
            // Users.OfficeCode is prose about not doing the thing, not the
            // thing - the same false positive readCode() exists to prevent.
            if (preg_match(self::USER_OFFICE_CODE, SourceTree::readCode($file))) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders,
            "The signed-in user's own OfficeCode was read outside the display payload.\n"
            . "Users.OfficeCode is the user's home office for display only (migration 0016).\n"
            . "If this is a scope decision - may this user see this row? - the answer is\n"
            . "ScopeGrants via Digos\\Repo\\ScopeGateway, which handles the several grants,\n"
            . "expiry and read/write distinction a single column on Users cannot express.\n"
            . 'If it is genuinely a second display read, add the file to DISPLAY_ONLY_READS.');
    }

    /**
     * The allowlist is a claim about the tree, so a stale entry is a silent
     * hole: a file that no longer reads the column would keep its exemption
     * ready for whatever gets written there next.
     */
    public function testDisplayAllowlistHasNoStaleEntries(): void
    {
        foreach (self::DISPLAY_ONLY_READS as $file) {
            $path = PROJECT_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);

            $this->assertFileExists($path,
                "Allowlisted file $file no longer exists - remove it from DISPLAY_ONLY_READS.");
            $this->assertMatchesRegularExpression(self::USER_OFFICE_CODE, SourceTree::readCode($file),
                "Allowlisted file $file no longer reads the user's OfficeCode - remove it from DISPLAY_ONLY_READS.");
        }
    }
}
