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
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use yii\base\Component;

/**
 * QR code generation.
 *
 * Short.io's QR endpoint is an authenticated POST returning image bytes - there
 * is no public URL to drop into an <img src>, the way Dub has. So every QR goes
 * through the plugin: a control panel action, a data URI, or a signed site
 * action, depending on where it's being rendered.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Qr extends Component
{
    // Constants
    // =========================================================================

    public const ID_PATTERN = '/^lnk_[A-Za-z0-9_-]+$/';

    // Public Methods
    // =========================================================================

    /**
     * Returns the raw image bytes for a link's QR code.
     *
     * @param string $idString
     * @param array $options
     * @return string|null
     */
    public function getBytes(string $idString, array $options = []): ?string
    {
        if (!self::isValidId($idString)) {
            return null;
        }

        $options = $this->normalizeOptions($options);
        $cache = Craft::$app->getCache();
        $key = $this->_cacheKey($idString, $options);
        $cached = $cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return base64_decode($cached, true) ?: null;
        }

        $result = Plugin::getInstance()->client->qr($idString, $this->apiPayload($options));

        if (!$result->isOk() || $result->raw === null || $result->raw === '') {
            return null;
        }

        $cache->set($key, base64_encode($result->raw), $this->_settings()->qrCacheDuration);

        return $result->raw;
    }

    /**
     * Returns a data URI, so a front-end template can render a QR with no public
     * endpoint at all.
     *
     * @param string $idString
     * @param array $options
     * @return string|null
     */
    public function getDataUri(string $idString, array $options = []): ?string
    {
        $options = $this->normalizeOptions($options);
        $bytes = $this->getBytes($idString, $options);

        if ($bytes === null) {
            return null;
        }

        return 'data:' . $this->contentType($options['type']) . ';base64,' . base64_encode($bytes);
    }

    /**
     * Returns the control panel URL that streams a QR.
     *
     * @param string $idString
     * @param array $options
     * @return string
     */
    public function getCpUrl(string $idString, array $options = []): string
    {
        $options = $this->normalizeOptions($options);

        return UrlHelper::actionUrl('short-io/qr/render', [
            'linkId' => $idString,
            'size' => $options['size'],
            'type' => $options['type'],
        ]);
    }

    /**
     * Returns a signed, anonymous site URL for a QR. Only usable when the
     * qrPublic setting is on.
     *
     * @param string $idString
     * @param array $options
     * @return string|null
     */
    public function getSignedUrl(string $idString, array $options = []): ?string
    {
        if (!$this->_settings()->qrPublic) {
            return null;
        }

        return UrlHelper::siteUrl(Craft::$app->getConfig()->getGeneral()->actionTrigger . '/short-io/qr/render', [
            'q' => $this->signToken($idString, $this->normalizeOptions($options)),
        ]);
    }

    /**
     * Signs a QR request so it can be served anonymously without becoming an
     * open proxy to the whole Short.io account.
     *
     * @param string $idString
     * @param array $options
     * @return string
     */
    public function signToken(string $idString, array $options): string
    {
        $payload = Json::encode([
            'i' => $idString,
            's' => $options['size'] ?? '',
            't' => $options['type'] ?? 'png',
            'c' => $options['color'] ?? '',
            'b' => $options['backgroundColor'] ?? '',
            // 0 means never expires, which is what statically cached pages need.
            'e' => $this->_settings()->qrSignedUrlTtl > 0
                ? time() + $this->_settings()->qrSignedUrlTtl
                : 0,
        ]);

        return bin2hex(Craft::$app->getSecurity()->encryptByKey($payload));
    }

    /**
     * Reads a signed QR token back, or null if it's invalid or expired.
     *
     * @param string $token
     * @return array|null
     */
    public function readToken(string $token): ?array
    {
        try {
            $raw = @hex2bin($token);

            if ($raw === false) {
                return null;
            }

            $payload = Json::decode(Craft::$app->getSecurity()->decryptByKey($raw));
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($payload) || !isset($payload['i'])) {
            return null;
        }

        if (!empty($payload['e']) && $payload['e'] < time()) {
            return null;
        }

        return [
            'idString' => (string)$payload['i'],
            'options' => $this->normalizeOptions([
                'size' => $payload['s'] ?? '',
                'type' => $payload['t'] ?? 'png',
                'color' => $payload['c'] ?? '',
                'backgroundColor' => $payload['b'] ?? '',
            ]),
        ];
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

        $normalized = [
            'size' => $size,
            'type' => $type,
            'color' => $color,
            'backgroundColor' => $background,
        ];

        // Short.io ignores custom colours entirely unless this is switched off,
        // so setting a colour has to imply it.
        $normalized['useDomainSettings'] = $color === '' && $background === '';

        return $normalized;
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
     * @param mixed $value
     * @return string
     */
    private function _hex(mixed $value): string
    {
        $value = ltrim(trim((string)$value), '#');

        if (!preg_match('/^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $value)) {
            return '';
        }

        return '#' . strtolower($value);
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
