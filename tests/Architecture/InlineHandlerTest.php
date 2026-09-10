<?php
/**
 * ============================================================================
 * InlineHandlerTest.php - an inline event handler is only ever built by
 * jsCall().
 *
 * Row actions used to concatenate the handler by hand -
 * `'Pages.x.edit(\'' + id + '\')'` - at thirty-odd call sites, which made
 * escaping every caller's job. Seven did it and twenty-seven did not, and the
 * ones that did were still only half protected: an on* attribute is
 * HTML-decoded before its contents are parsed as JavaScript, so esc()'s
 * &#39; decodes back to an apostrophe and ends the JS string anyway.
 *
 * It was reachable. PayrollPrefix is embedded verbatim in every payroll
 * number by nextPayrollNo(), and apiSaveSettings validated nothing, so a
 * prefix of `P" z="` produced payroll numbers whose row buttons rendered with
 * an extra parsed attribute - proven in a browser, attribute list
 * class,onclick,z - which is arbitrary attribute injection one substitution
 * away from an event handler.
 *
 * jsCall() is the single encoder (JSON.stringify for the JavaScript layer,
 * esc() for the HTML layer). This fails if anything builds an on* attribute
 * without it, because the next hand-built one will not be reviewed as
 * carefully as this comment.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class InlineHandlerTest extends TestCase
{
    /** An on* attribute being opened inside built markup: onclick=" and friends. */
    private const HANDLER_ATTRIBUTE = '/\bon[a-z]+\s*=\s*"/i';

    /** @return string[] */
    private function frontendFiles(): array
    {
        $files = glob(PROJECT_ROOT . '/views/*.php') ?: [];
        $files[] = PROJECT_ROOT . '/public/assets/js/app.js';

        return array_values(array_filter($files, 'is_file'));
    }

    public function testEveryInlineHandlerIsBuiltByJsCall(): void
    {
        $offenders = [];

        foreach ($this->frontendFiles() as $path) {
            $lines = explode("\n", (string) file_get_contents($path));

            foreach ($lines as $i => $line) {
                if (!preg_match(self::HANDLER_ATTRIBUTE, $line)) continue;

                // jsCall() may sit on the next line - the handler body is
                // sometimes concatenated across a line break for width.
                $window = $line . "\n" . ($lines[$i + 1] ?? '');
                if (!str_contains($window, 'jsCall(')) {
                    $offenders[] = SourceTree::relative($path) . ':' . ($i + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertSame([], $offenders,
            "An inline event handler was built without jsCall().\n"
            . "Concatenating a value into an on* attribute is how a control number became an\n"
            . "extra attribute on a button. esc() is not sufficient on its own here: the\n"
            . "attribute is HTML-decoded before its contents are parsed as JavaScript, so an\n"
            . "escaped apostrophe decodes back and still ends the string it was inside.\n"
            . 'Use jsCall(fn, args), or actionBtn(icon, fn, args, cls), which calls it.');
    }

    /**
     * The encoder has to actually be there for the rule above to mean
     * anything, and it has to keep both halves - the JavaScript one and the
     * HTML one. Dropping either silently reopens the hole for every caller at
     * once.
     */
    public function testJsCallEncodesForBothLayers(): void
    {
        $src = (string) file_get_contents(PROJECT_ROOT . '/public/assets/js/app.js');

        if (!preg_match('/function jsCall\s*\([^)]*\)\s*\{(.*?)\n\}/s', $src, $m)) {
            self::fail('Could not locate jsCall() in public/assets/js/app.js.');
        }

        $this->assertStringContainsString('JSON.stringify', $m[1],
            'jsCall() must JSON.stringify each argument - that is the JavaScript-string half.');
        $this->assertStringContainsString('esc(', $m[1],
            'jsCall() must esc() the finished call - that is the HTML-attribute half.');
    }
}
