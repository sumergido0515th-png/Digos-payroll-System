<?php
/**
 * ============================================================================
 * HolidayResolver - What a date was, and what that was worth.
 *
 *   resolve(holidays, rules, date, employmentTypeCode, worked, officeScope)
 *     -> { day_type, paid, multiplier, legal_basis, scope_level, scope_code,
 *          holiday_id, holiday_name, partial, hours_covered }
 *
 * Pure: no DB::, no $_SESSION, no clock, no file I/O. Rows in, decision out.
 * The caller loads the calendar and the rule table; this decides. That is what
 * makes the exit gate - "JO/COS vs plantilla holiday pay divergence, verified
 * by fixture" - a test rather than a screenshot.
 *
 * THREE DECISIONS, IN ORDER, and they are separate on purpose:
 *
 *   1. WHICH DECLARATION APPLIES.  Most-specific scope wins:
 *      National -> Region -> Province -> City -> Barangay. A city that declares
 *      its fiesta a local holiday overrides the national ordinary day; a
 *      national regular holiday still applies to a city that declared nothing.
 *
 *   2. WHICH RULE APPLIES.  day type x employment type x worked?, taking the
 *      version in force ON THE DATE BEING RESOLVED - not today's version. A
 *      payroll prepared last year was computed under last year's policy and a
 *      pre-audit re-checking it has to ask what the rule was then.
 *
 *   3. HOW MUCH OF THE DAY IT COVERED.  A suspension from 12:00 is half a day,
 *      and paying a whole one is as wrong as paying none.
 *
 * WHY THE EMPLOYMENT TYPE IS NOT AN AFTERTHOUGHT
 * A Job Order or Contract of Service worker is engaged for services actually
 * rendered. They are not paid for a regular holiday they did not work, where
 * an employee with an employer-employee relationship is. Paying it is a
 * disallowance, and it is the easiest mistake to make when a payroll is
 * prepared by hand - which is the entire reason this resolver exists.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Resolver;

final class HolidayResolver
{
    /**
     * Scope levels, least specific first. The index IS the precedence, so a
     * level absent from this list cannot silently outrank one that is present.
     */
    public const SCOPE_LEVELS = ['National', 'Region', 'Province', 'City', 'Barangay'];

    /** What a date can be. RestDay is not here - it comes from the shift. */
    public const DAY_TYPES = ['RegularDay', 'RegularHoliday', 'SpecialNonWorking',
        'SpecialWorking', 'LocalHoliday', 'WorkSuspension'];

    /** An ordinary day, when no declaration covers the date. */
    public const DEFAULT_DAY_TYPE = 'RegularDay';

    /**
     * Resolves one date for one employee.
     *
     * @param array<int, array<string, mixed>> $holidays every declaration, any scope
     * @param array<int, array<string, mixed>> $rules every pay rule version
     * @param string $date YYYY-MM-DD
     * @param string $employmentTypeCode 'JO', 'COS', ... ; '' for none
     * @param bool $worked whether the employee actually worked it
     * @param array<string, string> $officeScope e.g.
     *        ['Region' => 'XI', 'Province' => 'Davao del Sur', 'City' => 'Digos']
     * @param float $standardDayHours what a whole day is, for partial cover
     * @return array<string, mixed>
     */
    public static function resolve(
        array $holidays,
        array $rules,
        string $date,
        string $employmentTypeCode,
        bool $worked,
        array $officeScope = [],
        float $standardDayHours = 8.0
    ): array {
        $declaration = self::mostSpecificDeclaration($holidays, $date, $officeScope);
        $dayType = $declaration === null
            ? self::DEFAULT_DAY_TYPE
            : (string) $declaration['DayType'];

        $rule = self::ruleFor($rules, $dayType, $employmentTypeCode, $worked, $date);

        // No rule at all is reported, never guessed at. A resolver that
        // invented "unpaid, 0x" when its table was incomplete would produce a
        // wrong payroll that looked exactly like a correct one, and Phase 6
        // would have nothing to flag.
        if ($rule === null) {
            return self::answer($dayType, $declaration, [
                'paid' => false,
                'multiplier' => 0.0,
                'legal_basis' => '',
                'rule_id' => '',
                'unresolved' => true,
                'unresolved_reason' => sprintf(
                    'No pay rule covers a %s %s by an employee of type "%s" on %s. '
                    . 'Add the rule to HolidayPayRules with its legal basis before this '
                    . 'date can be paid.',
                    $dayType, $worked ? 'worked' : 'not worked',
                    $employmentTypeCode ?: '(none)', $date),
            ], $standardDayHours);
        }

        return self::answer($dayType, $declaration, [
            'paid' => (bool) $rule['Paid'],
            'multiplier' => (float) $rule['Multiplier'],
            'legal_basis' => (string) $rule['LegalBasis'],
            'rule_id' => (string) ($rule['RuleID'] ?? ''),
            'unresolved' => false,
            'unresolved_reason' => '',
        ], $standardDayHours);
    }

    /**
     * The declaration that governs a date for a scope, or null.
     *
     * Most specific wins. Two declarations at the SAME level for the same date
     * is a data error the unique key already prevents, but if one arrives
     * anyway the first is taken and the choice is deterministic rather than
     * dependent on row order - so a re-run gives the same answer.
     *
     * @param array<int, array<string, mixed>> $holidays
     * @param array<string, string> $officeScope
     */
    public static function mostSpecificDeclaration(
        array $holidays,
        string $date,
        array $officeScope = []
    ): ?array {
        $best = null;
        $bestRank = -1;

        foreach ($holidays as $holiday) {
            if ((string) ($holiday['HolidayDate'] ?? '') !== $date) continue;
            if (($holiday['Status'] ?? 'Active') !== 'Active') continue;

            $level = (string) ($holiday['ScopeLevel'] ?? 'National');
            $rank = array_search($level, self::SCOPE_LEVELS, true);
            if ($rank === false) continue;          // an unknown level ranks nothing

            // National needs no qualifier; every other level must match the
            // office's own scope, or it is somebody else's holiday.
            if ($level !== 'National') {
                $code = (string) ($holiday['ScopeCode'] ?? '');
                if ($code === '' || ($officeScope[$level] ?? null) !== $code) continue;
            }

            if ($rank > $bestRank
                || ($rank === $bestRank && $best !== null
                    && (string) $holiday['HolidayID'] < (string) $best['HolidayID'])) {
                $best = $holiday;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * The pay rule in force on a date.
     *
     * A rule naming the employment type beats the NULL fallback, and among
     * equally specific rules the latest EffectiveFrom that is not in the future
     * wins. Both halves matter: without the first, the JO divergence is never
     * reached; without the second, a policy change would rewrite history.
     *
     * @param array<int, array<string, mixed>> $rules
     */
    public static function ruleFor(
        array $rules,
        string $dayType,
        string $employmentTypeCode,
        bool $worked,
        string $date
    ): ?array {
        $best = null;

        foreach ($rules as $rule) {
            if ((string) $rule['DayType'] !== $dayType) continue;
            if ((bool) $rule['Worked'] !== $worked) continue;

            $ruleType = $rule['EmploymentTypeCode'] ?? null;
            $ruleType = $ruleType === null ? null : (string) $ruleType;

            // Either it names this employee's type, or it is the fallback.
            if ($ruleType !== null && $ruleType !== '' && $ruleType !== $employmentTypeCode) continue;

            $from = (string) ($rule['EffectiveFrom'] ?? '');
            if ($from !== '' && $from > $date) continue;                 // not yet in force

            $to = $rule['EffectiveTo'] ?? null;
            if ($to !== null && (string) $to !== '' && (string) $to < $date) continue;   // expired

            if ($best === null || self::rulePrecedes($best, $rule, $employmentTypeCode)) {
                $best = $rule;
            }
        }

        return $best;
    }

    /** True when $candidate should replace $current. */
    private static function rulePrecedes(array $current, array $candidate, string $employmentTypeCode): bool
    {
        $currentSpecific = self::isSpecificTo($current, $employmentTypeCode);
        $candidateSpecific = self::isSpecificTo($candidate, $employmentTypeCode);

        // Specificity first: a rule naming the employment type always beats the
        // fallback, however recent the fallback is. Otherwise a newly added
        // general rule would silently switch a JO back onto plantilla terms.
        if ($currentSpecific !== $candidateSpecific) return $candidateSpecific;

        return (string) ($candidate['EffectiveFrom'] ?? '') > (string) ($current['EffectiveFrom'] ?? '');
    }

    private static function isSpecificTo(array $rule, string $employmentTypeCode): bool
    {
        $ruleType = $rule['EmploymentTypeCode'] ?? null;
        return $ruleType !== null && (string) $ruleType !== ''
            && (string) $ruleType === $employmentTypeCode;
    }

    /**
     * How much of a working day a declaration covered.
     *
     * A suspension from 12:00 to 17:00 is five hours of an eight-hour day. The
     * fraction is what a partial-day computation multiplies by, and returning
     * 1.0 for a suspension that only ran half the day is the error this exists
     * to avoid.
     *
     * @return array{partial: bool, fraction: float, hours: float}
     */
    public static function coverage(?array $declaration, float $standardDayHours = 8.0): array
    {
        $whole = ['partial' => false, 'fraction' => 1.0, 'hours' => $standardDayHours];

        if ($declaration === null) return $whole;

        $start = (string) ($declaration['StartTime'] ?? '');
        $end = (string) ($declaration['EndTime'] ?? '');
        if ($start === '' || $end === '') return $whole;                 // whole day

        $minutes = self::minutesOfDay($end) - self::minutesOfDay($start);
        if ($minutes <= 0) return $whole;

        $hours = $minutes / 60;
        if ($standardDayHours <= 0) $standardDayHours = 8.0;

        // A window longer than a standard day is still one day, not more.
        $fraction = min(1.0, $hours / $standardDayHours);

        return [
            'partial' => $fraction < 1.0,
            'fraction' => round($fraction, 4),
            'hours' => round(min($hours, $standardDayHours), 2),
        ];
    }

    /** "13:30" or "13:30:00" -> minutes since midnight. */
    private static function minutesOfDay(string $time): int
    {
        $parts = explode(':', $time);
        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }

    /**
     * Assembles the answer, so every return has the same shape.
     *
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private static function answer(
        string $dayType,
        ?array $declaration,
        array $rule,
        float $standardDayHours
    ): array {
        $coverage = self::coverage($declaration, $standardDayHours);

        return [
            'day_type' => $dayType,
            'paid' => $rule['paid'],
            'multiplier' => $rule['multiplier'],
            'legal_basis' => $rule['legal_basis'],
            'rule_id' => $rule['rule_id'],
            'unresolved' => $rule['unresolved'],
            'unresolved_reason' => $rule['unresolved_reason'],

            'scope_level' => $declaration === null ? '' : (string) $declaration['ScopeLevel'],
            'scope_code' => $declaration === null ? '' : (string) ($declaration['ScopeCode'] ?? ''),
            'holiday_id' => $declaration === null ? '' : (string) $declaration['HolidayID'],
            'holiday_name' => $declaration === null ? '' : (string) ($declaration['HolidayName'] ?? ''),
            'holiday_basis' => $declaration === null ? '' : (string) ($declaration['LegalBasis'] ?? ''),

            'partial' => $coverage['partial'],
            'coverage_fraction' => $coverage['fraction'],
            'hours_covered' => $coverage['hours'],
        ];
    }
}
