<?php
/**
 * ============================================================================
 * DocumentModulesTest - Phase 3's exit gate.
 *
 * "Each document type stores and lists correctly under the correct scope."
 *
 * Two halves, and the second is the one worth having. Storing and listing is
 * boilerplate; what is not boilerplate is that these documents reach across
 * the boundary Phase 2 drew. A memorandum names employees, and naming somebody
 * in another office would make coverage a way to write to a scope you cannot
 * read. A bio exemption and a travel order are about a person and carry no
 * office code of their own, so their scope is inherited through a join, and a
 * join is easy to write without one.
 *
 * The versioned pair - work shifts and contracts - are tested for the property
 * they exist for: that an edit adds a row and the superseded one survives. A
 * module that quietly overwrote would pass every CRUD assertion.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\ContractRepo;
use Digos\Repo\ScopeGrantRepo;
use Digos\Repo\WorkShiftRepo;
use PHPUnit\Framework\TestCase;
use Throwable;

final class DocumentModulesTest extends TestCase
{
    private const MINE = 'ZZDOCA';
    private const THEIRS = 'ZZDOCB';
    private const MY_EMPLOYEE = 'EMP-ZZDOC-MINE';
    private const THEIR_EMPLOYEE = 'EMP-ZZDOC-THEIRS';
    private const THEIR_NAME = 'UNSEEABLE, Documented';

    private const HRMO = 'zzdoc-hrmo@digos.gov.ph';
    private const OTHER_HRMO = 'zzdoc-other@digos.gov.ph';

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

    /* ------------------------------------------------------------- memoranda */

    /** A memo issued by one office is invisible to a reader scoped elsewhere. */
    public function testAMemorandumIsListedOnlyWithinItsOwnOfficeScope(): void
    {
        \apiSaveMemorandum($this->memoPayload(), $this->mine());

        $mine = \apiListMemoranda([], $this->mine());
        $theirs = \apiListMemoranda([], $this->theirs());

        $this->assertSame(['ZZDOC-M-1'], array_column($mine, 'ControlNo'));
        $this->assertSame([], array_column($theirs, 'ControlNo'),
            "Another office's memorandum was listed.");
    }

    /** And fetching it directly reports the same thing as it not existing. */
    public function testFetchingAnOutOfScopeMemorandumReportsItAsNotFound(): void
    {
        $saved = \apiSaveMemorandum($this->memoPayload(), $this->mine());

        $this->expectExceptionMessage('Memorandum not found');
        \apiGetMemorandum(['MemoID' => $saved['MemoID']], $this->theirs());
    }

    /**
     * Coverage cannot reach across scope.
     *
     * Without this check a memo is a write path into another office: name their
     * employee on your own memorandum and you have created a record about
     * somebody you may not read.
     */
    public function testNamingAnOutOfScopeEmployeeOnAMemorandumIsRefused(): void
    {
        $message = $this->refusalFrom(fn() => \apiSaveMemorandum(
            $this->memoPayload(['EmployeeIDs' => [self::THEIR_EMPLOYEE]]), $this->mine()));

        $this->assertStringContainsString('not one you have access to', $message);
        $this->assertStringNotContainsString('UNSEEABLE', $message,
            'The refusal named the employee it was refusing to disclose.');
    }

    /** Coverage within scope stores and reads back. */
    public function testCoveredEmployeesAreStoredAndReturned(): void
    {
        $saved = \apiSaveMemorandum(
            $this->memoPayload(['EmployeeIDs' => [self::MY_EMPLOYEE]]), $this->mine());

        $memo = \apiGetMemorandum(['MemoID' => $saved['MemoID']], $this->mine());

        $this->assertSame(1, $saved['Covered']);
        $this->assertSame([self::MY_EMPLOYEE], array_column($memo['CoveredEmployees'], 'EmployeeID'));
    }

    /** A control number is the document's identity; two rows cannot share one. */
    public function testADuplicateControlNumberIsRefused(): void
    {
        \apiSaveMemorandum($this->memoPayload(), $this->mine());

        $message = $this->refusalFrom(fn() => \apiSaveMemorandum($this->memoPayload(), $this->mine()));
        $this->assertStringContainsString('already used', $message);
    }

    /** Effectivity is stored raw, but a backwards range is still nonsense. */
    public function testAnEffectivityEndingBeforeItStartsIsRefused(): void
    {
        $message = $this->refusalFrom(fn() => \apiSaveMemorandum($this->memoPayload([
            'EffectivityStart' => '2026-08-10',
            'EffectivityEnd' => '2026-08-01',
        ]), $this->mine()));

        $this->assertStringContainsString('ends before it starts', $message);
    }

    /**
     * Deleting a memo another one supersedes is refused.
     *
     * The foreign key is ON DELETE SET NULL, so the database would allow this
     * and silently forget what the survivor replaced.
     */
    public function testDeletingASupersededMemorandumIsRefusedWhileItIsReferenced(): void
    {
        $first = \apiSaveMemorandum($this->memoPayload(), $this->mine());
        \apiSaveMemorandum($this->memoPayload([
            'ControlNo' => 'ZZDOC-M-2',
            'SupersedesID' => $first['MemoID'],
        ]), $this->mine());

        $message = $this->refusalFrom(
            fn() => \apiDeleteMemorandum(['MemoID' => $first['MemoID']], $this->mine()));

        $this->assertStringContainsString('ZZDOC-M-2', $message);
    }

    /* ------------------------------------------- bio exemptions and travel */

    /** A document about a person inherits that person's scope, through a join. */
    public function testABioExemptionIsListedOnlyWithinTheEmployeesScope(): void
    {
        \apiSaveBioExemption([
            'EmployeeID' => self::MY_EMPLOYEE,
            'ReasonCode' => 'FIELDWORK',
            'ValidFrom' => '2026-07-01',
            'ValidTo' => '2026-07-31',
        ], $this->mine());

        $this->assertCount(1, \apiListBioExemptions([], $this->mine()));
        $this->assertCount(0, \apiListBioExemptions([], $this->theirs()),
            "Another office's bio exemption was listed.");
    }

    /** And one cannot be created for somebody outside it. */
    public function testABioExemptionForAnOutOfScopeEmployeeIsRefused(): void
    {
        $message = $this->refusalFrom(fn() => \apiSaveBioExemption(
            ['EmployeeID' => self::THEIR_EMPLOYEE, 'ReasonCode' => 'FIELDWORK'], $this->mine()));

        $this->assertStringContainsString('Employee not found', $message);
        $this->assertStringNotContainsString('UNSEEABLE', $message);
    }

    /** Travel orders behave the same way - same shape, same rule. */
    public function testATravelOrderIsListedOnlyWithinTheEmployeesScope(): void
    {
        \apiSaveTravelOrder([
            'TravelOrderNo' => 'ZZDOC-TO-1',
            'EmployeeID' => self::MY_EMPLOYEE,
            'Destination' => 'Davao City',
            'DepartDate' => '2026-07-06',
            'ReturnDate' => '2026-07-08',
            'PerDiem' => 1,
        ], $this->mine());

        $mine = \apiListTravelOrders([], $this->mine());

        $this->assertSame(['ZZDOC-TO-1'], array_column($mine, 'TravelOrderNo'));
        $this->assertSame(1, (int) $mine[0]['PerDiem']);
        $this->assertCount(0, \apiListTravelOrders([], $this->theirs()));
    }

    /** A return before departure is not a trip. */
    public function testATravelOrderReturningBeforeItDepartsIsRefused(): void
    {
        $message = $this->refusalFrom(fn() => \apiSaveTravelOrder([
            'TravelOrderNo' => 'ZZDOC-TO-2',
            'EmployeeID' => self::MY_EMPLOYEE,
            'DepartDate' => '2026-07-08',
            'ReturnDate' => '2026-07-06',
        ], $this->mine()));

        $this->assertStringContainsString('before the departure date', $message);
    }

    /* --------------------------------------------------- versioned: shifts */

    /**
     * Editing a shift adds a version. The old one survives with its own window.
     *
     * This is the property the table exists for. A module that updated the
     * times in place would satisfy every other assertion in this file.
     */
    public function testEditingAShiftCreatesAVersionAndKeepsTheOldOne(): void
    {
        \apiSaveWorkShift([
            'ShiftCode' => 'ZZDOCS', 'ShiftName' => 'Original',
            'TimeIn' => '08:00', 'TimeOut' => '17:00',
            'EffectiveFrom' => '2026-01-01',
        ], $this->mine());

        $second = \apiSaveWorkShift([
            'ShiftCode' => 'ZZDOCS', 'ShiftName' => 'Shifted later',
            'TimeIn' => '09:00', 'TimeOut' => '18:00',
            'EffectiveFrom' => '2026-07-01',
        ], $this->mine());

        $history = \apiGetWorkShiftHistory(['ShiftCode' => 'ZZDOCS'], $this->mine());

        $this->assertSame(2, $second['VersionNo']);
        $this->assertCount(2, $history, 'The first version was overwritten, not superseded.');
        $this->assertSame('08:00:00', $history[0]['TimeIn'],
            'The original start time did not survive the edit.');
        $this->assertSame('2026-06-30', $history[0]['EffectiveTo'],
            'The old version must end the day before the new one starts, so no date '
            . 'falls between them and none is covered by both.');
        $this->assertSame($history[0]['ShiftID'], $history[1]['SupersedesID']);
    }

    /** The current listing shows one row per code, not every version. */
    public function testTheShiftListShowsOnlyTheVersionInForce(): void
    {
        \apiSaveWorkShift(['ShiftCode' => 'ZZDOCS', 'EffectiveFrom' => '2026-01-01'], $this->mine());
        \apiSaveWorkShift(['ShiftCode' => 'ZZDOCS', 'EffectiveFrom' => '2026-07-01'], $this->mine());

        $current = array_values(array_filter(
            \apiListWorkShifts([], $this->mine()),
            fn(array $s) => $s['ShiftCode'] === 'ZZDOCS'));

        $this->assertCount(1, $current);
        $this->assertSame(2, (int) $current[0]['VersionNo']);
    }

    /** A version starting on or before the one it replaces is refused. */
    public function testAShiftVersionCannotStartBeforeTheOneItReplaces(): void
    {
        \apiSaveWorkShift(['ShiftCode' => 'ZZDOCS', 'EffectiveFrom' => '2026-07-01'], $this->mine());

        $message = $this->refusalFrom(fn() => \apiSaveWorkShift(
            ['ShiftCode' => 'ZZDOCS', 'EffectiveFrom' => '2026-01-01'], $this->mine()));

        $this->assertStringContainsString('must start after', $message);
    }

    /** Rest days are validated, because "no rest days" pays a plain Sunday. */
    public function testAnImpossibleRestDayIsRefused(): void
    {
        $message = $this->refusalFrom(fn() => \apiSaveWorkShift([
            'ShiftCode' => 'ZZDOCR', 'EffectiveFrom' => '2026-01-01', 'RestDays' => '8',
        ], $this->mine()));

        $this->assertStringContainsString('1 (Monday) to 7 (Sunday)', $message);
    }

    /* ------------------------------------------------ versioned: contracts */

    /**
     * A renewal supersedes. The rate in force last quarter is still answerable.
     *
     * This is what 0005 was created for and what nothing has ever exercised:
     * Phase 6's "daily rate != contract rate" rule compares a payroll line
     * against the rate that applied on the payroll's dates, and that question
     * has no answer once a renewal has overwritten it.
     */
    public function testRenewingAContractKeepsTheRateThatWasInForceBefore(): void
    {
        \apiSaveContract([
            'EmployeeID' => self::MY_EMPLOYEE, 'Rate' => 450.00, 'StartDate' => '2026-01-01',
        ], $this->mine());

        $renewal = \apiSaveContract([
            'EmployeeID' => self::MY_EMPLOYEE, 'Rate' => 520.00, 'StartDate' => '2026-07-01',
        ], $this->mine());

        $this->assertTrue($renewal['Renewed']);
        $this->assertSame('450.00', ContractRepo::rateInForceOn(self::MY_EMPLOYEE, '2026-03-15'),
            'The rate that applied in March was lost when the contract was renewed.');
        $this->assertSame('520.00', ContractRepo::rateInForceOn(self::MY_EMPLOYEE, '2026-07-15'));
    }

    /** The two windows meet without overlapping. */
    public function testTheSupersededContractEndsTheDayBeforeTheRenewalStarts(): void
    {
        \apiSaveContract([
            'EmployeeID' => self::MY_EMPLOYEE, 'Rate' => 450.00, 'StartDate' => '2026-01-01',
        ], $this->mine());
        \apiSaveContract([
            'EmployeeID' => self::MY_EMPLOYEE, 'Rate' => 520.00, 'StartDate' => '2026-07-01',
        ], $this->mine());

        $history = \apiGetContractHistory(['EmployeeID' => self::MY_EMPLOYEE], $this->mine());

        $this->assertCount(2, $history);
        $this->assertSame('2026-06-30', $history[0]['EndDate']);
        $this->assertSame('Superseded', $history[0]['Status']);
    }

    /**
     * A rate cannot be corrected in place.
     *
     * amend() is deliberately narrow. Allowing a rate edit here would be the
     * overwrite under another name, and it would be the easiest thing in the
     * world to add by accident.
     */
    public function testAContractRateCannotBeAmendedInPlace(): void
    {
        \apiSaveContract([
            'EmployeeID' => self::MY_EMPLOYEE, 'Rate' => 450.00, 'StartDate' => '2026-01-01',
        ], $this->mine());
        $contract = \apiGetContractHistory(['EmployeeID' => self::MY_EMPLOYEE], $this->mine())[0];

        \apiAmendContract(
            ['ContractID' => $contract['ContractID'], 'Rate' => 9999.00, 'Remarks' => 'typo'],
            $this->mine());

        $this->assertSame('450.00',
            ContractRepo::rateInForceOn(self::MY_EMPLOYEE, '2026-03-15'),
            'A rate was changed through the amendment path.');
    }

    /** Contracts are scoped through the employee, like the other documents. */
    public function testAContractIsListedOnlyWithinTheEmployeesScope(): void
    {
        \apiSaveContract([
            'EmployeeID' => self::MY_EMPLOYEE, 'Rate' => 450.00, 'StartDate' => '2026-01-01',
        ], $this->mine());

        $this->assertCount(1, \apiListContracts([], $this->mine()));
        $this->assertCount(0, \apiListContracts([], $this->theirs()));
    }

    /* -------------------------------------------------------------- fixture */

    private function memoPayload(array $overrides = []): array
    {
        return array_merge([
            'ControlNo' => 'ZZDOC-M-1',
            'Subject' => 'Overtime for the July flood clearing',
            'OfficeCode' => self::MINE,
            'AuthorityType' => 'Overtime',
            'EffectivityType' => 'Range',
            'EffectivityStart' => '2026-07-01',
            'EffectivityEnd' => '2026-07-15',
            'DateIssued' => '2026-06-28',
        ], $overrides);
    }

    /**
     * The message from a call that must be refused.
     *
     * Not a try/catch around an assertion: PHPUnit\Framework\Exception extends
     * RuntimeException, so catching RuntimeException around a failed assertion
     * swallows the failure and the test passes having proved nothing. That is
     * not hypothetical - it happened in Phase 2.
     */
    private function refusalFrom(callable $call): string
    {
        try {
            $call();
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        $this->fail('The call was expected to be refused and was not.');
    }

    private function mine(): array
    {
        return $this->user(self::HRMO);
    }

    private function theirs(): array
    {
        return $this->user(self::OTHER_HRMO);
    }

    private function user(string $email): array
    {
        return [
            'Email' => $email,
            'FullName' => 'Document fixture',
            'Role' => 'HRMO',
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS['HRMO'],
        ];
    }

    private function createFixture(): void
    {
        $db = TestDatabase::connect();

        foreach ([self::MINE, self::THEIRS] as $office) {
            $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
                ->execute([$office, "Document fixture $office", 'Active']);
        }

        foreach ([[self::MY_EMPLOYEE, self::MINE, 'MINE, Documented'],
                  [self::THEIR_EMPLOYEE, self::THEIRS, self::THEIR_NAME]] as $e) {
            [$id, $office, $name] = $e;
            [$last, $first] = explode(', ', $name);

            $db->prepare('INSERT INTO Employees (EmployeeID, LastName, FirstName, OfficeCode,
                                                 EmploymentType, EmploymentTypeCode, Position, Status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $last, $first, $office, 'Job Order', 'JO', 'Worker', 'Active']);
        }

        foreach ([[self::HRMO, self::MINE, 'SG-ZZDOC-1'],
                  [self::OTHER_HRMO, self::THEIRS, 'SG-ZZDOC-2']] as $u) {
            [$email, $office, $grantId] = $u;

            $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                          VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$email, 'Document fixture', 'HRMO', '', 'Active', 'x']);
            $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                          VALUES (?, ?, ?, 1, 1)')
                ->execute([$grantId, $email, $office]);
        }
    }

    /**
     * Cleaned by office rather than by id prefix.
     *
     * A failed assertion skips any inline cleanup, and an employee left behind
     * blocks the office delete and turns the NEXT test into an error whose
     * cause is nowhere near it. Deleting the dependents first, unconditionally,
     * is what stops one failure becoming several.
     */
    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $offices = "'" . self::MINE . "','" . self::THEIRS . "'";

        $db->exec("DELETE FROM MemorandumEmployees WHERE EmployeeID LIKE 'EMP-ZZDOC-%'");
        $db->exec("DELETE FROM Memorandum WHERE ControlNo LIKE 'ZZDOC-%'");
        $db->exec("DELETE FROM BioExemptions WHERE EmployeeID LIKE 'EMP-ZZDOC-%'");
        $db->exec("DELETE FROM TravelOrders WHERE EmployeeID LIKE 'EMP-ZZDOC-%'");
        $db->exec("DELETE FROM Contracts WHERE EmployeeID LIKE 'EMP-ZZDOC-%'");
        $db->exec("DELETE FROM WorkShifts WHERE ShiftCode LIKE 'ZZDOC%' ORDER BY VersionNo DESC");
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZDOC-%'");
        $db->exec("DELETE FROM Employees WHERE EmployeeID LIKE 'EMP-ZZDOC-%'");
        $db->exec("DELETE FROM Users WHERE Email LIKE 'zzdoc-%@digos.gov.ph'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ($offices)");
    }
}
