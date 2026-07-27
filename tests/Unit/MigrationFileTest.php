<?php

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Migration\MigrationFile;
use PHPUnit\Framework\TestCase;

final class MigrationFileTest extends TestCase
{
    /**
     * The whole point of the normalisation: the same committed migration has
     * CRLF in a Windows checkout and LF in a Linux one. If those hashed
     * differently, running the migrator from CI against a database migrated
     * from XAMPP would report tampering and refuse to continue.
     */
    public function testLineEndingsDoNotAffectTheChecksum(): void
    {
        $lf = "CREATE TABLE t (\n  id INT\n);\n";
        $crlf = "CREATE TABLE t (\r\n  id INT\r\n);\r\n";
        $cr = "CREATE TABLE t (\r  id INT\r);\r";

        $this->assertSame(MigrationFile::checksum($lf), MigrationFile::checksum($crlf));
        $this->assertSame(MigrationFile::checksum($lf), MigrationFile::checksum($cr));
    }

    public function testTrailingNewlinesDoNotAffectTheChecksum(): void
    {
        $this->assertSame(
            MigrationFile::checksum('SELECT 1;'),
            MigrationFile::checksum("SELECT 1;\n\n\n"));
    }

    public function testDifferentContentProducesDifferentChecksums(): void
    {
        $this->assertNotSame(
            MigrationFile::checksum('SELECT 1;'),
            MigrationFile::checksum('SELECT 2;'));
    }

    /** Internal whitespace is content: a changed migration must be detected. */
    public function testInternalChangesAreStillDetected(): void
    {
        $this->assertNotSame(
            MigrationFile::checksum("SELECT 1;\nSELECT 2;"),
            MigrationFile::checksum("SELECT 1;\nSELECT 3;"));
    }

    public function testChecksumIsASha256Hex(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', MigrationFile::checksum('x'));
    }
}
