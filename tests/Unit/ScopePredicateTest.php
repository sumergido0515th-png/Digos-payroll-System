<?php
/**
 * ============================================================================
 * ScopePredicateTest - The access decision, tested against fixtures.
 *
 * ScopePredicate is pure, so every rule below is exercised with no database
 * and no clock. That is the point of keeping it pure: a wrong answer here does
 * not throw, it returns another office's payroll, and the cheapest place to
 * catch that is a test that runs in a millisecond.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Access\ScopePredicate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScopePredicateTest extends TestCase
{
    private const TODAY = '2026-07-29';

    /** A grant row with everything wildcard, overridden per test. */
    private function grant(array $overrides = []): array
    {
        return $overrides + [
            'GrantID' => 'SG-TEST',
            'UserEmail' => 'someone@digos.gov.ph',
            'RoleCode' => null,
            'OfficeCode' => null,
            'FunctionCode' => null,
            'EmploymentTypeCode' => null,
            'FiscalYear' => null,
            'CanRead' => '1',
            'CanWrite' => '0',
            'ValidFrom' => null,
            'ValidTo' => null,
        ];
    }

    /* ---------------------------------------------------------------- build */

    /**
     * The rule the whole design rests on. An empty grant list is the state of
     * every user before anyone grants them anything, and if it widened to
     * "everything" the control would be absent exactly when it looks present.
     */
    public function testNoGrantsDeniesEverything(): void
    {
        $predicate = ScopePredicate::build([], 'Payroll');

        $this->assertSame('0 = 1', $predicate['sql']);
        $this->assertSame([], $predicate['params']);
    }

    public function testAGrantNarrowingNothingAllowsEverything(): void
    {
        $predicate = ScopePredicate::build([$this->grant()], 'Payroll');

        $this->assertSame('1 = 1', $predicate['sql']);
        $this->assertSame([], $predicate['params']);
    }

    public function testASingleOfficeGrantBindsThatOffice(): void
    {
        $predicate = ScopePredicate::build([$this->grant(['OfficeCode' => 'CMO'])], 'Payroll');

        $this->assertSame('((`OfficeCode` = ?))', $predicate['sql']);
        $this->assertSame(['CMO'], $predicate['params']);
    }

    public function testTwoGrantsAreOred(): void
    {
        $predicate = ScopePredicate::build([
            $this->grant(['OfficeCode' => 'CMO']),
            $this->grant(['OfficeCode' => 'OCEEM']),
        ], 'Payroll');

        $this->assertSame('((`OfficeCode` = ?) OR (`OfficeCode` = ?))', $predicate['sql']);
        $this->assertSame(['CMO', 'OCEEM'], $predicate['params']);
    }

    public function testDimensionsWithinOneGrantAreAnded(): void
    {
        $predicate = ScopePredicate::build(
            [$this->grant(['OfficeCode' => 'CMO', 'FunctionCode' => 'GEN'])], 'Payroll');

        $this->assertSame('((`OfficeCode` = ? AND `FunctionCode` = ?))', $predicate['sql']);
        $this->assertSame(['CMO', 'GEN'], $predicate['params']);
    }

    /** One unrestricted grant makes the rest redundant, and drops their params. */
    public function testAWildcardGrantAlongsideANarrowOneAllowsEverything(): void
    {
        $predicate = ScopePredicate::build([
            $this->grant(['OfficeCode' => 'CMO']),
            $this->grant(),
        ], 'Payroll');

        $this->assertSame('1 = 1', $predicate['sql']);
        $this->assertSame([], $predicate['params']);
    }

    /**
     * PayrollDetails scopes on the charged office, not the employee's home
     * office. Migration 0006 created that column so this question could be
     * asked; scoping on the home office answers a different one.
     */
    public function testPayrollDetailsScopesOnTheChargedOffice(): void
    {
        $predicate = ScopePredicate::build(
            [$this->grant(['OfficeCode' => 'CMO'])], 'PayrollDetails');

        $this->assertSame('((`ChargedOfficeCode` = ?))', $predicate['sql']);
    }

    /**
     * The direction an unverifiable dimension has to fail in. Payroll carries
     * no employment-type column, so a JO-only grant cannot be checked against
     * it - and ignoring the dimension would turn "JO only" into every payroll
     * in the city.
     */
    public function testAGrantNarrowingADimensionTheEntityLacksDeniesRatherThanWidens(): void
    {
        $predicate = ScopePredicate::build(
            [$this->grant(['EmploymentTypeCode' => 'JO'])], 'Payroll');

        $this->assertSame('0 = 1', $predicate['sql']);
    }

    /** Same rule, and the live case: no entity carries FiscalYear yet. */
    public function testAFiscalYearGrantDeniesUntilAnEntityCarriesTheYear(): void
    {
        $predicate = ScopePredicate::build(
            [$this->grant(['FiscalYear' => 2026])], 'Payroll');

        $this->assertSame('0 = 1', $predicate['sql']);
    }

    /** Employees does carry employment type, so there the same grant applies. */
    public function testEmployeesCanBeScopedByEmploymentType(): void
    {
        $predicate = ScopePredicate::build(
            [$this->grant(['EmploymentTypeCode' => 'JO'])], 'Employees');

        $this->assertSame('((`EmploymentTypeCode` = ?))', $predicate['sql']);
        $this->assertSame(['JO'], $predicate['params']);
    }

    public function testAnAliasPrefixesEveryColumn(): void
    {
        $predicate = ScopePredicate::build(
            [$this->grant(['OfficeCode' => 'CMO'])], 'Payroll', 'p.');

        $this->assertSame('((p.`OfficeCode` = ?))', $predicate['sql']);
    }

    /**
     * An unregistered entity is an unscoped query wearing a scoped query's
     * clothes, so it must throw rather than return something usable.
     */
    public function testAnUnregisteredEntityThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScopePredicate::build([$this->grant()], 'Logs');
    }

    /** An empty office code is not a scope of "", it is no scope at all. */
    public function testAnEmptyStringDimensionIsTreatedAsWildcard(): void
    {
        $predicate = ScopePredicate::build([$this->grant(['OfficeCode' => ''])], 'Payroll');

        $this->assertSame('1 = 1', $predicate['sql']);
    }

    /* ----------------------------------------------------------- applicable */

    public function testAGrantWithoutTheReadRightIsNotApplicableToAread(): void
    {
        $grants = [$this->grant(['CanRead' => '0', 'CanWrite' => '1'])];

        $this->assertSame([], ScopePredicate::applicable($grants, 'Encoder', self::TODAY));
        $this->assertCount(1,
            ScopePredicate::applicable($grants, 'Encoder', self::TODAY, 'write'));
    }

    public function testAGrantNamingAnotherRoleIsNotApplicable(): void
    {
        $grants = [$this->grant(['RoleCode' => 'Pre-Auditor'])];

        $this->assertSame([], ScopePredicate::applicable($grants, 'Encoder', self::TODAY));
        $this->assertCount(1,
            ScopePredicate::applicable($grants, 'Pre-Auditor', self::TODAY));
    }

    public function testAGrantWithoutARoleAppliesToWhicheverRoleIsHeld(): void
    {
        $this->assertCount(1,
            ScopePredicate::applicable([$this->grant()], 'Office Head', self::TODAY));
    }

    /** Expiry is the point of the validity window: a detail must end. */
    public function testAnExpiredGrantIsNotApplicable(): void
    {
        $grants = [$this->grant(['ValidTo' => '2026-07-28'])];

        $this->assertSame([], ScopePredicate::applicable($grants, 'Encoder', self::TODAY));
    }

    public function testAGrantThatHasNotStartedIsNotApplicable(): void
    {
        $grants = [$this->grant(['ValidFrom' => '2026-07-30'])];

        $this->assertSame([], ScopePredicate::applicable($grants, 'Encoder', self::TODAY));
    }

    public function testAGrantIsApplicableOnItsFirstAndLastDay(): void
    {
        $grants = [$this->grant(['ValidFrom' => self::TODAY, 'ValidTo' => self::TODAY])];

        $this->assertCount(1, ScopePredicate::applicable($grants, 'Encoder', self::TODAY));
    }

    /**
     * The end-to-end shape the gateway relies on: filtering first, building
     * second, and an expired grant leaving nothing behind to widen the query.
     */
    public function testAnExpiredGrantLeavesTheUserWithNoAccessAtAll(): void
    {
        $grants = [$this->grant(['OfficeCode' => 'CMO', 'ValidTo' => '2026-06-30'])];

        $live = ScopePredicate::applicable($grants, 'Encoder', self::TODAY);

        $this->assertSame('0 = 1', ScopePredicate::build($live, 'Payroll')['sql']);
    }
}
