<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\variables;

use coyshdigital\shortio\Plugin;
use Craft;
use craft\elements\Entry;

/**
 * The craft.shortIo Twig variable.
 *
 * Every method resolves through getCanonicalId(), so a draft returns its
 * canonical entry's link rather than nothing.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class ShortIoVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the short URL for an entry.
     *
     * @param Entry|null $entry
     * @return string|null
     */
    public function link(?Entry $entry = null): ?string
    {
        $record = $this->_record($entry);

        return $record?->shortUrl;
    }

    /**
     * Returns just the path portion of an entry's short link.
     *
     * @param Entry|null $entry
     * @return string|null
     */
    public function path(?Entry $entry = null): ?string
    {
        return $this->_record($entry)?->path;
    }

    /**
     * Returns click totals for an entry.
     *
     * Defaults to the last 30 days, which is Short.io's own default. Note that
     * period 'total' reports 0 regardless of a link's real traffic, so it is
     * not a usable lifetime figure.
     *
     * @param Entry|null $entry
     * @param string $period
     * @return array|null
     */
    public function clicks(?Entry $entry = null, string $period = 'last30'): ?array
    {
        $record = $this->_record($entry);

        return $record !== null ? Plugin::getInstance()->stats->getForRecord($record, $period) : null;
    }

    /**
     * Returns the public image URL for an entry's QR code.
     *
     * Short.io serves QR images from a public URL, so this drops straight into
     * an <img> tag on the front end and is cached by browsers and CDNs like any
     * other image. The first call for a link generates it.
     *
     * @param Entry|null $entry
     * @param array $options
     * @return string|null
     */
    public function qrUrl(?Entry $entry = null, array $options = []): ?string
    {
        return Plugin::getInstance()->qr->getUrlForRecord($this->_record($entry), $options);
    }

    /**
     * Alias of qrUrl(), for templates that read better as a src.
     *
     * @param Entry|null $entry
     * @param array $options
     * @return string|null
     */
    public function qrSrc(?Entry $entry = null, array $options = []): ?string
    {
        return $this->qrUrl($entry, $options);
    }

    /**
     * Returns the raw QR image bytes, for templates that want to write the file
     * somewhere themselves.
     *
     * @param Entry|null $entry
     * @param array $options
     * @return string|null
     */
    public function qrBytes(?Entry $entry = null, array $options = []): ?string
    {
        $record = $this->_record($entry);

        return $record !== null ? Plugin::getInstance()->qr->getBytes($record->linkIdString, $options) : null;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param Entry|null $entry
     * @return \coyshdigital\shortio\records\LinkRecord|null
     */
    private function _record(?Entry $entry)
    {
        if ($entry === null) {
            return null;
        }

        return Plugin::getInstance()->links->getLink($entry->getCanonicalId(), $entry->siteId);
    }
}
