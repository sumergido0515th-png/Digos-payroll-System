<?php
/**
 * ============================================================================
 * SqlExportTest - The script produced by `migrate.php --sql` is imported by
 * hand through phpMyAdmin, which sends the file to the server as raw text.
 *
 * WHY THIS EXISTS
 * MySQL only treats "--" as a comment when whitespace or end-of-line follows.
 * A decorative rule of bare dashes is therefore parsed as SQL and rejected
 * with "#1064 ... syntax error", which is what a real import produced before
 * this was fixed.
 *
 * The failure is invisible to any test that pre-processes the file with our
 * own StatementSplitter, because the splitter strips comments before the
 * server ever sees them - which is exactly how the bug shipped. These
 * assertions run against the raw bytes.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SqlExportTest extends TestCase
{
    private static string $sql = '';

    public static function setUpBeforeClass(): void
    {
        $target = sys_get_temp_dir() . '/digos_sql_export_test.sql';
        @unlink($target);

        $command = sprintf('%s %s --sql=%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(PROJECT_ROOT . '/tools/migrate.php'),
            escapeshellarg($target));

        exec($command, $output, $status);

        if ($status !== 0 || !is_file($target)) {
            self::fail('migrate.php --sql failed: ' . implode("\n", $output));
        }

        self::$sql = (string) file_get_contents($target);
        @unlink($target);
    }

    /** The defect that broke a real phpMyAdmin import. */
    public function testEveryCommentLineIsValidMysqlCommentSyntax(): void
    {
        $offenders = [];

        foreach (explode("\n", self::$sql) as $number => $line) {
            $line = rtrim($line, "\r");

            // Valid: "-- text", or "--" alone (the newline is the whitespace).
            // Invalid: "--" followed immediately by any non-whitespace, which
            // is what a rule of bare dashes looks like.
            if (preg_match('/^--[^\s]/', $line)) {
                $offenders[] = sprintf('line %d: %s', $number + 1, substr($line, 0, 60));
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These lines are not valid MySQL comments and will be parsed as SQL:\n  %s\n\n"
            . "MySQL requires whitespace after '--'. Use '-- ====' for a rule, never '===='.",
            implode("\n  ", $offenders)));
    }

    public function testExportHasNoByteOrderMark(): void
    {
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", self::$sql,
            'A BOM makes phpMyAdmin report a syntax error on line 1.');
    }

    public function testExportCarriesTheMigrationBookkeeping(): void
    {
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS schema_migrations', self::$sql);
        $this->assertStringContainsString('INSERT INTO schema_migrations', self::$sql,
            'Without this the imported database looks unmigrated and the runner would re-apply everything.');
    }

    /**
     * phpMyAdmin imports into whichever database is selected; a CREATE
     * DATABASE or USE naming our local database would target the wrong one on
     * a shared host, where the name is assigned by the provider.
     */
    public function testExportDoesNotHardcodeADatabaseName(): void
    {
        $this->assertDoesNotMatchRegularExpression('/^\s*(CREATE\s+DATABASE|USE)\s/mi', self::$sql);
    }

    public function testExportContainsTheBaselineSchema(): void
    {
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS Employees', self::$sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS Payroll', self::$sql);
    }
}
