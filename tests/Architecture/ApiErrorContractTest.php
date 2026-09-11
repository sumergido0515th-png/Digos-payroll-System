<?php
/**
 * ============================================================================
 * ApiErrorContractTest.php - only a message written for a person may be
 * returned verbatim.
 *
 * public/api.php decides that by asking whether the throwable is a
 * RuntimeException, which is the documented contract: CLAUDE.md says a
 * RuntimeException carries a message "written for a timekeeper, not a
 * developer", and everything else is logged to ErrorLog and answered with a
 * reference.
 *
 * PDOException breaks that test by being true for it. It extends
 * RuntimeException in PHP, so for a while every database error took the safe
 * branch and handed the driver's own words to whoever tripped it -
 * "SQLSTATE[22001]: String data, right truncated: 1406 Data too long for
 * column 'PayrollNo' at row 1" - while writing nothing to ErrorLog at all.
 * That is precisely the leak the branch was added to stop, and PDOException
 * was named in its own commit message as a thing to catch.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApiErrorContractTest extends TestCase
{
    /**
     * The language fact the guard below exists for.
     *
     * If a future PHP ever stops making PDOException a RuntimeException this
     * fails, which is the right outcome: it says the exclusion is no longer
     * load-bearing rather than leaving a mystery in the dispatcher.
     */
    public function testPdoExceptionIsARuntimeException(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new PDOException('probe'),
            'PDOException is no longer a RuntimeException - re-read api.php\'s catch block, '
            . 'the explicit exclusion there may now be unnecessary.');
    }

    public function testTheDispatcherExcludesPdoExceptionFromTheVerbatimBranch(): void
    {
        $src = SourceTree::readCode('public/api.php');

        $this->assertMatchesRegularExpression(
            '/\$e\s+instanceof\s+RuntimeException\s*&&\s*!\s*\$e\s+instanceof\s+PDOException/',
            $src,
            "public/api.php must not return a PDOException's message verbatim.\n"
            . "PDOException extends RuntimeException, so a bare `instanceof RuntimeException`\n"
            . "sends every database error - SQLSTATE text, table and column names - straight to\n"
            . "the browser and logs none of it. Keep the explicit exclusion so driver messages\n"
            . 'fall through to logUnexpectedError() with the rest of the unintended throwables.');
    }

    /**
     * The generic answer must not carry the throwable's own message, or
     * excluding PDOException above would achieve nothing.
     */
    public function testTheGenericBranchDoesNotEchoTheMessage(): void
    {
        $src = SourceTree::readCode('public/api.php');

        if (!preg_match('/\}\s*else\s*\{(.*?)\n\}/s', $src, $m)) {
            self::fail('Could not locate the else branch of api.php\'s catch block.');
        }

        $this->assertStringNotContainsString('fail($e->getMessage())', $m[1],
            'The unexpected-error branch must answer with a reference, never the throwable\'s '
            . 'own message.');
    }
}
