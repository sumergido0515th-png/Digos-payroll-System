<?php
/**
 * ============================================================================
 * PrintScopeTest - The print path is not a way around the scope layer.
 *
 * This file exists because it was. Reproduced live against the working
 * database, as a user scoped to one office:
 *
 *   via apiGetPayroll   (scoped)   -> refused: Payroll not found: PR-2026-000003
 *   via apiGetPrintHtml (same no)  -> RENDERED 9,934 bytes, employee names visible
 *
 * printBundle() took no user and queried Payroll directly. `print.run` is held
 * by five of the seven roles, so guessing a payroll number was the whole
 * attack, and the output was a fully rendered payroll form for another office.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\ScopeGrantRepo;
use PHPUnit\Framework\TestCase;

final class PrintScopeTest extends TestCase
{
    private const MINE = 'ZZPMINE';
    private const THEIRS = 'ZZPTHEIRS';
    private const PERIOD = 'PRD-ZZPRINT';
    private const MY_PAYROLL = 'ZZP-MINE-1';
    private const THEIR_PAYROLL = 'ZZP-THEIRS-1';
    private const SCOPED = 'zzp-scoped@digos.gov.ph';
    private const WIDE = 'zzp-wide@digos.gov.ph';
    private const ENCODER = 'zzp-encoder@digos.gov.ph';

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
        if (defined('DB_NAME')) $this->removeFixture();
        ScopeGrantRepo::forget();
    }

    private function createFixture(): void
    {
        $db = TestDatabase::connect();

        foreach ([self::MINE, self::THEIRS] as $office) {
            $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
                ->execute([$office, "Print fixture $office", 'Active']);
        }
        $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear, StartDate, EndDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::PERIOD, 'July', 2026, '2026-07-01', '2026-07-15', 'Open']);

        // One payroll per office, each with a real line so the forms render.
        foreach ([[self::MY_PAYROLL, self::MINE], [self::THEIR_PAYROLL, self::THEIRS]] as $i => $pair) {
            [$no, $office] = $pair;
            $employeeId = 'EMP-ZZPRINT-' . $i;

            $db->prepare('INSERT INTO Employees (EmployeeID, LastName, FirstName, OfficeCode,
                                                 EmploymentType, EmploymentTypeCode, Position, Status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$employeeId, 'Confidential' . $i, 'Name', $office,
                    'Job Order', 'JO', 'Utility Worker', 'Active']);
            $db->prepare('INSERT INTO EmployeeSensitive (EmployeeID, PagIBIG, CashCard, MonthlyRate, DailyRate)
                          VALUES (?, ?, ?, ?, ?)')
                ->execute([$employeeId, 'PGB-' . $i, 'CASHCARD-' . $i, 11440.00, 520.00]);

            $db->prepare('INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Status, TotalNet)
                          VALUES (?, ?, ?, ?, ?)')
                ->execute([$no, self::PERIOD, $office, 'FOR_PRE_AUDIT', 5200.00]);
            $db->prepare('INSERT INTO PayrollDetails (DetailID, PayrollNo, LineNo, EmployeeID,
                                                      ChargedOfficeCode, EmployeeName, SalaryRate,
                                                      DaysWorked, GrossPay, NetPay)
                          VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(['PD-ZZPRINT-' . $i, $no, $employeeId, $office,
                    'CONFIDENTIAL' . $i . ', Name', 520.00, 10, 5200.00, 5200.00]);
        }

        $users = [
            [self::SCOPED, 'Payroll In-Charge', self::MINE],
            [self::WIDE, 'Payroll In-Charge', null],
            [self::ENCODER, 'Encoder', self::MINE],
        ];
        foreach ($users as $i => [$email, $role, $office]) {
            $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                          VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$email, 'Print fixture', $role, '', 'Active', 'x']);
            $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                          VALUES (?, ?, ?, 1, 1)')
                ->execute(['SG-ZZPRINT-' . $i, $email, $office]);
        }
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZPRINT-%'");
        $db->exec("DELETE FROM PayrollDetails WHERE PayrollNo LIKE 'ZZP-%'");
        $db->exec("DELETE FROM Payroll WHERE PayrollNo LIKE 'ZZP-%'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID = '" . self::PERIOD . "'");
        $db->exec("DELETE FROM EmployeeSensitive WHERE EmployeeID LIKE 'EMP-ZZPRINT-%'");
        $db->exec("DELETE FROM Employees WHERE EmployeeID LIKE 'EMP-ZZPRINT-%'");
        $db->exec("DELETE FROM Users WHERE Email LIKE 'zzp-%@digos.gov.ph'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ('" . self::MINE . "','" . self::THEIRS . "')");
    }

    private function user(string $email, string $role = 'Payroll In-Charge'): array
    {
        return [
            'Email' => $email,
            'FullName' => 'Print fixture',
            'Role' => $role,
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS[$role],
        ];
    }

    /** The rendered form, or the refusal message. */
    private function render(string $payrollNo, array $user, string $form = 'payroll'): array
    {
        try {
            return ['html' => \apiGetPrintHtml(['PayrollNo' => $payrollNo, 'form' => $form], $user)['html']];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /* ----------------------------------------------------------- the leak */

    public function testPrintingAnotherOfficesPayrollIsRefused(): void
    {
        $result = $this->render(self::THEIR_PAYROLL, $this->user(self::SCOPED));

        $this->assertArrayNotHasKey('html', $result,
            'The print path rendered another office\'s payroll. This is the leak.');
        $this->assertStringContainsString('not found', strtolower($result['error']));
    }

    /** Names are the disclosure that mattered, so assert on them directly. */
    public function testTheRefusalLeaksNoEmployeeNames(): void
    {
        $result = $this->render(self::THEIR_PAYROLL, $this->user(self::SCOPED));

        $this->assertStringNotContainsStringIgnoringCase(
            'CONFIDENTIAL1', $result['error'] ?? ($result['html'] ?? ''));
    }

    public function testTheirOwnOfficesPayrollStillPrints(): void
    {
        $result = $this->render(self::MY_PAYROLL, $this->user(self::SCOPED));

        $this->assertArrayHasKey('html', $result, $result['error'] ?? '');
        $this->assertStringContainsStringIgnoringCase('CONFIDENTIAL0', $result['html']);
    }

    /** Control: the same two payrolls render for a wildcard user. */
    public function testAWildcardUserPrintsBoth(): void
    {
        foreach ([self::MY_PAYROLL, self::THEIR_PAYROLL] as $no) {
            $result = $this->render($no, $this->user(self::WIDE));
            $this->assertArrayHasKey('html', $result,
                "The fixture payroll $no does not render even unscoped: " . ($result['error'] ?? ''));
        }
    }

    /** The other three forms go through the same bundle, so all four are covered. */
    public function testEveryFormIsScopedNotJustThePayrollForm(): void
    {
        foreach (['payroll', 'summary', 'cafoa'] as $form) {
            $result = $this->render(self::THEIR_PAYROLL, $this->user(self::SCOPED), $form);
            $this->assertArrayNotHasKey('html', $result,
                "The '$form' form rendered another office's payroll.");
        }
    }

    /* ------------------------------------------------- restricted tier */

    public function testThePagibigListNeedsTheSensitivePermission(): void
    {
        $result = $this->render(self::MY_PAYROLL, $this->user(self::ENCODER, 'Encoder'), 'pagibig');

        $this->assertArrayNotHasKey('html', $result,
            'An Encoder printed the Pag-IBIG list, which shows Pag-IBIG numbers and monthly rates.');
        $this->assertStringContainsString('not allowed to see', $result['error']);
    }

    public function testThePagibigListPrintsForARoleThatMayReadTheTier(): void
    {
        $result = $this->render(self::MY_PAYROLL, $this->user(self::SCOPED), 'pagibig');

        $this->assertArrayHasKey('html', $result, $result['error'] ?? '');
        $this->assertStringContainsString('PGB-0', $result['html'],
            'The Pag-IBIG number is missing - the restricted tier was not joined.');
    }

    /**
     * The ordinary payroll form must NOT require the restricted tier. It reads
     * SalaryRate from PayrollDetails and employee names, both of which an
     * Encoder may already see - refusing it would break the main print path for
     * the role that most often uses it.
     */
    public function testTheMainFormsPrintWithoutTheSensitivePermission(): void
    {
        foreach (['payroll', 'summary', 'cafoa'] as $form) {
            $result = $this->render(self::MY_PAYROLL, $this->user(self::ENCODER, 'Encoder'), $form);
            $this->assertArrayHasKey('html', $result,
                "An Encoder cannot print the '$form' form: " . ($result['error'] ?? ''));
        }
    }

    /**
     * The main form's "Signature or Thumbmark/Cash Card Number" column prints
     * the employee's cash card number when one is on file, for a role entitled
     * to the restricted tier - otherwise the cert on this form ("paid in
     * cash... established his identity...") is signed off against a blank cell
     * for someone who is not paid in cash at all.
     */
    public function testTheMainFormPrintsTheCashCardNumberWhenOnFile(): void
    {
        $result = $this->render(self::MY_PAYROLL, $this->user(self::SCOPED));

        $this->assertArrayHasKey('html', $result, $result['error'] ?? '');
        $this->assertStringContainsString('CASHCARD-0', $result['html'],
            'The cash card number is missing from the main payroll form.');
    }

    /**
     * An Encoder may print the main form (previous test) but does not hold
     * `employee.sensitive`, so the cash card number - Tier 2 data - must not
     * leak into that column. The cell stays blank, as it always has, for a
     * physical signature instead.
     */
    public function testTheMainFormHidesTheCashCardNumberWithoutTheSensitivePermission(): void
    {
        $result = $this->render(self::MY_PAYROLL, $this->user(self::ENCODER, 'Encoder'));

        $this->assertArrayHasKey('html', $result, $result['error'] ?? '');
        $this->assertStringNotContainsString('CASHCARD-0', $result['html'],
            'An Encoder without employee.sensitive saw the cash card number.');
    }

    /* --------------------------------------------------- partial scope */

    /* ------------------------------------------------ review marking */

    /** Sets the fixture payroll's status without going through the workflow. */
    private function setStatus(string $status): void
    {
        TestDatabase::connect()
            ->prepare('UPDATE Payroll SET Status = ? WHERE PayrollNo = ?')
            ->execute([$status, self::MY_PAYROLL]);
    }

    /**
     * The whole reason the marking exists: a Draft previewed from the workflow
     * must not produce a printout that could be mistaken for an approved one.
     * Asserted on every form, because a reviewer prints whichever one they need.
     */
    public function testAnUnapprovedPayrollIsMarkedOnEveryForm(): void
    {
        $this->setStatus('DRAFT');

        foreach (['payroll', 'pagibig', 'summary', 'cafoa'] as $form) {
            $result = $this->render(self::MY_PAYROLL, $this->user(self::SCOPED), $form);

            $this->assertArrayHasKey('html', $result, $result['error'] ?? '');
            $this->assertStringContainsString('NOT OFFICIAL', $result['html'],
                "The '$form' form of a Draft payroll printed with no marking.");
            $this->assertStringContainsString('DRAFT', $result['html'],
                "The '$form' form does not say which status it is in.");

            // Counted, not merely present. A doubled injection still "contains
            // NOT OFFICIAL" and sails through a contains-assertion - which is
            // exactly how the marking came to be emitted twice on every form
            // before this line existed.
            $this->assertSame(1, substr_count($result['html'], 'review-strip">'),
                "The '$form' form emitted the review strip more than once.");
            $this->assertSame(1, substr_count($result['html'], 'review-wash">'),
                "The '$form' form emitted the review wash more than once.");
        }
    }

    public function testAnApprovedPayrollIsNotMarkedOnAnyForm(): void
    {
        $this->setStatus('PRE_AUDIT_APPROVED');

        foreach (['payroll', 'pagibig', 'summary', 'cafoa'] as $form) {
            $result = $this->render(self::MY_PAYROLL, $this->user(self::SCOPED), $form);

            $this->assertArrayHasKey('html', $result, $result['error'] ?? '');
            $this->assertStringNotContainsString('NOT OFFICIAL', $result['html'],
                "The '$form' form of an APPROVED payroll was marked not official.");
        }
    }

    /**
     * FOR_PRE_AUDIT is submitted for review but not yet approved, so it is
     * still marked. Named for the state rather than "Submitted" - that word
     * now names its own later, official status, and reusing it here would
     * read as testing the wrong one.
     */
    public function testAPayrollAwaitingPreAuditIsStillMarked(): void
    {
        $this->setStatus('FOR_PRE_AUDIT');
        $result = $this->render(self::MY_PAYROLL, $this->user(self::SCOPED));

        $this->assertStringContainsString('NOT OFFICIAL', $result['html'] ?? '');
        $this->assertStringContainsString('FOR_PRE_AUDIT', $result['html'] ?? '');
    }

    public function testACancelledPayrollSaysNotForPayment(): void
    {
        $this->setStatus('CANCELLED');
        $result = $this->render(self::MY_PAYROLL, $this->user(self::SCOPED));

        $this->assertStringContainsString('NOT FOR PAYMENT', $result['html'] ?? '');
    }

    /**
     * The marking must survive printing. `.noprint` is the class these forms
     * already use to drop screen-only furniture, so the marking landing inside
     * it would leave the screen honest and the paper copy not - the wrong way
     * round, since the paper is what leaves the building.
     */
    public function testTheMarkingIsNotInsideANoprintRule(): void
    {
        $this->setStatus('DRAFT');
        $html = $this->render(self::MY_PAYROLL, $this->user(self::SCOPED))['html'] ?? '';

        $this->assertStringNotContainsString('noprint', $this->markingMarkup($html),
            'The review marking is inside a screen-only element.');
        $this->assertMatchesRegularExpression('/@media\s+print\{[^}]*review-/', $html,
            'No print rule keeps the marking visible on paper.');
    }

    /** The marking elements, without the rest of the document. */
    private function markingMarkup(string $html): string
    {
        preg_match_all('/<div class="review-[^"]*".*?<\/div>/s', $html, $m);
        return implode('', $m[0]);
    }

    /**
     * Header in scope, every line charged elsewhere. Refuse rather than render
     * an empty form: a payroll with no rows does not look broken, it looks like
     * a payroll with nobody on it, and it would be printed and filed.
     */
    public function testAPayrollWhoseLinesAreAllOutOfScopeIsRefusedNotPrintedEmpty(): void
    {
        TestDatabase::connect()
            ->prepare('UPDATE PayrollDetails SET ChargedOfficeCode = ? WHERE PayrollNo = ?')
            ->execute([self::THEIRS, self::MY_PAYROLL]);

        $result = $this->render(self::MY_PAYROLL, $this->user(self::SCOPED));

        $this->assertArrayNotHasKey('html', $result,
            'An empty payroll form was rendered for a payroll whose lines are all out of scope.');
        $this->assertStringContainsString('nothing you can print', $result['error']);
    }
}
