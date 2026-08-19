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
use Craft;
use yii\base\Component;

/**
 * Resolves Short.io domains.
 *
 * Creating a link needs the domain's hostname; listing and archiving need its
 * numeric id. Both are wanted often enough to be worth caching, and the cache
 * key is hashed from the resolved API key so switching environments can never
 * serve another account's domains.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Domains extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns every domain on the account.
     *
     * @param bool $force Whether to bypass the cache.
     * @return array
     */
    public function getAll(bool $force = false): array
    {
        $settings = $this->_settings();

        if (!$settings->isConfigured() && $settings->getApiKey() === '') {
            return [];
        }

        $cache = Craft::$app->getCache();
        $key = $this->_cacheKey();

        if (!$force) {
            $cached = $cache->get($key);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = Plugin::getInstance()->client->listDomains();

        if (!$result->isOk() || !is_array($result->data)) {
            return [];
        }

        // The endpoint returns a bare array, not an envelope.
        $domains = array_values(array_filter($result->data, static fn($d) => is_array($d) && isset($d['hostname'])));

        $cache->set($key, $domains, $settings->domainCacheDuration);

        return $domains;
    }

    /**
     * Returns just the hostnames.
     *
     * @return array
     */
    public function getHostnames(): array
    {
        return array_values(array_filter(array_map(
            static fn(array $d) => $d['hostname'] ?? null,
            $this->getAll()
        )));
    }

    /**
     * Returns hostname => label options for a select field.
     *
     * @return array
     */
    public function getOptions(): array
    {
        $options = [];

        foreach ($this->getAll() as $domain) {
            $hostname = $domain['hostname'] ?? null;

            if ($hostname === null) {
                continue;
            }

            $state = $domain['state'] ?? null;
            $options[] = [
                'label' => $state !== null && $state !== 'configured'
                    ? sprintf('%s (%s)', $hostname, str_replace('_', ' ', $state))
                    : $hostname,
                'value' => $hostname,
            ];
        }

        return $options;
    }

    /**
     * Returns the domain list shaped for an autosuggest field.
     *
     * A plain select can't be used here: the domain setting is very often an
     * environment variable reference like $SHORT_IO_DOMAIN, which matches no
     * option and would be silently blanked the next time someone saved the
     * settings screen. Autosuggest keeps the free-text value while still
     * offering the real domains to pick from.
     *
     * @return array
     */
    public function getSuggestions(): array
    {
        $data = [];

        foreach ($this->getAll() as $domain) {
            $hostname = $domain['hostname'] ?? null;

            if ($hostname === null) {
                continue;
            }

            $data[] = [
                'name' => $hostname,
                'hint' => ($domain['state'] ?? 'configured') === 'configured'
                    ? null
                    : str_replace('_', ' ', (string)$domain['state']),
            ];
        }

        if ($data === []) {
            return [];
        }

        return [
            [
                'label' => Craft::t('short-io', 'Your Short.io domains'),
                'data' => $data,
            ],
        ];
    }

    /**
     * Resolves a hostname to its full domain record.
     *
     * Falls back to the account's only domain when nothing is configured and
     * there's exactly one to choose from.
     *
     * @param string|null $hostname
     * @return array|null
     */
    public function resolve(?string $hostname = null): ?array
    {
        $hostname = $hostname !== null && $hostname !== '' ? $hostname : $this->_settings()->getDomain();
        $domains = $this->getAll();

        if ($hostname === '') {
            return count($domains) === 1 ? $domains[0] : null;
        }

        foreach ($domains as $domain) {
            if (($domain['hostname'] ?? null) === $hostname) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Returns the configured hostname.
     *
     * @return string|null
     */
    public function getHostname(): ?string
    {
        $hostname = $this->_settings()->getDomain();

        if ($hostname !== '') {
            return $hostname;
        }

        $domain = $this->resolve();

        return $domain['hostname'] ?? null;
    }

    /**
     * Returns the numeric domain id for a hostname.
     *
     * @param string|null $hostname
     * @return int|null
     */
    public function getDomainId(?string $hostname = null): ?int
    {
        $domain = $this->resolve($hostname);

        return isset($domain['id']) ? (int)$domain['id'] : null;
    }

    /**
     * Clears the cached domain list.
     *
     * @return void
     */
    public function clearCache(): void
    {
        Craft::$app->getCache()->delete($this->_cacheKey());
    }

    // Private Methods
    // =========================================================================

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
     * @return string
     */
    private function _cacheKey(): string
    {
        return 'short-io:domains:' . sha1($this->_settings()->getApiKey());
    }
}
