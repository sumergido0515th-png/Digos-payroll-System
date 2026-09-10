<?php
/**
 * ============================================================================
 * ErrorLogTest - app/ErrorLog.php's write and read paths.
 *
 * Covers what CascadeGuardTest and MigrationColumnsAreUsedTest cannot: that
 * ErrorLogRepo::record() actually lands a row with every field intact, that
 * apiGetErrorLog() filters and orders it correctly, that apiLogClientError()
 * stamps the caller's own email rather than trusting the payload for it, and
 * that logUnexpectedError() - the function api.php's dispatcher now calls
 * for any Throwable that is not a RuntimeException - both records the full
 * detail and returns a short reference rather than the raw message.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\ErrorLogRepo;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorLogTest extends TestCase
{
    private const MARK = 'ZZERRORLOG';

    protected function setUp(): void
    {
        if (!TestDatabase::isAvailable()) {
            $this->markTestSkipped('No test database reachable. Run php tools/migrate.php first.');
        }
        ApplicationLayer::load();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (defined('DB_NAME')) $this->cleanup();
    }

    private function cleanup(): void
    {
        TestDatabase::connect()
            ->prepare('DELETE FROM ErrorLog WHERE Message LIKE ?')
            ->execute([self::MARK . '%']);
    }

    private function user(): array
    {
        return [
            'Email' => 'zz-errorlog-fixture@digos.gov.ph',
            'FullName' => 'ErrorLog fixture',
            'Role' => 'Encoder',
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS['Encoder'],
        ];
    }

    public function testRecordWritesEveryFieldAndRecentReadsItBack(): void
    {
        ErrorLogRepo::record([
            'Source' => 'php',
            'Message' => self::MARK . ' something broke',
            'File' => '/app/Whatever.php',
            'Line' => 42,
            'Trace' => "#0 {main}",
            'Url' => 'payroll.digos.gov.ph/api.php',
            'UserEmail' => 'someone@digos.gov.ph',
            'UserAgent' => 'PHPUnit',
        ]);

        $rows = ErrorLogRepo::recent(null, self::MARK, 10);
        $this->assertCount(1, $rows);
        $this->assertSame('php', $rows[0]['Source']);
        $this->assertSame(self::MARK . ' something broke', $rows[0]['Message']);
        $this->assertSame('/app/Whatever.php', $rows[0]['File']);
        $this->assertSame(42, (int) $rows[0]['Line']);
        $this->assertSame('someone@digos.gov.ph', $rows[0]['UserEmail']);
    }

    public function testRecentFiltersBySource(): void
    {
        ErrorLogRepo::record(['Source' => 'php', 'Message' => self::MARK . ' php one']);
        ErrorLogRepo::record(['Source' => 'js', 'Message' => self::MARK . ' js one']);

        $php = ErrorLogRepo::recent('php', self::MARK, 10);
        $js = ErrorLogRepo::recent('js', self::MARK, 10);

        $this->assertCount(1, $php);
        $this->assertSame('php', $php[0]['Source']);
        $this->assertCount(1, $js);
        $this->assertSame('js', $js[0]['Source']);
    }

    public function testRecentOrdersNewestFirst(): void
    {
        ErrorLogRepo::record(['Source' => 'php', 'Message' => self::MARK . ' first']);
        ErrorLogRepo::record(['Source' => 'php', 'Message' => self::MARK . ' second']);
        ErrorLogRepo::record(['Source' => 'php', 'Message' => self::MARK . ' third']);

        $rows = ErrorLogRepo::recent(null, self::MARK, 10);

        $this->assertSame(
            [self::MARK . ' third', self::MARK . ' second', self::MARK . ' first'],
            array_map(fn(array $r) => $r['Message'], $rows));
    }

    public function testApiGetErrorLogDelegatesToRecent(): void
    {
        ErrorLogRepo::record(['Source' => 'js', 'Message' => self::MARK . ' from the api call']);

        $rows = \apiGetErrorLog(['search' => self::MARK], $this->user());

        $this->assertCount(1, $rows);
        $this->assertSame(self::MARK . ' from the api call', $rows[0]['Message']);
    }

    public function testApiLogClientErrorStampsTheCallersOwnEmailNotThePayloads(): void
    {
        $result = \apiLogClientError([
            'message' => self::MARK . ' a screen threw',
            'file' => 'app.js',
            'line' => 17,
            'stack' => 'TypeError: x is not a function',
            'url' => 'https://payroll.digos.gov.ph/#payroll',
            'userAgent' => 'Mozilla/5.0 fixture',
            // A caller-supplied UserEmail must never override the session's
            // own identity - that would let one account's browser errors be
            // attributed to another.
            'UserEmail' => 'someone-else@digos.gov.ph',
        ], $this->user());

        $this->assertTrue($result['logged']);

        $rows = ErrorLogRepo::recent('js', self::MARK, 10);
        $this->assertCount(1, $rows);
        $this->assertSame($this->user()['Email'], $rows[0]['UserEmail']);
        $this->assertSame('app.js', $rows[0]['File']);
        $this->assertSame(17, (int) $rows[0]['Line']);
    }

    public function testLogUnexpectedErrorRecordsDetailAndReturnsAShortReference(): void
    {
        $e = new RuntimeException(self::MARK . ' deliberately thrown, not the message shown to the user');

        $ref = \logUnexpectedError($e);

        $this->assertMatchesRegularExpression('/^[0-9A-F]{8}$/', $ref);

        $rows = ErrorLogRepo::recent('php', self::MARK, 10);
        $this->assertCount(1, $rows);
        $this->assertSame($e->getMessage(), $rows[0]['Message']);
        $this->assertSame($e->getFile(), $rows[0]['File']);
        $this->assertSame($e->getLine(), (int) $rows[0]['Line']);
    }

    public function testRecordDegradesRatherThanThrowOnAMissingRequiredField(): void
    {
        // 'Source' is deliberately omitted - record() must degrade to
        // 'unknown' rather than let the one place that is already handling
        // an error throw a second one.
        ErrorLogRepo::record(['Message' => self::MARK . ' malformed entry']);

        $rows = ErrorLogRepo::recent(null, self::MARK, 10);
        $this->assertCount(1, $rows);
        $this->assertSame('unknown', $rows[0]['Source']);
        $this->assertSame(self::MARK . ' malformed entry', $rows[0]['Message']);
    }
}
