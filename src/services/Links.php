<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\services;

use coyshdigital\shortio\helpers\Sections;
use coyshdigital\shortio\jobs\SyncLink;
use coyshdigital\shortio\models\ApiResult;
use coyshdigital\shortio\models\Settings;
use coyshdigital\shortio\Plugin;
use coyshdigital\shortio\records\LinkRecord;
use Craft;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\View;
use yii\base\Component;
use yii\base\Event;
use yii\base\ModelEvent;

/**
 * The spine of the plugin: everything that decides what a link should be, and
 * reconciles that with what Short.io currently has.
 *
 * The API call happens in before-save, so a failure can veto the save. Only the
 * database write happens in after-save, by which point nothing can fail.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Links extends Component
{
    // Constants
    // =========================================================================

    public const OP_WRITE = 'write';
    public const OP_DELETE = 'delete';
    public const OP_SUSPEND = 'suspend';
    public const OP_RESUME = 'resume';

    /**
     * Matches Craft's own sidebar field wrappers by class *token*.
     *
     * Yii sorts `id` ahead of `class`, so Craft renders `<div id="…" class="field …">`.
     * A literal `<div class="field` match only ever lands on hand-rolled markup
     * from some other plugin further up the sidebar.
     */
    private const FIELD_DIV_PATTERN = '/<div\b[^>]*\bclass="(?:[^"]*\s)?field(?:\s[^"]*)?"/';

    // Static Properties
    // =========================================================================

    /**
     * @var bool Whether the save-event handlers are currently inert.
     */
    private static bool $_suspended = false;

    // Private Properties
    // =========================================================================

    /**
     * @var array Work prepared in before-save, to be committed in after-save.
     */
    private array $_pending = [];

    // Public Methods
    // =========================================================================

    /**
     * Makes the save-event handlers inert. Bulk operations call sync() directly
     * and must not re-enter through the events.
     *
     * @return void
     */
    public function suspend(): void
    {
        self::$_suspended = true;
    }

    /**
     * @return void
     */
    public function resume(): void
    {
        self::$_suspended = false;
    }

    /**
     * Returns the stored link for an entry on a site.
     *
     * @param int|null $entryId
     * @param int|null $siteId
     * @return LinkRecord|null
     */
    public function getLink(?int $entryId, ?int $siteId): ?LinkRecord
    {
        if ($entryId === null || $siteId === null) {
            return null;
        }

        /** @var LinkRecord|null $record */
        $record = LinkRecord::findOne(['entryId' => $entryId, 'siteId' => $siteId]);

        return $record;
    }

    /**
     * Returns the short URL for an entry, or null.
     *
     * @param int|null $entryId
     * @param int|null $siteId
     * @return string|null
     */
    public function getShortUrl(?int $entryId, ?int $siteId): ?string
    {
        return $this->getLink($entryId, $siteId)?->shortUrl;
    }

    /**
     * Prepares whatever the entry's link should become. Returns an error message
     * when the save should be blocked, or null when all is well.
     *
     * @param ModelEvent $event
     * @return void
     */
    public function handleBeforeSave(ModelEvent $event): void
    {
        /** @var Entry $entry */
        $entry = $event->sender;

        try {
            $error = $this->prepare($entry);
        } catch (\Throwable $e) {
            Craft::error('Short.io failed preparing a link: ' . $e->getMessage(), __METHOD__);
            return;
        }

        if ($error !== null) {
            $this->_veto($event, $entry, $error);
        }
    }

    /**
     * Commits whatever before-save prepared.
     *
     * @param ModelEvent $event
     * @return void
     */
    public function handleAfterSave(ModelEvent $event): void
    {
        /** @var Entry $entry */
        $entry = $event->sender;
        $key = $this->_pendingKey($entry);

        if (!isset($this->_pending[$key])) {
            return;
        }

        $pending = $this->_pending[$key];
        unset($this->_pending[$key]);

        try {
            $this->_commit($entry, $pending);
        } catch (\Throwable $e) {
            Craft::error('Short.io failed committing a link: ' . $e->getMessage(), __METHOD__);
        }

        $this->_forgetPending($entry);
    }

    /**
     * Suspends or deletes the links for a deleted entry.
     *
     * @param Event $event
     * @return void
     */
    public function handleAfterDelete(Event $event): void
    {
        /** @var Entry $entry */
        $entry = $event->sender;

        if (self::$_suspended || !$this->_settings()->isConfigured()) {
            return;
        }

        try {
            // A soft delete leaves dateDeleted set and the entry recoverable, so
            // the link is suspended rather than destroyed. A hard delete is
            // final, and so is the link.
            $hard = $entry->dateDeleted === null;

            foreach ($this->_recordsForEntry($entry) as $record) {
                if ($hard || $this->_settings()->onDelete === Settings::ON_UNPUBLISH_DELETE) {
                    $this->_deleteRemote($record);
                    $record->delete();
                } elseif ($this->_settings()->onDelete === Settings::ON_UNPUBLISH_EXPIRE) {
                    $this->_setSuspended($record, true);
                }
            }
        } catch (\Throwable $e) {
            Craft::error('Short.io failed handling an entry delete: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Brings a restored entry's links back to life.
     *
     * craft-dub has no equivalent, so a restored entry keeps a dead link there.
     *
     * @param Event $event
     * @return void
     */
    public function handleAfterRestore(Event $event): void
    {
        /** @var Entry $entry */
        $entry = $event->sender;

        if (self::$_suspended || !$this->_settings()->isConfigured()) {
            return;
        }

        try {
            foreach ($this->_recordsForEntry($entry) as $record) {
                $this->_setSuspended($record, false);
            }
        } catch (\Throwable $e) {
            Craft::error('Short.io failed handling an entry restore: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Splices the Short Link panel into the entry sidebar.
     *
     * @param DefineHtmlEvent $event
     * @return void
     */
    public function handleDefineSidebarHtml(DefineHtmlEvent $event): void
    {
        try {
            /** @var Entry $entry */
            $entry = $event->sender;
            $row = $this->_renderSidebarRow($entry);

            if ($row !== null) {
                $event->html = $this->spliceSidebar($event->html, $row);
            }
        } catch (\Throwable $e) {
            // A link row must never take the entry editor down.
            Craft::warning('Short.io couldn’t render its sidebar row: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Works out what the entry's link should be and reconciles it with Short.io.
     *
     * Returns an error message when the save should be blocked.
     *
     * @param Entry $entry
     * @param string|null $forcePath
     * @return string|null
     */
    public function prepare(Entry $entry, ?string $forcePath = null): ?string
    {
        if (!$this->_shouldHandle($entry)) {
            return null;
        }

        $request = Craft::$app->getRequest();
        $isWeb = !$request->getIsConsoleRequest();

        $customPath = $forcePath;
        $hadLink = false;

        // The sidebar renders the path field disabled without the manage
        // permission, but that is only markup - nothing stops someone posting
        // shortIoPath by hand, and an empty one plus the sentinel would delete
        // the link. So the posted values are only honoured for users who are
        // actually allowed to steer them; everyone else still gets the
        // automatic behaviour.
        if ($isWeb && $forcePath === null && $this->_canManage()) {
            $posted = $request->getBodyParam('shortIoPath');
            $customPath = $posted === null ? null : trim((string)$posted);
            // Rendered only when a link already exists, so an empty path field
            // means "the editor cleared it" rather than "there was never one".
            $hadLink = (string)$request->getBodyParam('shortIoLinkPresent') === '1';
        }

        $key = $this->_pendingKey($entry);
        $record = $this->getLink($entry->getCanonicalId(), $entry->siteId);

        if ($customPath === '' && ($hadLink || $record !== null)) {
            $this->_pending[$key] = ['op' => self::OP_DELETE];
            return null;
        }

        if (!$this->_isLive($entry)) {
            if ($record !== null && $this->_settings()->onUnpublish !== Settings::ON_UNPUBLISH_NOTHING) {
                $this->_pending[$key] = ['op' => self::OP_SUSPEND];
            }
            return null;
        }

        if ($record === null && $customPath === null && !$this->_settings()->autoPath) {
            return null;
        }

        $destination = $this->_resolveDestinationUrl($entry);

        if ($destination === null || $destination === '') {
            return null;
        }

        $path = $this->_desiredPath($entry, $customPath, $record);
        $result = $record !== null
            ? $this->_prepareUpdate($record, $entry, $path, $destination)
            : $this->_prepareCreate($entry, $path, $destination);

        if ($result instanceof ApiResult) {
            return $this->_errorFor($result, $entry);
        }

        if ($result === null) {
            // Nothing relevant changed. craft-dub PATCHes on every save; we don't.
            return null;
        }

        $this->_pending[$key] = ['op' => self::OP_WRITE, 'link' => $result];
        $this->_rememberPending($entry, $result['idString'] ?? '');

        return null;
    }

    /**
     * Syncs an entry's link outside the save lifecycle. Used by the console
     * commands and the retry job.
     *
     * @param Entry $entry
     * @param string|null $path
     * @return string|null An error message, or null.
     */
    public function sync(Entry $entry, ?string $path = null): ?string
    {
        $error = $this->prepare($entry, $path);

        if ($error !== null) {
            return $error;
        }

        $key = $this->_pendingKey($entry);

        if (!isset($this->_pending[$key])) {
            return null;
        }

        $pending = $this->_pending[$key];
        unset($this->_pending[$key]);
        $this->_commit($entry, $pending);
        $this->_forgetPending($entry);

        return null;
    }

    /**
     * Writes a local row for a link that already exists at Short.io.
     *
     * @param int $entryId
     * @param int $siteId
     * @param array $link
     * @return LinkRecord
     */
    public function adopt(int $entryId, int $siteId, array $link): LinkRecord
    {
        $record = $this->getLink($entryId, $siteId) ?? new LinkRecord([
            'entryId' => $entryId,
            'siteId' => $siteId,
        ]);

        $this->_applyLink($record, $link);
        $record->save(false);

        return $record;
    }

    /**
     * Splices a rendered row into sidebar HTML.
     *
     * Public so it can be unit-tested without a Craft bootstrap.
     *
     * @param string $html
     * @param string $row
     * @return string
     */
    public function spliceSidebar(string $html, string $row): string
    {
        $notesPos = strpos($html, 'name="notes"');
        $head = $notesPos !== false ? substr($html, 0, $notesPos) : $html;

        // Craft groups sidebar metadata as <fieldset><legend class="h6">, so
        // landing immediately before the fieldset that holds the notes field
        // makes this row a sibling group rather than something wedged inside
        // Craft's own. Falling inside one would nest a fieldset in a fieldset
        // and inherit the wrong heading style.
        $offset = strrpos($head, '<fieldset');

        if ($offset === false) {
            // No fieldsets (older markup, or another element type): settle for
            // the last field wrapper. Match the class as a *token* - Yii sorts
            // `id` ahead of `class`, so Craft's own rows render as
            // `<div id="..." class="field ...">` and a literal `<div class="field`
            // match only ever lands on another plugin's hand-rolled markup.
            if (!preg_match_all(self::FIELD_DIV_PATTERN, $head, $matches, PREG_OFFSET_CAPTURE)) {
                return $html . $row;
            }

            $offset = $matches[0][count($matches[0]) - 1][1];
        }

        // substr, not preg_replace: the row carries JS containing `$` sequences,
        // which preg_replace would read as backreferences and eat.
        return substr($html, 0, $offset) . $row . substr($html, $offset);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns whether the current user may steer short links by hand.
     *
     * Console and queue contexts have no identity but are trusted - they only
     * ever run work the site itself initiated.
     *
     * @return bool
     */
    private function _canManage(): bool
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return true;
        }

        $user = Craft::$app->getUser();

        return $user->getIsAdmin() || $user->checkPermission(Plugin::PERMISSION_MANAGE_LINKS);
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
     * Decides whether this entry could have a short link at all.
     *
     * Deliberately says nothing about drafts: the control panel always edits a
     * provisional draft, so the sidebar has to render for one even though
     * saving one never syncs.
     *
     * @param Entry $entry
     * @return bool
     */
    private function _isEligible(Entry $entry): bool
    {
        if (!$this->_settings()->isConfigured() || $entry->siteId === null) {
            return false;
        }

        // Craft 5: an entry nested in a Matrix field is still a craft\elements\Entry
        // but has no section and no URL. Without this, every Matrix block save
        // would fire an API call.
        $section = Sections::forEntry($entry);

        if ($section === null || !$this->_settings()->appliesToSection($section->handle)) {
            return false;
        }

        foreach ($section->getSiteSettings() as $siteSettings) {
            if ((int)$siteSettings->siteId === (int)$entry->siteId) {
                return (bool)$siteSettings->hasUrls;
            }
        }

        return false;
    }

    /**
     * Decides whether this entry is ours to act on during a save.
     *
     * @param Entry $entry
     * @return bool
     */
    private function _shouldHandle(Entry $entry): bool
    {
        if (self::$_suspended || !$this->_isEligible($entry)) {
            return false;
        }

        if (ElementHelper::isDraftOrRevision($entry)) {
            return false;
        }

        if ($this->_settings()->siteMode === Settings::SITE_MODE_PRIMARY && $entry->propagating) {
            return false;
        }

        // One `php craft resave/entries` would otherwise be thousands of calls.
        if (Craft::$app->getRequest()->getIsConsoleRequest() && !$this->_settings()->syncOnResave) {
            return false;
        }

        return true;
    }

    /**
     * Returns whether the entry is (about to be) publicly live.
     *
     * Deliberately not getStatus(). At before-save on a brand-new entry Craft
     * hasn't stamped postDate yet - it does that in its own beforeSave, which
     * runs after ours - so getStatus() reports "pending" for an entry that is
     * about to go live, and the link would never be created. An unset postDate
     * therefore means "now".
     *
     * @param Entry $entry
     * @return bool
     */
    private function _isLive(Entry $entry): bool
    {
        if (!$entry->enabled || !$entry->getEnabledForSite()) {
            return false;
        }

        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        if ($entry->postDate !== null && $entry->postDate > $now) {
            return false;
        }

        if ($entry->expiryDate !== null && $entry->expiryDate <= $now) {
            return false;
        }

        return true;
    }

    /**
     * Keyed on uid, not id: Craft assigns uid before before-save fires on a new
     * element, whereas id is still null. Per-site, so a multisite propagation
     * pass can't clobber the primary site's record.
     *
     * @param Entry $entry
     * @return string
     */
    private function _pendingKey(Entry $entry): string
    {
        return $entry->uid . '_' . $entry->siteId;
    }

    /**
     * Works out where the short link should point.
     *
     * At before-save the entry's uri is null (new) or stale (the slug changed in
     * this save), because Craft regenerates it during validation - which runs
     * after before-save. Computing it on a throwaway clone gets the URI Craft is
     * about to assign without touching the real entry.
     *
     * @param Entry $entry
     * @return string|null
     */
    private function _resolveDestinationUrl(Entry $entry): ?string
    {
        try {
            $clone = clone $entry;
            ElementHelper::setUniqueUri($clone);
            $url = $clone->getUrl() ?? $entry->getUrl();
        } catch (\Throwable $e) {
            Craft::warning('Short.io couldn’t resolve a destination URL: ' . $e->getMessage(), __METHOD__);
            $url = $entry->getUrl();
        }

        if ($url === null) {
            return null;
        }

        $template = $this->_settings()->destinationTemplate;

        if ($template !== '') {
            try {
                $url = Craft::$app->getView()->renderObjectTemplate($template, $entry, ['url' => $url]);
            } catch (\Throwable $e) {
                Craft::warning('Short.io couldn’t render the destination template: ' . $e->getMessage(), __METHOD__);
            }
        }

        return $url;
    }

    /**
     * @param Entry $entry
     * @param string|null $custom
     * @param LinkRecord|null $record
     * @return string
     */
    private function _desiredPath(Entry $entry, ?string $custom, ?LinkRecord $record): string
    {
        if ($custom !== null && $custom !== '') {
            return ltrim($custom, '/');
        }

        if ($record !== null) {
            return $record->path;
        }

        $slug = $entry->slug ?: StringHelper::slugify((string)$entry->title);

        return ltrim($this->_settings()->pathPrefix . $slug, '/');
    }

    /**
     * @param Entry $entry
     * @param string $path
     * @param string $url
     * @return array|ApiResult
     */
    private function _prepareCreate(Entry $entry, string $path, string $url): array|ApiResult
    {
        // If after-save never fired last time (another plugin vetoed the save
        // after we'd already created the link), reuse what we made rather than
        // making a second one.
        $orphan = Craft::$app->getCache()->get($this->_pendingCacheKey($entry));

        if (is_string($orphan) && $orphan !== '') {
            $result = Plugin::getInstance()->client->updateLink($orphan, [
                'originalURL' => $url,
                'path' => $path,
            ]);

            if ($result->isOk()) {
                return $result->data ?? [];
            }
        }

        $payload = $this->_linkPayload($entry, $path, $url) + [
            'domain' => $this->_settings()->getDomain(),
            // Deliberately false: Short.io then returns the *existing* link for
            // this destination instead of minting a second one, which makes a
            // lost local row self-heal into re-adoption.
            'allowDuplicates' => false,
        ];

        $result = Plugin::getInstance()->client->createLink($payload);

        if ($result->isConflict()) {
            return $this->_resolveConflict($path, $url, null) ?? $result;
        }

        if (!$result->isOk()) {
            return $result;
        }

        $link = $result->data ?? [];

        // On a duplicate hit Short.io ignores the path we asked for, so rename.
        if (($link['duplicate'] ?? false) && ($link['path'] ?? null) !== $path && isset($link['idString'])) {
            $renamed = Plugin::getInstance()->client->updateLink($link['idString'], ['path' => $path]);

            if ($renamed->isOk()) {
                return $renamed->data ?? $link;
            }

            if ($renamed->isConflict()) {
                return $this->_resolveConflict($path, $url, null) ?? $renamed;
            }

            Craft::warning(
                "Short.io kept link {$link['idString']} at path “{$link['path']}” rather than “{$path}”.",
                __METHOD__
            );
        }

        return $link;
    }

    /**
     * @param LinkRecord $record
     * @param Entry $entry
     * @param string $path
     * @param string $url
     * @return array|ApiResult|null
     */
    private function _prepareUpdate(LinkRecord $record, Entry $entry, string $path, string $url): array|ApiResult|null
    {
        $payload = $this->_linkPayload($entry, $path, $url);
        $changed = $record->originalUrl !== $url
            || $record->path !== $path
            || ($this->_settings()->titleFromEntry && $record->title !== ($payload['title'] ?? null))
            || $record->suspended;

        if (!$changed) {
            return null;
        }

        if ($record->suspended) {
            $payload['expiresAt'] = null;
            $payload['archived'] = false;
        }

        $result = Plugin::getInstance()->client->updateLink($record->linkIdString, $payload, $record->domainId);

        if ($result->isNotFound()) {
            return $this->_healMissingRemote($record, $entry, $path, $url);
        }

        if ($result->isConflict()) {
            return $this->_resolveConflict($path, $url, $record->id) ?? $result;
        }

        if (!$result->isOk()) {
            return $result;
        }

        return $result->data ?? [];
    }

    /**
     * Works out what to do when Short.io says a path is already taken.
     *
     * @param string $path
     * @param string $url
     * @param int|null $excludeRecordId
     * @return array|null
     */
    private function _resolveConflict(string $path, string $url, ?int $excludeRecordId): ?array
    {
        $domain = $this->_settings()->getDomain();
        $result = Plugin::getInstance()->client->expand($domain, $path);

        if (!$result->isOk() || !is_array($result->data)) {
            return null;
        }

        $link = $result->data;
        $idString = $link['idString'] ?? null;

        if ($idString === null) {
            return null;
        }

        $claimed = LinkRecord::find()
            ->where(['linkIdString' => $idString])
            ->andFilterWhere(['not', ['id' => $excludeRecordId]])
            ->exists();

        if ($claimed) {
            return null;
        }

        if (($link['originalURL'] ?? null) === $url) {
            // Almost always our own link, after a database restore or a link
            // created by hand in the dashboard.
            return $link;
        }

        if (!$this->_settings()->adoptExistingPaths) {
            return null;
        }

        $repointed = Plugin::getInstance()->client->updateLink($idString, ['originalURL' => $url]);

        if (!$repointed->isOk()) {
            return null;
        }

        Craft::info("Short.io repointed existing link {$idString} at {$url}.", __METHOD__);

        return $repointed->data ?? $link;
    }

    /**
     * Recovers when a local row points at a link Short.io no longer has.
     *
     * @param LinkRecord $record
     * @param Entry $entry
     * @param string $path
     * @param string $url
     * @return array|ApiResult
     */
    private function _healMissingRemote(LinkRecord $record, Entry $entry, string $path, string $url): array|ApiResult
    {
        $result = Plugin::getInstance()->client->expand($record->domain, $record->path);

        if ($result->isOk() && is_array($result->data)) {
            $found = $result->data;

            if (($found['idString'] ?? null) !== $record->linkIdString) {
                // Deleted and recreated by hand. Re-point the row, then retry.
                $this->_applyLink($record, $found);
                $record->save(false);

                $retry = Plugin::getInstance()->client->updateLink(
                    $record->linkIdString,
                    $this->_linkPayload($entry, $path, $url),
                    $record->domainId
                );

                return $retry->isOk() ? ($retry->data ?? []) : $retry;
            }

            // Contradictory: the update 404'd but expand found it. Retry once
            // without the domain_id, then give up rather than destroying data.
            $retry = Plugin::getInstance()->client->updateLink(
                $record->linkIdString,
                $this->_linkPayload($entry, $path, $url)
            );

            return $retry->isOk() ? ($retry->data ?? []) : $retry;
        }

        Craft::info("Short.io link {$record->linkIdString} no longer exists; recreating.", __METHOD__);
        $record->delete();

        return $this->_prepareCreate($entry, $path, $url);
    }

    /**
     * @param Entry $entry
     * @param string $path
     * @param string $url
     * @return array
     */
    private function _linkPayload(Entry $entry, string $path, string $url): array
    {
        $settings = $this->_settings();

        $payload = [
            'originalURL' => $url,
            'path' => $path,
            'redirectType' => $settings->redirectType,
        ];

        if ($settings->titleFromEntry) {
            $payload['title'] = (string)$entry->title;
        }

        if ($settings->tags !== []) {
            $payload['tags'] = array_values($settings->tags);
        }

        return $payload;
    }

    /**
     * @param Entry $entry
     * @param array $pending
     * @return void
     */
    private function _commit(Entry $entry, array $pending): void
    {
        $entryId = $entry->getCanonicalId();

        if ($entryId === null || $entry->siteId === null) {
            return;
        }

        $record = $this->getLink($entryId, $entry->siteId);

        switch ($pending['op']) {
            case self::OP_DELETE:
                if ($record !== null) {
                    $this->_deleteRemote($record);
                    $record->delete();
                }
                break;

            case self::OP_SUSPEND:
                if ($record !== null) {
                    $this->_setSuspended($record, true);
                }
                break;

            case self::OP_RESUME:
                if ($record !== null) {
                    $this->_setSuspended($record, false);
                }
                break;

            case self::OP_WRITE:
                $link = $pending['link'] ?? [];

                if (!isset($link['idString'])) {
                    return;
                }

                $record ??= new LinkRecord(['entryId' => $entryId, 'siteId' => $entry->siteId]);
                $this->_applyLink($record, $link);
                $record->suspended = false;
                $record->save(false);
                break;
        }
    }

    /**
     * Copies a Short.io link payload onto a record.
     *
     * @param LinkRecord $record
     * @param array $link
     * @return void
     */
    private function _applyLink(LinkRecord $record, array $link): void
    {
        $record->linkIdString = (string)($link['idString'] ?? $record->linkIdString);
        $record->linkId = isset($link['id']) ? (string)$link['id'] : $record->linkId;
        $record->domainId = isset($link['DomainId']) ? (int)$link['DomainId'] : $record->domainId;
        $record->path = (string)($link['path'] ?? $record->path);
        $record->shortUrl = (string)($link['secureShortURL'] ?? $link['shortURL'] ?? $record->shortUrl);
        $record->originalUrl = (string)($link['originalURL'] ?? $record->originalUrl);
        $record->title = $link['title'] ?? $record->title;

        if (($record->domain ?? '') === '') {
            $record->domain = $this->_settings()->getDomain();
        }

        if ($record->domainId === null) {
            $record->domainId = Plugin::getInstance()->domains->getDomainId($record->domain);
        }
    }

    /**
     * Expires or un-expires a link.
     *
     * Archiving alone is not enough: Short.io's docs are explicit that an
     * archived link "remains accessible and functions as intended", so an
     * archived link for an unpublished entry would still redirect to a 404.
     * Expiry is what actually stops it, and it's reversible.
     *
     * @param LinkRecord $record
     * @param bool $suspended
     * @return void
     */
    private function _setSuspended(LinkRecord $record, bool $suspended): void
    {
        $payload = $suspended
            ? [
                'expiresAt' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('c'),
                'expiredURL' => $this->_expiredUrl($record),
                'archived' => true,
            ]
            : [
                'expiresAt' => null,
                'archived' => false,
            ];

        $result = Plugin::getInstance()->client->updateLink($record->linkIdString, $payload, $record->domainId);

        if ($result->isOk()) {
            $record->suspended = $suspended;
            $record->save(false);
        }
    }

    /**
     * @param LinkRecord $record
     * @return string
     */
    private function _expiredUrl(LinkRecord $record): string
    {
        $configured = trim($this->_settings()->expiredUrl);

        if ($configured !== '') {
            return $configured;
        }

        try {
            return UrlHelper::siteUrl('/', null, null, $record->siteId);
        } catch (\Throwable) {
            return UrlHelper::baseSiteUrl();
        }
    }

    /**
     * @param LinkRecord $record
     * @return void
     */
    private function _deleteRemote(LinkRecord $record): void
    {
        Plugin::getInstance()->client->deleteLink($record->linkIdString);
    }

    /**
     * @param Entry $entry
     * @return LinkRecord[]
     */
    private function _recordsForEntry(Entry $entry): array
    {
        $entryId = $entry->getCanonicalId();

        if ($entryId === null) {
            return [];
        }

        /** @var LinkRecord[] $records */
        $records = LinkRecord::findAll(['entryId' => $entryId]);

        return $records;
    }

    /**
     * Turns a failed API result into a message, or null when the failure is
     * transient and the site would rather not block the editor.
     *
     * @param ApiResult $result
     * @param Entry $entry
     * @return string|null
     */
    private function _errorFor(ApiResult $result, Entry $entry): ?string
    {
        $domain = $this->_settings()->getDomain();

        if ($result->isConflict()) {
            return Craft::t('short-io', 'That short link path is already in use on {domain}.', ['domain' => $domain]);
        }

        if ($result->httpStatus === 401 || $result->httpStatus === 403) {
            return Craft::t('short-io', 'Short.io rejected the API key.');
        }

        if ($result->isTransient()) {
            if ($this->_settings()->failureMode === Settings::FAILURE_WARN) {
                Craft::warning('Short.io was unavailable; queueing a retry. ' . ($result->message ?? ''), __METHOD__);
                $this->_queueRetry($entry);

                return null;
            }

            return Craft::t('short-io', 'Short.io is busy or unavailable, so the entry wasn’t saved. Try again in a moment.');
        }

        return $result->message !== null
            ? Craft::t('short-io', 'Short.io said: {message}', ['message' => $result->message])
            : Craft::t('short-io', 'Short.io couldn’t create the short link.');
    }

    /**
     * @param Entry $entry
     * @return void
     */
    private function _queueRetry(Entry $entry): void
    {
        $entryId = $entry->getCanonicalId();

        if ($entryId === null || $entry->siteId === null) {
            return;
        }

        Craft::$app->getQueue()->push(new SyncLink([
            'entryId' => $entryId,
            'siteId' => $entry->siteId,
        ]));
    }

    /**
     * @param ModelEvent $event
     * @param Entry $entry
     * @param string $message
     * @return void
     */
    private function _veto(ModelEvent $event, Entry $entry, string $message): void
    {
        // shortIoPath isn't a real Entry attribute, so an error on it only shows
        // in the editor's error summary. Attaching to slug when the path was
        // derived rather than typed puts the message next to the field the
        // editor would actually change.
        $typed = !Craft::$app->getRequest()->getIsConsoleRequest()
            && trim((string)Craft::$app->getRequest()->getBodyParam('shortIoPath', '')) !== '';

        $entry->addError($typed ? 'shortIoPath' : 'slug', $message);
        $event->isValid = false;
    }

    /**
     * @param Entry $entry
     * @return string
     */
    private function _pendingCacheKey(Entry $entry): string
    {
        return 'short-io:pending:' . $this->_pendingKey($entry);
    }

    /**
     * @param Entry $entry
     * @param string $idString
     * @return void
     */
    private function _rememberPending(Entry $entry, string $idString): void
    {
        if ($idString !== '') {
            Craft::$app->getCache()->set($this->_pendingCacheKey($entry), $idString, 300);
        }
    }

    /**
     * @param Entry $entry
     * @return void
     */
    private function _forgetPending(Entry $entry): void
    {
        Craft::$app->getCache()->delete($this->_pendingCacheKey($entry));
    }

    /**
     * @param Entry $entry
     * @return string|null
     */
    private function _renderSidebarRow(Entry $entry): ?string
    {
        if (!$this->_isEligible($entry) && $this->getLink($entry->getCanonicalId(), $entry->siteId) === null) {
            return null;
        }

        $settings = $this->_settings();
        $record = $this->getLink($entry->getCanonicalId(), $entry->siteId);
        $user = Craft::$app->getUser();

        if (!$user->getIsAdmin() && !$user->checkPermission(Plugin::PERMISSION_VIEW_LINKS)) {
            return null;
        }

        $clicks = null;

        if ($settings->showClicks && $record !== null) {
            $clicks = Plugin::getInstance()->stats->getForRecord($record);
        }

        return Craft::$app->getView()->renderTemplate('short-io/_sidebar/link.twig', [
            'entry' => $entry,
            'link' => $record,
            'settings' => $settings,
            'domain' => $settings->getDomain(),
            'clicks' => $clicks,
            'canManage' => $user->getIsAdmin() || $user->checkPermission(Plugin::PERMISSION_MANAGE_LINKS),
            'qrUrl' => $record !== null && $settings->qrViewMode !== Settings::QR_NONE
                ? Plugin::getInstance()->qr->getCpUrl($record->linkIdString)
                : null,
        ], View::TEMPLATE_MODE_CP);
    }
}
