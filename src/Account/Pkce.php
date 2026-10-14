<?php

namespace VeronaLabs\WpPremiumSdk\Account;

/**
 * PKCE (RFC 7636) for the account sign-in.
 *
 * The site makes a random code verifier, keeps it on the server next to the OAuth
 * state, and sends Nexus only its S256 challenge on the authorize URL. The code
 * Nexus sends back can then be exchanged only by whoever holds the verifier, so a
 * code that leaks from the redirect (browser history, logs, a referrer) is useless
 * on its own. The verifier never goes into a URL, a cookie or the browser.
 */
final class Pkce
{
    /** The only challenge method Nexus accepts. */
    public const METHOD = 'S256';

    /** Random bytes behind a verifier: 48 bytes encode to 64 characters. */
    private const VERIFIER_BYTES = 48;

    /**
     * A new code verifier: 64 characters of [A-Za-z0-9_-], inside the 43–128
     * characters of [A-Za-z0-9._~-] the spec allows.
     */
    public static function createVerifier(): string
    {
        return self::base64Url(random_bytes(self::VERIFIER_BYTES));
    }

    /**
     * The S256 challenge for a verifier: BASE64URL(SHA256(verifier)) without
     * padding, always 43 characters.
     */
    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
