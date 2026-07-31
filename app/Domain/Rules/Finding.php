<?php
/**
 * ============================================================================
 * Finding - one thing the pre-audit noticed.
 *
 * A plain value object rather than an array, because a finding is passed
 * between the engine, the workflow, the print gate and the screen, and each of
 * those would otherwise be free to invent its own key names. `severity` and
 * `ruleId` in particular are read by Phase 7's transition guard and Phase 8's
 * print gate; a typo in an array key there fails open.
 *
 * SUBJECT IS OPTIONAL AND DELIBERATELY LOOSE. Some findings are about one
 * payroll line (a rate that does not match a contract), some about one
 * employee across the period (overtime beyond what was authorised), and some
 * about the payroll as a whole (an empty signatory block). Forcing all three
 * into an employee id would make the last kind lie.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Rules;

final class Finding
{
    public function __construct(
        public readonly string $ruleId,
        public readonly string $severity,
        public readonly string $message,
        public readonly string $employeeId = '',
        public readonly string $date = '',
        /** @var array<string, mixed> whatever the rule wants the reader to see */
        public readonly array $context = []
    ) {
    }

    /**
     * The shape the API and the tests use.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'RuleID' => $this->ruleId,
            'Severity' => $this->severity,
            'Message' => $this->message,
            'EmployeeID' => $this->employeeId,
            'Date' => $this->date,
            'Context' => $this->context,
        ];
    }
}
