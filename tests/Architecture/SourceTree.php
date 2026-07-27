<?php
/**
 * ============================================================================
 * SourceTree.php - Shared source-inspection helpers for the architecture
 * suite. These tests read the source as text rather than loading it, because
 * app/bootstrap.php opens a database connection and starts a session.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

final class SourceTree
{
    /**
     * Every PHP file in the application, repo-relative with forward slashes.
     * Excludes vendor/, tests/ and anything not tracked as source.
     *
     * @return string[]
     */
    public static function phpFiles(): array
    {
        $roots = ['app', 'public', 'views', 'tools'];
        $out = [];

        foreach ($roots as $root) {
            $dir = PROJECT_ROOT . DIRECTORY_SEPARATOR . $root;
            if (!is_dir($dir)) continue;

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $out[] = self::relative($file->getPathname());
                }
            }
        }
        foreach (['cron.php'] as $single) {
            if (is_file(PROJECT_ROOT . DIRECTORY_SEPARATOR . $single)) $out[] = $single;
        }

        sort($out);
        return $out;
    }

    /** Normalises an absolute path to a repo-relative, forward-slashed path. */
    public static function relative(string $absolute): string
    {
        $rel = substr($absolute, strlen(PROJECT_ROOT) + 1);
        return str_replace('\\', '/', $rel);
    }

    /** Reads a repo-relative file. */
    public static function read(string $relative): string
    {
        return (string) file_get_contents(
            PROJECT_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    }

    /**
     * Parses the ROUTES table out of public/api.php as text, so the test does
     * not have to boot the application.
     *
     * @return array<string, array{permission: string, module: string, log: string}>
     */
    public static function routes(): array
    {
        $src = self::read('public/api.php');

        if (!preg_match('/const\s+ROUTES\s*=\s*\[(.*?)\n\];/s', $src, $block)) {
            throw new \RuntimeException('Could not locate the ROUTES table in public/api.php.');
        }

        preg_match_all(
            "/'(?<action>api\w+)'\s*=>\s*\[\s*'(?<perm>[^']*)'\s*,\s*'(?<module>[^']*)'\s*,\s*'(?<log>[^']*)'\s*\]/",
            $block[1], $matches, PREG_SET_ORDER);

        $routes = [];
        foreach ($matches as $m) {
            $routes[$m['action']] = [
                'permission' => $m['perm'],
                'module' => $m['module'],
                'log' => $m['log'],
            ];
        }
        return $routes;
    }

    /**
     * Every function whose name begins with "api", mapped to the file that
     * declares it. These are the application's endpoints.
     *
     * @return array<string, string> action => repo-relative file
     */
    public static function apiFunctions(): array
    {
        $found = [];
        foreach (self::phpFiles() as $file) {
            preg_match_all('/^\s*function\s+(api\w+)\s*\(/m', self::read($file), $m);
            foreach ($m[1] as $name) $found[$name] = $file;
        }
        return $found;
    }
}
