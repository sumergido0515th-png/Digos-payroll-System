<?php
/**
 * ============================================================================
 * PayrollPrintIsOfficialTest - which payroll statuses print as the official
 * document, and which print marked NOT OFFICIAL.
 *
 * WHY THIS EXISTS
 * This one boolean decides whether a piece of paper leaving the office can be
 * mistaken for an approved payroll. It is the narrowest piece of Phase 8's
 * print gating, brought forward because previewing a Draft from the workflow
 * would otherwise produce a printout indistinguishable from a real one.
 *
 * The function is pure - a status string in, a bool out - so every status the
 * workflow can reach is checked here without a database. The list below is
 * deliberately the whole of PAYROLL_FLOW rather than a sample: a status added
 * later and forgotten would default to whatever in_array() says, and the safe
 * answer for an unrecognised status is "not official".
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PayrollPrintIsOfficialTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Helpers first: the marking runs its label through esc(). None of the
        // three open a connection, so the unit suite stays database-free.
        require_once PROJECT_ROOT . '/app/Helpers.php';
        require_once PROJECT_ROOT . '/app/Domain/Workflow/PayrollWorkflow.php';
        require_once PROJECT_ROOT . '/app/PrintDoc.php';
    }

    public static function statuses(): array
    {
        return [
            'Draft is being prepared and has not been submitted' => ['DRAFT', false],
            'For pre-audit is submitted but not yet reviewed' => ['FOR_PRE_AUDIT', false],
            'Pre-audit approved is the official document' => ['PRE_AUDIT_APPROVED', true],
            'For printing is past sign-off, waiting to print' => ['FOR_PRINTING', true],
            'Printed is official and the form has been rendered' => ['PRINTED', true],
            'Submitted is approved and handed to the paying office' => ['SUBMITTED', true],
            'Suspended must never print as though it stands' => ['SUSPENDED', false],
            'Returned to preparer is not yet resubmitted' => ['RETURNED_TO_PREPARER', false],
            'Cancelled must never print as though it stands' => ['CANCELLED', false],
        ];
    }

    /**
     * @dataProvider statuses
     */
    public function testEveryWorkflowStatus(string $status, bool $expected): void
    {
        $this->assertSame($expected, \payrollPrintIsOfficial($status));
    }

    /** An unrecognised status fails to the safe side, not the convenient one. */
    public function testAnUnknownStatusIsNotOfficial(): void
    {
        $this->assertFalse(\payrollPrintIsOfficial(''));
        $this->assertFalse(\payrollPrintIsOfficial('ForPreAudit'));
    }

    /** Case matters: this system never stores a lower-cased status. */
    public function testTheComparisonIsExact(): void
    {
        $this->assertFalse(\payrollPrintIsOfficial('pre_audit_approved'));
        $this->assertFalse(\payrollPrintIsOfficial('Pre_Audit_Approved'));
    }

    /* -------------------------------------------------------- the marking */

    public function testAnOfficialPayrollGetsNoMarking(): void
    {
        $this->assertSame('', \reviewOverlayHtml('PRE_AUDIT_APPROVED'));
        $this->assertSame('', \reviewOverlayHtml('FOR_PRINTING'));
        $this->assertSame('', \reviewOverlayHtml('PRINTED'));
        $this->assertSame('', \reviewOverlayHtml('SUBMITTED'));
    }

    public function testAnUnapprovedPayrollIsMarkedAndNamesItsStatus(): void
    {
        $html = \reviewOverlayHtml('FOR_PRE_AUDIT');

        $this->assertStringContainsString('NOT OFFICIAL', $html);
        $this->assertStringContainsString('FOR_PRE_AUDIT', $html);
    }

    /** A suspended payroll is marked, the same as any other unofficial status. */
    public function testASuspendedPayrollIsMarked(): void
    {
        $html = \reviewOverlayHtml('SUSPENDED');

        $this->assertStringContainsString('NOT OFFICIAL', $html);
        $this->assertStringContainsString('SUSPENDED', $html);
    }

    public function testACancelledPayrollSaysSoRatherThanJustNotOfficial(): void
    {
        $html = \reviewOverlayHtml('CANCELLED');

        $this->assertStringContainsString('CANCELLED', $html);
        $this->assertStringContainsString('NOT FOR PAYMENT', $html);
    }

    /**
     * The marking has to reach paper. A rule that hid it when printing would
     * leave the screen honest and the printout not, which is the wrong way
     * round - the paper copy is the one that leaves the building.
     */
    public function testTheMarkingIsNotHiddenWhenPrinting(): void
    {
        $css = \reviewOverlayCss();

        // Checked by parsing the rules rather than by matching one spelling of
        // them. The first version of this test looked for the literal
        // '.review-strip{display:none' and passed happily when the marking was
        // hidden as '.review-strip,.review-wash{display:none' - a grouped
        // selector it had never considered. Any rule that targets the marking
        // is examined now, however it is written.
        foreach ($this->rulesTargetingTheMarking($css) as $selector => $body) {
            $this->assertStringNotContainsString('display:none', $body,
                "The rule '$selector' hides the review marking.");
            $this->assertStringNotContainsString('visibility:hidden', $body,
                "The rule '$selector' hides the review marking.");
        }

        $this->assertStringContainsString('print-color-adjust:exact', $css,
            'Without this the browser drops the strip background when printing.');
    }

    /** At least one rule must keep the marking on the page when printing. */
    public function testAPrintRuleExplicitlyKeepsTheMarking(): void
    {
        $css = \reviewOverlayCss();

        $this->assertSame(1, preg_match('/@media\s+print\s*\{/', $css),
            'No @media print block, so nothing guarantees the marking reaches paper.');

        $printBlock = substr($css, (int) strpos($css, '@media print'));
        $this->assertStringContainsString('review-', $printBlock,
            'The print block does not mention the marking at all.');
    }

    /**
     * Every CSS rule whose selector names one of the marking's classes.
     *
     * @return array<string,string> selector => declaration body
     */
    private function rulesTargetingTheMarking(string $css): array
    {
        preg_match_all('/([^{}]*review-[^{}]*)\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER);

        $rules = [];
        foreach ($matches as $match) {
            $rules[trim($match[1])] = $match[2];
        }

        $this->assertNotSame([], $rules, 'No rules target the marking - the regex has drifted.');
        return $rules;
    }

    /**
     * The marking must not be built from the WatermarkUrl / WatermarkOpacity
     * settings. PHASE_PLAN.md warns against exactly that: those are decorative
     * branding an administrator can blank, and a control asserting that a page
     * is not official cannot be switchable from a settings screen.
     */
    public function testTheMarkingIsNotDrivenByTheDecorativeWatermarkSettings(): void
    {
        $css = \reviewOverlayCss() . \reviewOverlayHtml('DRAFT');

        $this->assertStringNotContainsString('WatermarkUrl', $css);
        $this->assertStringNotContainsString('WatermarkOpacity', $css);
        $this->assertStringNotContainsString('assets/uploads', $css);
    }
}
