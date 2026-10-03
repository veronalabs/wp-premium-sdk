<?php

namespace VeronaLabs\WpPremiumSdk\License;

use RuntimeException;

/**
 * Thrown when the `wp_premium_sdk/activation_gate` filter refuses an activation.
 *
 * By then Nexus has already given this site a seat, so the SDK tries to hand it
 * back. This exception reports whether that worked, so the host can tell the user
 * to free the seat from their account when it did not. The message is the gate's
 * own error text.
 */
class ActivationVetoedException extends RuntimeException
{
    private bool $removedRemotely;

    private ?string $rollbackErrorCode;

    public function __construct(string $message, bool $removedRemotely, ?string $rollbackErrorCode = null)
    {
        parent::__construct($message);

        $this->removedRemotely = $removedRemotely;
        $this->rollbackErrorCode = $rollbackErrorCode;
    }

    /**
     * Whether Nexus confirmed the seat was released.
     */
    public function removedRemotely(): bool
    {
        return $this->removedRemotely;
    }

    /**
     * Why the release failed (a LicenseErrorCode value), or null when it worked.
     */
    public function rollbackErrorCode(): ?string
    {
        return $this->rollbackErrorCode;
    }
}
