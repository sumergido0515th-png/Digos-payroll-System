<?php
/**
 * ============================================================================
 * ScopeGrantAdminTest - Issuing and revoking scope, through the application.
 *
 * Before this module existed, migration 0016 seeded a wildcard grant per
 * administrator and every grant after that was an INSERT typed by hand. That
 * left the control that decides who sees which office's payroll with no audit
 * trail of its own administration, and no way to onboard a user without
 * database credentials.
 *
 * The two tests that matter are the ones about locking yourself out and about
 * the blank form: both are cases where the obvious implementation is wrong in
 * the dangerous direction.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\ScopeGrantRepo;
use PHPUnit\Framework\TestCase;

final class ScopeGrantAdminTest extends TestCase
{
    private const OFFICE = 'ZZGRANT';
    private const ADMIN = 'zz-grant-admin@digos.gov.ph';
    private const STAFF = 'zz-grant-staff@digos.gov.ph';

    protected function setUp(): void
    {
        if (!TestDatabase::isAvailable()) {
            $this->markTestSkipped('No test database reachable. Run php tools/migrate.php first.');
        }
        ApplicationLayer::load();
        $this->removeFixture();
        $this->createFixture();
        ScopeGrantRepo::forget();
    }

    protected function tearDown(): void
    {
        if (defined('DB_NAME')) $this->removeFixture();
        ScopeGrantRepo::forget();
    }

    private function createFixture(): void
    {
        $db = TestDatabase::connect();

        $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
            ->execute([self::OFFICE, 'Grant fixture', 'Active']);

        foreach ([[self::ADMIN, 'Admin'], [self::STAFF, 'Encoder']] as [$email, $role]) {
            $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                          VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$email, 'Grant fixture ' . $role, $role, '', 'Active', 'x']);
        }

        // The administrator's own wildcard grant, as 0016 seeds it.
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, CanRead, CanWrite) VALUES (?, ?, 1, 1)')
            ->execute(['SG-ZZGRANT-ADM', self::ADMIN]);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM ScopeGrants WHERE UserEmail LIKE 'zz-grant-%@digos.gov.ph'");
        $db->exec("DELETE FROM Users WHERE Email LIKE 'zz-grant-%@digos.gov.ph'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode = '" . self::OFFICE . "'");
    }

    private function admin(): array
    {
        return [
            'Email' => self::ADMIN,
            'FullName' => 'Grant fixture Admin',
            'Role' => 'Admin',
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS['Admin'],
        ];
    }

    private function staff(): array
    {
        return [
            'Email' => self::STAFF,
            'FullName' => 'Grant fixture Encoder',
            'Role' => 'Encoder',
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS['Encoder'],
        ];
    }

    /** The onboarding path the system did not have: grant, then read. */
    public function testIssuingAGrantGivesAUserAccessTheyDidNotHave(): void
    {
        $before = \apiListPayrolls([], $this->staff());
        $this->assertSame([], $before, 'A user with no grant should see nothing.');

        \apiSaveScopeGrant([
            'UserEmail' => self::STAFF,
            'OfficeCode' => self::OFFICE,
            'CanRead' => '1',
            'Remarks' => 'Covers the market office',
        ], $this->admin());
        ScopeGrantRepo::forget();

        $grants = \apiListScopeGrants(['UserEmail' => self::STAFF], $this->admin());
        $this->assertCount(1, $grants);
        $this->assertStringContainsString('office ' . self::OFFICE, $grants[0]['Covers']);
    }

    /** GrantedBy is the column that was never written while this was SQL-only. */
    public function testTheGrantRecordsWhoIssuedIt(): void
    {
        $saved = \apiSaveScopeGrant([
            'UserEmail' => self::STAFF, 'OfficeCode' => self::OFFICE, 'CanRead' => '1',
        ], $this->admin());

        $grant = ScopeGrantRepo::find($saved['GrantID']);

        $this->assertSame(self::ADMIN, $grant['GrantedBy'],
            'The grant does not record who issued it - the control has no audit trail.');
    }

    /**
     * Blank means wildcard, so an empty form is the widest grant in the system
     * rather than the narrowest. The summary has to say so in words, because a
     * row of blank cells on screen reads as an unfinished record.
     */
    public function testABlankGrantIsDescribedAsCoveringEverything(): void
    {
        $saved = \apiSaveScopeGrant([
            'UserEmail' => self::STAFF, 'CanRead' => '1',
        ], $this->admin());

        $this->assertStringContainsString('EVERYTHING', $saved['Covers']);
    }

    /**
     * The lockout this module exists to prevent, reintroduced by the obvious
     * implementation of "delete a row". Revoking your own last grant leaves you
     * unable to reach the screen that would undo it, and the only way back is
     * the database.
     */
    public function testAnAdministratorCannotRevokeTheirOwnLastGrant(): void
    {
        try {
            \apiDeleteScopeGrant(['GrantID' => 'SG-ZZGRANT-ADM'], $this->admin());
            $this->fail('An administrator revoked their own last grant and locked themselves out.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('lock you out', $e->getMessage());
        }

        $this->assertNotNull(ScopeGrantRepo::find('SG-ZZGRANT-ADM'));
    }

    /** But a second grant makes the first safe to remove. */
    public function testRevokingIsAllowedWhenAnotherGrantRemains(): void
    {
        \apiSaveScopeGrant([
            'UserEmail' => self::ADMIN, 'OfficeCode' => self::OFFICE, 'CanRead' => '1',
        ], $this->admin());

        \apiDeleteScopeGrant(['GrantID' => 'SG-ZZGRANT-ADM'], $this->admin());

        $this->assertNull(ScopeGrantRepo::find('SG-ZZGRANT-ADM'));
    }

    /** A grant that permits neither reading nor writing is not a grant. */
    public function testAGrantMustAllowSomething(): void
    {
        $this->expectExceptionMessage('has to allow reading or writing');

        \apiSaveScopeGrant(['UserEmail' => self::STAFF], $this->admin());
    }

    public function testValidToMustNotPrecedeValidFrom(): void
    {
        $this->expectExceptionMessage('Valid To must fall on or after Valid From');

        \apiSaveScopeGrant([
            'UserEmail' => self::STAFF, 'CanRead' => '1',
            'ValidFrom' => '2026-08-01', 'ValidTo' => '2026-07-01',
        ], $this->admin());
    }

    public function testAnUnknownRoleIsRefused(): void
    {
        $this->expectExceptionMessage('Unknown role');

        \apiSaveScopeGrant([
            'UserEmail' => self::STAFF, 'CanRead' => '1', 'RoleCode' => 'Viewer',
        ], $this->admin());
    }

    /** Editing must update the row rather than issue a second one. */
    public function testSavingWithAnIdUpdatesInPlace(): void
    {
        $saved = \apiSaveScopeGrant([
            'UserEmail' => self::STAFF, 'OfficeCode' => self::OFFICE, 'CanRead' => '1',
        ], $this->admin());

        \apiSaveScopeGrant([
            'GrantID' => $saved['GrantID'],
            'UserEmail' => self::STAFF, 'OfficeCode' => self::OFFICE,
            'CanRead' => '1', 'CanWrite' => '1',
        ], $this->admin());

        $this->assertCount(1, \apiListScopeGrants(['UserEmail' => self::STAFF], $this->admin()));
        $this->assertSame(1, (int) ScopeGrantRepo::find($saved['GrantID'])['CanWrite']);
    }

    /** An expired grant is listed, and visibly marked as not live. */
    public function testAnExpiredGrantIsFlaggedInTheListing(): void
    {
        \apiSaveScopeGrant([
            'UserEmail' => self::STAFF, 'OfficeCode' => self::OFFICE, 'CanRead' => '1',
            'ValidTo' => date('Y-m-d', strtotime('-1 day')),
        ], $this->admin());

        $grants = \apiListScopeGrants(['UserEmail' => self::STAFF], $this->admin());

        $this->assertFalse($grants[0]['IsLive']);
    }
}
