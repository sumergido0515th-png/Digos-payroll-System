<?php
/**
 * ============================================================================
 * ImageSettingsTest.php - The settings offered as image uploads and the
 * settings allowed to hold one must be the same set.
 *
 * IMAGE_SETTINGS in app/Settings.php is the server's allowlist: apiUploadImage
 * Setting refuses any key not in it with "That setting does not hold an image."
 * views/settings.php independently decides which settings render an upload
 * control, by tagging a descriptor 'image'. Nothing connected the two, so a
 * setting added to the form but not the allowlist offered a working-looking
 * file picker that refused every file - and the refusal names the setting, not
 * the omission, so it reads like a broken upload rather than a missing line.
 *
 * The Backlog logged this when the allowlist held two entries and asked for a
 * guard if it grew. It has since doubled - PrintLogoUrl and LoginBackgroundUrl
 * joined OfficeLogoUrl and WatermarkUrl - so this is that guard.
 *
 * Both directions matter. An allowlist entry with no upload control is the
 * mirror rot: a key that may hold an image that nothing can ever put one in.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ImageSettingsTest extends TestCase
{
    /**
     * Keys in the server's upload allowlist, parsed as text: app/Settings.php
     * is loaded by app/bootstrap.php, which opens a database connection.
     *
     * @return string[]
     */
    private function allowlistKeys(): array
    {
        $src = SourceTree::readCode('app/Settings.php');

        if (!preg_match('/const\s+IMAGE_SETTINGS\s*=\s*\[(.*?)\];/s', $src, $block)) {
            self::fail('Could not locate the IMAGE_SETTINGS table in app/Settings.php.');
        }

        preg_match_all("/'(\w+)'\s*=>/", $block[1], $m);
        sort($m[1]);
        return $m[1];
    }

    /**
     * Settings the Settings screen renders an image upload for. The
     * descriptors are [key, label, type, extra] and the type is the third
     * element, so an 'image' in third position is the form offering a file
     * picker for that key.
     *
     * @return string[]
     */
    private function uploadControlKeys(): array
    {
        $src = SourceTree::read('views/settings.php');

        preg_match_all("/\[\s*'(\w+)'\s*,\s*'[^']*'\s*,\s*'image'/", $src, $m);
        $keys = array_values(array_unique($m[1]));
        sort($keys);
        return $keys;
    }

    public function testEverySettingOfferedAsAnUploadMayActuallyHoldAnImage(): void
    {
        $missing = array_values(array_diff($this->uploadControlKeys(), $this->allowlistKeys()));

        $this->assertSame([], $missing,
            "views/settings.php offers an image upload for a setting IMAGE_SETTINGS does not allow.\n"
            . "apiUploadImageSetting will refuse every file with \"That setting does not hold an\n"
            . "image\", which reads to whoever hits it like a broken upload rather than a missing\n"
            . 'line. Add the key to IMAGE_SETTINGS in app/Settings.php with its filename prefix.');
    }

    public function testEveryAllowedImageSettingIsReachableFromTheForm(): void
    {
        $unreachable = array_values(array_diff($this->allowlistKeys(), $this->uploadControlKeys()));

        $this->assertSame([], $unreachable,
            "IMAGE_SETTINGS allows an upload for a setting the Settings screen never offers one for.\n"
            . "Nothing can put an image in it, so the entry is either a leftover from a removed\n"
            . "control or a control that was never added. Remove it from IMAGE_SETTINGS, or give it\n"
            . "an 'image' descriptor in views/settings.php.");
    }

    /**
     * The parsers are the whole test, so a silent parse failure - a reshaped
     * table matching nothing - would pass both assertions above by comparing
     * two empty sets.
     */
    public function testBothSidesParsedSomething(): void
    {
        $this->assertNotEmpty($this->allowlistKeys(),
            'Parsed no keys out of IMAGE_SETTINGS - the table was reshaped and this guard is blind.');
        $this->assertNotEmpty($this->uploadControlKeys(),
            "Parsed no 'image' descriptors out of views/settings.php - the descriptor shape changed "
            . 'and this guard is blind.');
    }
}
