<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\models;

use coyshdigital\shortio\services\Client;
use craft\helpers\Json;
use Psr\Http\Message\ResponseInterface;

/**
 * The outcome of one Short.io API call.
 *
 * Every Client method returns one of these rather than throwing, so callers can
 * branch on status without a try/catch around each call.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class ApiResult
{
    // Public Properties
    // =========================================================================

    /**
     * @var string One of the Client::STATUS_* constants.
     */
    public string $status = Client::STATUS_FAILED;

    /**
     * @var int The HTTP status code, or 0 if the request never completed.
     */
    public int $httpStatus = 0;

    /**
     * @var array|null The decoded JSON response body.
     */
    public ?array $data = null;

    /**
     * @var string|null The raw response body, used for QR image bytes.
     */
    public ?string $raw = null;

    /**
     * @var string|null A human-readable error message from Short.io.
     */
    public ?string $message = null;

    /**
     * @var string|null The request field Short.io blamed, when it names one.
     */
    public ?string $field = null;

    /**
     * @var int|null The Retry-After value, in seconds.
     */
    public ?int $retryAfter = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns whether the call succeeded.
     *
     * @return bool
     */
    public function isOk(): bool
    {
        return $this->status === Client::STATUS_OK;
    }

    /**
     * Returns whether the call is worth retrying later.
     *
     * @return bool
     */
    public function isTransient(): bool
    {
        return $this->status === Client::STATUS_RETRY;
    }

    /**
     * Returns whether Short.io says the resource doesn't exist.
     *
     * @return bool
     */
    public function isNotFound(): bool
    {
        return $this->status === Client::STATUS_NOT_FOUND;
    }

    /**
     * Returns whether the path is already taken.
     *
     * @return bool
     */
    public function isConflict(): bool
    {
        return $this->status === Client::STATUS_CONFLICT;
    }

    /**
     * Returns a value from the decoded response body.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Builds a result from a Guzzle response.
     *
     * @param ResponseInterface $response
     * @param bool $raw Whether the body is binary (a QR image) rather than JSON.
     * @return self
     */
    public static function fromResponse(ResponseInterface $response, bool $raw = false): self
    {
        $result = new self();
        $result->httpStatus = $response->getStatusCode();
        $result->status = Client::statusForHttpCode($result->httpStatus);

        $body = (string)$response->getBody();

        if ($raw && $result->isOk()) {
            $result->raw = $body;
            return $result;
        }

        if ($body !== '') {
            try {
                $decoded = Json::decode($body);
                $result->data = is_array($decoded) ? $decoded : null;
            } catch (\Throwable) {
                // Short.io occasionally returns an HTML error page. Keep the
                // body around for the log rather than pretending it was JSON.
                $result->message = mb_substr(strip_tags($body), 0, 200);
            }
        }

        if (!$result->isOk() && $result->data !== null) {
            $result->message = $result->data['message']
                ?? $result->data['error']
                ?? $result->data['code']
                ?? null;
            $result->field = $result->data['field'] ?? null;
        }

        if ($response->hasHeader('Retry-After')) {
            $result->retryAfter = (int)$response->getHeaderLine('Retry-After') ?: null;
        }

        return $result;
    }

    /**
     * Builds a synthetic result for a request that never reached Short.io.
     *
     * @param string $status
     * @param string|null $message
     * @return self
     */
    public static function synthetic(string $status, ?string $message = null): self
    {
        $result = new self();
        $result->status = $status;
        $result->message = $message;

        return $result;
    }
}
