<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\services;

use coyshdigital\shortio\models\Settings;
use coyshdigital\shortio\Plugin;
use coyshdigital\shortio\records\LinkRecord;
use Craft;
use yii\base\Component;

/**
 * Click statistics.
 *
 * These live on statistics.short.io rather than api.short.io, and come back in
 * a different shape from the link endpoints. The one bonus over Dub is
 * humanClicks: bot traffic told apart from the real thing.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Stats extends Component
{
    // Constants
    // =========================================================================

    /**
     * Short.io's own default, and the only sensible one here: period=total
     * reports 0 even for links with plenty of clicks, so it cannot be trusted
     * as a lifetime figure.
     */
    public const PERIOD_DEFAULT = 'last30';

    public const PERIODS = [
        'today',
        'yesterday',
        'total',
        'week',
        'month',
        'lastmonth',
        'last7',
        'last30',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Returns totals for a link.
     *
     * @param string $identifier
     * @param string $period
     * @return array|null [totalClicks, humanClicks]
     */
    public function get(string $identifier, string $period = self::PERIOD_DEFAULT): ?array
    {
        if (!in_array($period, self::PERIODS, true)) {
            $period = self::PERIOD_DEFAULT;
        }

        $cache = Craft::$app->getCache();
        $key = "short-io:clicks:{$identifier}:{$period}";
        $cached = $cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $result = Plugin::getInstance()->client->statistics($identifier, [
            'period' => $period,
            'tz' => Craft::$app->getTimeZone(),
            // The sidebar only ever wants totals, and skipping the top-N
            // breakdowns makes the call markedly cheaper.
            'skipTops' => 'true',
        ]);

        if (!$result->isOk() || !is_array($result->data)) {
            return null;
        }

        $totals = [
            'totalClicks' => (int)($result->data['totalClicks'] ?? 0),
            'humanClicks' => (int)($result->data['humanClicks'] ?? 0),
        ];

        $cache->set($key, $totals, $this->_ttlFor($period));

        return $totals;
    }

    /**
     * Returns totals for a stored link, falling back to the numeric id when the
     * idString isn't recognised.
     *
     * @param LinkRecord $record
     * @param string $period
     * @return array|null
     */
    public function getForRecord(LinkRecord $record, string $period = self::PERIOD_DEFAULT): ?array
    {
        $totals = $this->get($record->linkIdString, $period);

        if ($totals === null && $record->linkId !== null && $record->linkId !== '') {
            $totals = $this->get($record->linkId, $period);
        }

        return $totals;
    }

    /**
     * Refreshes the snapshot columns, oldest first.
     *
     * The Links index reads those columns rather than calling the API per row -
     * a 50-row page would otherwise be 50 requests to a second host.
     *
     * @param int $limit
     * @return int How many rows were refreshed.
     */
    public function refreshSnapshots(int $limit = 500): int
    {
        Plugin::getInstance()->client->setAllowSleep(true);

        /** @var LinkRecord[] $records */
        $records = LinkRecord::find()
            ->orderBy(['clicksUpdated' => SORT_ASC])
            ->limit($limit)
            ->all();

        $count = 0;

        foreach ($records as $record) {
            $totals = $this->getForRecord($record);

            if ($totals === null) {
                continue;
            }

            $record->clicks = $totals['totalClicks'];
            $record->humanClicks = $totals['humanClicks'];
            $record->clicksUpdated = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $record->save(false);
            $count++;
        }

        return $count;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string $period
     * @return int
     */
    private function _ttlFor(string $period): int
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        // Today's number moves; a lifetime total barely does.
        return $period === 'today' ? min(300, $settings->statsCacheDuration) : $settings->statsCacheDuration;
    }
}
