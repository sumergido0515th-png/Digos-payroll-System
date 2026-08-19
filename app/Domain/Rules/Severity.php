<?php
/**
 * ============================================================================
 * Severity - what a finding does to the payroll it is about.
 *
 * Three tiers, and the difference between them is not "how bad" but "what
 * happens next". That distinction is the whole reason the engine is worth
 * building: a system that reports twenty problems of equal weight is a system
 * whose users learn to click past all twenty.
 *
 *   BLOCKER  Print is disabled and there is NO override. These are the
 *            findings where proceeding produces a document that is wrong on
 *            its face - a negative net pay, a rate that contradicts the
 *            contract, a line charged to an office the preparer cannot see.
 *
 *   WARNING  Allowed to proceed, with a justification that is logged. Most
 *            document-integrity findings are here: a missing bio exemption is
 *            usually a filing delay rather than a fabrication, and blocking
 *            payroll for it would mean people are not paid because a scan is
 *            late.
 *
 *   INFO     Advisory. Worth a pre-auditor's eye, not worth stopping for.
 *
 * MOVING A RULE BETWEEN TIERS IS A POLICY DECISION, not a tuning knob. The
 * catalogue in docs/RULES.md records the tier and the reasoning for each, and
 * a change to either belongs there as well as here.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Rules;

final class Severity
{
    public const BLOCKER = 'BLOCKER';
    public const WARNING = 'WARNING';
    public const INFO = 'INFO';

    /** Most severe first, which is the order findings are presented in. */
    public const ORDER = [self::BLOCKER, self::WARNING, self::INFO];

    /** Rank for sorting; lower is more severe. */
    public static function rank(string $severity): int
    {
        $rank = array_search($severity, self::ORDER, true);

        // An unknown severity sorts last rather than first. A rule added with
        // a typo'd tier should be easy to spot at the bottom of the list, not
        // promoted above every real blocker.
        return $rank === false ? count(self::ORDER) : $rank;
    }

    /**
     * Whether a set of findings prevents printing.
     *
     * @param array<int, Finding> $findings
     */
    public static function blocks(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding->severity === self::BLOCKER) return true;
        }
        return false;
    }
}
