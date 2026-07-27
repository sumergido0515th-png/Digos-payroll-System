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
