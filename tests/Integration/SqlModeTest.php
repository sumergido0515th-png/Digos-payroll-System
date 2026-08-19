<?php
/**
 * ============================================================================
 * SqlModeTest - Every connection must run in strict mode.
 *
 * WHY THIS EXISTS
 * MariaDB 10.4 as shipped with XAMPP runs with a permissive sql_mode. Without
 * STRICT_ALL_TABLES the server silently coerces bad values rather than
 * rejecting them - measured on the deployment target:
 *
 *   DECIMAL(12,2) <- "12,450.00"          stored as 12.00
 *   DECIMAL(6,2)  <- 99999.99             stored as 9999.99
 *   VARCHAR(10)   <- "DELA CRUZ, JUAN..." stored as "DELA CRUZ,"
 *
 * In a payroll system that is a wrong figure on a signed voucher instead of an
 * error someone can act on. The application currently launders money values
 * through num()/round2() before they reach the database, so today's path is
 * covered - but every module added in Phases 1-8 is a new opportunity to write
 * to a DECIMAL column directly, and this is the backstop for all of them.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use PDOException;
use PHPUnit\Framework\TestCase;

final class SqlModeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!TestDatabase::isAvailable()) {
            $this->markTestSkipped('No test database reachable.');
        }

        TestDatabase::connect()->exec('DROP TABLE IF EXISTS t_sqlmode_probe');
        TestDatabase::connect()->exec('CREATE TABLE t_sqlmode_probe (
            Name   VARCHAR(10)   NOT NULL DEFAULT "",
            NetPay DECIMAL(12,2) NOT NULL DEFAULT 0,
            Days   DECIMAL(6,2)  NOT NULL DEFAULT 0
        )');
    }

    protected function tearDown(): void
    {
        TestDatabase::connect()->exec('DROP TABLE IF EXISTS t_sqlmode_probe');
    }

    public function testConnectionRunsWithStrictAllTables(): void
    {
        $mode = TestDatabase::connect()->query('SELECT @@session.sql_mode')->fetchColumn();

        $this->assertStringContainsString('STRICT_ALL_TABLES', (string) $mode,
            'The session is not strict - bad values will be silently coerced. '
            . 'Check DB_SQL_MODE in app/config.php reaches every PDO connection.');
    }

    public function testAThousandsSeparatedAmountIsRejectedNotTruncatedToTwelvePesos(): void
    {
        $this->expectException(PDOException::class);

        TestDatabase::connect()
            ->prepare('INSERT INTO t_sqlmode_probe (NetPay) VALUES (?)')
            ->execute(['12,450.00']);
    }

    public function testAnOutOfRangeAmountIsRejectedNotClamped(): void
    {
        $this->expectException(PDOException::class);

        TestDatabase::connect()
            ->prepare('INSERT INTO t_sqlmode_probe (Days) VALUES (?)')
            ->execute([99999.99]);
    }

    public function testAnOverLongNameIsRejectedNotTruncated(): void
    {
        $this->expectException(PDOException::class);

        TestDatabase::connect()
            ->prepare('INSERT INTO t_sqlmode_probe (Name) VALUES (?)')
            ->execute(['DELA CRUZ, JUAN JOSE']);
    }

    /** Strictness must not reject values that are actually valid. */
    public function testWellFormedValuesStillInsert(): void
    {
        TestDatabase::connect()
            ->prepare('INSERT INTO t_sqlmode_probe (Name, NetPay, Days) VALUES (?, ?, ?)')
            ->execute(['DELA CRUZ', 12450.00, 21.50]);

        $row = TestDatabase::connect()
            ->query('SELECT Name, NetPay, Days FROM t_sqlmode_probe')->fetch();

        $this->assertSame('DELA CRUZ', $row['Name']);
        $this->assertSame('12450.00', $row['NetPay']);
        $this->assertSame('21.50', $row['Days']);
    }
}
