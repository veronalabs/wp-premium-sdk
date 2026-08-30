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
     * When the plugin is network-activated, the network owns the licence rather than each
     * subsite, so every subsite reports the network's own address and the whole network
     * counts once. That mirrors how the rest of the ecosystem behaves and, more
     * importantly, matches how the plugin was actually installed: one decision, taken
     * once, for all of them.
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
     * The installation the licence belongs to: the network when the plugin is activated
     * across one, otherwise this site alone.
     */
    private static function licensedSiteUrl(): string
    {
        if (is_multisite() && function_exists('is_plugin_active_for_network')) {
            if (! function_exists('get_plugins')) {
                require_once ABSPATH.'wp-admin/includes/plugin.php';
            }

            $plugin = self::networkActivatedPlugin();

            if ($plugin !== null && is_plugin_active_for_network($plugin)) {
                return network_home_url();
            }
        }

        return home_url();
    }

    /**
     * The plugin file to ask about, set by the host plugin at boot.
     *
     * Null when the host has not told us, in which case the network question cannot be
     * answered and this site answers for itself — the safe direction, since it counts
     * more sites rather than fewer.
     */
    private static ?string $pluginFile = null;

    public static function useNetworkLicenceFor(string $pluginFile): void
    {
        self::$pluginFile = $pluginFile;
    }

    private static function networkActivatedPlugin(): ?string
    {
        return self::$pluginFile;
    }
}
