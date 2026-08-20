<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\console\controllers;

use coyshdigital\shortio\helpers\Sections;
use coyshdigital\shortio\Plugin;
use coyshdigital\shortio\records\LinkRecord;
use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Short link maintenance.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class LinksController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether to report what would happen without changing anything.
     */
    public bool $dryRun = false;

    /**
     * @var string|null Limit to one section handle.
     */
    public ?string $section = null;

    /**
     * @var int|null Limit to one site id.
     */
    public ?int $site = null;

    /**
     * @var int How many rows to process.
     */
    public int $limit = 500;

    /**
     * @var bool Whether verify repairs the drift it finds.
     */
    public bool $fix = false;

    /**
     * @var bool Whether sync pushes every link even when nothing looks changed.
     */
    public bool $force = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'sync' => array_merge($options, ['dryRun', 'section', 'site', 'limit', 'force']),
            'verify' => array_merge($options, ['fix', 'limit']),
            'refresh-stats' => array_merge($options, ['limit']),
            'prune' => array_merge($options, ['dryRun']),
            default => $options,
        };
    }

    /**
     * @inheritdoc
     */
    public function optionAliases(): array
    {
        return ['d' => 'dryRun', 's' => 'section', 'l' => 'limit'];
    }

    /**
     * Checks that every part of the Short.io integration actually works.
     *
     * Creates a throwaway link, expands it, fetches its QR code and statistics,
     * then deletes it again.
     *
     * @return int
     */
    public function actionDiagnose(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $this->stdout("Short.io diagnostics\n", Console::BOLD);
        $this->stdout(str_repeat('-', 60) . "\n");

        $key = $settings->getApiKey();
        $this->_step('API key configured', $key !== '', $key !== '' ? 'ends ' . substr($key, -4) : 'not set');

        if ($key === '') {
            $this->stderr("\nSet an API key in the control panel first.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $domains = $plugin->domains->getAll(true);
        $this->_step('Domain list fetched', $domains !== [], count($domains) . ' domain(s)');

        $hostname = $plugin->domains->getHostname();
        $domainId = $plugin->domains->getDomainId();
        $this->_step('Domain resolved', $hostname !== null && $domainId !== null, sprintf('%s (id %s)', $hostname ?? '-', $domainId ?? '-'));

        if ($hostname === null || $domainId === null) {
            $this->stderr("\nPick a domain in the control panel first.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $plugin->client->setAllowSleep(true);

        $path = 'craft-diagnose-' . bin2hex(random_bytes(4));
        $target = 'https://example.com/craft-short-io-diagnostics';

        $created = $plugin->client->createLink([
            'originalURL' => $target,
            'domain' => $hostname,
            'path' => $path,
            'title' => 'Craft diagnostics (safe to delete)',
            'allowDuplicates' => false,
        ]);
        $this->_step('Create a link', $created->isOk(), $created->isOk() ? ($created->get('shortURL') ?? '') : ($created->message ?? 'failed'));

        if (!$created->isOk()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $idString = (string)$created->get('idString');

        $expanded = $plugin->client->expand($hostname, $path);
        $this->_step('Look it up by path', $expanded->isOk(), $expanded->isOk() ? 'found' : ($expanded->message ?? 'failed'));

        $updated = $plugin->client->updateLink($idString, ['title' => 'Craft diagnostics (updated)'], $domainId);
        $this->_step('Update it', $updated->isOk(), $updated->isOk() ? 'ok' : ($updated->message ?? 'failed'));

        $qrUrl = $plugin->qr->getUrl($idString);
        $this->_step('Generate a QR code', $qrUrl !== null, $qrUrl ?? 'failed');

        if ($qrUrl !== null) {
            // The generated image is served publicly, so this check runs without
            // an Authorization header on purpose.
            try {
                $image = Craft::createGuzzleClient(['http_errors' => false])->request('GET', $qrUrl);
                $bytes = (string)$image->getBody();
                $ok = $image->getStatusCode() === 200 && $bytes !== '';
                $this->_step(
                    'Fetch the QR image',
                    $ok,
                    $ok
                        ? strlen($bytes) . ' bytes, ' . $this->_sniff($bytes) . ', ' . $image->getHeaderLine('Content-Type')
                        : 'HTTP ' . $image->getStatusCode()
                );
            } catch (\Throwable $e) {
                $this->_step('Fetch the QR image', false, $e->getMessage());
            }
        }

        $stats = $plugin->client->statistics($idString, ['period' => 'total', 'skipTops' => 'true']);
        $this->_step('Fetch statistics', $stats->isOk(), $stats->isOk() ? 'totalClicks ' . (int)($stats->get('totalClicks') ?? 0) : ($stats->message ?? 'failed'));

        $listed = $plugin->client->listLinks($domainId, null, 1);
        $this->_step('List links', $listed->isOk(), $listed->isOk() ? 'count ' . (int)($listed->get('count') ?? 0) : ($listed->message ?? 'failed'));

        $deleted = $plugin->client->deleteLink($idString);
        $this->_step('Delete the test link', $deleted->isOk(), $deleted->isOk() ? 'cleaned up' : ($deleted->message ?? 'FAILED - delete it by hand'));

        $this->stdout(str_repeat('-', 60) . "\n");
        $this->stdout("Done.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Re-syncs links from their entries.
     *
     * @return int
     */
    public function actionSync(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->isConfigured()) {
            $this->stderr("Short.io isn't configured.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $handles = $this->section !== null
            ? [$this->section]
            : array_column(Sections::options(), 'value');

        $siteIds = $this->site !== null
            ? [$this->site]
            : array_map(static fn($s) => $s->id, Craft::$app->getSites()->getAllSites());

        $plugin->client->setAllowSleep(true);
        $plugin->links->suspend();

        $synced = 0;
        $failed = 0;

        try {
            foreach ($siteIds as $siteId) {
                $query = Entry::find()->siteId($siteId)->status(null)->limit($this->limit);

                if ($handles !== []) {
                    $query->section($handles);
                }

                foreach ($query->all() as $entry) {
                    if ($this->dryRun) {
                        $this->stdout("would sync: {$entry->title} (#{$entry->id}/{$siteId})\n");
                        $synced++;
                        continue;
                    }

                    $error = $plugin->links->sync($entry, null, $this->force);

                    if ($error !== null) {
                        $this->stdout("failed: {$entry->title} - {$error}\n", Console::FG_RED);
                        $failed++;
                    } else {
                        $this->stdout("synced: {$entry->title}\n", Console::FG_GREEN);
                        $synced++;
                    }
                }
            }
        } finally {
            $plugin->links->resume();
        }

        $this->stdout("\n{$synced} synced, {$failed} failed.\n");

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Reports links that have drifted from what Short.io actually has.
     *
     * @return int
     */
    public function actionVerify(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->isConfigured()) {
            $this->stderr("Short.io isn't configured.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $plugin->client->setAllowSleep(true);

        /** @var LinkRecord[] $records */
        $records = LinkRecord::find()->limit($this->limit)->all();
        $ok = $missing = $moved = 0;

        foreach ($records as $record) {
            $result = $plugin->client->expand($record->domain, $record->path);

            if (!$result->isOk()) {
                $missing++;
                $this->stdout("missing: {$record->shortUrl}\n", Console::FG_RED);

                if ($this->fix) {
                    $record->delete();
                    $this->stdout("  removed the local row; re-save the entry to recreate\n", Console::FG_YELLOW);
                }

                continue;
            }

            $found = $result->data ?? [];

            if (($found['idString'] ?? null) !== $record->linkIdString) {
                $moved++;
                $this->stdout("moved: {$record->shortUrl} is now {$found['idString']}\n", Console::FG_YELLOW);

                if ($this->fix) {
                    $record->linkIdString = (string)$found['idString'];
                    $record->linkId = isset($found['id']) ? (string)$found['id'] : $record->linkId;
                    $record->save(false);
                    $this->stdout("  re-pointed\n", Console::FG_GREEN);
                }

                continue;
            }

            $ok++;
        }

        $this->stdout("\n{$ok} ok, {$moved} moved, {$missing} missing.\n");

        if (($moved > 0 || $missing > 0) && !$this->fix) {
            $this->stdout("Re-run with --fix to repair.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Refreshes the click snapshots the Links index reads.
     *
     * The Links screen keeps itself current in the background, so this is for
     * doing the lot at once - after an adopt run, say - rather than something
     * that needs scheduling.
     *
     * @return int
     */
    public function actionRefreshStats(): int
    {
        $count = Plugin::getInstance()->stats->refreshSnapshots($this->limit);
        $this->stdout("Refreshed {$count} link(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Removes rows whose entry no longer exists.
     *
     * @return int
     */
    public function actionPrune(): int
    {
        /** @var LinkRecord[] $records */
        $records = LinkRecord::find()->all();
        $removed = 0;

        foreach ($records as $record) {
            $exists = Entry::find()->id($record->entryId)->siteId($record->siteId)->status(null)->exists();

            if ($exists) {
                continue;
            }

            $this->stdout(($this->dryRun ? 'would prune: ' : 'pruned: ') . $record->shortUrl . "\n");

            if (!$this->dryRun) {
                $record->delete();
            }

            $removed++;
        }

        $this->stdout("\n{$removed} row(s).\n");

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string $label
     * @param bool $ok
     * @param string $detail
     * @return void
     */
    private function _step(string $label, bool $ok, string $detail = ''): void
    {
        $this->stdout($ok ? '  ok   ' : '  fail ', $ok ? Console::FG_GREEN : Console::FG_RED);
        $this->stdout(str_pad($label, 26));
        $this->stdout($detail . "\n", Console::FG_GREY);
    }

    /**
     * Reports what a QR response body actually is, since the API reference and
     * the older guide disagree about the format.
     *
     * @param string $bytes
     * @return string
     */
    private function _sniff(string $bytes): string
    {
        if (str_starts_with($bytes, "\x89PNG")) {
            return 'PNG';
        }

        if (str_starts_with(ltrim($bytes), '<')) {
            return 'SVG';
        }

        if (str_starts_with($bytes, '%PDF')) {
            return 'PDF';
        }

        return 'unknown format';
    }
}
