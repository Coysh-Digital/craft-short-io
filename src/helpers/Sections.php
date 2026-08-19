<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\helpers;

use Craft;
use craft\elements\Entry;

/**
 * Craft 4 / Craft 5 compatibility shim for the sections service.
 *
 * Craft 5 folded craft\services\Sections into craft\services\Entries and removed
 * Craft::$app->getSections() outright, so a plugin that supports both versions
 * can't call either one directly.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Sections
{
    // Static Properties
    // =========================================================================

    /**
     * @var bool|null Memoised result of the version check.
     */
    private static ?bool $_isCraft5 = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns whether we're running on Craft 5 or later.
     *
     * @return bool
     */
    public static function isCraft5(): bool
    {
        if (self::$_isCraft5 === null) {
            self::$_isCraft5 = version_compare(Craft::$app->getVersion(), '5.0', '>=');
        }

        return self::$_isCraft5;
    }

    /**
     * Returns every section, on either Craft version.
     *
     * @return array
     */
    public static function all(): array
    {
        if (self::isCraft5()) {
            /** @phpstan-ignore-next-line Craft 5 only. */
            return Craft::$app->getEntries()->getAllSections();
        }

        /** @phpstan-ignore-next-line Craft 4 only. */
        return Craft::$app->getSections()->getAllSections();
    }

    /**
     * Returns the section an entry belongs to, or null.
     *
     * On Craft 5 an entry nested inside a Matrix field is still a
     * craft\elements\Entry but has no section at all, and getSection() throws
     * rather than returning null. Callers want "no section" either way.
     *
     * @param Entry $entry
     * @return mixed
     */
    public static function forEntry(Entry $entry): mixed
    {
        try {
            return $entry->getSection();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Returns section options for a checkboxSelectField, limited to sections
     * that have URLs somewhere (a section with no URLs can't be short-linked).
     *
     * @return array
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::all() as $section) {
            foreach ($section->getSiteSettings() as $siteSettings) {
                if ($siteSettings->hasUrls) {
                    $options[] = [
                        'label' => $section->name,
                        'value' => $section->handle,
                    ];
                    break;
                }
            }
        }

        return $options;
    }
}
