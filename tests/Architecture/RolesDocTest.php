<?php
/**
 * ============================================================================
 * Guard - docs/ROLES.md is generated, and nothing ran the check that says so.
 *
 * WHY THIS EXISTS
 *   The role/permission matrix is Phase 2's second deliverable and the
 *   document anyone consults to answer "what can an Encoder actually do?".
 *   It is generated from PERMISSIONS in app/Auth.php precisely because a
 *   hand-written matrix is accurate the day it is written and wrong a month
 *   later - but the generator's own --check was wired into nothing: not CI,
 *   not this suite. It had drifted by a whole permission (`data.import`, from
 *   the bulk import work) and nobody heard.
 *
 *   It had also been quietly lying. The generator paired quotes over raw
 *   source, so the apostrophe in a comment reading "the encoder's day job" -
 *   sitting inside the Encoder's own block - desynced the pairing and ate
 *   that role's permission list. The published matrix showed the Encoder
 *   holding none of attachment.edit, dtr.edit, print.run and five others. It
 *   holds all of them. Enforcement was never affected; app/Auth.php is the
 *   live source and the application reads it directly. Only the document
 *   people reason about was wrong, and it was wrong in the direction that
 *   understates access.
 *
 *   This test runs the generator's own functions rather than reimplementing
 *   them. A check that renders the document a second way proves only that the
 *   two copies agree with each other.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class RolesDocTest extends TestCase
{
    /** Defines the generator's functions. Runs nothing - see its foot. */
    public static function setUpBeforeClass(): void
    {
        require_once PROJECT_ROOT . '/tools/generate-roles-doc.php';
    }

    public function testTheGeneratedMatrixIsCurrent(): void
    {
        $expected = \rolesDocMarkdown();
        $actual = SourceTree::read('docs/ROLES.md');

        $this->assertSame(
            \normaliseLineEndings($expected),
            \normaliseLineEndings($actual),
            'docs/ROLES.md no longer matches PERMISSIONS in app/Auth.php or the ROUTES '
            . 'table - run php tools/generate-roles-doc.php and commit the result.');
    }

    /**
     * Every parsed permission has to look like a permission.
     *
     * This is the guard against the desync itself rather than its output: a
     * captured fragment of punctuation means the quote pairing has slipped
     * again, and the matrix beyond that point is describing the wrong role.
     * Checking the rendered document alone would not catch it - the document
     * and the parse agree, because the document is built from the parse.
     */
    public function testNoParsedPermissionIsAFragmentOfPunctuation(): void
    {
        $permissions = \parsePermissions(
            \stripComments(SourceTree::read('app/Auth.php')));

        foreach ($permissions as $role => $held) {
            foreach ($held as $permission) {
                $this->assertMatchesRegularExpression(
                    '/^(\*|[a-z]+(\.[a-z]+)+)$/', $permission,
                    "Role '$role' parsed a permission of '"
                    . str_replace(["\r", "\n"], ['\r', '\n'], $permission)
                    . "', which is not a permission name. The quote pairing in "
                    . 'parsePermissions() has desynced - an apostrophe has most likely '
                    . 'reached it through something stripComments() does not remove.');
            }
        }
    }

    /**
     * The Encoder is the role the desync actually corrupted, and the one whose
     * entry a reader is most likely to trust without checking. Naming it keeps
     * the regression legible if it ever returns.
     */
    public function testTheEncoderRoleParsesItsFullPermissionList(): void
    {
        $permissions = \parsePermissions(
            \stripComments(SourceTree::read('app/Auth.php')));

        $this->assertArrayHasKey('Encoder', $permissions,
            'The Encoder role is gone from PERMISSIONS, or the parse lost it.');
        $this->assertContains('dtr.edit', $permissions['Encoder'],
            'The Encoder no longer parses as holding dtr.edit - keying the DTR grid is '
            . 'the whole of that role. This is what the apostrophe desync broke.');
    }
}
