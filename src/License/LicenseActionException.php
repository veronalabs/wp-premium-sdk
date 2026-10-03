<?php

namespace VeronaLabs\WpPremiumSdk\License;

use RuntimeException;

/**
 * The SDK refused or could not finish a license action on its own side, before or
 * without Nexus saying no: the key is managed elsewhere (`key_from_constant`,
 * `network_managed`), WordPress may not write files (`file_mods_disabled`,
 * `filesystem_credentials_needed`), and the like.
 *
 * Carries a LicenseErrorCode value the AJAX endpoints pass on as `code`, plus any
 * extra keys for the error payload. The message is English fallback text; hosts
 * show their own translated message for the code.
 */
class LicenseActionException extends RuntimeException
{
    private string $errorCode;

    /** @var array<string, mixed> */
    private array $extra;

    /**
     * @param  array<string, mixed>  $extra  Merged into the AJAX error payload.
     */
    public function __construct(string $errorCode, string $message, array $extra = [])
    {
        parent::__construct($message);

        $this->errorCode = $errorCode;
        $this->extra = $extra;
    }

    /**
     * The machine-readable reason (a LicenseErrorCode value).
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }
}
