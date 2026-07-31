<?php
/**
 * ============================================================================
 * HolidayResolverTest - Phase 4's exit gate, holiday half.
 *
 * The gate names three of these explicitly: holiday scope precedence, the
 * JO/COS vs plantilla divergence, and partial-day work suspension.
 *
 * Fixtures rather than the seeded table, so each test states the rule it is
 * about. A test reading the migration's seed would pass or fail for reasons
 * that are not written down in it, and the seed is explicitly marked as
 * needing confirmation against the city's own issuances.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Resolver\HolidayResolver;
use PHPUnit\Framework\TestCase;

final class HolidayResolverTest extends TestCase
{
    private const SCOPE = ['Region' => 'XI', 'Province' => 'Davao del Sur', 'City' => 'Digos'];

    /* ------------------------------------------------- scope precedence */

    /** A national holiday applies where nothing more specific was declared. */
    public function testANationalHolidayAppliesWithNoLocalDeclaration(): void
    {
        $r = $this->resolve([$this->national()], '2026-08-25', 'JO', true);

        $this->assertSame('RegularHoliday', $r['day_type']);
        $this->assertSame('National', $r['scope_level']);
    }

    /** A city declaration outranks the national one for that city. */
    public function testACityDeclarationOverridesTheNationalOne(): void
    {
        $r = $this->resolve([$this->national(), $this->city()], '2026-08-25', 'JO', true);

        $this->assertSame('LocalHoliday', $r['day_type']);
        $this->assertSame('City', $r['scope_level']);
        $this->assertSame('Digos', $r['scope_code']);
    }

    /** The full ladder: the most specific of four declarations wins. */
    public function testTheMostSpecificOfEveryLevelWins(): void
    {
        $declarations = [
            $this->national(),
            $this->holiday(['HolidayID' => 'H-REG', 'DayType' => 'SpecialNonWorking',
                'ScopeLevel' => 'Region', 'ScopeCode' => 'XI']),
            $this->holiday(['HolidayID' => 'H-PROV', 'DayType' => 'SpecialWorking',
                'ScopeLevel' => 'Province', 'ScopeCode' => 'Davao del Sur']),
            $this->city(),
            $this->holiday(['HolidayID' => 'H-BRGY', 'DayType' => 'WorkSuspension',
                'ScopeLevel' => 'Barangay', 'ScopeCode' => 'Aplaya']),
        ];

        $withoutBarangay = $this->resolve($declarations, '2026-08-25', 'JO', true);
        $this->assertSame('City', $withoutBarangay['scope_level'],
            'The barangay declaration is not this office\'s and must not apply.');

        $withBarangay = $this->resolve($declarations, '2026-08-25', 'JO', true,
            self::SCOPE + ['Barangay' => 'Aplaya']);
        $this->assertSame('Barangay', $withBarangay['scope_level']);
    }

    /** Another city's holiday is not this city's. */
    public function testAnotherCitysHolidayDoesNotApply(): void
    {
        $elsewhere = $this->holiday(['HolidayID' => 'H-ELSE', 'DayType' => 'LocalHoliday',
            'ScopeLevel' => 'City', 'ScopeCode' => 'Davao City']);

        $r = $this->resolve([$elsewhere], '2026-08-25', 'JO', true);

        $this->assertSame('RegularDay', $r['day_type']);
        $this->assertSame('', $r['scope_level']);
    }

    /** A date with no declaration at all is an ordinary working day. */
    public function testADateWithNoDeclarationIsAnOrdinaryDay(): void
    {
        $r = $this->resolve([$this->national()], '2026-08-26', 'JO', true);

        $this->assertSame('RegularDay', $r['day_type']);
        $this->assertTrue($r['paid']);
        $this->assertSame(1.0, $r['multiplier']);
    }

    /* ----------------------------------------- THE JO/COS divergence */

    /**
     * The finding this system exists to prevent.
     *
     * A plantilla employee is paid for a regular holiday they did not work. A
     * Job Order worker is not - they are engaged for services actually
     * rendered. Paying it is a disallowance.
     */
    public function testAJobOrderWorkerIsNotPaidForAnUnworkedRegularHoliday(): void
    {
        $jo = $this->resolve([$this->national()], '2026-08-25', 'JO', false);
        $plantilla = $this->resolve([$this->national()], '2026-08-25', 'PLANTILLA', false);

        $this->assertFalse($jo['paid'], 'A JO was paid for a regular holiday they did not work.');
        $this->assertSame(0.0, $jo['multiplier']);
        $this->assertStringContainsString('Job Order', $jo['legal_basis'],
            'The refusal must cite the basis - an unexplained zero is not auditable.');

        $this->assertTrue($plantilla['paid'], 'A plantilla employee lost their holiday pay.');
        $this->assertSame(1.0, $plantilla['multiplier']);
    }

    /** Contract of Service diverges the same way, by its own rule. */
    public function testContractOfServiceDivergesTheSameWay(): void
    {
        $cos = $this->resolve([$this->national()], '2026-08-25', 'COS', false);

        $this->assertFalse($cos['paid']);
        $this->assertStringContainsString('Contract of Service', $cos['legal_basis']);
    }

    /** Worked, they converge again: everybody gets 200%. */
    public function testWorkingTheHolidayPaysTheSameForEveryone(): void
    {
        foreach (['JO', 'COS', 'PLANTILLA'] as $type) {
            $r = $this->resolve([$this->national()], '2026-08-25', $type, true);

            $this->assertTrue($r['paid'], "$type was not paid for a holiday they worked.");
            $this->assertSame(2.0, $r['multiplier']);
        }
    }

    /** A rule naming the type beats the fallback however recent the fallback. */
    public function testASpecificRuleBeatsAMoreRecentFallback(): void
    {
        $rules = array_merge($this->rules(), [[
            'RuleID' => 'HPR-RH-NW-NEW', 'DayType' => 'RegularHoliday',
            'EmploymentTypeCode' => null, 'Worked' => 0, 'Paid' => 1, 'Multiplier' => 1.0,
            'LegalBasis' => 'A later general rule', 'EffectiveFrom' => '2026-01-01',
            'EffectiveTo' => null,
        ]]);

        $r = HolidayResolver::resolve($rules === [] ? [] : [$this->national()], $rules,
            '2026-08-25', 'JO', false, self::SCOPE);

        $this->assertFalse($r['paid'],
            'A newer general rule silently switched a JO back onto plantilla terms.');
    }

    /* ------------------------------------------------ rule versioning */

    /** The rule in force on the DATE governs, not today's. */
    public function testTheRuleVersionInForceOnTheDateIsTheOneApplied(): void
    {
        $rules = array_merge($this->rules(), [[
            'RuleID' => 'HPR-SNW-W-2027', 'DayType' => 'SpecialNonWorking',
            'EmploymentTypeCode' => null, 'Worked' => 1, 'Paid' => 1, 'Multiplier' => 1.5,
            'LegalBasis' => 'A more generous ordinance from 2027',
            'EffectiveFrom' => '2027-01-01', 'EffectiveTo' => null,
        ]]);

        $snw = $this->holiday(['HolidayID' => 'H-SNW', 'DayType' => 'SpecialNonWorking']);

        $before = HolidayResolver::resolve([$snw], $rules, '2026-08-25', 'JO', true, self::SCOPE);
        $after = HolidayResolver::resolve(
            [$this->holiday(['HolidayID' => 'H-SNW2', 'HolidayDate' => '2027-08-25',
                'DayType' => 'SpecialNonWorking'])],
            $rules, '2027-08-25', 'JO', true, self::SCOPE);

        $this->assertSame(1.3, $before['multiplier'],
            'A 2027 policy was applied to a 2026 payroll.');
        $this->assertSame(1.5, $after['multiplier']);
    }

    /** An expired rule stops applying. */
    public function testAnExpiredRuleNoLongerApplies(): void
    {
        $rules = [[
            'RuleID' => 'HPR-OLD', 'DayType' => 'RegularHoliday', 'EmploymentTypeCode' => null,
            'Worked' => 1, 'Paid' => 1, 'Multiplier' => 2.0, 'LegalBasis' => 'Expired',
            'EffectiveFrom' => '2016-01-01', 'EffectiveTo' => '2020-12-31',
        ]];

        $r = HolidayResolver::resolve([$this->national()], $rules, '2026-08-25', 'JO', true, self::SCOPE);

        $this->assertTrue($r['unresolved']);
    }

    /**
     * A gap in the rule table is reported, never guessed at.
     *
     * A resolver that invented "unpaid, 0x" would produce a wrong payroll that
     * looked exactly like a correct one, and Phase 6 would have nothing to
     * flag.
     */
    public function testAMissingRuleIsReportedRatherThanAssumedUnpaid(): void
    {
        $r = HolidayResolver::resolve([$this->national()], [], '2026-08-25', 'JO', true, self::SCOPE);

        $this->assertTrue($r['unresolved']);
        $this->assertStringContainsString('No pay rule covers', $r['unresolved_reason']);
        $this->assertStringContainsString('RegularHoliday', $r['unresolved_reason']);
    }

    /* --------------------------------- partial-day work suspension */

    /** A suspension from midday covers half an eight-hour day, not all of it. */
    public function testAMiddaySuspensionCoversHalfTheDay(): void
    {
        $suspension = $this->holiday([
            'HolidayID' => 'H-SUSP', 'DayType' => 'WorkSuspension',
            'ScopeLevel' => 'City', 'ScopeCode' => 'Digos',
            'StartTime' => '13:00', 'EndTime' => '17:00',
        ]);

        $r = $this->resolve([$suspension], '2026-08-25', 'PLANTILLA', false);

        $this->assertSame('WorkSuspension', $r['day_type']);
        $this->assertTrue($r['partial']);
        $this->assertSame(0.5, $r['coverage_fraction']);
        $this->assertSame(4.0, $r['hours_covered']);
    }

    /** A whole-day suspension is a whole day. */
    public function testAWholeDaySuspensionIsNotPartial(): void
    {
        $suspension = $this->holiday([
            'HolidayID' => 'H-SUSP2', 'DayType' => 'WorkSuspension',
            'ScopeLevel' => 'City', 'ScopeCode' => 'Digos',
        ]);

        $r = $this->resolve([$suspension], '2026-08-25', 'PLANTILLA', false);

        $this->assertFalse($r['partial']);
        $this->assertSame(1.0, $r['coverage_fraction']);
    }

    /** And a JO is not paid for the suspended hours they did not work. */
    public function testAJobOrderWorkerIsNotPaidForASuspensionTheyDidNotWork(): void
    {
        $suspension = $this->holiday([
            'HolidayID' => 'H-SUSP3', 'DayType' => 'WorkSuspension',
            'ScopeLevel' => 'City', 'ScopeCode' => 'Digos',
            'StartTime' => '13:00', 'EndTime' => '17:00',
        ]);

        $jo = $this->resolve([$suspension], '2026-08-25', 'JO', false);
        $plantilla = $this->resolve([$suspension], '2026-08-25', 'PLANTILLA', false);

        $this->assertFalse($jo['paid']);
        $this->assertTrue($plantilla['paid'],
            'A suspension is the government sending people home, not an absence.');
    }

    /** A window longer than a standard day is still one day. */
    public function testASuspensionLongerThanTheWorkingDayIsStillOneDay(): void
    {
        $suspension = $this->holiday([
            'HolidayID' => 'H-SUSP4', 'DayType' => 'WorkSuspension',
            'ScopeLevel' => 'City', 'ScopeCode' => 'Digos',
            'StartTime' => '06:00', 'EndTime' => '22:00',
        ]);

        $r = $this->resolve([$suspension], '2026-08-25', 'PLANTILLA', false);

        $this->assertSame(1.0, $r['coverage_fraction']);
        $this->assertSame(8.0, $r['hours_covered']);
    }

    /* -------------------------------------------------------- fixture */

    private function resolve(
        array $holidays,
        string $date,
        string $type,
        bool $worked,
        ?array $scope = null
    ): array {
        return HolidayResolver::resolve(
            $holidays, $this->rules(), $date, $type, $worked, $scope ?? self::SCOPE);
    }

    private function national(): array
    {
        return $this->holiday(['HolidayID' => 'H-NAT', 'DayType' => 'RegularHoliday',
            'ScopeLevel' => 'National', 'ScopeCode' => null,
            'HolidayName' => 'National Heroes Day']);
    }

    private function city(): array
    {
        return $this->holiday(['HolidayID' => 'H-CITY', 'DayType' => 'LocalHoliday',
            'ScopeLevel' => 'City', 'ScopeCode' => 'Digos',
            'HolidayName' => 'City charter day']);
    }

    /** @param array<string, mixed> $overrides */
    private function holiday(array $overrides = []): array
    {
        return array_merge([
            'HolidayID' => 'H-X',
            'HolidayDate' => '2026-08-25',
            'HolidayName' => '',
            'DayType' => 'RegularHoliday',
            'ScopeLevel' => 'National',
            'ScopeCode' => null,
            'StartTime' => null,
            'EndTime' => null,
            'LegalBasis' => 'Fixture',
            'Status' => 'Active',
        ], $overrides);
    }

    /**
     * The rule table this file reasons about, stated rather than seeded.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rules(): array
    {
        $rule = fn(string $id, string $dayType, ?string $type, int $worked,
                   int $paid, float $multiplier, string $basis) => [
            'RuleID' => $id, 'DayType' => $dayType, 'EmploymentTypeCode' => $type,
            'Worked' => $worked, 'Paid' => $paid, 'Multiplier' => $multiplier,
            'LegalBasis' => $basis, 'EffectiveFrom' => '2016-01-01', 'EffectiveTo' => null,
        ];

        return [
            $rule('R1', 'RegularDay', null, 1, 1, 1.0, 'Ordinary working day.'),
            $rule('R2', 'RegularDay', null, 0, 0, 0.0, 'No work, no pay.'),

            $rule('R3', 'RegularHoliday', null, 1, 1, 2.0, 'Labor Code art. 94 - 200% worked.'),
            $rule('R4', 'RegularHoliday', null, 0, 1, 1.0, 'Labor Code art. 94 - 100% not worked.'),
            $rule('R5', 'RegularHoliday', 'JO', 0, 0, 0.0,
                'Job Order engagement - services actually rendered only.'),
            $rule('R6', 'RegularHoliday', 'COS', 0, 0, 0.0,
                'Contract of Service engagement - services actually rendered only.'),

            $rule('R7', 'SpecialNonWorking', null, 1, 1, 1.3, 'Special non-working - +30%.'),
            $rule('R8', 'SpecialNonWorking', null, 0, 0, 0.0, 'Special non-working - no work, no pay.'),

            $rule('R9', 'SpecialWorking', null, 1, 1, 1.0, 'Special working - ordinary day.'),
            $rule('R10', 'SpecialWorking', null, 0, 0, 0.0, 'Special working - no work, no pay.'),

            $rule('R11', 'LocalHoliday', null, 1, 1, 1.3, 'Local holiday by ordinance.'),
            $rule('R12', 'LocalHoliday', null, 0, 0, 0.0, 'Local holiday not worked.'),

            $rule('R13', 'WorkSuspension', null, 1, 1, 1.0, 'Suspension - hours worked paid.'),
            $rule('R14', 'WorkSuspension', null, 0, 1, 1.0, 'Suspension - not docked.'),
            $rule('R15', 'WorkSuspension', 'JO', 0, 0, 0.0,
                'Job Order engagement - no services rendered, nothing payable.'),
            $rule('R16', 'WorkSuspension', 'COS', 0, 0, 0.0,
                'Contract of Service engagement - no services rendered, nothing payable.'),
        ];
    }
}
