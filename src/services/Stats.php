<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\services;

use coyshdigital\shortio\jobs\RefreshStats;
use coyshdigital\shortio\models\Settings;
use coyshdigital\shortio\Plugin;
use coyshdigital\shortio\records\LinkRecord;
use Craft;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeZone;
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

    /**
     * How long one queued refresh suppresses the next. Paging or searching the
     * Links screen shouldn't stack up overlapping jobs for the same rows.
     */
    private const QUEUE_GUARD = 'short-io:stats:queued';
    private const QUEUE_GUARD_TTL = 120;

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

        // Opening an entry pays for this call anyway, so let the Links screen's
        // snapshot have the answer too.
        if ($totals !== null && $period === self::PERIOD_DEFAULT) {
            $this->_storeSnapshot($record, $totals);
        }

        return $totals;
    }

    /**
     * Returns whether a record's snapshot is old enough to be worth refetching.
     *
     * @param LinkRecord $record
     * @return bool
     */
    public function isStale(LinkRecord $record): bool
    {
        if ($record->clicksUpdated === null || $record->clicksUpdated === '') {
            return true;
        }

        $updated = DateTimeHelper::toDateTime($record->clicksUpdated);

        if (!$updated instanceof \DateTimeInterface) {
            return true;
        }

        // Never more often than the figures themselves are cached - a shorter
        // window would queue work that can only answer from the cache.
        return (time() - $updated->getTimestamp()) >= max(60, $this->_settings()->statsCacheDuration);
    }

    /**
     * Queues a background refresh for whichever of these records has gone
     * stale, so the Links screen stays current without a scheduled command.
     *
     * @param LinkRecord[] $records
     * @return int How many rows were queued.
     */
    public function queueRefresh(array $records): int
    {
        if (!Plugin::getInstance()->client->isConfigured()) {
            return 0;
        }

        $ids = [];

        foreach ($records as $record) {
            if ($this->isStale($record)) {
                $ids[] = (int)$record->id;
            }
        }

        if ($ids === []) {
            return 0;
        }

        try {
            // add() rather than set(): only the first caller through the gate
            // gets to queue, so a burst of requests makes one job, not ten.
            if (!Craft::$app->getCache()->add(self::QUEUE_GUARD, true, self::QUEUE_GUARD_TTL)) {
                return 0;
            }

            Craft::$app->getQueue()->push(new RefreshStats(['ids' => $ids]));
        } catch (\Throwable $e) {
            // A queue that won't accept the job is not worth a 500 on a listing.
            Craft::warning('Short.io couldn’t queue a statistics refresh: ' . $e->getMessage(), __METHOD__);

            return 0;
        }

        return count($ids);
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
    public function refreshSnapshots(int $limit = 500, ?array $ids = null): int
    {
        if ($ids !== null && $ids === []) {
            return 0;
        }

        Plugin::getInstance()->client->setAllowSleep(true);

        $query = LinkRecord::find()
            ->orderBy(['clicksUpdated' => SORT_ASC])
            ->limit($limit);

        if ($ids !== null) {
            $query->andWhere(['id' => $ids]);
        }

        /** @var LinkRecord[] $records */
        $records = $query->all();
        $count = 0;

        foreach ($records as $record) {
            // getForRecord() writes the snapshot itself.
            if ($this->getForRecord($record) !== null) {
                $count++;
            }
        }

        return $count;
    }

    // Private Methods
    // =========================================================================

    /**
     * Writes the figures onto the record, but only when they actually say
     * something new - the Links screen reads these columns on every render, and
     * an unchanged count isn't worth a write.
     *
     * @param LinkRecord $record
     * @param array $totals
     * @return void
     */
    private function _storeSnapshot(LinkRecord $record, array $totals): void
    {
        $changed = $record->clicks !== $totals['totalClicks']
            || $record->humanClicks !== $totals['humanClicks'];

        if (!$changed && !$this->isStale($record)) {
            return;
        }

        $record->clicks = $totals['totalClicks'];
        $record->humanClicks = $totals['humanClicks'];
        $record->clicksUpdated = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        try {
            $record->save(false);
        } catch (\Throwable $e) {
            Craft::warning('Short.io couldn’t store a click snapshot: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @return Settings
     */
    private function _settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings;
    }

    /**
     * @param string $period
     * @return int
     */
    private function _ttlFor(string $period): int
    {
        $duration = $this->_settings()->statsCacheDuration;

        // Today's number moves; a lifetime total barely does.
        return $period === 'today' ? min(300, $duration) : $duration;
    }
}
