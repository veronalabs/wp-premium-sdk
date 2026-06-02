<?php

namespace VeronaLabs\WpPremiumSdk\License;

/**
 * Canonical, machine-readable license codes shared by the SDK and Nexus.
 *
 * These are language-neutral identifiers — NOT user-facing strings. Each host
 * plugin maps a code to a translatable message under its own literal text
 * domain (WordPress .pot scanners only extract literal-domain strings, so the
 * human text cannot live here). Nexus emits the action error codes; the SDK
 * computes the state codes from stored license data (see LicenseManager::classify()).
 *
 * Values are a stable contract: never rename a value, only add new ones. See
 * docs/nexus-license-error-codes.md for when Nexus should emit each one.
 *
 * No native enum — the SDK floors at PHP 7.4.
 */
final class LicenseErrorCode
{
    // --- License STATE codes (computed by LicenseManager::classify()) ---------

    /** License is active and within its validity window. */
    public const ACTIVE = 'active';

    /** Active, but expires within the warning window (<= 14 days). */
    public const EXPIRING_SOON = 'expiring_soon';

    /** Past its expiry date, or Nexus reports status "expired". */
    public const EXPIRED = 'expired';

    /** Suspended by Nexus (e.g. a billing/payment problem). */
    public const SUSPENDED = 'suspended';

    /** Revoked by Nexus (e.g. refund, chargeback, abuse). */
    public const REVOKED = 'revoked';

    /** Disabled by Nexus (administratively turned off). */
    public const DISABLED = 'disabled';

    /** Activation count has reached max_activations. */
    public const OVER_LIMIT = 'over_limit';

    /** No license key stored on this site yet. */
    public const NOT_ACTIVATED = 'not_activated';

    /** Stored status is present but unrecognized — safe catch-all. */
    public const INVALID = 'invalid';

    // --- ACTION error codes (emitted by Nexus on activate/validate/deactivate) -

    /** The supplied key does not exist / is malformed. */
    public const INVALID_KEY = 'invalid_key';

    /** The key exists but has been disabled. */
    public const KEY_DISABLED = 'key_disabled';

    /** No activation slots remain for this key. */
    public const ACTIVATION_LIMIT_REACHED = 'activation_limit_reached';

    /** This domain is not allowed to activate the key. */
    public const DOMAIN_NOT_ALLOWED = 'domain_not_allowed';

    /** The key is expired (server-side rejection during an action). */
    public const LICENSE_EXPIRED = 'license_expired';

    /** The key is suspended (server-side rejection during an action). */
    public const LICENSE_SUSPENDED = 'license_suspended';

    /** Too many requests — the caller is rate limited. */
    public const RATE_LIMITED = 'rate_limited';

    /** Nexus encountered an internal error. */
    public const SERVER_ERROR = 'server_error';

    /** Nexus returned an error with no recognized code. */
    public const UNKNOWN = 'unknown';

    // --- CLIENT-only error codes (never sent by Nexus) ------------------------

    /** WP HTTP transport failure (WP_Error) — could not reach the server. */
    public const NETWORK_ERROR = 'network_error';

    /** The server responded, but the body was not valid JSON. */
    public const INVALID_RESPONSE = 'invalid_response';

    /**
     * All canonical code values, deduplicated.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ACTIVE,
            self::EXPIRING_SOON,
            self::EXPIRED,
            self::SUSPENDED,
            self::REVOKED,
            self::DISABLED,
            self::OVER_LIMIT,
            self::NOT_ACTIVATED,
            self::INVALID,
            self::INVALID_KEY,
            self::KEY_DISABLED,
            self::ACTIVATION_LIMIT_REACHED,
            self::DOMAIN_NOT_ALLOWED,
            self::LICENSE_EXPIRED,
            self::LICENSE_SUSPENDED,
            self::RATE_LIMITED,
            self::SERVER_ERROR,
            self::UNKNOWN,
            self::NETWORK_ERROR,
            self::INVALID_RESPONSE,
        ];
    }

    /**
     * Whether a code is one the SDK knows about. Unknown codes are valid input —
     * callers fall back to the server message — this just reports recognition.
     */
    public static function isKnown(string $code): bool
    {
        return in_array($code, self::all(), true);
    }
}
