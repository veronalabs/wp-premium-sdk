<?php

namespace VeronaLabs\WpPremiumSdk\Support;

/**
 * Tiny helper for sanitized $_GET/$_POST access inside AJAX handlers.
 */
class Request
{
    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = '')
    {
        if (isset($_POST[$key])) {
            return is_array($_POST[$key])
                ? array_map('sanitize_text_field', wp_unslash($_POST[$key]))
                : sanitize_text_field(wp_unslash($_POST[$key]));
        }

        if (isset($_GET[$key])) {
            return is_array($_GET[$key])
                ? array_map('sanitize_text_field', wp_unslash($_GET[$key]))
                : sanitize_text_field(wp_unslash($_GET[$key]));
        }

        return $default;
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function getArray(string $key): array
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? [];

        return is_array($value) ? wp_unslash($value) : [];
    }

    /**
     * The address this installation is licensed under.
     *
     * `home_url()` rather than the address of the page being viewed, because those are
     * different questions. A multilingual plugin serves `/en` and `/fr` from one
     * installation and `home_url()` is the same for both — so a translated site does not
     * spend a seat per language, which is what the store's own activation records show
     * happening to customers whose plugin sends the viewed URL.
     *
     * **The path is kept.** In a subdirectory network the path is the only thing telling
     * `example.com/site1` from `example.com/site2`; they are genuinely separate sites with
     * separate content and separate admins. Reducing this to the host alone would report
     * every site in such a network as the same one, and a thousand-site network would
     * activate against a single seat.
     *
     * **Every subsite of a network counts as its own site**, whether the plugin is
     * network-activated or not: each one reports its own `home_url()` and uses its own
     * seat. Networks that need many seats buy a plan with enough of them.
     *
     * Only the scheme and a leading `www.` are dropped, because neither distinguishes one
     * site from another. A trailing slash goes too, so `example.com/` and `example.com`
     * are not two records of one site.
     */
    public static function currentDomain(): string
    {
        $url = self::licensedSiteUrl();

        $host = wp_parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return '';
        }

        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '');
        $path = rtrim($path, '/');

        return $host.$path;
    }

    /**
     * The installation the licence belongs to: always this site's own home address,
     * on a network as much as anywhere else.
     */
    private static function licensedSiteUrl(): string
    {
        return home_url();
    }

    /**
     * A domain or URL reduced to the form used for comparing two of them: lower case,
     * no scheme, no `www.`, no default port, no trailing dot or slash. Path kept, as in
     * currentDomain(). Used to tell whether a site on the license is this one.
     */
    public static function normaliseDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^https?[:/]+#', '', $domain, 1);
        $domain = (string) preg_replace('#^www\.#', '', $domain, 1);

        $slash = strpos($domain, '/');
        $host = $slash === false ? $domain : substr($domain, 0, $slash);
        $path = $slash === false ? '' : substr($domain, $slash);

        $host = (string) preg_replace('#:(80|443)$#', '', $host, 1);
        $host = rtrim($host, '.');

        return $host.rtrim($path, '/');
    }

    /**
     * No longer does anything: every subsite now counts as its own site, so there is
     * no network-wide licence to opt into.
     *
     * @deprecated 1.0.0-beta.7 Each subsite is licensed on its own address. Remove the
     *             call; it will be deleted in a later release.
     */
    public static function useNetworkLicenceFor(string $pluginFile): void
    {
        // Intentionally empty — kept so hosts that still call it do not fatal.
    }
}
