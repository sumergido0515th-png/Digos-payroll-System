<?php
/**
 * ============================================================================
 * Guard - The printed form's row geometry is one number written in five
 * places, and nothing but this test holds them together.
 *
 * WHY THIS EXISTS
 *   The printed payroll form has a fixed number of body rows. PrintDoc pads
 *   the body out to that number, the rule engine refuses a payroll carrying
 *   more lines than will fit, and MaxEmployeesPerPayroll stops one that large
 *   from being built at all. Change one copy and not the others and nothing
 *   fails: the payroll passes pre-audit, prints, and drops its last lines off
 *   the bottom of the form. The failure is only ever visible on paper.
 *
 *   Until this guard existed the coupling was a comment - one in RuleEngine
 *   saying "Must match PRINT_ROWS in app/PrintDoc.php", one in CLAUDE.md, and
 *   one in RuleEngineTest. A comment does not fail a build.
 *
 *   The seeded default in 0001 counts because applied migrations are
 *   immutable: if the geometry ever changes, a correcting migration is part
 *   of that change, and this is the test that says so.
 *
 *   An administrator can still set MaxEmployeesPerPayroll to something else
 *   at runtime. That is the rule engine's own PRINT_ROWS check to catch, not
 *   this guard's - here we only hold the source constants together.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PrintRowGeometryTest extends TestCase
{
    /** The declaration this guard treats as the source of truth. */
    private const CANONICAL = 'app/PrintDoc.php';

    public function testEveryCopyOfThePrintedRowCountAgrees(): void
    {
        $copies = self::everyCopy();
        $expected = $copies[0]['value'];

        foreach ($copies as $copy) {
            $this->assertSame($expected, $copy['value'],
                "The printed form's row count disagrees between " . self::CANONICAL
                . " ($expected) and {$copy['where']} ({$copy['value']}). All of them are "
                . 'load-bearing for the printed form geometry - change them together.');
        }
    }

    /**
     * The scan for getSetting() fallbacks must actually find some. If the
     * lookups are ever reworded, the loop above would still pass while
     * checking nothing, which is worse than no guard at all.
     */
    public function testTheSettingFallbackScanStillFindsSomething(): void
    {
        $this->assertNotEmpty(self::settingFallbacks(),
            "No hardcoded MaxEmployeesPerPayroll fallback found in any source file. "
            . 'Either they are all gone - remove that part of this guard - or the lookup '
            . 'was reworded and the pattern here needs updating.');
    }

    /**
     * Every place the row count is written down, canonical copy first.
     *
     * @return list<array{where: string, value: int}>
     */
    private static function everyCopy(): array
    {
        $copies = [
            [
                'where' => 'PRINT_ROWS in ' . self::CANONICAL,
                'value' => self::soleMatch(
                    '/\bconst\s+PRINT_ROWS\s*=\s*(\d+)\s*;/',
                    SourceTree::readCode(self::CANONICAL),
                    self::CANONICAL),
            ],
            [
                'where' => 'RuleEngine::PRINT_ROWS',
                'value' => self::soleMatch(
                    '/\bpublic\s+const\s+PRINT_ROWS\s*=\s*(\d+)\s*;/',
                    SourceTree::readCode('app/Domain/Rules/RuleEngine.php'),
                    'app/Domain/Rules/RuleEngine.php'),
            ],
            [
                'where' => "the seeded MaxEmployeesPerPayroll in migrations/0001_baseline_schema.sql",
                'value' => self::soleMatch(
                    "/\(\s*'MaxEmployeesPerPayroll'\s*,\s*'(\d+)'\s*\)/",
                    SourceTree::read('migrations/0001_baseline_schema.sql'),
                    'migrations/0001_baseline_schema.sql'),
            ],
        ];

        foreach (self::settingFallbacks() as $fallback) {
            $copies[] = [
                'where' => "the getSetting() fallback in {$fallback['file']}",
                'value' => $fallback['value'],
            ];
        }

        return $copies;
    }

    /**
     * Every hardcoded default written beside a MaxEmployeesPerPayroll lookup.
     * Read from comment-stripped source, so prose about the number and
     * commented-out code are not mistaken for a live fallback.
     *
     * @return list<array{file: string, value: int}>
     */
    private static function settingFallbacks(): array
    {
        $out = [];

        foreach (SourceTree::phpFiles() as $file) {
            preg_match_all(
                "/getSetting\(\s*'MaxEmployeesPerPayroll'\s*,\s*'(\d+)'\s*\)/",
                SourceTree::readCode($file), $m);

            foreach ($m[1] as $value) {
                $out[] = ['file' => $file, 'value' => (int) $value];
            }
        }

        return $out;
    }

    /**
     * The captured integer, failing loudly when the declaration no longer
     * matches - a guard that silently stops finding its subject is a guard
     * that passes forever.
     */
    private static function soleMatch(string $pattern, string $subject, string $file): int
    {
        if (!preg_match($pattern, $subject, $m)) {
            self::fail("Could not find the printed row count in $file - this guard has gone "
                . 'blind. Point the pattern in PrintRowGeometryTest at the new declaration.');
        }

        return (int) $m[1];
    }
}
