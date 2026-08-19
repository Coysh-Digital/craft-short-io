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
     * @param Entry|null $entry
     * @param string $period
     * @return array|null
     */
    public function clicks(?Entry $entry = null, string $period = 'total'): ?array
    {
        $record = $this->_record($entry);

        return $record !== null ? Plugin::getInstance()->stats->getForRecord($record, $period) : null;
    }

    /**
     * Returns something usable as an <img src> for an entry's QR code.
     *
     * Short.io has no public QR URL, so what comes back depends on where it's
     * being rendered: a control panel action URL in the CP, a signed site URL
     * when the qrPublic setting is on, and otherwise a data URI - which keeps
     * front-end QR codes working with no public endpoint at all.
     *
     * @param Entry|null $entry
     * @param array $options
     * @return string|null
     */
    public function qrSrc(?Entry $entry = null, array $options = []): ?string
    {
        $record = $this->_record($entry);

        if ($record === null) {
            return null;
        }

        $qr = Plugin::getInstance()->qr;

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            return $qr->getCpUrl($record->linkIdString, $options);
        }

        return $qr->getSignedUrl($record->linkIdString, $options)
            ?? $qr->getDataUri($record->linkIdString, $options);
    }

    /**
     * Returns the raw QR bytes for an entry, for templates that want to write
     * their own file.
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
