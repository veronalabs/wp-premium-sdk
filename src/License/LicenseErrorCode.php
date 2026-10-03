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

    /**
     * More sites are activated than the license allows (activation_count >
     * max_activations), or this site is not activated and every seat is taken.
     */
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

    /** The key is valid, but for a different product than the one asking. */
    public const WRONG_PRODUCT = 'wrong_product';

    /** The account access token has expired (sent on an api/v1 401). */
    public const TOKEN_EXPIRED = 'token_expired';

    /** Too many requests — the caller is rate limited. Carries retry_after when known. */
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
     * The account sign-in expired mid-flow (Nexus answered 401 or token_expired).
     * The SDK has already cleared the session; the host asks the user to sign in again.
     */
    public const ACCOUNT_EXPIRED = 'account_expired';

    /**
     * The account sign-in in progress was started by another WordPress user; this
     * user gets the normal activation screen instead of their licenses.
     */
    public const SIGN_IN_OTHER_USER = 'sign_in_other_user';

    /**
     * Network Admin asked to activate more subsites than the license has free seats.
     * Carries `needed` and `left`.
     */
    public const NOT_ENOUGH_SEATS = 'not_enough_seats';

    /**
     * The key is set by a wp-config constant, so the license page cannot change or
     * remove it. The host says: "The key is set in wp-config.php. Delete it there."
     */
    public const KEY_FROM_CONSTANT = 'key_from_constant';

    /**
     * The plugin is network-activated: the network admin manages the key, and a
     * subsite can only view it.
     */
    public const NETWORK_MANAGED = 'network_managed';

    /** The site has a license, but it is still registered to another domain. */
    public const DOMAIN_UNCHANGED = 'domain_unchanged';

    /** File changes are switched off on this site (DISALLOW_FILE_MODS or a filter). */
    public const FILE_MODS_DISABLED = 'file_mods_disabled';

    /** WordPress needs FTP/SSH credentials to write plugin files. */
    public const FILESYSTEM_CREDENTIALS_NEEDED = 'filesystem_credentials_needed';

    /** The plugin did not say which tier is installed (ClientConfig `installed_tier`). */
    public const INSTALLED_TIER_UNKNOWN = 'installed_tier_unknown';

    /** Nexus did not say which tier the license gets (a server older than tier_slug on the manifest). */
    public const LICENSED_TIER_UNKNOWN = 'licensed_tier_unknown';

    /** The manifest carried no package to install. */
    public const PACKAGE_UNAVAILABLE = 'package_unavailable';

    /** WordPress could not install the package. */
    public const INSTALL_FAILED = 'install_failed';

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
            self::WRONG_PRODUCT,
            self::TOKEN_EXPIRED,
            self::RATE_LIMITED,
            self::SERVER_ERROR,
            self::UNKNOWN,
            self::NETWORK_ERROR,
            self::INVALID_RESPONSE,
            self::ACCOUNT_EXPIRED,
            self::SIGN_IN_OTHER_USER,
            self::NOT_ENOUGH_SEATS,
            self::KEY_FROM_CONSTANT,
            self::NETWORK_MANAGED,
            self::DOMAIN_UNCHANGED,
            self::FILE_MODS_DISABLED,
            self::FILESYSTEM_CREDENTIALS_NEEDED,
            self::INSTALLED_TIER_UNKNOWN,
            self::LICENSED_TIER_UNKNOWN,
            self::PACKAGE_UNAVAILABLE,
            self::INSTALL_FAILED,
        ];
    }

    /**
     * Whether a failure says nothing about the license itself: the server could not
     * be reached, broke, sent something unreadable, or asked us to slow down. Callers
     * keep their cached license on these and treat every other code as the server's
     * answer.
     */
    public static function isTransient(string $code): bool
    {
        return in_array($code, [
            self::NETWORK_ERROR,
            self::SERVER_ERROR,
            self::INVALID_RESPONSE,
            self::RATE_LIMITED,
        ], true);
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
