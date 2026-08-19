<?php
/**
 * ============================================================================
 * AuthorityResolverTest - Phase 4's exit gate, authority half.
 *
 * The gate names two of these explicitly: overlapping memos and superseded
 * windows. The third group - the time-window intersection - is the
 * "never take claimed hours at face value" rule, and it is the one most likely
 * to be quietly wrong, because every individual case looks plausible.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Resolver\AuthorityResolver;
use PHPUnit\Framework\TestCase;

final class AuthorityResolverTest extends TestCase
{
    private const EMPLOYEE = 'EMP-1';

    /* ------------------------------------------------------- the basics */

    /** A memo covering the employee and the date authorises. */
    public function testAMemoCoveringTheEmployeeAndDateAuthorises(): void
    {
        $r = $this->resolve([$this->memo()], '2026-07-06');

        $this->assertTrue($r['authorised']);
        $this->assertSame('M-1', $r['memo_id']);
        $this->assertSame('CN-001', $r['control_no']);
    }

    /** A memo that does not name the employee does not authorise them. */
    public function testAMemoThatDoesNotCoverTheEmployeeDoesNotAuthorise(): void
    {
        $r = AuthorityResolver::resolve(
            [$this->memo()], [['MemoID' => 'M-1', 'EmployeeID' => 'SOMEBODY-ELSE']],
            self::EMPLOYEE, '2026-07-06');

        $this->assertFalse($r['authorised']);
        $this->assertStringContainsString('No memorandum covers', $r['reason']);
    }

    /** Outside the window is outside the authority. */
    public function testADateOutsideTheWindowIsNotAuthorised(): void
    {
        $this->assertFalse($this->resolve([$this->memo()], '2026-08-01')['authorised']);
    }

    /** A draft memo authorises nothing - it has not been issued. */
    public function testADraftMemorandumAuthorisesNothing(): void
    {
        $r = $this->resolve([$this->memo(['Status' => 'Draft'])], '2026-07-06');

        $this->assertFalse($r['authorised']);
    }

    /** Asking for a kind of authority the memo does not confer finds none. */
    public function testTheAuthorityTypeIsMatched(): void
    {
        $this->assertFalse(
            $this->resolve([$this->memo()], '2026-07-06', 'Travel')['authorised']);
        $this->assertTrue(
            $this->resolve([$this->memo()], '2026-07-06', 'Overtime')['authorised']);
    }

    /* ------------------------------------------------- overlapping memos */

    /**
     * Two memoranda covering the same moment: the later governs, the earlier
     * is reported.
     *
     * Silently taking one would hide exactly the situation a pre-audit needs
     * to see - two instruments authorising the same overtime, possibly at
     * different rates.
     */
    public function testOverlappingMemorandaAreAllReportedWithTheLatestFirst(): void
    {
        $r = $this->resolve([
            $this->memo(),
            $this->memo(['MemoID' => 'M-2', 'ControlNo' => 'CN-002',
                'DateIssued' => '2026-07-02']),
        ], '2026-07-06');

        $this->assertSame('M-2', $r['memo_id'], 'The later instrument should govern.');
        $this->assertSame([['memo_id' => 'M-1', 'control_no' => 'CN-001']], $r['overlapping']);
    }

    /** One memo means no overlap to report. */
    public function testASingleMemorandumReportsNoOverlap(): void
    {
        $this->assertSame([], $this->resolve([$this->memo()], '2026-07-06')['overlapping']);
    }

    /* ------------------------------------------------ superseded windows */

    /**
     * Superseding truncates the earlier window to the day before.
     *
     * The original end is kept alongside, because "authorised by a memo that
     * had already been replaced" is a finding, and stating it needs both the
     * truncated window and the one originally granted.
     */
    public function testSupersedingTruncatesTheEarlierWindowAndSaysSo(): void
    {
        $memos = [
            $this->memo(),                                          // 07-01 .. 07-15
            $this->memo(['MemoID' => 'M-2', 'ControlNo' => 'CN-002',
                'EffectivityStart' => '2026-07-08', 'EffectivityEnd' => '2026-07-20',
                'SupersedesID' => 'M-1', 'DateIssued' => '2026-07-07']),
        ];

        $window = AuthorityResolver::effectiveWindow($memos[0], $this->byId($memos));

        $this->assertTrue($window['truncated']);
        $this->assertSame('2026-07-07', $window['end'],
            'The superseded window must end the day before its replacement begins.');
        $this->assertSame('2026-07-15', $window['original_end'],
            'The window originally granted has to remain stateable.');
        $this->assertSame('M-2', $window['truncated_by']);
    }

    /** A date inside the original window but after truncation is not covered. */
    public function testADateInTheTruncatedTailIsNoLongerAuthorisedByTheOldMemo(): void
    {
        $memos = [
            $this->memo(),
            $this->memo(['MemoID' => 'M-2', 'ControlNo' => 'CN-002',
                'EffectivityStart' => '2026-07-08', 'EffectivityEnd' => '2026-07-20',
                'SupersedesID' => 'M-1', 'DateIssued' => '2026-07-07']),
        ];

        $r = AuthorityResolver::resolve($memos,
            [['MemoID' => 'M-1', 'EmployeeID' => self::EMPLOYEE]],   // only the OLD memo covers
            self::EMPLOYEE, '2026-07-10');

        $this->assertFalse($r['authorised'],
            '10 July is inside the old memo\'s original window but after it was superseded.');
    }

    /** A successor starting after the original ended truncates nothing. */
    public function testASuccessorStartingAfterTheOriginalEndedIsNotATruncation(): void
    {
        $memos = [
            $this->memo(),                                          // ends 07-15
            $this->memo(['MemoID' => 'M-2', 'EffectivityStart' => '2026-08-01',
                'SupersedesID' => 'M-1']),
        ];

        $window = AuthorityResolver::effectiveWindow($memos[0], $this->byId($memos));

        $this->assertFalse($window['truncated'],
            'Reporting an ordinary renewal as a truncation would be noise on every one.');
        $this->assertSame('2026-07-15', $window['end']);
    }

    /** The chain walks back through everything replaced. */
    public function testTheSupersessionChainWalksBackThroughEveryReplacement(): void
    {
        $memos = [
            $this->memo(['MemoID' => 'M-1', 'ControlNo' => 'CN-001']),
            $this->memo(['MemoID' => 'M-2', 'ControlNo' => 'CN-002', 'SupersedesID' => 'M-1',
                'EffectivityStart' => '2026-07-08']),
            $this->memo(['MemoID' => 'M-3', 'ControlNo' => 'CN-003', 'SupersedesID' => 'M-2',
                'EffectivityStart' => '2026-07-12']),
        ];

        $chain = AuthorityResolver::supersessionChain($memos[2], $this->byId($memos));

        $this->assertSame(['CN-002', 'CN-001'], array_column($chain, 'control_no'));
        $this->assertSame('2026-07-12', $chain[0]['superseded_on']);
    }

    /**
     * A cycle in the chain terminates instead of hanging.
     *
     * The three chain columns are self-referencing foreign keys with no cycle
     * constraint, so A-supersedes-B-supersedes-A is storable. Bad data should
     * produce a bad report, not an unresponsive screen.
     */
    public function testACircularSupersessionChainTerminates(): void
    {
        $memos = [
            $this->memo(['MemoID' => 'M-1', 'SupersedesID' => 'M-2']),
            $this->memo(['MemoID' => 'M-2', 'SupersedesID' => 'M-1']),
        ];

        $chain = AuthorityResolver::supersessionChain($memos[0], $this->byId($memos));

        $this->assertCount(1, $chain);
    }

    /* ------------------------------------- the time-window intersection */

    /**
     * The narrowest of the three wins.
     *
     * A memo authorising 18:00-22:00 and a punch-out at 23:30 authorise four
     * hours, not five and a half. This is the whole "never take claimed hours
     * at face value" rule.
     */
    public function testTheClaimedSpanIsNarrowedToTheMemoWindow(): void
    {
        $minutes = AuthorityResolver::authorisedMinutes(
            $this->memo(['TimeFrom' => '18:00', 'TimeTo' => '22:00']),
            [],
            ['From' => '17:30', 'To' => '23:30']);

        $this->assertSame(240, $minutes, 'Four hours were authorised; six and a half were claimed.');
    }

    /** And a claim narrower than the authority is taken at its own length. */
    public function testAClaimNarrowerThanTheAuthorityIsNotWidenedToIt(): void
    {
        $minutes = AuthorityResolver::authorisedMinutes(
            $this->memo(['TimeFrom' => '18:00', 'TimeTo' => '22:00']),
            [],
            ['From' => '18:00', 'To' => '20:00']);

        $this->assertSame(120, $minutes,
            'A memo authorising four hours does not pay four to somebody who worked two.');
    }

    /** A claim wholly outside the window authorises nothing, not a negative. */
    public function testAClaimOutsideTheWindowAuthorisesNothing(): void
    {
        $minutes = AuthorityResolver::authorisedMinutes(
            $this->memo(['TimeFrom' => '18:00', 'TimeTo' => '22:00']),
            [],
            ['From' => '08:00', 'To' => '12:00']);

        $this->assertSame(0, $minutes);
    }

    /**
     * A non-overtime authority is bounded by the shift; overtime is not.
     *
     * Overtime is by definition outside the shift, so intersecting the two
     * would authorise nothing at all - the inversion this asserts against.
     */
    public function testOvertimeIsNotBoundedByTheShiftButOtherAuthoritiesAre(): void
    {
        $shift = ['TimeIn' => '08:00', 'TimeOut' => '17:00'];

        $overtime = AuthorityResolver::authorisedMinutes(
            $this->memo(['AuthorityType' => 'Overtime', 'TimeFrom' => '18:00', 'TimeTo' => '22:00']),
            $shift, ['From' => '18:00', 'To' => '22:00']);

        $flexi = AuthorityResolver::authorisedMinutes(
            $this->memo(['AuthorityType' => 'FlexiTime', 'TimeFrom' => '07:00', 'TimeTo' => '19:00']),
            $shift, ['From' => '07:00', 'To' => '19:00']);

        $this->assertSame(240, $overtime, 'Overtime was clipped to a shift it is defined as outside.');
        $this->assertSame(540, $flexi, 'A within-shift authority must be bounded by the shift.');
    }

    /* --------------------------------------------- effectivity readings */

    /** Specific dates cover only the dates listed. */
    public function testSpecificDatesCoverOnlyThoseDates(): void
    {
        $memo = $this->memo(['EffectivityType' => 'Specific',
            'SpecificDates' => '2026-07-04,2026-07-11']);

        $this->assertTrue($this->resolve([$memo], '2026-07-04')['authorised']);
        $this->assertFalse($this->resolve([$memo], '2026-07-05')['authorised']);
    }

    /** Recurring covers the listed weekdays inside the window and no others. */
    public function testRecurringCoversTheListedWeekdaysOnly(): void
    {
        // 2026-07-06 is a Monday, 2026-07-07 a Tuesday.
        $memo = $this->memo(['EffectivityType' => 'Recurring', 'RecurrenceDays' => '1,3']);

        $this->assertTrue($this->resolve([$memo], '2026-07-06')['authorised'], 'Monday');
        $this->assertFalse($this->resolve([$memo], '2026-07-07')['authorised'], 'Tuesday');
        $this->assertTrue($this->resolve([$memo], '2026-07-08')['authorised'], 'Wednesday');
    }

    /** Recurring on no days covers no days, rather than every day. */
    public function testRecurringWithNoWeekdaysCoversNothing(): void
    {
        $memo = $this->memo(['EffectivityType' => 'Recurring', 'RecurrenceDays' => '']);

        $this->assertFalse($this->resolve([$memo], '2026-07-06')['authorised']);
    }

    /** Open-ended runs from its start with no end. */
    public function testOpenEndedRunsIndefinitelyFromItsStart(): void
    {
        $memo = $this->memo(['EffectivityType' => 'OpenEnded', 'EffectivityEnd' => null]);

        $this->assertFalse($this->resolve([$memo], '2026-06-30')['authorised']);
        $this->assertTrue($this->resolve([$memo], '2027-01-01')['authorised']);
    }

    /** A memo with no office is citywide, and says so. */
    public function testAMemoWithNoOfficeReportsItsScopeAsCitywide(): void
    {
        $this->assertSame('Citywide',
            $this->resolve([$this->memo(['OfficeCode' => null])], '2026-07-06')['source_scope']);
        $this->assertSame('CMO',
            $this->resolve([$this->memo(['OfficeCode' => 'CMO'])], '2026-07-06')['source_scope']);
    }

    /* -------------------------------------------------------- fixture */

    private function resolve(array $memos, string $date, string $type = ''): array
    {
        $coverage = array_map(
            fn(array $m) => ['MemoID' => $m['MemoID'], 'EmployeeID' => self::EMPLOYEE], $memos);

        return AuthorityResolver::resolve($memos, $coverage, self::EMPLOYEE, $date, $type);
    }

    /** @return array<string, array<string, mixed>> */
    private function byId(array $memos): array
    {
        $out = [];
        foreach ($memos as $memo) $out[(string) $memo['MemoID']] = $memo;
        return $out;
    }

    /** @param array<string, mixed> $overrides */
    private function memo(array $overrides = []): array
    {
        return array_merge([
            'MemoID' => 'M-1',
            'ControlNo' => 'CN-001',
            'Subject' => 'Overtime for the July flood clearing',
            'AuthorityType' => 'Overtime',
            'OfficeCode' => 'CMO',
            'EffectivityType' => 'Range',
            'EffectivityStart' => '2026-07-01',
            'EffectivityEnd' => '2026-07-15',
            'TimeFrom' => null,
            'TimeTo' => null,
            'SpecificDates' => null,
            'RecurrenceDays' => null,
            'SupersedesID' => null,
            'AmendsID' => null,
            'RevokedByID' => null,
            'DateIssued' => '2026-06-28',
            'Status' => 'Active',
        ], $overrides);
    }
}
