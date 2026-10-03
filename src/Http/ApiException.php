<?php

namespace VeronaLabs\WpPremiumSdk\Http;

use Exception;
use Throwable;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;

/**
 * An API/transport failure carrying a machine-readable error code.
 *
 * The Exception message stays the raw server (or transport) text so today's
 * "show the server message" behavior is preserved as a fallback. The added
 * error code lets callers map the failure to a translatable, scenario-specific
 * message under their own text domain. See LicenseErrorCode for the values.
 *
 * The Exception code is the HTTP status the server answered with (0 when the
 * request never got an answer), so callers can tell a 401 from a 403 without
 * re-parsing the response.
 */
class ApiException extends Exception
{
    private string $errorCode;

    /** @var array<string, mixed> */
    private array $data;

    private ?int $retryAfter;

    /**
     * @param  array<string, mixed>  $data  The parsed error response body, so
     *                                       callers can read extras like the
     *                                       `renewal` block on an expired-license
     *                                       failure. Empty for transport errors.
     * @param  int  $code  The HTTP status code, or 0 for a transport failure.
     * @param  int|null  $retryAfter  Seconds the server asked us to wait (from a
     *                                `Retry-After` header), or null when it did not say.
     */
    public function __construct(string $message, string $errorCode = LicenseErrorCode::UNKNOWN, array $data = [], int $code = 0, ?Throwable $previous = null, ?int $retryAfter = null)
    {
        parent::__construct($message, $code, $previous);

        $this->errorCode = $errorCode !== '' ? $errorCode : LicenseErrorCode::UNKNOWN;
        $this->data = $data;
        $this->retryAfter = $retryAfter;
    }

    /**
     * The canonical, machine-readable error code (a LicenseErrorCode value).
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The parsed error response body from Nexus (empty for transport errors).
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * The HTTP status the server answered with, or 0 when there was no answer.
     */
    public function getHttpStatus(): int
    {
        return (int) $this->getCode();
    }

    /**
     * Seconds to wait before trying again, when the server said (429/503).
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * True when the failure says nothing about the license: the server was not
     * reached, broke, answered with something unreadable, or rate limited us.
     */
    public function isTransient(): bool
    {
        return LicenseErrorCode::isTransient($this->errorCode);
    }
}
