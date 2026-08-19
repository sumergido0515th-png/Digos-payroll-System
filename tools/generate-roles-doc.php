<?php
/**
 * ============================================================================
 * generate-roles-doc.php - Writes docs/ROLES.md from the live PERMISSIONS
 * matrix and the ROUTES table.
 *
 *   php tools/generate-roles-doc.php            write docs/ROLES.md
 *   php tools/generate-roles-doc.php --check    exit 1 if it would change
 *
 * Generated rather than hand-written because a role/permission matrix is
 * exactly the kind of document that is accurate the day it is written and
 * wrong a month later. --check is what a future CI step would run.
 *
 * Reads app/Auth.php and public/api.php as TEXT, like the architecture suite
 * does, so this tool never opens a database or starts a session.
 * ============================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("generate-roles-doc.php is a command-line tool.\n");
}

const ROOT = __DIR__ . '/..';

$check = in_array('--check', array_slice($argv, 1), true);
$target = ROOT . '/docs/ROLES.md';

$permissions = parsePermissions(file_get_contents(ROOT . '/app/Auth.php'));
$routes = parseRoutes(file_get_contents(ROOT . '/public/api.php'));

$markdown = render($permissions, $routes);

if ($check) {
    $current = is_file($target) ? file_get_contents($target) : '';
    if (trim($current) === trim($markdown)) {
        echo "docs/ROLES.md is up to date.\n";
        exit(0);
    }
    fwrite(STDERR, "docs/ROLES.md is stale - run php tools/generate-roles-doc.php\n");
    exit(1);
}

file_put_contents($target, $markdown);
printf("wrote docs/ROLES.md - %d roles, %d permissions, %d routes\n",
    count($permissions), count(allPermissions($permissions, $routes)), count($routes));

/* ========================================================================== */

/** @return array<string, string[]> role => permissions */
function parsePermissions(string $src): array
{
    if (!preg_match('/const\s+PERMISSIONS\s*=\s*\[(.*?)\n\];/s', $src, $block)) {
        throw new RuntimeException('Could not locate PERMISSIONS in app/Auth.php.');
    }
    preg_match_all("/'(?<role>[^']+)'\s*=>\s*\[(?<perms>[^\]]*)\]/s", $block[1], $m, PREG_SET_ORDER);

    $out = [];
    foreach ($m as $role) {
        preg_match_all("/'([^']+)'/", $role['perms'], $perms);
        $out[$role['role']] = $perms[1];
    }
    if (count($out) < 5) {
        throw new RuntimeException('Parsed only ' . count($out) . ' roles - the regex has drifted.');
    }
    return $out;
}

/** @return array<string, array{permission:string, module:string, log:string}> */
function parseRoutes(string $src): array
{
    preg_match('/const\s+ROUTES\s*=\s*\[(.*?)\n\];/s', $src, $block);
    preg_match_all(
        "/'(?<action>api\w+)'\s*=>\s*\[\s*'(?<perm>[^']*)'\s*,\s*'(?<mod>[^']*)'\s*,\s*'(?<log>[^']*)'\s*\]/",
        $block[1] ?? '', $m, PREG_SET_ORDER);

    $out = [];
    foreach ($m as $r) {
        $out[$r['action']] = ['permission' => $r['perm'], 'module' => $r['mod'], 'log' => $r['log']];
    }
    return $out;
}

/** Every permission named anywhere, sorted. */
function allPermissions(array $permissions, array $routes): array
{
    $all = [];
    foreach ($permissions as $perms) {
        foreach ($perms as $p) if ($p !== '*') $all[$p] = true;
    }
    foreach ($routes as $r) {
        if ($r['permission'] !== '') $all[$r['permission']] = true;
    }
    ksort($all);
    return array_keys($all);
}

function render(array $permissions, array $routes): string
{
    $roles = array_keys($permissions);
    $all = allPermissions($permissions, $routes);

    $out = "# Roles and permissions\n\n"
        . "**Generated** by `php tools/generate-roles-doc.php` from `PERMISSIONS` in\n"
        . "[app/Auth.php](../app/Auth.php) and the `ROUTES` table in\n"
        . "[public/api.php](../public/api.php). **Do not edit by hand** - regenerate it.\n"
        . "`php tools/generate-roles-doc.php --check` fails if this file has drifted.\n\n"
        . "Phase 2's second deliverable, alongside the scope enforcement layer itself.\n\n"
        . "## What this document is not\n\n"
        . "**These are actions, not scope.** Holding `payroll.view` says you may look at\n"
        . "payrolls; it never says *which*. That is `ScopeGrants`, applied by\n"
        . "`Digos\\Repo\\ScopeGateway`, and it is what lets one Pre-Auditor cover two offices\n"
        . "and another cover one without inventing a role per office. A user with no grant\n"
        . "reads nothing, whatever this table says.\n\n"
        . "`*` is every permission, and only Admin holds it.\n\n"
        . "## Matrix\n\n";

    $out .= '| Permission | ' . implode(' | ', $roles) . " |\n";
    $out .= '|---|' . str_repeat('---|', count($roles)) . "\n";

    foreach ($all as $permission) {
        $cells = [];
        foreach ($roles as $role) {
            $held = in_array('*', $permissions[$role], true)
                || in_array($permission, $permissions[$role], true);
            $cells[] = $held ? 'yes' : '-';
        }
        $out .= '| `' . $permission . '` | ' . implode(' | ', $cells) . " |\n";
    }

    // Permissions a route requires that no named role lists - reachable only
    // through Admin's '*'. RouteTableTest keeps this set honest.
    $adminOnly = [];
    foreach ($all as $permission) {
        $namedHolder = false;
        foreach ($roles as $role) {
            if (in_array('*', $permissions[$role], true)) continue;
            if (in_array($permission, $permissions[$role], true)) { $namedHolder = true; break; }
        }
        if (!$namedHolder) $adminOnly[] = $permission;
    }

    if ($adminOnly) {
        $out .= "\n## Administrator-only\n\n"
            . "No named role lists these; they are reachable only through Admin's `*`.\n"
            . "`RouteTableTest::testEveryRoutePermissionIsOneSomeRoleCanHold` fails if one\n"
            . "appears here by accident rather than by decision.\n\n";
        foreach ($adminOnly as $p) $out .= "- `$p`\n";
    }

    $out .= "\n## Endpoints by permission\n\n";
    $byPermission = [];
    foreach ($routes as $action => $r) {
        $byPermission[$r['permission'] === '' ? '(any signed-in user)' : $r['permission']][] = $action;
    }
    ksort($byPermission);
    foreach ($byPermission as $permission => $actions) {
        sort($actions);
        $label = $permission === '(any signed-in user)' ? $permission : '`' . $permission . '`';
        $out .= '- ' . $label . ' — ' . implode(', ', $actions) . "\n";
    }

    return $out;
}
