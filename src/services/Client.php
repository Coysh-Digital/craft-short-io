<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\services;

use coyshdigital\shortio\models\ApiResult;
use coyshdigital\shortio\models\Settings;
use coyshdigital\shortio\Plugin;
use Craft;
use GuzzleHttp\Client as GuzzleClient;
use yii\base\Component;

/**
 * The only place the plugin speaks HTTP to Short.io.
 *
 * Every method returns an ApiResult rather than throwing, and no method ever
 * lets a Throwable escape: a link is never worth taking a page down for.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Client extends Component
{
    // Constants
    // =========================================================================

    public const API_BASE = 'https://api.short.io/';
    public const STATS_BASE = 'https://statistics.short.io/';

    public const STATUS_OK = 'ok';
    public const STATUS_NOT_FOUND = 'notFound';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_RETRY = 'retry';
    public const STATUS_FAILED = 'failed';

    /**
     * Short.io's published per-endpoint rate limits, as requests per second.
     *
     * @see https://developers.short.io/reference/post_links
     */
    public const BUCKET_CREATE = 'create';
    public const BUCKET_MUTATE = 'mutate';
    public const BUCKET_STATS = 'stats';

    private const BUCKET_RATES = [
        self::BUCKET_CREATE => 50.0,
        self::BUCKET_MUTATE => 20.0,
        self::BUCKET_STATS => 10.0,
    ];

    // Private Properties
    // =========================================================================

    /**
     * @var GuzzleClient|null
     */
    private ?GuzzleClient $_client = null;

    /**
     * @var GuzzleClient|null
     */
    private ?GuzzleClient $_statsClient = null;

    /**
     * @var bool Whether the throttle may sleep. Only ever true off the web request.
     */
    private bool $_allowSleep = false;

    // Public Methods
    // =========================================================================

    /**
     * Overrides the API client. Test seam.
     *
     * @param GuzzleClient|null $client
     * @return void
     */
    public function setClient(?GuzzleClient $client): void
    {
        $this->_client = $client;
    }

    /**
     * Overrides the statistics client. Test seam.
     *
     * @param GuzzleClient|null $client
     * @return void
     */
    public function setStatsClient(?GuzzleClient $client): void
    {
        $this->_statsClient = $client;
    }

    /**
     * Allows the throttle to sleep rather than failing fast. Console and queue
     * contexts only - a web request must never block on a rate limit.
     *
     * @param bool $allowSleep
     * @return self
     */
    public function setAllowSleep(bool $allowSleep): self
    {
        $this->_allowSleep = $allowSleep;

        return $this;
    }

    /**
     * Returns whether there's an API key to work with.
     *
     * Deliberately not Settings::isConfigured(), which also wants a domain.
     * Listing domains is how you find out what to put in that setting, so
     * requiring one here would make the settings screen's domain picker
     * impossible to populate on a fresh install.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->_settings()->getApiKey() !== '';
    }

    /**
     * Creates a link.
     *
     * @param array $body
     * @return ApiResult
     */
    public function createLink(array $body): ApiResult
    {
        return $this->_request(self::BUCKET_CREATE, 'POST', 'links', ['json' => $body]);
    }

    /**
     * Updates a link. Short.io uses POST here, not PATCH or PUT.
     *
     * @param string $idString
     * @param array $body
     * @param int|null $domainId
     * @return ApiResult
     */
    public function updateLink(string $idString, array $body, ?int $domainId = null): ApiResult
    {
        $options = ['json' => $body];

        if ($domainId !== null) {
            $options['query'] = ['domain_id' => $domainId];
        }

        return $this->_request(self::BUCKET_MUTATE, 'POST', 'links/' . rawurlencode($idString), $options);
    }

    /**
     * Archives or unarchives a link.
     *
     * Short.io has dedicated endpoints for this, and they are not optional:
     * sending `archived: false` to the update endpoint answers 200 and then
     * leaves the link archived anyway.
     *
     * Note that archiving does not stop a link redirecting - it only hides it
     * from the Short.io dashboard.
     *
     * @param string $idString
     * @param bool $archived
     * @param int|null $domainId
     * @return ApiResult
     */
    public function setArchived(string $idString, bool $archived, ?int $domainId = null): ApiResult
    {
        $body = ['link_id' => $idString];

        if ($domainId !== null) {
            $body['domain_id'] = $domainId;
        }

        return $this->_request(
            self::BUCKET_MUTATE,
            'POST',
            $archived ? 'links/archive' : 'links/unarchive',
            ['json' => $body]
        );
    }

    /**
     * Deletes a link.
     *
     * @param string $idString
     * @return ApiResult
     */
    public function deleteLink(string $idString): ApiResult
    {
        return $this->_request(self::BUCKET_MUTATE, 'DELETE', 'links/' . rawurlencode($idString));
    }

    /**
     * Looks a link up by domain and path.
     *
     * @param string $domain
     * @param string $path
     * @return ApiResult
     */
    public function expand(string $domain, string $path): ApiResult
    {
        return $this->_request(self::BUCKET_MUTATE, 'GET', 'links/expand', [
            'query' => ['domain' => $domain, 'path' => $path],
        ]);
    }

    /**
     * Lists links on a domain, one page at a time.
     *
     * @param int $domainId
     * @param string|null $pageToken
     * @param int $limit
     * @return ApiResult
     */
    public function listLinks(int $domainId, ?string $pageToken = null, int $limit = 150): ApiResult
    {
        $query = [
            'domain_id' => $domainId,
            'limit' => max(1, min(150, $limit)),
        ];

        if ($pageToken !== null && $pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }

        return $this->_request(self::BUCKET_MUTATE, 'GET', 'api/links', ['query' => $query]);
    }

    /**
     * Lists the domains on the account.
     *
     * @param int $limit
     * @return ApiResult
     */
    public function listDomains(int $limit = 300): ApiResult
    {
        return $this->_request(self::BUCKET_MUTATE, 'GET', 'api/domains', [
            'query' => ['limit' => max(1, min(300, $limit))],
        ]);
    }

    /**
     * Generates a QR code. The response body is image bytes, not JSON.
     *
     * @param string $idString
     * @param array $options
     * @return ApiResult
     */
    public function qr(string $idString, array $options): ApiResult
    {
        return $this->_request(
            self::BUCKET_MUTATE,
            'POST',
            'links/qr/' . rawurlencode($idString),
            ['json' => $options],
            true
        );
    }

    /**
     * Fetches link statistics. Different host, different response shape.
     *
     * @param string $identifier
     * @param array $query
     * @return ApiResult
     */
    public function statistics(string $identifier, array $query = []): ApiResult
    {
        return $this->_request(
            self::BUCKET_STATS,
            'GET',
            'statistics/link/' . rawurlencode($identifier),
            ['query' => $query],
            false,
            true
        );
    }

    /**
     * Classifies an HTTP status code.
     *
     * 404 and 409 are pulled out because the link reconciliation logic branches
     * on them: a 404 means "create instead", a 409 means "that path is taken".
     *
     * @param int $code
     * @return string
     */
    public static function statusForHttpCode(int $code): string
    {
        if ($code >= 200 && $code < 300) {
            return self::STATUS_OK;
        }

        if ($code === 404) {
            return self::STATUS_NOT_FOUND;
        }

        if ($code === 409) {
            return self::STATUS_CONFLICT;
        }

        if ($code === 429 || $code >= 500) {
            return self::STATUS_RETRY;
        }

        return self::STATUS_FAILED;
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
     * Builds the main API client.
     *
     * @return GuzzleClient
     */
    private function _client(): GuzzleClient
    {
        if ($this->_client === null) {
            $this->_client = Craft::createGuzzleClient([
                'base_uri' => self::API_BASE,
                'timeout' => $this->_settings()->httpTimeout,
                'http_errors' => false,
                'headers' => [
                    // Short.io wants the raw key. "Bearer <key>" 401s.
                    'Authorization' => $this->_settings()->getApiKey(),
                    'Accept' => 'application/json',
                    // Deliberately no Content-Type here. Short.io answers a
                    // body-less DELETE with 400 Bad Request when one is set,
                    // and Guzzle adds the header itself for requests that do
                    // carry a JSON body.
                ],
            ]);
        }

        return $this->_client;
    }

    /**
     * Builds the statistics client. Statistics live on their own host, so this
     * can't share the API client.
     *
     * @return GuzzleClient
     */
    private function _statsClient(): GuzzleClient
    {
        if ($this->_statsClient === null) {
            $this->_statsClient = Craft::createGuzzleClient([
                'base_uri' => self::STATS_BASE,
                'timeout' => $this->_settings()->httpTimeout,
                'http_errors' => false,
                'headers' => [
                    'Authorization' => $this->_settings()->getApiKey(),
                    'Accept' => 'application/json',
                ],
            ]);
        }

        return $this->_statsClient;
    }

    /**
     * Issues one request and turns whatever happens into an ApiResult.
     *
     * @param string $bucket
     * @param string $method
     * @param string $uri
     * @param array $options
     * @param bool $raw
     * @param bool $stats
     * @return ApiResult
     */
    private function _request(
        string $bucket,
        string $method,
        string $uri,
        array $options = [],
        bool $raw = false,
        bool $stats = false,
    ): ApiResult {
        if (!$this->isConfigured()) {
            return ApiResult::synthetic(
                self::STATUS_FAILED,
                Craft::t('short-io', 'No Short.io API key is set.')
            );
        }

        if (!$this->_throttle($bucket)) {
            return ApiResult::synthetic(
                self::STATUS_RETRY,
                Craft::t('short-io', 'Short.io’s rate limit was reached.')
            );
        }

        try {
            $client = $stats ? $this->_statsClient() : $this->_client();
            $response = $client->request($method, $uri, $options);
            $result = ApiResult::fromResponse($response, $raw);
        } catch (\Throwable $e) {
            // A connection failure is worth retrying; it says nothing about
            // whether the request itself was valid.
            Craft::warning("Short.io request failed ({$method} {$uri}): " . $e->getMessage(), __METHOD__);

            return ApiResult::synthetic(self::STATUS_RETRY, $e->getMessage());
        }

        if (!$result->isOk() && !$result->isNotFound()) {
            Craft::warning(
                "Short.io returned {$result->httpStatus} for {$method} {$uri}: " . ($result->message ?? 'no message'),
                __METHOD__
            );
        }

        return $result;
    }

    /**
     * Best-effort rate limiting, per endpoint bucket.
     *
     * Fails open when there's no cache: throttling is a courtesy to Short.io,
     * not a correctness guarantee, and a missing cache shouldn't stop the plugin
     * working. Returns false when the caller should back off.
     *
     * @param string $bucket
     * @return bool
     */
    private function _throttle(string $bucket): bool
    {
        $perSecond = self::BUCKET_RATES[$bucket] ?? 20.0;
        $cache = Craft::$app->getCache();
        $window = (int)floor(microtime(true) * $perSecond);
        $key = "short-io:rl:{$bucket}:{$window}";

        try {
            $count = (int)$cache->get($key);

            if ($count < 1) {
                $cache->set($key, 1, 2);
                return true;
            }

            if (!$this->_allowSleep) {
                return false;
            }

            // Console and queue only: wait out the window rather than failing.
            usleep((int)min(1000000, 1000000 / $perSecond));
            $cache->set("short-io:rl:{$bucket}:" . ($window + 1), 1, 2);

            return true;
        } catch (\Throwable) {
            return true;
        }
    }
}
