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
use craft\queue\BaseJob;

/**
 * Brings the click snapshots the Links screen reads back up to date.
 *
 * Queued by the Links screen itself when it renders rows whose figures have
 * gone stale, so click counts keep themselves current without anyone having to
 * schedule short-io/links/refresh-stats.
 *
 * @author Coysh Digital
 * @since 1.0.4
 */
class RefreshStats extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int[] The link row ids to refresh. Empty means the oldest snapshots.
     */
    public array $ids = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $ids = array_values(array_filter(array_map('intval', $this->ids)));

        Plugin::getInstance()->stats->refreshSnapshots(
            $ids !== [] ? count($ids) : 100,
            $ids !== [] ? $ids : null
        );
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('short-io', 'Refreshing short link click counts');
    }
}
