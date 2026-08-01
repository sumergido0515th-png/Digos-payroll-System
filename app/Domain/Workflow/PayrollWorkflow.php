<?php
/**
 * ============================================================================
 * PayrollWorkflow - the state graph, and the two decisions that gate it.
 *
 *   DRAFT -> FOR_PRE_AUDIT -> PRE_AUDIT_APPROVED -> FOR_PRINTING -> PRINTED -> SUBMITTED
 *                   |- SUSPENDED -> (settle) -> FOR_PRE_AUDIT
 *                   `- RETURNED_TO_PREPARER -> FOR_PRE_AUDIT
 *
 * Pure: no DB::, no $_SESSION, no clock, no file I/O. The graph, the approval
 * guard and the suspension split are all array-in, array-out - which is what
 * lets the exit gate ("pre-auditor can suspend/approve; preparer cannot
 * approve its own payroll") be checked against fixtures instead of two people
 * clicking through a UI.
 *
 * WHAT THIS CLASS DOES NOT DO
 * It does not read RuleEngine findings from a database, and it does not write
 * a Suspensions row. app/Payroll.php is the shell: it loads the findings via
 * app/PreAudit.php, calls guardApproval() with them, and if the guard refuses,
 * persists what this class says to raise through Digos\Repo\SuspensionRepo.
 * The split between deciding and doing is the same shape as RuleEngine itself.
 *
 * FOR_PRINTING AND PRINTED ARE PLACEHOLDERS ON PURPOSE. Phase 8 attaches
 * payload hashes and print serials to the PRINTED transition; this phase only
 * gives the two states somewhere to live in the graph. A payroll can be
 * FOR_PRINTING today and it means exactly "past pre-audit, not yet printed" -
 * nothing more, until Phase 8 says otherwise.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Workflow;

final class PayrollWorkflow
{
    /** Legal transitions, keyed by the status a payroll is leaving. */
    public const FLOW = [
        'DRAFT' => ['FOR_PRE_AUDIT', 'CANCELLED'],
        'FOR_PRE_AUDIT' => ['PRE_AUDIT_APPROVED', 'SUSPENDED', 'RETURNED_TO_PREPARER', 'CANCELLED'],
        'PRE_AUDIT_APPROVED' => ['SUSPENDED', 'FOR_PRINTING', 'CANCELLED'],
        'FOR_PRINTING' => ['PRINTED', 'CANCELLED'],

        // A printed batch can still be voided before it reaches the paying
        // office - SUBMITTED is the actual point of no return, since money
        // moves after that. Cancelling here does not un-print the physical
        // form; Phase 8's reprint-reason trail is where that gets recorded.
        'PRINTED' => ['SUBMITTED', 'CANCELLED'],
        'SUSPENDED' => ['FOR_PRE_AUDIT'],
        'RETURNED_TO_PREPARER' => ['FOR_PRE_AUDIT', 'CANCELLED'],
        'SUBMITTED' => [],
        'CANCELLED' => [],
    ];

    /** A payroll may still be edited (apiSavePayroll) while in one of these. */
    public const EDITABLE = ['DRAFT', 'FOR_PRE_AUDIT', 'RETURNED_TO_PREPARER'];

    /**
     * Printable without the "NOT OFFICIAL" watermark PrintDoc.php applies.
     *
     * Generalises the Phase 2 check that used to read
     * `in_array($status, ['Approved', 'Released'])`: anything that has passed
     * pre-audit sign-off is official, whichever side of printing it is on.
     */
    public const OFFICIAL = ['PRE_AUDIT_APPROVED', 'FOR_PRINTING', 'PRINTED', 'SUBMITTED'];

    /** Every status a payroll can hold, for validation and fixtures alike. */
    public const ALL = ['DRAFT', 'FOR_PRE_AUDIT', 'PRE_AUDIT_APPROVED', 'FOR_PRINTING',
        'PRINTED', 'SUBMITTED', 'SUSPENDED', 'RETURNED_TO_PREPARER', 'CANCELLED'];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::FLOW[$from] ?? [], true);
    }

    public static function isTerminal(string $status): bool
    {
        return (self::FLOW[$status] ?? null) === [];
    }

    public static function isOfficial(string $status): bool
    {
        return in_array($status, self::OFFICIAL, true);
    }

    public static function isEditable(string $status): bool
    {
        return in_array($status, self::EDITABLE, true);
    }

    /**
     * Whether an approval attempt succeeds outright, or must become a
     * suspension instead.
     *
     * BLOCKER findings are why FOR_PRE_AUDIT -> PRE_AUDIT_APPROVED can be
     * refused; this is the "no override" Phase 6 promised, enforced at the one
     * transition where it matters rather than left to the caller to remember.
     * A refusal here is not an error - it is the workflow doing its job, which
     * is why the caller gets back what to raise rather than an exception.
     *
     * @param array<int, array{RuleID: string, Severity: string, Message: string,
     *              EmployeeID: string}> $findings from RuleEngine::validateToArray()
     * @return array{approved: bool, toRaise: array<int, array<string, string>>}
     *         toRaise: one entry per BLOCKER, with EmployeeID '' meaning batch-wide
     */
    public static function guardApproval(array $findings): array
    {
        $blockers = array_values(array_filter($findings, fn(array $f) => $f['Severity'] === 'BLOCKER'));

        if (!$blockers) return ['approved' => true, 'toRaise' => []];

        return [
            'approved' => false,
            'toRaise' => array_map(fn(array $f) => [
                'GroundCode' => self::groundCodeFor($f['RuleID']),
                'RuleID' => $f['RuleID'],
                'EmployeeID' => $f['EmployeeID'],
                'Particulars' => $f['Message'],
            ], $blockers),
        ];
    }

    /** The rule category a ground code is drawn from, e.g. 'CON-005' -> 'CONFLICT'. */
    public static function groundCodeFor(string $ruleId): string
    {
        return match (substr($ruleId, 0, 3)) {
            'DOC' => 'DOCUMENT_INTEGRITY',
            'CON' => 'CONFLICT',
            'CMP', 'SHF' => 'COMPUTATION',
            'FRM' => 'FORM_COMPLETENESS',
            'CAL' => 'CALENDAR',
            'SCP' => 'SCOPE',
            default => 'OTHER',
        };
    }

    /**
     * Splits a payroll's lines into what proceeds and what is held.
     *
     * Employee-scoped by default: a suspension naming one employee holds only
     * that line, and the rest of the batch is not the pre-auditor's problem
     * to keep waiting on. Renumbers LineNo 1..n within each group, because the
     * printed form reads position from this column and a gap in the sequence
     * is exactly the kind of thing FRM-003 exists to catch on the far side.
     *
     * A batch-wide suspension (no employee named) is not this function's
     * concern - the caller does not call it, and the whole payroll moves to
     * SUSPENDED as one unit instead.
     *
     * @param array<int, array<string, mixed>> $lines PayrollDetails rows, any order
     * @param string[] $suspendedEmployeeIds
     * @return array{clean: array<int, array<string, mixed>>,
     *               suspended: array<int, array<string, mixed>>}
     */
    public static function partitionForSuspension(array $lines, array $suspendedEmployeeIds): array
    {
        $suspendedSet = array_flip($suspendedEmployeeIds);
        $clean = [];
        $suspended = [];

        foreach ($lines as $line) {
            if (isset($suspendedSet[(string) ($line['EmployeeID'] ?? '')])) {
                $suspended[] = $line;
            } else {
                $clean[] = $line;
            }
        }

        return ['clean' => self::renumber($clean), 'suspended' => self::renumber($suspended)];
    }

    /** @param array<int, array<string, mixed>> $lines */
    private static function renumber(array $lines): array
    {
        foreach (array_values($lines) as $i => $line) {
            $lines[$i]['LineNo'] = $i + 1;
        }
        return array_values($lines);
    }
}
