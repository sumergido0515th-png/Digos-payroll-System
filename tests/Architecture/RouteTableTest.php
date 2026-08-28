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
     * @return array<string, string[]> role => permissions
     */
    private function permissionMatrix(): array
    {
        $src = SourceTree::read('app/Auth.php');

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

    public function testRouteTableIsNotEmpty(): void
    {
        // Cheap canary: if the ROUTES regex ever stops matching, the three
        // guards above would all vacuously pass.
        $this->assertGreaterThan(30, count(SourceTree::routes()),
            'Parsed suspiciously few routes - the ROUTES parser in SourceTree is probably broken.');
    }
}
