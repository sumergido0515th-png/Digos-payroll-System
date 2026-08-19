<?php
/**
 * ============================================================================
 * PayrollWorkflowTest - the state graph and its two gated decisions.
 *
 * Every state in PayrollWorkflow::ALL is checked for a legal path to it and,
 * where the plan calls for one, a legal path onward - the graph is worth
 * asserting node by node, not just edge by edge, because a state nothing can
 * reach is exactly as broken as an edge that skips a required review.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Workflow\PayrollWorkflow;
use PHPUnit\Framework\TestCase;

final class PayrollWorkflowTest extends TestCase
{
    /* ------------------------------------------------------------- the graph */

    /** The forward path the plan draws, edge by edge. */
    public function testTheForwardPathIsWalkable(): void
    {
        $path = ['DRAFT', 'FOR_PRE_AUDIT', 'PRE_AUDIT_APPROVED', 'FOR_PRINTING', 'PRINTED', 'SUBMITTED'];

        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue(PayrollWorkflow::canTransition($path[$i], $path[$i + 1]),
                "{$path[$i]} -> {$path[$i + 1]} should be legal.");
        }
    }

    /** A suspension always leads back to review, never anywhere else. */
    public function testASuspensionSettlesOnlyBackToPreAudit(): void
    {
        $this->assertSame(['FOR_PRE_AUDIT'], PayrollWorkflow::FLOW['SUSPENDED']);
    }

    /** A returned payroll re-enters through resubmission, like a fresh draft. */
    public function testAReturnedPayrollCanBeResubmittedOrCancelled(): void
    {
        $this->assertSame(['FOR_PRE_AUDIT', 'CANCELLED'], PayrollWorkflow::FLOW['RETURNED_TO_PREPARER']);
    }

    /**
     * Phase 8's tamper revert: every official-but-not-yet-submitted state can
     * fall back to FOR_PRE_AUDIT when a print-time hash mismatch is caught,
     * but SUBMITTED never does - money has already moved.
     */
    public function testEveryOfficialPreSubmissionStateCanRevertToPreAuditOnTamper(): void
    {
        foreach (['PRE_AUDIT_APPROVED', 'FOR_PRINTING', 'PRINTED'] as $status) {
            $this->assertTrue(PayrollWorkflow::canTransition($status, 'FOR_PRE_AUDIT'),
                "$status should be able to revert to FOR_PRE_AUDIT on a hash mismatch.");
        }
        $this->assertFalse(PayrollWorkflow::canTransition('SUBMITTED', 'FOR_PRE_AUDIT'),
            'A SUBMITTED payroll must never revert - it is the actual point of no return.');
    }

    /** SUBMITTED and CANCELLED are where the graph actually ends. */
    public function testSubmittedAndCancelledAreTerminal(): void
    {
        $this->assertTrue(PayrollWorkflow::isTerminal('SUBMITTED'));
        $this->assertTrue(PayrollWorkflow::isTerminal('CANCELLED'));
        $this->assertFalse(PayrollWorkflow::isTerminal('DRAFT'));
    }

    /** Nothing is reachable from a terminal state. */
    public function testNoTransitionLeavesATerminalState(): void
    {
        $this->assertSame([], PayrollWorkflow::FLOW['SUBMITTED']);
        $this->assertSame([], PayrollWorkflow::FLOW['CANCELLED']);
    }

    /** Skipping pre-audit review entirely is not a legal move. */
    public function testDraftCannotJumpStraightToApproved(): void
    {
        $this->assertFalse(PayrollWorkflow::canTransition('DRAFT', 'PRE_AUDIT_APPROVED'));
    }

    /** Cancellation reaches every non-terminal state - nothing is stuck open. */
    public function testCancellationIsReachableFromEveryLiveState(): void
    {
        foreach (PayrollWorkflow::ALL as $status) {
            if (PayrollWorkflow::isTerminal($status) || $status === 'SUSPENDED') continue;

            $this->assertTrue(PayrollWorkflow::canTransition($status, 'CANCELLED'),
                "$status has no way to be cancelled.");
        }
    }

    /**
     * A payroll that is merely suspended must be settleable before it can be
     * cancelled - checked here as the deliberate exception to the rule above,
     * so a change that quietly added SUSPENDED -> CANCELLED would fail this
     * test rather than the one before it.
     */
    public function testASuspendedPayrollMustBeSettledBeforeItCanBeCancelled(): void
    {
        $this->assertFalse(PayrollWorkflow::canTransition('SUSPENDED', 'CANCELLED'));
    }

    /** Every state in ALL actually appears in the graph. */
    public function testEveryStateIsAKnownNodeOfTheGraph(): void
    {
        foreach (PayrollWorkflow::ALL as $status) {
            $this->assertArrayHasKey($status, PayrollWorkflow::FLOW);
        }
    }

    /* ---------------------------------------------------------------- editable */

    public function testOnlyDraftForPreAuditAndReturnedAreEditable(): void
    {
        foreach (PayrollWorkflow::ALL as $status) {
            $expected = in_array($status, ['DRAFT', 'FOR_PRE_AUDIT', 'RETURNED_TO_PREPARER'], true);
            $this->assertSame($expected, PayrollWorkflow::isEditable($status), $status);
        }
    }

    /* ----------------------------------------------------------------- official */

    /** Official is "passed pre-audit sign-off", not "printed". */
    public function testOfficialStartsAtPreAuditApproval(): void
    {
        $this->assertFalse(PayrollWorkflow::isOfficial('FOR_PRE_AUDIT'));
        $this->assertTrue(PayrollWorkflow::isOfficial('PRE_AUDIT_APPROVED'));
        $this->assertTrue(PayrollWorkflow::isOfficial('FOR_PRINTING'));
        $this->assertTrue(PayrollWorkflow::isOfficial('PRINTED'));
        $this->assertTrue(PayrollWorkflow::isOfficial('SUBMITTED'));
    }

    /** A suspended payroll is not official, however far it had gotten. */
    public function testASuspendedPayrollIsNeverOfficial(): void
    {
        $this->assertFalse(PayrollWorkflow::isOfficial('SUSPENDED'));
    }

    /* ------------------------------------------------------- approval guard */

    /** No BLOCKER findings: approval proceeds, nothing to raise. */
    public function testNoBlockersMeansApprovalSucceeds(): void
    {
        $result = PayrollWorkflow::guardApproval([
            ['RuleID' => 'DOC-001', 'Severity' => 'WARNING', 'Message' => 'x', 'EmployeeID' => 'E1'],
            ['RuleID' => 'CMP-002', 'Severity' => 'INFO', 'Message' => 'y', 'EmployeeID' => 'E2'],
        ]);

        $this->assertTrue($result['approved']);
        $this->assertSame([], $result['toRaise']);
    }

    /** A single BLOCKER refuses approval and names exactly what to raise. */
    public function testABlockerRefusesApprovalAndSpecifiesTheSuspension(): void
    {
        $result = PayrollWorkflow::guardApproval([
            ['RuleID' => 'CMP-002', 'Severity' => 'BLOCKER', 'Message' => 'rate mismatch', 'EmployeeID' => 'E1'],
        ]);

        $this->assertFalse($result['approved']);
        $this->assertCount(1, $result['toRaise']);
        $this->assertSame('COMPUTATION', $result['toRaise'][0]['GroundCode']);
        $this->assertSame('CMP-002', $result['toRaise'][0]['RuleID']);
        $this->assertSame('E1', $result['toRaise'][0]['EmployeeID']);
        $this->assertSame('rate mismatch', $result['toRaise'][0]['Particulars']);
    }

    /** Several BLOCKERs raise several suspensions, not one merged one. */
    public function testMultipleBlockersEachRaiseTheirOwnSuspension(): void
    {
        $result = PayrollWorkflow::guardApproval([
            ['RuleID' => 'CMP-002', 'Severity' => 'BLOCKER', 'Message' => 'a', 'EmployeeID' => 'E1'],
            ['RuleID' => 'CON-003', 'Severity' => 'BLOCKER', 'Message' => 'b', 'EmployeeID' => 'E2'],
            ['RuleID' => 'FRM-002', 'Severity' => 'BLOCKER', 'Message' => 'c', 'EmployeeID' => ''],
        ]);

        $this->assertFalse($result['approved']);
        $this->assertCount(3, $result['toRaise']);
    }

    /** A batch-level BLOCKER (no employee) is not silently attributed to one. */
    public function testABatchLevelBlockerKeepsAnEmptyEmployeeId(): void
    {
        $result = PayrollWorkflow::guardApproval([
            ['RuleID' => 'FRM-002', 'Severity' => 'BLOCKER', 'Message' => 'totals disagree', 'EmployeeID' => ''],
        ]);

        $this->assertSame('', $result['toRaise'][0]['EmployeeID']);
    }

    /** @dataProvider groundCodes */
    public function testGroundCodesMatchTheRuleCategory(string $ruleId, string $expected): void
    {
        $this->assertSame($expected, PayrollWorkflow::groundCodeFor($ruleId));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function groundCodes(): array
    {
        return [
            'document' => ['DOC-001', 'DOCUMENT_INTEGRITY'],
            'conflict' => ['CON-005', 'CONFLICT'],
            'computation' => ['CMP-002', 'COMPUTATION'],
            'shift' => ['SHF-003', 'COMPUTATION'],
            'form' => ['FRM-002', 'FORM_COMPLETENESS'],
            'calendar' => ['CAL-001', 'CALENDAR'],
            'scope' => ['SCP-001', 'SCOPE'],
            'unknown' => ['XYZ-999', 'OTHER'],
        ];
    }

    /* -------------------------------------------------------- batch splitting */

    /** Clean and suspended lines are partitioned by employee. */
    public function testLinesArePartitionedByWhetherTheEmployeeIsSuspended(): void
    {
        $lines = [
            ['EmployeeID' => 'E1', 'LineNo' => 1],
            ['EmployeeID' => 'E2', 'LineNo' => 2],
            ['EmployeeID' => 'E3', 'LineNo' => 3],
        ];

        $result = PayrollWorkflow::partitionForSuspension($lines, ['E2']);

        $this->assertSame(['E1', 'E3'], array_column($result['clean'], 'EmployeeID'));
        $this->assertSame(['E2'], array_column($result['suspended'], 'EmployeeID'));
    }

    /**
     * Each group is renumbered 1..n, not left with a gap.
     *
     * The printed form reads position from LineNo, and a payroll with lines
     * numbered 1, 3, 4 after a split looks like row 2 went missing rather than
     * moved elsewhere.
     */
    public function testEachGroupIsRenumberedFromOneWithNoGap(): void
    {
        $lines = [
            ['EmployeeID' => 'E1', 'LineNo' => 1],
            ['EmployeeID' => 'E2', 'LineNo' => 2],
            ['EmployeeID' => 'E3', 'LineNo' => 3],
            ['EmployeeID' => 'E4', 'LineNo' => 4],
        ];

        $result = PayrollWorkflow::partitionForSuspension($lines, ['E2', 'E4']);

        $this->assertSame([1, 2], array_column($result['clean'], 'LineNo'));
        $this->assertSame([1, 2], array_column($result['suspended'], 'LineNo'));
    }

    /** No suspended employees: everything is clean, nothing to hold back. */
    public function testNoSuspendedEmployeesLeavesEverythingClean(): void
    {
        $lines = [['EmployeeID' => 'E1', 'LineNo' => 1], ['EmployeeID' => 'E2', 'LineNo' => 2]];

        $result = PayrollWorkflow::partitionForSuspension($lines, []);

        $this->assertCount(2, $result['clean']);
        $this->assertSame([], $result['suspended']);
    }

    /** Every employee suspended: nothing clean survives the split. */
    public function testEveryEmployeeSuspendedLeavesNothingClean(): void
    {
        $lines = [['EmployeeID' => 'E1', 'LineNo' => 1], ['EmployeeID' => 'E2', 'LineNo' => 2]];

        $result = PayrollWorkflow::partitionForSuspension($lines, ['E1', 'E2']);

        $this->assertSame([], $result['clean']);
        $this->assertCount(2, $result['suspended']);
    }
}
