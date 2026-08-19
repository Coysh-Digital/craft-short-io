<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\models;

use coyshdigital\shortio\Plugin;
use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Short.io settings model.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Settings extends Model
{
    // Constants
    // =========================================================================

    public const ON_UNPUBLISH_EXPIRE = 'expire';
    public const ON_UNPUBLISH_DELETE = 'delete';
    public const ON_UNPUBLISH_NOTHING = 'nothing';

    public const FAILURE_BLOCK = 'block';
    public const FAILURE_WARN = 'warn';

    public const SITE_MODE_ALL = 'all';
    public const SITE_MODE_PRIMARY = 'primary';

    public const QR_NONE = 'none';
    public const QR_ICON = 'icon';
    public const QR_FULL = 'full';

    public const REDIRECT_TYPES = [301, 302, 307, 308];

    // Public Properties
    // =========================================================================

    /**
     * @var string The Short.io secret API key. Best set to an environment
     *      variable reference such as $SHORT_IO_API_KEY.
     */
    public string $apiKey = '';

    /**
     * @var string The Short.io domain links are created on, e.g. go.example.com.
     */
    public string $domain = '';

    /**
     * @var array|string Section handles that get short links, or '*' for all.
     */
    public array|string $sections = '*';

    /**
     * @var string Whether every site gets its own link, or only the primary one.
     */
    public string $siteMode = self::SITE_MODE_ALL;

    /**
     * @var bool Whether a link is created automatically, with no editor action.
     */
    public bool $autoPath = true;

    /**
     * @var string A prefix applied to automatically generated paths, e.g. 'blog/'.
     */
    public string $pathPrefix = '';

    /**
     * @var int The HTTP redirect code Short.io serves for these links.
     */
    public int $redirectType = 302;

    /**
     * @var bool Whether the entry title is sent to Short.io as the link title.
     */
    public bool $titleFromEntry = true;

    /**
     * @var array Tags applied to every link the plugin creates.
     */
    public array $tags = [];

    /**
     * @var string An object template used to build the destination URL. The
     *      resolved entry URL is available as {url}.
     */
    public string $destinationTemplate = '';

    /**
     * @var array Default campaign (UTM) values applied to every link, unless an
     *      entry overrides them. Each may be an object template, so a value can
     *      vary per entry - {slug} for instance. Blank means the parameter is
     *      not added at all.
     */
    public array $utmDefaults = [
        'source' => '',
        'medium' => '',
        'campaign' => '',
        'term' => '',
        'content' => '',
    ];

    /**
     * @var bool Whether the plugin refuses to modify any link it didn't create.
     *
     *      Short.io has no way of marking which links belong to Craft, so the
     *      plugin's own table is the only record. With this on, a link that
     *      isn't in that table is never renamed, repointed or taken over - a
     *      wanted path that already exists stops the save instead. Leave it on
     *      unless the domain is used by Craft and nothing else.
     */
    public bool $protectExistingLinks = true;

    /**
     * @var bool Whether an existing, unclaimed link at a wanted path is taken
     *      over and repointed at the entry. Ignored while protectExistingLinks
     *      is on.
     */
    public bool $adoptExistingPaths = false;

    /**
     * @var string What happens to a link when its entry stops being live.
     */
    public string $onUnpublish = self::ON_UNPUBLISH_EXPIRE;

    /**
     * @var string What happens to a link when its entry is soft-deleted. A hard
     *      delete always deletes the link.
     */
    public string $onDelete = self::ON_UNPUBLISH_EXPIRE;

    /**
     * @var string Where an expired link sends visitors. Blank means the site's
     *      base URL.
     */
    public string $expiredUrl = '';

    /**
     * @var string What happens when Short.io can't be reached at all.
     *
     *      Defaults to letting the save through and retrying in the queue: an
     *      outage at Short.io is no reason an editor can't publish. A rejected
     *      key or a path that's already taken still stops the save either way,
     *      because those are about the entry rather than the weather.
     */
    public string $failureMode = self::FAILURE_WARN;

    /**
     * @var bool Whether console requests (including resave/entries) sync links.
     */
    public bool $syncOnResave = false;

    /**
     * @var string How the QR code appears in the entry sidebar.
     */
    public string $qrViewMode = self::QR_ICON;

    /**
     * @var array|string A single row of QR styling: size, colour, background, type.
     */
    public array|string $qrStyle = [
        [
            'size' => 8,
            'color' => '',
            'backgroundColor' => '',
            'type' => 'png',
        ],
    ];

    /**
     * @var bool Whether click counts are shown in the entry sidebar.
     */
    public bool $showClicks = true;

    /**
     * @var int The HTTP timeout, in seconds, for Short.io requests.
     */
    public int $httpTimeout = 10;

    /**
     * @var int How long click statistics are cached, in seconds.
     */
    public int $statsCacheDuration = 900;

    /**
     * @var int How long the domain list is cached, in seconds.
     */
    public int $domainCacheDuration = 3600;

    /**
     * @var int How long generated QR images are cached, in seconds.
     */
    public int $qrCacheDuration = 2592000;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['apiKey', 'domain', 'pathPrefix', 'destinationTemplate', 'expiredUrl'], 'string'];
        $rules[] = [['domain'], 'validateDomain'];
        $rules[] = [['redirectType'], 'in', 'range' => self::REDIRECT_TYPES];
        $rules[] = [['siteMode'], 'in', 'range' => [self::SITE_MODE_ALL, self::SITE_MODE_PRIMARY]];
        $rules[] = [['onUnpublish', 'onDelete'], 'in', 'range' => [
            self::ON_UNPUBLISH_EXPIRE,
            self::ON_UNPUBLISH_DELETE,
            self::ON_UNPUBLISH_NOTHING,
        ]];
        $rules[] = [['failureMode'], 'in', 'range' => [self::FAILURE_BLOCK, self::FAILURE_WARN]];
        $rules[] = [['qrViewMode'], 'in', 'range' => [self::QR_NONE, self::QR_ICON, self::QR_FULL]];
        $rules[] = [
            ['httpTimeout', 'statsCacheDuration', 'domainCacheDuration', 'qrCacheDuration'],
            'integer',
            'min' => 0,
        ];
        $rules[] = [['autoPath', 'titleFromEntry', 'adoptExistingPaths', 'protectExistingLinks', 'syncOnResave', 'showClicks'], 'boolean'];
        $rules[] = [['sections'], 'filter', 'filter' => [$this, 'filterSections']];
        $rules[] = [['utmDefaults'], 'filter', 'filter' => [$this, 'filterUtmDefaults']];
        $rules[] = [['tags'], 'filter', 'filter' => [$this, 'filterTags']];
        $rules[] = [['qrStyle'], 'filter', 'filter' => [$this, 'filterQrStyle']];
        $rules[] = [['httpTimeout'], 'required'];

        return $rules;
    }

    /**
     * Validates that the configured domain exists on the Short.io account.
     *
     * Degrades silently when the domain list comes back empty: that means there
     * is no API key yet, or Short.io is unreachable, and neither should stop
     * someone saving the settings screen for the first time.
     *
     * @param string $attribute
     * @return void
     */
    public function validateDomain(string $attribute): void
    {
        $value = trim((string)$this->$attribute);

        if ($value === '' || str_starts_with($value, '$')) {
            return;
        }

        $hostnames = Plugin::getInstance()->domains->getHostnames();

        if ($hostnames === []) {
            return;
        }

        if (!in_array($value, $hostnames, true)) {
            $this->addError($attribute, Craft::t('short-io', '“{domain}” isn’t a domain on this Short.io account.', [
                'domain' => $value,
            ]));
        }
    }

    /**
     * Returns whether there's enough configuration to talk to Short.io.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->getApiKey() !== '' && $this->getDomain() !== '';
    }

    /**
     * Returns the API key with any environment variable resolved.
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return trim((string)App::parseEnv($this->apiKey));
    }

    /**
     * Returns the domain hostname with any environment variable resolved.
     *
     * @return string
     */
    public function getDomain(): string
    {
        return trim((string)App::parseEnv($this->domain));
    }

    /**
     * Returns the enabled section handles, or ['*'].
     *
     * @return array
     */
    public function getSectionHandles(): array
    {
        $env = App::env('SHORT_IO_SECTIONS');

        if ($env !== null && $env !== '') {
            return array_values(array_filter(array_map('trim', explode(',', (string)$env))));
        }

        if ($this->sections === '*' || $this->sections === []) {
            return ['*'];
        }

        return (array)$this->sections;
    }

    /**
     * Returns whether the SHORT_IO_SECTIONS environment variable is overriding
     * the stored section list.
     *
     * @return bool
     */
    public function isSectionsOverridden(): bool
    {
        $env = App::env('SHORT_IO_SECTIONS');

        return $env !== null && $env !== '';
    }

    /**
     * Returns whether a section handle is short-linked.
     *
     * @param string|null $handle
     * @return bool
     */
    public function appliesToSection(?string $handle): bool
    {
        if ($handle === null) {
            return false;
        }

        $handles = $this->getSectionHandles();

        return in_array('*', $handles, true) || in_array($handle, $handles, true);
    }

    /**
     * Returns the normalised single row of QR styling.
     *
     * @return array
     */
    public function getQrStyle(): array
    {
        $rows = $this->filterQrStyle($this->qrStyle);

        return $rows[0];
    }

    /**
     * The five campaign parameters, in the order they are shown.
     *
     * @return array
     */
    public static function utmKeys(): array
    {
        return ['source', 'medium', 'campaign', 'term', 'content'];
    }

    /**
     * Returns the default campaign values, with every key present.
     *
     * @return array
     */
    public function getUtmDefaults(): array
    {
        return $this->filterUtmDefaults($this->utmDefaults);
    }

    /**
     * Returns whether any campaign default is set at all.
     *
     * @return bool
     */
    public function hasUtmDefaults(): bool
    {
        foreach ($this->getUtmDefaults() as $value) {
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalises the campaign defaults so every key exists and is a string.
     *
     * @param mixed $value
     * @return array
     */
    public function filterUtmDefaults(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $out = [];

        foreach (self::utmKeys() as $key) {
            $out[$key] = trim((string)($value[$key] ?? ''));
        }

        return $out;
    }

    /**
     * Normalises the sections setting.
     *
     * @param mixed $value
     * @return array|string
     */
    public function filterSections(mixed $value): array|string
    {
        if (!is_array($value) || $value === []) {
            return '*';
        }

        if (in_array('*', $value, true)) {
            return '*';
        }

        return array_values($value);
    }

    /**
     * Normalises the tags setting, which the CP posts as a comma-separated string.
     *
     * @param mixed $value
     * @return array
     */
    public function filterTags(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', $value), static fn($tag) => $tag !== '')));
    }

    /**
     * Reduces the QR style table to exactly one row and clamps its cells.
     *
     * Blank cells stay blank, so Short.io's own domain-level defaults apply
     * rather than us inventing values it never asked for.
     *
     * @param mixed $value
     * @return array
     */
    public function filterQrStyle(mixed $value): array
    {
        $row = is_array($value) ? (reset($value) ?: []) : [];

        if (!is_array($row)) {
            $row = [];
        }

        $size = $row['size'] ?? '';
        if ($size !== '' && $size !== null) {
            $size = max(1, min(99, (int)$size));
        }

        $type = ($row['type'] ?? 'png') === 'svg' ? 'svg' : 'png';

        return [
            [
                'size' => $size,
                'color' => $this->_normalizeHex($row['color'] ?? ''),
                'backgroundColor' => $this->_normalizeHex($row['backgroundColor'] ?? ''),
                'type' => $type,
            ],
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Normalises a hex colour to exactly one leading hash, or blank.
     *
     * @param mixed $value
     * @return string
     */
    private function _normalizeHex(mixed $value): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        $value = ltrim($value, '#');

        if (!preg_match('/^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $value)) {
            return '';
        }

        return '#' . strtolower($value);
    }
}
