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
 * Matches short links you already have at Short.io to the entries they point at.
 *
 * Unlike craft-dub's equivalent, this never writes to Short.io. Dub stamps an
 * externalId on the remote link, so the mapping survives a lost database.
 * Short.io has no such field, which makes the plugin's own table the only record
 * of which link belongs to which entry - so adoption is purely local.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class AdoptController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The default action.
     */
    public $defaultAction = 'index';

    /**
     * @var bool Whether to report what would happen without writing anything.
     */
    public bool $dryRun = false;

    /**
     * @var bool Whether to re-point rows that already exist.
     */
    public bool $overwrite = false;

    /**
     * @var bool Whether to fall back to matching on the link path.
     */
    public bool $byPath = false;

    /**
     * @var string|null Limit to one section handle.
     */
    public ?string $section = null;

    /**
     * @var int|null Limit to one site id.
     */
    public ?int $site = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'dryRun',
            'overwrite',
            'byPath',
            'section',
            'site',
        ]);
    }

    /**
     * @inheritdoc
     */
    public function optionAliases(): array
    {
        return ['d' => 'dryRun', 's' => 'section', 'o' => 'overwrite'];
    }

    /**
     * @return int
     */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->isConfigured()) {
            $this->stderr("Short.io isn't configured. Add an API key and domain first.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $domainId = $plugin->domains->getDomainId();
        $hostname = $plugin->domains->getHostname();

        if ($domainId === null || $hostname === null) {
            $this->stderr("Couldn't resolve the configured domain.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        if (!$this->dryRun && $this->interactive && !$this->confirm("Adopt existing {$hostname} links into Craft?")) {
            return ExitCode::OK;
        }

        $plugin->client->setAllowSleep(true);

        $this->stdout("Fetching links from {$hostname}...\n");
        [$byUrl, $byPath, $total] = $this->_fetchLinks($domainId);
        $this->stdout("Found {$total} link(s).\n\n");

        $adopted = $skipped = $ambiguous = $unmatched = 0;
        $unmatchedEntries = [];

        foreach ($this->_entries() as $entry) {
            $url = $this->_normalise($entry->getUrl());

            if ($url === null) {
                continue;
            }

            $candidates = $byUrl[$url] ?? [];

            if ($candidates === [] && $this->byPath) {
                $candidate = $byPath[ltrim((string)$entry->slug, '/')] ?? null;
                $candidates = $candidate !== null ? [$candidate] : [];
            }

            if ($candidates === []) {
                $unmatched++;
                $unmatchedEntries[] = sprintf('%s (#%d/%d)', $entry->title, $entry->id, $entry->siteId);
                continue;
            }

            if (count($candidates) > 1) {
                // Two sites can legitimately share a destination path, so
                // guessing here would mean silently mis-assigning a link.
                $ambiguous++;
                $this->stdout(sprintf(
                    "ambiguous: %s matches %d links (%s)\n",
                    $entry->title,
                    count($candidates),
                    implode(', ', array_map(static fn($l) => $l['shortURL'] ?? '?', $candidates))
                ), Console::FG_YELLOW);
                continue;
            }

            $link = $candidates[0];
            $existing = LinkRecord::findOne(['entryId' => $entry->id, 'siteId' => $entry->siteId]);

            if ($existing !== null && !$this->overwrite) {
                $skipped++;
                continue;
            }

            $this->stdout(sprintf(
                "%s %s -> %s\n",
                $this->dryRun ? 'would adopt:' : 'adopted:',
                $link['shortURL'] ?? $link['idString'] ?? '?',
                $entry->title
            ), Console::FG_GREEN);

            if (!$this->dryRun) {
                $plugin->links->adopt($entry->id, $entry->siteId, $link);
            }

            $adopted++;
        }

        $this->stdout("\n" . str_repeat('-', 60) . "\n");
        $this->stdout(sprintf(
            "%d adopted, %d skipped, %d ambiguous, %d unmatched.\n",
            $adopted,
            $skipped,
            $ambiguous,
            $unmatched
        ), Console::BOLD);

        if ($unmatchedEntries !== []) {
            $this->stdout("\nEntries with no matching link:\n", Console::FG_GREY);

            foreach (array_slice($unmatchedEntries, 0, 25) as $line) {
                $this->stdout("  {$line}\n", Console::FG_GREY);
            }

            if (count($unmatchedEntries) > 25) {
                $this->stdout('  ... and ' . (count($unmatchedEntries) - 25) . " more\n", Console::FG_GREY);
            }
        }

        $this->stdout(
            "\nShort.io stores nothing that points back at Craft, so this table is the\n" .
            "only record of which link belongs to which entry. Back it up, and re-run\n" .
            "this command after restoring a database.\n",
            Console::FG_GREY
        );

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * Pages through every link on the domain, building lookup maps.
     *
     * Matching happens in memory so the entry loop costs no API calls at all.
     *
     * @param int $domainId
     * @return array
     */
    private function _fetchLinks(int $domainId): array
    {
        $byUrl = [];
        $byPath = [];
        $total = 0;
        $pageToken = null;

        do {
            $result = Plugin::getInstance()->client->listLinks($domainId, $pageToken);

            if (!$result->isOk()) {
                $this->stderr('Failed listing links: ' . ($result->message ?? 'unknown error') . "\n", Console::FG_RED);
                break;
            }

            foreach (($result->get('links') ?? []) as $link) {
                if (!is_array($link)) {
                    continue;
                }

                $total++;
                $url = $this->_normalise($link['originalURL'] ?? null);

                if ($url !== null) {
                    $byUrl[$url][] = $link;
                }

                if (isset($link['path'])) {
                    $byPath[ltrim((string)$link['path'], '/')] = $link;
                }
            }

            $pageToken = $result->get('nextPageToken');
        } while (!empty($pageToken));

        return [$byUrl, $byPath, $total];
    }

    /**
     * @return iterable<Entry>
     */
    private function _entries(): iterable
    {
        $handles = $this->section !== null
            ? [$this->section]
            : array_column(Sections::options(), 'value');

        if ($handles === []) {
            return [];
        }

        $siteIds = $this->site !== null
            ? [$this->site]
            : array_map(static fn($s) => $s->id, Craft::$app->getSites()->getAllSites());

        foreach ($siteIds as $siteId) {
            $query = Entry::find()
                ->siteId($siteId)
                ->section($handles)
                ->status(null);

            foreach ($query->each() as $entry) {
                yield $entry;
            }
        }
    }

    /**
     * Normalises a URL so the two sides of the match agree.
     *
     * @param string|null $url
     * @return string|null
     */
    private function _normalise(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        $path = rtrim($parts['path'] ?? '', '/');

        return strtolower($parts['host']) . ($path === '' ? '/' : $path);
    }
}
