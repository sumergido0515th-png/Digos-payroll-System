<?php
/**
 * ============================================================================
 * StatementSplitterTest - The migration runner is only as trustworthy as its
 * statement splitter: a mis-split file applies half a migration to a real
 * payroll database and cannot be rolled back (MySQL commits on DDL).
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Migration\StatementSplitter;
use PHPUnit\Framework\TestCase;

final class StatementSplitterTest extends TestCase
{
    public function testSplitsOnTopLevelSemicolons(): void
    {
        $this->assertSame(
            ['SELECT 1', 'SELECT 2'],
            StatementSplitter::split('SELECT 1; SELECT 2;'));
    }

    public function testDropsEmptyAndWhitespaceOnlyStatements(): void
    {
        $this->assertSame(['SELECT 1'], StatementSplitter::split(";;\n  \n SELECT 1 ;  ;"));
    }

    public function testTrailingStatementWithoutSemicolonIsKept(): void
    {
        $this->assertSame(['SELECT 1'], StatementSplitter::split('SELECT 1'));
    }

    public function testSemicolonInsideStringLiteralDoesNotSplit(): void
    {
        $sql = "INSERT INTO Settings VALUES ('Sub', 'Digos City; Davao del Sur');";

        $out = StatementSplitter::split($sql);

        $this->assertCount(1, $out);
        $this->assertStringContainsString('Digos City; Davao del Sur', $out[0]);
    }

    public function testSemicolonInsideBacktickIdentifierDoesNotSplit(): void
    {
        $out = StatementSplitter::split('SELECT `odd;name` FROM t;');

        $this->assertSame(['SELECT `odd;name` FROM t'], $out);
    }

    public function testDoubledQuoteIsAnEscapedQuoteNotAStringEnd(): void
    {
        // 'O''Brien; Jr' is one literal; the semicolon must not split it.
        $sql = "INSERT INTO t VALUES ('O''Brien; Jr'); SELECT 2;";

        $out = StatementSplitter::split($sql);

        $this->assertCount(2, $out);
        $this->assertStringContainsString("O''Brien; Jr", $out[0]);
        $this->assertSame('SELECT 2', $out[1]);
    }

    public function testBackslashEscapedQuoteDoesNotEndTheString(): void
    {
        $sql = "INSERT INTO t VALUES ('a\\'b; c'); SELECT 2;";

        $out = StatementSplitter::split($sql);

        $this->assertCount(2, $out);
        $this->assertSame('SELECT 2', $out[1]);
    }

    public function testLineCommentsAreStripped(): void
    {
        $sql = "-- a leading note; with a semicolon\nSELECT 1;\n# hash comment; too\nSELECT 2;";

        $out = StatementSplitter::split($sql);

        $this->assertSame(['SELECT 1', 'SELECT 2'], $out);
    }

    public function testBlockCommentsAreStripped(): void
    {
        $out = StatementSplitter::split("/* header; note */ SELECT 1; /* tail */ SELECT 2;");

        $this->assertSame(['SELECT 1', 'SELECT 2'], $out);
    }

    public function testUnterminatedBlockCommentConsumesTheRest(): void
    {
        $this->assertSame(['SELECT 1'], StatementSplitter::split('SELECT 1; /* never closed'));
    }

    public function testDoubleDashInsideAStringIsNotAComment(): void
    {
        $sql = "INSERT INTO t VALUES ('a -- b; c'); SELECT 2;";

        $out = StatementSplitter::split($sql);

        $this->assertCount(2, $out);
        $this->assertStringContainsString('a -- b; c', $out[0]);
    }

    public function testEmptyInputProducesNoStatements(): void
    {
        $this->assertSame([], StatementSplitter::split(''));
        $this->assertSame([], StatementSplitter::split("\n\n  \n"));
        $this->assertSame([], StatementSplitter::split('-- only a comment'));
    }

    /**
     * The real migration files are the actual input this class exists for,
     * so assert against them rather than only synthetic cases.
     */
    public function testBaselineMigrationSplitsIntoExecutableStatements(): void
    {
        $path = PROJECT_ROOT . '/migrations/0001_baseline_schema.sql';
        $this->assertFileExists($path, 'The baseline migration is missing.');

        $statements = StatementSplitter::split((string) file_get_contents($path));

        $this->assertNotEmpty($statements);
        foreach ($statements as $statement) {
            $this->assertMatchesRegularExpression(
                '/^(CREATE|INSERT|ALTER|DROP|SET|USE)\b/i', $statement,
                "Split produced a fragment that is not a statement:\n" . substr($statement, 0, 200));
        }
    }
}
