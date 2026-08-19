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
use craft\helpers\Json;
use yii\base\Component;

/**
 * QR codes.
 *
 * Short.io's API reference describes POST /links/qr/{id} as returning image
 * bytes. It doesn't: it returns JSON holding a public URL on shortiougc.com,
 * and that URL serves the PNG to anyone.
 *
 * The URL is stable for a link, but it is not simply derivable - fetching it
 * before the API has been asked for it once returns 403. So the authenticated
 * call is what generates the image; after that the URL can be used directly in
 * an <img> tag, on the front end included, and cached by browsers and CDNs like
 * any other image.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Qr extends Component
{
    // Constants
    // =========================================================================

    /**
     * Short.io's API reference documents link ids as `lnk_…`, but the ids it
     * actually issues are `link_…`. Both are accepted here so the plugin keeps
     * working whichever the account returns.
     */
    public const ID_PATTERN = '/^(?:link|lnk)_[A-Za-z0-9_-]+$/';

    // Public Methods
    // =========================================================================

    /**
     * Returns the public image URL for a link's QR code, generating it on first
     * use.
     *
     * @param string $idString
     * @param array $options
     * @return string|null
     */
    public function getUrl(string $idString, array $options = []): ?string
    {
        if (!self::isValidId($idString)) {
            return null;
        }

        $options = $this->normalizeOptions($options);
        $cache = Craft::$app->getCache();
        $key = $this->_cacheKey($idString, $options);
        $cached = $cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $result = Plugin::getInstance()->client->qr($idString, $this->apiPayload($options));

        if (!$result->isOk()) {
            return null;
        }

        // The response is JSON despite the documentation describing image bytes.
        $url = null;

        if ($result->raw !== null && $result->raw !== '') {
            try {
                $decoded = Json::decode($result->raw);
                $url = is_array($decoded) ? ($decoded['url'] ?? null) : null;
            } catch (\Throwable) {
                $url = null;
            }
        }

        if (!is_string($url) || $url === '') {
            return null;
        }

        $cache->set($key, $url, $this->_settings()->qrCacheDuration);

        return $url;
    }

    /**
     * Returns the QR URL for a stored link.
     *
     * @param LinkRecord|null $record
     * @param array $options
     * @return string|null
     */
    public function getUrlForRecord(?LinkRecord $record, array $options = []): ?string
    {
        return $record !== null ? $this->getUrl($record->linkIdString, $options) : null;
    }

    /**
     * Fetches the QR image itself, for templates that want to write the file
     * somewhere.
     *
     * @param string $idString
     * @param array $options
     * @return string|null
     */
    public function getBytes(string $idString, array $options = []): ?string
    {
        $url = $this->getUrl($idString, $options);

        if ($url === null) {
            return null;
        }

        try {
            // No Authorization header: the image URL is deliberately public.
            $response = Craft::createGuzzleClient(['http_errors' => false])->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return (string)$response->getBody();
        } catch (\Throwable $e) {
            Craft::warning('Short.io QR image couldn’t be fetched: ' . $e->getMessage(), __METHOD__);

            return null;
        }
    }

    /**
     * Normalises and clamps QR options.
     *
     * @param array $options
     * @return array
     */
    public function normalizeOptions(array $options = []): array
    {
        $style = $this->_settings()->getQrStyle();

        $size = $options['size'] ?? $style['size'] ?? '';
        if ($size !== '' && $size !== null) {
            // Short.io's size is a small scale factor, not a pixel count.
            $size = max(1, min(99, (int)$size));
        } else {
            $size = '';
        }

        $type = ($options['type'] ?? $style['type'] ?? 'png') === 'svg' ? 'svg' : 'png';
        $color = $this->_hex($options['color'] ?? $style['color'] ?? '');
        $background = $this->_hex($options['backgroundColor'] ?? $style['backgroundColor'] ?? '');

        return [
            'size' => $size,
            'type' => $type,
            'color' => $color,
            'backgroundColor' => $background,
            // Short.io ignores custom colours entirely unless this is off, so
            // setting one has to imply it.
            'useDomainSettings' => $color === '' && $background === '',
        ];
    }

    /**
     * Returns the payload actually sent to Short.io, with blanks stripped.
     *
     * @param array $options
     * @return array
     */
    public function apiPayload(array $options): array
    {
        $payload = ['useDomainSettings' => $options['useDomainSettings'] ?? true];

        foreach (['size', 'color', 'backgroundColor', 'type'] as $key) {
            if (isset($options[$key]) && $options[$key] !== '') {
                $payload[$key] = $options[$key];
            }
        }

        return $payload;
    }

    /**
     * @param string $type
     * @return string
     */
    public function contentType(string $type): string
    {
        return $type === 'svg' ? 'image/svg+xml' : 'image/png';
    }

    /**
     * @param string $idString
     * @return bool
     */
    public static function isValidId(string $idString): bool
    {
        return (bool)preg_match(self::ID_PATTERN, $idString);
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
     * Normalises a hex colour for Short.io, which validates against
     * ^[0-9A-Fa-f]{6,8}$ - so a leading hash is rejected, even though the
     * control panel's colour field stores one.
     *
     * @param mixed $value
     * @return string
     */
    private function _hex(mixed $value): string
    {
        $value = ltrim(trim((string)$value), '#');

        if (!preg_match('/^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $value)) {
            return '';
        }

        return strtolower($value);
    }

    /**
     * @param string $idString
     * @param array $options
     * @return string
     */
    private function _cacheKey(string $idString, array $options): string
    {
        return 'short-io:qr:' . $idString . ':' . sha1(Json::encode($options));
    }
}
