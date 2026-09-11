<?php
/**
 * ============================================================================
 * Guards 2 and 3 - The ROUTES table in public/api.php is the single door to
 * every piece of business logic. It is also the single place where
 * authentication, permission checks and audit logging are applied.
 *
 * WHY THIS EXISTS
 *   Guard 2: an api* function with no ROUTES entry is dead code; a ROUTES
 *            entry with no function is a 'Unknown action' error in production.
 *   Guard 3: a ROUTES entry with an empty permission is callable by any
 *            signed-in account regardless of role. That is occasionally
 *            correct (session lifecycle) and otherwise a security hole - so
 *            it must be an explicit, named decision rather than an omission.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class RouteTableTest extends TestCase
{
    /**
     * Endpoints deliberately callable by any authenticated user, regardless
     * of role. Session lifecycle only - adding anything else here needs a
     * stated reason.
     *
     * @var string[]
     */
    private const INTENTIONALLY_UNPRIVILEGED = [
        'apiGetSession',    // SPA boot: identity + settings for the current user
        'apiHeartbeat',     // idle-timer refresh, returns no data
        'apiLogout',        // ending your own session needs no permission
        'apiLogClientError', // any signed-in role can report a broken screen
    ];

    /**
     * Permissions no named role holds, reachable only through Admin's '*'.
     *
     * Every entry is a deliberate decision that one role and no other may do
     * this. Anything not listed here has to appear in some role's PERMISSIONS,
     * which is what catches a typo: 'scope.manag' would be held by nobody,
     * leaving the route reachable only by an administrator - failing closed,
     * but silently and for the wrong reason.
     *
     * @var array<string,string>
     */
    private const ADMIN_ONLY_PERMISSIONS = [
        'scope.manage' => 'who may see which office is an administrator decision; '
            . 'a role that could widen its own scope would be no control at all',
        'user.manage' => 'account creation and role assignment',
        'settings.edit' => 'system-wide settings, including the payroll number prefix',
        'settings.view' => 'same screen as settings.edit',
        'backup.run' => 'reads and restores the whole database',

        // Found by this guard on its first run, and PRE-DATES Phase 2: no role
        // has ever held it, on either side of the role remap. Employee deletion
        // has always been administrator-only through '*', and apiDeleteEmployee
        // already refuses anyone who appears on a payroll line, so it removes
        // mistakes rather than history. Listed rather than granted to HRMO,
        // because handing a role a destructive power it never had is a policy
        // decision and not a side effect of adding a test.
        //
        // DECIDED 2026-08-29: it stays administrator-only. The question was
        // raised here for Phase 7, which shipped without answering it, so this
        // is the answer rather than the deferral it was. What changed in the
        // meantime is that the endpoint now refuses on payroll lines,
        // contracts, DTR days, travel orders, bio exemptions and suspensions -
        // six kinds of history, up from one - so the power being withheld is
        // only ever the removal of an employee nothing points at. That is a
        // small enough thing to keep asking an administrator for.
        'employee.delete' => 'destructive master-data removal; administrator-only since Phase 0, '
            . 'ratified 2026-08-29',
    ];

    /**
     * Routed endpoints no screen calls, each with the reason.
     *
     * WHY THIS GUARD EXISTS
     * The other guards in this file prove a route is wired, permissioned and
     * audited. None of them proves anybody can reach it. The gap has cost
     * three real defects: `apiGetPayrollFacets`/`apiGetEmployeeFacets` sat
     * unused while the filter bars they were written for disclosed the
     * citywide office list (9E); the four document facet endpoints sat unused
     * while one hardcoded Status list served five tabs, offering memoranda a
     * status no memo can hold; and `apiReturnPayroll` sat unused while
     * RETURNED_TO_PREPARER - a state in PayrollWorkflow::FLOW, in EDITABLE,
     * with the Payroll screen already offering edit and re-submit on it - was
     * unreachable from the UI entirely.
     *
     * Every one of those was invisible because nothing fails when a working
     * endpoint has no caller. This list is the record of what is still in
     * that position. **It may only shrink**, like
     * DatabaseAccessTest's grandfather list: an entry comes off when a screen
     * calls it, and a new one goes on only with a reason a reader can weigh.
     *
     * @var array<string,string>
     */
    private const NOT_CALLED_BY_ANY_SCREEN = [
        // Superseded rather than missing.
        'apiLogout' => 'public/logout.php calls the same authLogout() and is what the '
            . 'sign-out button navigates to; this is a second door to one room',
        'apiGetDtrTotals' => 'apiGetDtrGrid already returns grid.totals, which is what '
            . 'views/dtr.php renders',
        'apiGetMemorandum' => 'views/documents.php opens its edit form from the row the '
            . 'list already returned; this is the read that also carries the covered '
            . 'employees, which that form therefore does not show',
        'apiGetRoles' => 'views/users.php builds its role dropdown from App.lookups',
        'apiGetScopeDimensions' => 'views/users.php builds its grant form from App.lookups',

        // UI that was never built. Each is a feature, not a spare endpoint.
        'apiListHolidays' => 'no holiday screen exists at all - migration 0019 shipped the '
            . 'table and the pay rules, and the only trace in the UI is a legend label in '
            . 'views/coverage.php and a day-type option in views/dtr.php',
        'apiSaveHoliday' => 'no holiday screen exists',
        'apiDeleteHoliday' => 'no holiday screen exists',
        'apiListHolidayPayRules' => 'no holiday screen exists',
        'apiResolveDay' => 'app/Calendar.php\'s own header calls this "the endpoint a screen '
            . 'calls to ask what this date was"; no screen asks',
        'apiImportBiometricLogs' => 'no biometric import screen - views/import.php covers '
            . 'master data only, deliberately (see the Backlog), but nothing covers this either',
        'apiAmendContract' => 'contract amendment has no UI; views/documents.php offers save '
            . 'and renew only',
        'apiGetContractHistory' => 'no UI shows a contract\'s superseded versions',
        'apiGetWorkShiftHistory' => 'no UI shows a shift\'s superseded versions, though '
            . 'apiListWorkShifts takes IncludeSuperseded',
        'apiDeleteDtrDay' => 'views/dtr.php saves a whole grid; it has no per-day delete',
        'apiGetAttachment' => 'views/coverage.php lists attachments and links to '
            . 'public/download.php for the file; nothing reads one back through the API',
    ];

    public function testEveryRouteResolvesToADefinedFunction(): void
    {
        $functions = SourceTree::apiFunctions();
        $missing = array_diff(array_keys(SourceTree::routes()), array_keys($functions));

        $this->assertSame([], array_values($missing), sprintf(
            "public/api.php routes to functions that do not exist:\n  - %s\n" .
            "Calling these returns 'Unknown action' at runtime.",
            implode("\n  - ", $missing)));
    }

    public function testEveryApiFunctionIsReachableThroughTheRouteTable(): void
    {
        $routes = SourceTree::routes();
        $unrouted = [];

        foreach (SourceTree::apiFunctions() as $action => $file) {
            if (!isset($routes[$action])) $unrouted[] = "$action ($file)";
        }

        $this->assertSame([], $unrouted, sprintf(
            "api* functions with no ROUTES entry - unreachable, and unguarded\n" .
            "if they are ever called another way:\n  - %s",
            implode("\n  - ", $unrouted)));
    }

    public function testEveryRouteDeclaresAPermission(): void
    {
        $unguarded = [];

        foreach (SourceTree::routes() as $action => $route) {
            if ($route['permission'] !== '') continue;
            if (in_array($action, self::INTENTIONALLY_UNPRIVILEGED, true)) continue;
            $unguarded[] = $action;
        }

        $this->assertSame([], $unguarded, sprintf(
            "Routes with an empty permission are callable by every signed-in\n" .
            "account, whatever their role:\n  - %s\n\n" .
            "Give each a permission, or add it to INTENTIONALLY_UNPRIVILEGED\n" .
            "with a comment explaining why it is safe.",
            implode("\n  - ", $unguarded)));
    }

    /**
     * A permission no role can hold is a route only an administrator reaches,
     * usually because somebody mistyped it.
     *
     * The failure is quiet, which is why it needs a test: requirePermission()
     * refuses everyone except Admin's '*', so the endpoint appears to work for
     * whoever built it and is invisibly broken for every other role. Nothing
     * throws and nothing logs.
     */
    public function testEveryRoutePermissionIsOneSomeRoleCanHold(): void
    {
        $granted = [];
        foreach ($this->permissionMatrix() as $permissions) {
            foreach ($permissions as $permission) $granted[$permission] = true;
        }

        $orphans = [];
        foreach (SourceTree::routes() as $action => $route) {
            $permission = $route['permission'];
            if ($permission === '' || $permission === '*') continue;
            if (isset($granted[$permission])) continue;
            if (isset(self::ADMIN_ONLY_PERMISSIONS[$permission])) continue;

            $orphans[] = "$action needs '$permission'";
        }

        $this->assertSame([], $orphans, sprintf(
            "Route permissions that appear in no role's PERMISSIONS list:\n  - %s\n\n" .
            "Either the permission is misspelled, or the role that should hold it\n" .
            "never got it. If it is genuinely administrator-only, add it to\n" .
            "ADMIN_ONLY_PERMISSIONS with the reason.",
            implode("\n  - ", $orphans)));
    }

    /**
     * The PERMISSIONS matrix, parsed out of app/Auth.php as text.
     *
     * Read rather than loaded: app/Auth.php is procedural and requiring it
     * would pull in the database and session layer, which the architecture
     * suite exists to run without.
     *
     * readCode() rather than read(): the inner regex below matches every
     * quoted string, and an apostrophe in a comment - "the role's own
     * permission" - desyncs its quote-pairing exactly the way one did in
     * generate-roles-doc.php before that tool was fixed the same way. Comments
     * are prose about permissions, not permissions.
     *
     * @return array<string, string[]> role => permissions
     */
    private function permissionMatrix(): array
    {
        $src = SourceTree::readCode('app/Auth.php');

        if (!preg_match('/const\s+PERMISSIONS\s*=\s*\[(.*?)\n\];/s', $src, $block)) {
            throw new \RuntimeException('Could not locate PERMISSIONS in app/Auth.php.');
        }

        preg_match_all("/'(?<role>[^']+)'\s*=>\s*\[(?<perms>[^\]]*)\]/s", $block[1], $roles, PREG_SET_ORDER);

        $matrix = [];
        foreach ($roles as $role) {
            preg_match_all("/'([^']+)'/", $role['perms'], $perms);
            $matrix[$role['role']] = $perms[1];
        }

        // Canary: a matrix that failed to parse would make the guard above
        // pass by finding nothing to check.
        if (count($matrix) < 5) {
            throw new \RuntimeException(
                'Parsed only ' . count($matrix) . ' roles from PERMISSIONS - the regex has drifted.');
        }
        return $matrix;
    }

    public function testMutatingRoutesAreAudited(): void
    {
        $mutatingVerbs = ['Save', 'Delete', 'Submit', 'Approve', 'Return', 'Release',
            'Cancel', 'Undo', 'Restore', 'Backup', 'Apply', 'Email'];
        $unlogged = [];

        foreach (SourceTree::routes() as $action => $route) {
            if ($route['log'] !== '') continue;

            foreach ($mutatingVerbs as $verb) {
                if (str_starts_with($action, 'api' . $verb)) {
                    $unlogged[] = $action;
                    break;
                }
            }
        }

        $this->assertSame([], $unlogged, sprintf(
            "Mutating routes with no audit action - changes would not appear in\n" .
            "the Logs table, and the Phase 8 certification could not account for\n" .
            "them:\n  - %s",
            implode("\n  - ", $unlogged)));
    }

    /**
     * Every route is either called by something a person can reach, or listed
     * above with the reason it is not.
     *
     * "Reachable" is deliberately shallow - the action name appearing as a
     * QUOTED string in a view, a public entry point or app.js. It cannot
     * prove the call site is on a path a user can walk, and it is not meant
     * to: what it catches is the endpoint with no call site at all, which is
     * the shape all three defects above had.
     *
     * The quotes are load-bearing, and the first version of this test did not
     * have them. Every real call spells the action as a string literal -
     * `api('apiListSuspensions', ...)`, or `list: 'apiListMemoranda'` in a
     * config table that `api(cfg.list)` later reads - while a comment naming
     * one writes it bare or in backticks. Matching the bare word made the
     * guard pass its own sabotage: un-wiring `apiReturnPayroll` from
     * views/preaudit.php left the docblock above it still saying the name,
     * and the guard read the prose as the caller. That is this codebase's
     * recurring defect (see DatabaseAccessTest's generator, and
     * SourceTree::readCode, which strips PHP comments but cannot reach
     * JavaScript inside a <script> tag), caught here by sabotage rather than
     * shipped.
     */
    public function testEveryRouteIsCalledByTheFrontendOrListedAsNotCalled(): void
    {
        $callers = self::frontendSources();
        $uncalled = [];
        $stale = [];

        foreach (array_keys(SourceTree::routes()) as $action) {
            $called = false;
            foreach ($callers as $source) {
                if (preg_match('/[\'"]' . preg_quote($action, '/') . '[\'"]/', $source)) {
                    $called = true;
                    break;
                }
            }

            if (!$called && !isset(self::NOT_CALLED_BY_ANY_SCREEN[$action])) {
                $uncalled[] = $action;
            }
            if ($called && isset(self::NOT_CALLED_BY_ANY_SCREEN[$action])) {
                $stale[] = $action;
            }
        }

        $this->assertSame([], $uncalled, sprintf(
            "Routed endpoints no string literal in views/, public/*.php or app.js names:\n  - %s\n" .
            "A working endpoint with no caller fails nothing and is invisible. Either wire "
            . "it to a screen, or add it to NOT_CALLED_BY_ANY_SCREEN with the reason.",
            implode("\n  - ", $uncalled)));

        $this->assertSame([], $stale, sprintf(
            "NOT_CALLED_BY_ANY_SCREEN names endpoints a screen now calls:\n  - %s\n" .
            "The list may only shrink - remove these entries.",
            implode("\n  - ", $stale)));
    }

    /**
     * Everything a browser loads, as text.
     *
     * public/api.php is excluded because it names every action by definition,
     * which would make the guard pass on its own subject.
     *
     * @return string[]
     */
    private static function frontendSources(): array
    {
        $root = dirname(__DIR__, 2);
        $paths = array_merge(
            glob($root . '/views/*.php') ?: [],
            glob($root . '/public/*.php') ?: [],
            glob($root . '/public/assets/js/*.js') ?: []);

        $sources = [];
        foreach ($paths as $path) {
            if (basename($path) === 'api.php') continue;
            $sources[] = (string) file_get_contents($path);
        }

        self::assertNotEmpty($sources, 'No frontend sources found - the glob is wrong.');
        return $sources;
    }

    public function testRouteTableIsNotEmpty(): void
    {
        // Cheap canary: if the ROUTES regex ever stops matching, the three
        // guards above would all vacuously pass.
        $this->assertGreaterThan(30, count(SourceTree::routes()),
            'Parsed suspiciously few routes - the ROUTES parser in SourceTree is probably broken.');
    }
}
