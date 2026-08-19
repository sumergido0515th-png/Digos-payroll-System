<?php
/**
 * ============================================================================
 * AuditSummaryTest - auditSummary() in app/Helpers.php.
 *
 * WHY THIS EXISTS
 * apiApprovePayroll's re-authentication and apiSaveUser's password change both
 * post a `Password` field, and both actions are logged - api.php writes
 * auditSummary($payload) into Logs.Details on every mutating route. Until
 * SENSITIVE_PAYLOAD_KEYS existed, every approval left the pre-auditor's own
 * password sitting in a table `log.view` lets several roles read.
 *
 * Pure - arrays in, array out, no DB:: - so this is tested without a database,
 * the same way the inline-image redaction already was.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AuditSummaryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once PROJECT_ROOT . '/app/Helpers.php';
    }

    public function testPasswordIsRedacted(): void
    {
        $result = \auditSummary(['PayrollNo' => 'PR-2026-000001', 'Password' => 'hunter2']);

        $this->assertSame('<redacted>', $result['Password']);
        $this->assertStringNotContainsString('hunter2', json_encode($result));
    }

    public function testFieldsOtherThanThePasswordListAreUntouched(): void
    {
        $result = \auditSummary(['PayrollNo' => 'PR-2026-000001', 'Email' => 'user@digos.gov.ph']);

        $this->assertSame('PR-2026-000001', $result['PayrollNo']);
        $this->assertSame('user@digos.gov.ph', $result['Email']);
    }

    /** The pre-existing inline-image redaction must survive this change. */
    public function testInlineDataUrlsAreStillRedacted(): void
    {
        $result = \auditSummary(['PhotoURL' => 'data:image/png;base64,AAAABBBB']);

        $this->assertStringContainsString('inline data', $result['PhotoURL']);
        $this->assertStringNotContainsString('AAAABBBB', $result['PhotoURL']);
    }

    public function testAPayloadWithNeitherSecretIsReturnedAsIs(): void
    {
        $payload = ['OfficeCode' => 'CMO', 'Status' => 'Active'];

        $this->assertSame($payload, \auditSummary($payload));
    }
}
