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
 */
class ApiException extends Exception
{
    private string $errorCode;

    public function __construct(string $message, string $errorCode = LicenseErrorCode::UNKNOWN, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->errorCode = $errorCode !== '' ? $errorCode : LicenseErrorCode::UNKNOWN;
    }

    /**
     * The canonical, machine-readable error code (a LicenseErrorCode value).
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
