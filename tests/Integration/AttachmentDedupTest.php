<?php
/**
 * ============================================================================
 * AttachmentDedupTest - Phase 5's exit gate, dedup half.
 *
 * "Uploading a duplicate file (same hash, different control number) is
 *  rejected."
 *
 * The different control number is the whole point. Two uploads of the same
 * scan under one number is an obvious mistake; under two numbers it looks like
 * two independent pieces of evidence corroborating each other, and that is the
 * thing a pre-audit must not be fooled by.
 *
 * Also asserted here: the constraint holds at the database, not only in the
 * application. A check that lives solely in PHP is a check two concurrent
 * requests can both pass.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\ScopeGrantRepo;
use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;

final class AttachmentDedupTest extends TestCase
{
    private const OFFICE = 'ZZATT';
    private const OTHER_OFFICE = 'ZZATTB';
    private const EMPLOYEE = 'EMP-ZZATT-1';
    private const OTHER_EMPLOYEE = 'EMP-ZZATT-2';
    private const HRMO = 'zzatt-hrmo@digos.gov.ph';

    /** @var string[] files written by a test, removed in tearDown */
    private array $written = [];

    protected function setUp(): void
    {
        if (!TestDatabase::isAvailable()) {
            $this->markTestSkipped('No test database reachable. Run php tools/migrate.php first.');
        }
        ApplicationLayer::load();
        $this->removeFixture();
        $this->createFixture();
        ScopeGrantRepo::forget();
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $name) {
            $path = ATTACHMENT_DIR . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) unlink($path);
        }
        $this->written = [];

        if (defined('DB_NAME')) $this->removeFixture();
        ScopeGrantRepo::forget();
    }

    /* --------------------------------------------------------- the exit gate */

    /** The same bytes under a second control number are refused. */
    public function testTheSameFileUnderADifferentControlNumberIsRejected(): void
    {
        $this->upload(['ControlNo' => 'CN-001']);

        $message = $this->refusalFrom(fn() => $this->upload([
            'ControlNo' => 'CN-002',
            'FileName' => 'a-different-name.pdf',
        ]));

        $this->assertStringContainsString('same file', $message);
        $this->assertStringContainsString('CN-001', $message,
            'The refusal must name what is being duplicated, or it cannot be acted on.');
    }

    /** A genuinely different file under a different number is accepted. */
    public function testADifferentFileIsAccepted(): void
    {
        $first = $this->upload(['ControlNo' => 'CN-001']);
        $second = $this->upload(['ControlNo' => 'CN-002', 'body' => 'a different scan entirely']);

        $this->assertNotSame($first['Sha256'], $second['Sha256']);
        $this->assertCount(2, \apiListAttachments([], $this->hrmo()));
    }

    /**
     * The guarantee is the database's, not the application's.
     *
     * A check that lives only in PHP is one two concurrent requests can both
     * pass. This inserts a second row with the same hash directly, and the
     * unique key has to be what stops it.
     */
    public function testTheDatabaseItselfRefusesASecondRowWithTheSameHash(): void
    {
        $first = $this->upload(['ControlNo' => 'CN-001']);

        $this->expectException(PDOException::class);

        TestDatabase::connect()->prepare(
            'INSERT INTO Attachments (AttachmentID, FileName, StoredName, Sha256, ControlNo)
             VALUES (?, ?, ?, ?, ?)')
            ->execute(['ATT-ZZDUP', 'sneaked-in.pdf', 'x.pdf', $first['Sha256'], 'CN-999']);
    }

    /* ------------------------------------------------------------- coverage */

    /** Coverage is captured at upload, one row per employee per date. */
    public function testCoverageIsStoredPerEmployeePerDate(): void
    {
        $result = $this->upload([
            'CoversFrom' => '2026-07-06',
            'CoversTo' => '2026-07-08',
        ]);

        $this->assertSame(3, $result['covered']);

        $rows = TestDatabase::connect()->query(
            "SELECT CoveredDate FROM AttachmentCoverage
              WHERE EmployeeID = '" . self::EMPLOYEE . "' ORDER BY CoveredDate")->fetchAll();

        $this->assertSame(['2026-07-06', '2026-07-07', '2026-07-08'],
            array_column($rows, 'CoveredDate'));
    }

    /** An explicit date list wins over the range - it is the more specific claim. */
    public function testAnExplicitDateListOverridesTheRange(): void
    {
        $result = $this->upload([
            'CoversFrom' => '2026-07-01',
            'CoversTo' => '2026-07-31',
            'CoveredDates' => ['2026-07-06', '2026-07-09'],
        ]);

        $this->assertSame(2, $result['covered']);
    }

    /** An attachment bound to nobody is refused. */
    public function testAnAttachmentCoveringNobodyIsRefused(): void
    {
        $message = $this->refusalFrom(fn() => $this->upload(['EmployeeIDs' => []]));

        $this->assertStringContainsString('at least one employee', $message);
    }

    /** Binding to another office's employee is refused. */
    public function testBindingToAnOutOfScopeEmployeeIsRefused(): void
    {
        $message = $this->refusalFrom(
            fn() => $this->upload(['EmployeeIDs' => [self::OTHER_EMPLOYEE]]));

        $this->assertStringContainsString('Employee not found', $message);
    }

    /* ------------------------------------------------------ file validation */

    /** The file's own bytes decide its type, not its name or declared MIME. */
    public function testAFileThatIsNotAPdfOrImageIsRefusedWhateverItIsCalled(): void
    {
        $message = $this->refusalFrom(fn() => $this->upload([
            'FileName' => 'looks-legitimate.pdf',
            'data' => 'data:application/pdf;base64,' . base64_encode('<?php echo "not a pdf";'),
        ]));

        $this->assertStringContainsString('Only PDF, JPG and PNG', $message);
    }

    /** A rejected upload leaves no file behind. */
    public function testARejectedDuplicateWritesNoFile(): void
    {
        $this->upload(['ControlNo' => 'CN-001']);
        $before = count(glob(ATTACHMENT_DIR . DIRECTORY_SEPARATOR . '*') ?: []);

        $this->refusalFrom(fn() => $this->upload(['ControlNo' => 'CN-002']));

        $this->assertSame($before, count(glob(ATTACHMENT_DIR . DIRECTORY_SEPARATOR . '*') ?: []),
            'A refused upload left bytes on disk that nothing references.');
    }

    /* -------------------------------------------------------------- scope */

    /** An attachment is visible to whoever may read the people it covers. */
    public function testAnAttachmentIsNotListedForAnotherOfficesReader(): void
    {
        $this->upload([]);

        $this->assertCount(1, \apiListAttachments([], $this->hrmo()));
        $this->assertCount(0, \apiListAttachments([], $this->otherHrmo()),
            "Another office's evidence was listed.");
    }

    /* -------------------------------------------------------------- fixture */

    /**
     * Uploads a minimal but genuine PDF.
     *
     * A real %PDF- header, because attachmentTypeOf() reads the leading bytes
     * and a fixture of "hello" would be testing the refusal path by accident.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function upload(array $overrides): array
    {
        $body = $overrides['body'] ?? 'the scanned memorandum';
        unset($overrides['body']);

        $payload = array_merge([
            'FileName' => 'memo.pdf',
            'data' => 'data:application/pdf;base64,'
                . base64_encode("%PDF-1.4\n" . $body . "\n%%EOF"),
            'ControlNo' => 'CN-001',
            'DocumentType' => 'Memorandum',
            'EmployeeIDs' => [self::EMPLOYEE],
            'CoversFrom' => '2026-07-06',
            'CoversTo' => '2026-07-06',
        ], $overrides);

        $result = \apiUploadAttachment($payload, $this->hrmo());
        $this->written[] = $result['AttachmentID'] . '.pdf';

        return $result;
    }

    private function refusalFrom(callable $call): string
    {
        try {
            $call();
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        $this->fail('The call was expected to be refused and was not.');
    }

    private function hrmo(): array
    {
        return $this->user(self::HRMO);
    }

    private function otherHrmo(): array
    {
        return $this->user('zzatt-other@digos.gov.ph');
    }

    private function user(string $email): array
    {
        return [
            'Email' => $email, 'FullName' => 'Attachment fixture', 'Role' => 'HRMO',
            'OfficeCode' => '', 'permissions' => \PERMISSIONS['HRMO'],
        ];
    }

    private function createFixture(): void
    {
        $db = TestDatabase::connect();

        foreach ([self::OFFICE, self::OTHER_OFFICE] as $office) {
            $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
                ->execute([$office, "Attachment fixture $office", 'Active']);
        }

        foreach ([[self::EMPLOYEE, self::OFFICE, 'MINE, Evidenced'],
                  [self::OTHER_EMPLOYEE, self::OTHER_OFFICE, 'UNSEEABLE, Evidenced']] as $e) {
            [$id, $office, $name] = $e;
            [$last, $first] = explode(', ', $name);

            $db->prepare('INSERT INTO Employees (EmployeeID, LastName, FirstName, OfficeCode,
                                                 EmploymentType, EmploymentTypeCode, Position, Status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $last, $first, $office, 'Job Order', 'JO', 'Worker', 'Active']);
        }

        foreach ([[self::HRMO, self::OFFICE, 'SG-ZZATT-1'],
                  ['zzatt-other@digos.gov.ph', self::OTHER_OFFICE, 'SG-ZZATT-2']] as $u) {
            [$email, $office, $grantId] = $u;

            $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                          VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$email, 'Attachment fixture', 'HRMO', '', 'Active', 'x']);
            $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                          VALUES (?, ?, ?, 1, 1)')->execute([$grantId, $email, $office]);
        }
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $offices = "'" . self::OFFICE . "','" . self::OTHER_OFFICE . "'";

        $db->exec("DELETE FROM AttachmentCoverage WHERE EmployeeID LIKE 'EMP-ZZATT-%'");
        $db->exec("DELETE FROM Attachments WHERE UploadedBy LIKE 'zzatt-%@digos.gov.ph'
                      OR AttachmentID = 'ATT-ZZDUP'");
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZATT-%'");
        $db->exec("DELETE FROM Employees WHERE EmployeeID LIKE 'EMP-ZZATT-%'");
        $db->exec("DELETE FROM Users WHERE Email LIKE 'zzatt-%@digos.gov.ph'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ($offices)");
    }
}
