<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\jobs;

use coyshdigital\shortio\Plugin;
use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;

/**
 * Retries a link sync that failed during an entry save.
 *
 * Only ever queued when the failureMode setting is 'warn' - in 'block' mode the
 * save is vetoed instead, so there's nothing to retry.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class SyncLink extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int|null The canonical entry id.
     */
    public ?int $entryId = null;

    /**
     * @var int|null The site id.
     */
    public ?int $siteId = null;

    /**
     * @var string|null An explicit path to claim, if there was one.
     */
    public ?string $path = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        if ($this->entryId === null || $this->siteId === null) {
            return;
        }

        $entry = Entry::find()
            ->id($this->entryId)
            ->siteId($this->siteId)
            ->status(null)
            ->one();

        if (!$entry instanceof Entry) {
            return;
        }

        $links = Plugin::getInstance()->links;

        // Go straight at sync() rather than re-entering through the save events.
        $links->suspend();
        Plugin::getInstance()->client->setAllowSleep(true);

        try {
            $error = $links->sync($entry, $this->path);

            if ($error !== null) {
                throw new \RuntimeException($error);
            }
        } finally {
            $links->resume();
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('short-io', 'Syncing a short link');
    }
}
