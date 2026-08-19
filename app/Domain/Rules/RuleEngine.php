<?php
/**
 * ============================================================================
 * RuleEngine - the pre-audit, as one pure function.
 *
 *   RuleEngine::validate(array $context): Finding[]
 *
 * Pure: no DB::, no $_SESSION, no clock, no file I/O. Everything it needs is
 * in $context, loaded by app/PreAudit.php. That is what makes the exit gate -
 * "fixture payrolls produce exactly the expected findings, verified by
 * automated test" - possible at all.
 *
 * WHY ONE FUNCTION AND NOT A PLUGIN SYSTEM
 * Twenty-two rules is a list, not an architecture. A registry of rule objects
 * would add a layer whose only job is to iterate, and would make "which rules
 * ran against this payroll?" a runtime question instead of something you can
 * answer by reading RULES.md. When the set outgrows one file it should be
 * split by category, not made dynamic.
 *
 * WHAT THE ENGINE DOES NOT DO
 * It does not decide what happens next. It reports; Phase 7's transition guard
 * and Phase 8's print gate read the severities and act. Keeping that split
 * means a rule can be re-tiered without touching workflow code, and the engine
 * can be run speculatively - "what would this payroll be flagged for?" - with
 * no consequences.
 *
 * MISSING DATA IS NOT A CLEAN BILL. Several rules can only run when their
 * input is present: no contract rows means the rate check has nothing to
 * compare against. Those cases report an INFO rather than silently passing,
 * because a rule that cannot run and a rule that found nothing look identical
 * from the outside and only one of them is reassuring.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Rules;

use Digos\Domain\Resolver\AuthorityResolver;
use Digos\Domain\Resolver\HolidayResolver;
use Digos\Domain\Resolver\ShiftResolver;

final class RuleEngine
{
    /** Rows per printed page. Must match PRINT_ROWS in app/PrintDoc.php. */
    public const PRINT_ROWS = 15;

    /**
     * Every finding for one payroll, most severe first.
     *
     * @param array<string, mixed> $context see CONTEXT_KEYS
     * @return array<int, Finding>
     */
    public static function validate(array $context): array
    {
        $findings = array_merge(
            self::documentIntegrity($context),
            self::conflicts($context),
            self::shiftAndComputation($context),
            self::formCompleteness($context),
            self::calendar($context),
            self::scopeIntegrity($context));

        // Stable order: severity, then rule, then employee. Two runs over the
        // same payroll must produce the same list in the same order, or a
        // reviewer comparing yesterday's findings to today's is reading noise.
        usort($findings, fn(Finding $a, Finding $b) =>
            [Severity::rank($a->severity), $a->ruleId, $a->employeeId, $a->date]
            <=> [Severity::rank($b->severity), $b->ruleId, $b->employeeId, $b->date]);

        return $findings;
    }

    /** @return array<int, array<string, mixed>> the findings as plain arrays */
    public static function validateToArray(array $context): array
    {
        return array_map(fn(Finding $f) => $f->toArray(), self::validate($context));
    }

    /* ======================================================================
     * Attachment and document integrity
     * ==================================================================== */

    /** @return array<int, Finding> */
    private static function documentIntegrity(array $context): array
    {
        $findings = [];

        $days = $context['dtrDays'] ?? [];
        $coverage = $context['attachmentCoverage'] ?? [];
        $exemptions = $context['bioExemptions'] ?? [];
        $travelOrders = $context['travelOrders'] ?? [];

        // DOC-001 - a hand-keyed working day with nothing on file.
        //
        // The rule the whole document layer exists to serve. WARNING rather
        // than BLOCKER on purpose: a missing scan is usually a filing delay,
        // and refusing to pay people because a document has not been uploaded
        // yet punishes the wrong party.
        foreach ($days as $day) {
            if (($day['Source'] ?? 'Manual') !== 'Manual') continue;
            if ((float) ($day['HoursWorked'] ?? 0) <= 0) continue;

            $employeeId = (string) $day['EmployeeID'];
            $date = (string) $day['WorkDate'];

            if (self::coversDate($coverage, $employeeId, $date)) continue;
            if (self::exemptionCovering($exemptions, $employeeId, $date) !== null) continue;
            if (self::travelOrderCovering($travelOrders, $employeeId, $date) !== null) continue;

            $findings[] = new Finding('DOC-001', Severity::WARNING,
                'Hours on ' . $date . ' were keyed by hand and no bio exemption, travel '
                . 'order or attachment on file explains the missing biometric record.',
                $employeeId, $date);
        }

        // DOC-002 - a travel order whose dates fall outside the period it is
        // being cited in. Usually a typed year; occasionally a document
        // attached to the wrong payroll.
        foreach ($travelOrders as $order) {
            $depart = (string) ($order['DepartDate'] ?? '');
            if ($depart === '') continue;

            $return = trim((string) ($order['ReturnDate'] ?? '')) ?: $depart;
            $start = (string) ($context['periodStart'] ?? '');
            $end = (string) ($context['periodEnd'] ?? '');
            if ($start === '' || $end === '') continue;

            if ($return < $start || $depart > $end) {
                $findings[] = new Finding('DOC-002', Severity::WARNING,
                    'Travel order ' . ($order['TravelOrderNo'] ?? '') . ' covers ' . $depart
                    . ' to ' . $return . ', entirely outside this period (' . $start
                    . ' to ' . $end . ').',
                    (string) ($order['EmployeeID'] ?? ''), $depart);
            }
        }

        // DOC-003 - two attachments with one hash. The database's unique key
        // makes this unreachable through the application, so a hit here means
        // rows arrived another way - a restore, or a direct edit. INFO,
        // because it is a data-integrity note rather than a payroll defect.
        $seenHashes = [];
        foreach ($context['attachments'] ?? [] as $attachment) {
            $hash = (string) ($attachment['Sha256'] ?? '');
            if ($hash === '') continue;

            if (isset($seenHashes[$hash])) {
                $findings[] = new Finding('DOC-003', Severity::INFO,
                    'Attachment "' . ($attachment['FileName'] ?? '') . '" is byte-identical to "'
                    . $seenHashes[$hash] . '". One document filed twice reads as two pieces '
                    . 'of evidence.', '', '',
                    ['Sha256' => $hash]);
            }
            $seenHashes[$hash] = (string) ($attachment['FileName'] ?? '');
        }

        // DOC-004 - one control number on two documents. The control number is
        // the document's identity in the paper world, and two rows sharing one
        // cannot be told apart in an audit.
        $seenControlNos = [];
        foreach ($context['memoranda'] ?? [] as $memo) {
            $controlNo = (string) ($memo['ControlNo'] ?? '');
            if ($controlNo === '') continue;

            if (isset($seenControlNos[$controlNo])) {
                $findings[] = new Finding('DOC-004', Severity::INFO,
                    'Control number ' . $controlNo . ' is used by more than one memorandum.',
                    '', '', ['ControlNo' => $controlNo]);
            }
            $seenControlNos[$controlNo] = true;
        }

        return $findings;
    }

    /* ======================================================================
     * Conflict detection
     * ==================================================================== */

    /** @return array<int, Finding> */
    private static function conflicts(array $context): array
    {
        $findings = [];
        $days = $context['dtrDays'] ?? [];

        foreach ($days as $day) {
            $employeeId = (string) $day['EmployeeID'];
            $date = (string) $day['WorkDate'];

            // CON-001 - travelling and absent on the same day. One of the two
            // records is wrong, and which one changes whether the day is paid.
            if (!empty($day['IsAbsent'])
                && self::travelOrderCovering($context['travelOrders'] ?? [], $employeeId, $date) !== null) {
                $findings[] = new Finding('CON-001', Severity::WARNING,
                    $date . ' is recorded as an absence and is also covered by a travel '
                    . 'order. One of the two is wrong, and they imply different pay.',
                    $employeeId, $date);
            }

            // CON-002 - a bio exemption excusing a punch that exists anyway.
            // The exemption says the device could not see this person; the
            // device saw them. Not fraud, usually - a standing exemption left
            // in place after the reason ended - but it is the kind of thing
            // that makes every other exemption less believable.
            if (($day['Source'] ?? '') === 'Biometric'
                && self::exemptionCovering($context['bioExemptions'] ?? [], $employeeId, $date) !== null) {
                $findings[] = new Finding('CON-002', Severity::INFO,
                    'A bio exemption covers ' . $date . ', but a biometric record exists for '
                    . 'that day. The exemption may be standing after its reason ended.',
                    $employeeId, $date);
            }
        }

        // CON-003 - the same person on two payrolls covering overlapping dates.
        //
        // Redacted per the Phase 2 rule: the finding names the employee, whom
        // the reader can already see, and NOT the other payroll's number or
        // office, which may be outside their scope. The caller supplies this
        // already-redacted; the engine only reports it.
        foreach ($context['overlappingPayrolls'] ?? [] as $overlap) {
            $findings[] = new Finding('CON-003', Severity::BLOCKER,
                ($overlap['EmployeeName'] ?? $overlap['EmployeeID'] ?? 'An employee')
                . ' also appears on another payroll covering dates in this period. '
                . 'Paying both is a double payment.',
                (string) ($overlap['EmployeeID'] ?? ''));
        }

        // CON-004 / CON-005 - overtime without authority, or beyond it.
        $overtimeByEmployee = [];
        foreach ($days as $day) {
            $overtime = (float) ($day['OvertimeHours'] ?? 0);
            if ($overtime <= 0) continue;

            $employeeId = (string) $day['EmployeeID'];
            $date = (string) $day['WorkDate'];
            $overtimeByEmployee[$employeeId] = ($overtimeByEmployee[$employeeId] ?? 0) + $overtime;

            $authority = AuthorityResolver::resolve(
                $context['memoranda'] ?? [], $context['memorandumCoverage'] ?? [],
                $employeeId, $date, 'Overtime');

            if (!$authority['authorised']) {
                $findings[] = new Finding('CON-004', Severity::WARNING,
                    $overtime . ' hour(s) of overtime on ' . $date . ' with no memorandum '
                    . 'authorising overtime for this employee on that date.',
                    $employeeId, $date);
                continue;
            }

            // CON-005 - claimed beyond what the memo's own window allows.
            // BLOCKER: unlike a missing document, this is a figure that
            // contradicts the authority actually cited on the same voucher.
            $authorisedMinutes = (int) $authority['authorised_minutes'];
            if ($authorisedMinutes > 0 && $overtime * 60 > $authorisedMinutes) {
                $findings[] = new Finding('CON-005', Severity::BLOCKER,
                    $overtime . ' hour(s) of overtime claimed on ' . $date . ', but memorandum '
                    . $authority['control_no'] . ' authorises at most '
                    . round($authorisedMinutes / 60, 2) . ' hour(s) that day.',
                    $employeeId, $date,
                    ['MemoID' => $authority['memo_id'], 'AuthorisedMinutes' => $authorisedMinutes]);
            }
        }

        return $findings;
    }

    /* ======================================================================
     * Shift and computation
     * ==================================================================== */

    /** @return array<int, Finding> */
    private static function shiftAndComputation(array $context): array
    {
        $findings = [];

        $shiftVersions = $context['shiftVersions'] ?? [];
        $contracts = $context['contracts'] ?? [];
        $periodEnd = (string) ($context['periodEnd'] ?? '');

        foreach ($context['dtrDays'] ?? [] as $day) {
            $employeeId = (string) $day['EmployeeID'];
            $date = (string) $day['WorkDate'];

            // SHF-001 - a day judged against a shift that did not exist yet.
            // Phase 4 versioned shifts precisely so this is answerable; a day
            // predating every version has no definition of "late" behind it.
            if ($shiftVersions !== [] && ShiftResolver::versionOn($shiftVersions, $date) === null) {
                $findings[] = new Finding('SHF-001', Severity::WARNING,
                    'No version of the work shift was in force on ' . $date . ', so the late '
                    . 'and undertime figures for that day were computed against nothing.',
                    $employeeId, $date);
            }

            // SHF-003 - a rest day worked with no memorandum authorising it.
            $shift = ShiftResolver::versionOn($shiftVersions, $date);
            if ((float) ($day['HoursWorked'] ?? 0) > 0 && ShiftResolver::isRestDay($shift, $date)) {
                $authority = AuthorityResolver::resolve(
                    $context['memoranda'] ?? [], $context['memorandumCoverage'] ?? [],
                    $employeeId, $date);

                if (!$authority['authorised']) {
                    $findings[] = new Finding('SHF-003', Severity::WARNING,
                        $date . ' is a rest day under the shift in force and hours were worked, '
                        . 'with no memorandum authorising it.', $employeeId, $date);
                }
            }
        }

        // SHF-002 - night differential claimed by a shift that has no night
        // window. INFO: the claim may be right and the shift definition stale.
        foreach ($context['lines'] ?? [] as $line) {
            if ((float) ($line['NightDiffHours'] ?? 0) <= 0) continue;

            $shift = ShiftResolver::versionOn($shiftVersions, $periodEnd);
            if ($shift === null || empty($shift['NightDiffFrom'])) {
                $findings[] = new Finding('SHF-002', Severity::INFO,
                    'Night differential is claimed, but the work shift in force defines no '
                    . 'night differential window.', (string) ($line['EmployeeID'] ?? ''));
            }
        }

        foreach ($context['lines'] ?? [] as $line) {
            $employeeId = (string) ($line['EmployeeID'] ?? '');

            // CMP-003 - arithmetic that cannot be right. BLOCKER, and the one
            // rule nobody should ever need to think about: a voucher for a
            // negative amount is wrong on its face.
            $net = (float) ($line['NetPay'] ?? 0);
            $gross = (float) ($line['GrossPay'] ?? 0);
            $deductions = (float) ($line['TotalDeductions'] ?? 0);

            if ($net <= 0) {
                $findings[] = new Finding('CMP-003', Severity::BLOCKER,
                    'Net pay is ' . number_format($net, 2) . '. A payroll line cannot pay '
                    . 'nothing or less than nothing.', $employeeId);
            } elseif ($deductions > $gross) {
                $findings[] = new Finding('CMP-003', Severity::BLOCKER,
                    'Deductions of ' . number_format($deductions, 2) . ' exceed gross pay of '
                    . number_format($gross, 2) . '.', $employeeId);
            }

            // CMP-001 / CMP-002 - the contract checks. These are why Phase 3
            // gave Contracts supersession semantics: both compare against the
            // engagement in force ON THIS PERIOD, which only has an answer
            // while the superseded rows survive.
            //
            // Looked up at the period START, not the end. CMP-001 is precisely
            // the case of a contract that expired part-way through, and a
            // lookup at the end would never find one - it would report "no
            // contract" instead, which is a different and much weaker finding.
            $contract = self::contractInForce($contracts, $employeeId,
                (string) ($context['periodStart'] ?? $periodEnd));

            if ($contract === null) {
                // Not a clean bill - the rule could not run. See the class
                // docblock: a check with no input and a check that passed look
                // identical from outside, and only one is reassuring.
                $findings[] = new Finding('CMP-002', Severity::INFO,
                    'No contract covers this employee for ' . $periodEnd . ', so the daily '
                    . 'rate on this line could not be checked against one.', $employeeId);
                continue;
            }

            $contractEnd = trim((string) ($contract['EndDate'] ?? ''));
            if ($contractEnd !== '' && $contractEnd < $periodEnd) {
                $findings[] = new Finding('CMP-001', Severity::WARNING,
                    'The contract in force ends ' . $contractEnd . ', before the period ends '
                    . $periodEnd . '. Days after that date are outside the engagement.',
                    $employeeId, $contractEnd);
            }

            // CMP-002 - the rate on the line against the rate in the contract.
            // BLOCKER: the voucher and the engagement disagree about what this
            // person is paid, and no amount of justification makes both true.
            $lineRate = round((float) ($line['SalaryRate'] ?? 0), 2);
            $contractRate = round((float) ($contract['Rate'] ?? 0), 2);

            if ($lineRate > 0 && $contractRate > 0 && abs($lineRate - $contractRate) >= 0.01) {
                $findings[] = new Finding('CMP-002', Severity::BLOCKER,
                    'The line pays ' . number_format($lineRate, 2) . ' a day; the contract in '
                    . 'force says ' . number_format($contractRate, 2) . '.',
                    $employeeId, '', ['ContractID' => $contract['ContractID'] ?? '']);
            }
        }

        return $findings;
    }

    /* ======================================================================
     * Form completeness
     * ==================================================================== */

    /** @return array<int, Finding> */
    private static function formCompleteness(array $context): array
    {
        $findings = [];
        $payroll = $context['payroll'] ?? [];
        $lines = $context['lines'] ?? [];

        // FRM-001 - an unsigned form. WARNING rather than BLOCKER: a draft
        // legitimately has no approver yet, and Phase 7's transition guard is
        // where an unsigned form actually stops moving.
        foreach (['PreparedBy' => 'prepared by', 'ApprovedBy' => 'approved by'] as $field => $label) {
            if (trim((string) ($payroll[$field] ?? '')) === '') {
                $findings[] = new Finding('FRM-001', Severity::WARNING,
                    'The signatory block has no "' . $label . '" name.');
            }
        }

        // FRM-002 - the sum of the lines against the header's own totals. A
        // header stating a figure its lines do not add up to is a document
        // that contradicts itself, so BLOCKER.
        if ($lines) {
            $summed = 0.0;
            foreach ($lines as $line) $summed += (float) ($line['NetPay'] ?? 0);

            $stated = (float) ($payroll['TotalNet'] ?? 0);
            if (abs(round($summed, 2) - round($stated, 2)) >= 0.01) {
                $findings[] = new Finding('FRM-002', Severity::BLOCKER,
                    'The lines total ' . number_format($summed, 2) . ' but the payroll states '
                    . number_format($stated, 2) . '.', '', '',
                    ['Summed' => round($summed, 2), 'Stated' => round($stated, 2)]);
            }
        }

        // FRM-003 - more lines than the printed form has rows.
        //
        // PRINT_ROWS and MaxEmployeesPerPayroll are both 15 and both
        // load-bearing for the printed geometry (see CLAUDE.md > Traps). A
        // payroll that exceeds it does not split cleanly across pages; the
        // last rows fall off the form.
        if (count($lines) > self::PRINT_ROWS) {
            $findings[] = new Finding('FRM-003', Severity::INFO,
                count($lines) . ' lines exceed the ' . self::PRINT_ROWS . ' rows the printed '
                . 'form holds, so the last ones will not appear on it.', '', '',
                ['Lines' => count($lines), 'FormRows' => self::PRINT_ROWS]);
        }

        return $findings;
    }

    /* ======================================================================
     * Calendar and holiday
     * ==================================================================== */

    /** @return array<int, Finding> */
    private static function calendar(array $context): array
    {
        $findings = [];

        $holidays = $context['holidays'] ?? [];
        $payRules = $context['holidayPayRules'] ?? [];
        $officeScope = $context['officeScope'] ?? [];
        $employmentTypes = $context['employmentTypes'] ?? [];

        foreach ($context['dtrDays'] ?? [] as $day) {
            $employeeId = (string) $day['EmployeeID'];
            $date = (string) $day['WorkDate'];
            if ((float) ($day['HoursWorked'] ?? 0) <= 0) continue;

            $declaration = HolidayResolver::mostSpecificDeclaration($holidays, $date, $officeScope);
            if ($declaration === null) continue;

            $dayType = (string) $declaration['DayType'];
            if (!in_array($dayType, ['RegularHoliday', 'SpecialNonWorking', 'LocalHoliday',
                    'WorkSuspension'], true)) {
                continue;
            }

            // CAL-001 - worked on a day the city declared non-working, with
            // nothing authorising it.
            $authority = AuthorityResolver::resolve(
                $context['memoranda'] ?? [], $context['memorandumCoverage'] ?? [],
                $employeeId, $date);

            if (!$authority['authorised']) {
                $findings[] = new Finding('CAL-001', Severity::WARNING,
                    $date . ' is a ' . $dayType . ' and hours were worked, with no memorandum '
                    . 'authorising work on it.', $employeeId, $date,
                    ['HolidayID' => $declaration['HolidayID']]);
            }

            // CAL-002 - a holiday premium paid to an engagement that has no
            // contractual basis for one. This is the Phase 4 divergence
            // surfacing as a finding: paying a JO for a day they did not work
            // is a disallowance, and the resolver already knows.
            $resolved = HolidayResolver::resolve(
                $holidays, $payRules, $date,
                (string) ($employmentTypes[$employeeId] ?? ''), false, $officeScope);

            if (!$resolved['paid'] && (float) ($day['HoursWorked'] ?? 0) <= 0) {
                $findings[] = new Finding('CAL-002', Severity::WARNING,
                    'A holiday premium on ' . $date . ' has no contractual basis for this '
                    . 'engagement type. ' . $resolved['legal_basis'],
                    $employeeId, $date);
            }
        }

        // CAL-003 - a declaration with no legal basis recorded. The column is
        // NOT NULL, so this catches an empty string: a proclamation nobody can
        // cite is not one a pre-audit can rely on.
        foreach ($holidays as $holiday) {
            if (trim((string) ($holiday['LegalBasis'] ?? '')) !== '') continue;

            $findings[] = new Finding('CAL-003', Severity::INFO,
                'The declaration for ' . ($holiday['HolidayDate'] ?? '') . ' records no legal '
                . 'basis, so a finding that depends on it cannot cite one.',
                '', (string) ($holiday['HolidayDate'] ?? ''),
                ['HolidayID' => $holiday['HolidayID'] ?? '']);
        }

        return $findings;
    }

    /* ======================================================================
     * Scope integrity
     * ==================================================================== */

    /** @return array<int, Finding> */
    private static function scopeIntegrity(array $context): array
    {
        $findings = [];

        // SCP-001 - a line charged to an office the preparer cannot see.
        //
        // The reason PayrollDetails scopes on ChargedOfficeCode rather than on
        // the employee's home office, decided back in migration 0006. BLOCKER:
        // a preparer who cannot read an office cannot verify what they are
        // charging to it.
        $preparerScope = $context['preparerOfficeCodes'] ?? null;
        if ($preparerScope === null) return $findings;         // wildcard, nothing to check

        foreach ($context['lines'] ?? [] as $line) {
            $charged = (string) ($line['ChargedOfficeCode'] ?? '');
            if ($charged === '' || in_array($charged, $preparerScope, true)) continue;

            $findings[] = new Finding('SCP-001', Severity::BLOCKER,
                'This line is charged to office ' . $charged . ', which is outside the '
                . 'preparer\'s scope. They cannot verify what they are charging to it.',
                (string) ($line['EmployeeID'] ?? ''), '',
                ['ChargedOfficeCode' => $charged]);
        }

        return $findings;
    }

    /* ======================================================================
     * Shared lookups
     *
     * Duplicated in spirit from CoverageMatrix, deliberately: that class
     * decides what colour a cell is and this one decides whether a payroll is
     * printable, and coupling them would mean a change to the screen could
     * silently change what blocks a voucher.
     * ==================================================================== */

    /** @param array<int, array<string, mixed>> $coverage */
    private static function coversDate(array $coverage, string $employeeId, string $date): bool
    {
        foreach ($coverage as $row) {
            if ((string) ($row['EmployeeID'] ?? '') === $employeeId
                && (string) ($row['CoveredDate'] ?? '') === $date) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed>|null */
    private static function exemptionCovering(array $exemptions, string $employeeId, string $date): ?array
    {
        foreach ($exemptions as $exemption) {
            if ((string) ($exemption['EmployeeID'] ?? '') !== $employeeId) continue;
            if (($exemption['Status'] ?? 'Active') !== 'Active') continue;

            $from = trim((string) ($exemption['ValidFrom'] ?? ''));
            $to = trim((string) ($exemption['ValidTo'] ?? ''));
            if ($from !== '' && $date < $from) continue;
            if ($to !== '' && $date > $to) continue;

            return $exemption;
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private static function travelOrderCovering(array $travelOrders, string $employeeId, string $date): ?array
    {
        foreach ($travelOrders as $order) {
            if ((string) ($order['EmployeeID'] ?? '') !== $employeeId) continue;
            if (($order['Status'] ?? 'Active') !== 'Active') continue;

            $from = (string) ($order['DepartDate'] ?? '');
            if ($from === '') continue;

            $to = trim((string) ($order['ReturnDate'] ?? '')) ?: $from;
            if ($date >= $from && $date <= $to) return $order;
        }
        return null;
    }

    /**
     * The contract covering a date, newest start first.
     *
     * @return array<string, mixed>|null
     */
    private static function contractInForce(array $contracts, string $employeeId, string $date): ?array
    {
        $best = null;

        foreach ($contracts as $contract) {
            if ((string) ($contract['EmployeeID'] ?? '') !== $employeeId) continue;

            $start = trim((string) ($contract['StartDate'] ?? ''));
            $end = trim((string) ($contract['EndDate'] ?? ''));
            if ($start !== '' && $start > $date) continue;
            if ($end !== '' && $end < $date) continue;

            if ($best === null || $start > trim((string) ($best['StartDate'] ?? ''))) {
                $best = $contract;
            }
        }

        return $best;
    }
}
