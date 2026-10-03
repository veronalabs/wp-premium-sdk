<?php

namespace VeronaLabs\WpPremiumSdk\Update;

use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\License\LicenseActionException;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;

/**
 * Installs the licensed tier's package over a build of another tier (#15).
 *
 * Someone installs the Basic ZIP with a Pro key, or upgrades Basic to Pro in the
 * portal. The version is the same, so WordPress's updater offers nothing. Nexus's
 * manifest names the tier it licenses (`tier_slug`, on every success reply); the
 * host names the tier it shipped (ClientConfig `installed_tier`). When they differ,
 * this installs the manifest's package in place of the running plugin.
 */
class TierPackageInstaller
{
    private ClientConfig $config;

    private PluginUpdater $updater;

    private string $pluginBasename;

    public function __construct(ClientConfig $config, PluginUpdater $updater, string $pluginBasename)
    {
        $this->config = $config;
        $this->updater = $updater;
        $this->pluginBasename = $pluginBasename;
    }

    /**
     * The installed and licensed tiers when they differ, from the cached manifest
     * only (no request). Null when they match or either one is unknown.
     *
     * @return array{installed: string, licensed: string}|null
     */
    public function tierMismatch(): ?array
    {
        $installed = $this->installedTier();
        $manifest = $this->updater->cachedManifest();
        $licensed = $manifest !== null ? self::licensedTier($manifest) : null;

        if ($installed === null || $licensed === null || $installed === $licensed) {
            return null;
        }

        return ['installed' => $installed, 'licensed' => $licensed];
    }

    /**
     * Fetch the manifest fresh and, when its tier is not the installed one, install
     * its package over this plugin. Clears the cached manifest afterwards.
     *
     * Checks before asking Nexus that WordPress may write plugin files at all
     * (`file_mods_disabled`) and can do so without asking for FTP/SSH credentials
     * (`filesystem_credentials_needed`); it never prompts.
     *
     * @throws LicenseActionException With the reason the install did not happen.
     * @throws \VeronaLabs\WpPremiumSdk\Http\ApiException When Nexus refuses or cannot be reached.
     *
     * @return array{installed: bool, installed_tier: string, licensed_tier: string, version: string|null, plugin_file: string|null}
     */
    public function install(): array
    {
        $installed = $this->installedTier();

        if ($installed === null) {
            throw new LicenseActionException(LicenseErrorCode::INSTALLED_TIER_UNKNOWN, 'The plugin does not say which tier is installed.');
        }

        $this->assertCanWritePlugins();

        $manifest = $this->updater->fetchPackageManifest();
        $licensed = self::licensedTier($manifest);

        if ($licensed === null) {
            throw new LicenseActionException(LicenseErrorCode::LICENSED_TIER_UNKNOWN, 'The licensing server did not say which tier this license includes.');
        }

        $inner = is_array($manifest['manifest'] ?? null) ? $manifest['manifest'] : [];
        $version = isset($inner['version']) ? (string) $inner['version'] : null;

        if ($licensed === $installed) {
            return [
                'installed' => false,
                'installed_tier' => $installed,
                'licensed_tier' => $licensed,
                'version' => $version,
                'plugin_file' => null,
            ];
        }

        $package = (string) ($inner['plugin']['url'] ?? $inner['download_url'] ?? '');

        if ($package === '') {
            throw new LicenseActionException(LicenseErrorCode::PACKAGE_UNAVAILABLE, 'The licensing server sent no package to install.');
        }

        $pluginFile = $this->upgrade($package);

        // The cached manifest described the old build.
        $this->updater->flush();

        return [
            'installed' => true,
            'installed_tier' => $installed,
            'licensed_tier' => $licensed,
            'version' => $version,
            'plugin_file' => $pluginFile,
        ];
    }

    /**
     * The tier a manifest reply licenses: top-level `tier_slug` (Nexus sends it on
     * every success reply), else inside the manifest or its plugin block.
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function licensedTier(array $manifest): ?string
    {
        $tier = $manifest['tier_slug']
            ?? $manifest['manifest']['tier_slug']
            ?? $manifest['manifest']['plugin']['tier_slug']
            ?? '';

        return is_string($tier) && $tier !== '' ? $tier : null;
    }

    private function installedTier(): ?string
    {
        $tier = $this->config->installedTier();

        return $tier !== '' ? $tier : null;
    }

    /**
     * @throws LicenseActionException
     */
    protected function assertCanWritePlugins(): void
    {
        if (! wp_is_file_mod_allowed('wp_premium_sdk_tier_package')) {
            throw new LicenseActionException(LicenseErrorCode::FILE_MODS_DISABLED, 'Plugin files cannot be changed on this site.');
        }

        if (! function_exists('get_filesystem_method')) {
            require_once ABSPATH.'wp-admin/includes/file.php';
        }

        $pluginDir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '';

        if (get_filesystem_method([], $pluginDir) === 'direct') {
            return;
        }

        // FTP/SSH: usable only when wp-config already holds the credentials.
        // request_filesystem_credentials() prints a form when it lacks them, so its
        // output is swallowed and a false answer becomes the error code.
        ob_start();
        $credentials = request_filesystem_credentials('', '', false, $pluginDir, null, true);
        ob_end_clean();

        if ($credentials === false || ! WP_Filesystem($credentials, $pluginDir, true)) {
            throw new LicenseActionException(LicenseErrorCode::FILESYSTEM_CREDENTIALS_NEEDED, 'WordPress needs FTP or SSH details to change plugin files.');
        }
    }

    /**
     * Install the package over the running plugin with WordPress's own upgrader.
     *
     * @throws LicenseActionException When WordPress could not install it.
     *
     * @return string|null The installed plugin's basename, as WordPress found it.
     */
    protected function upgrade(string $package): ?string
    {
        if (! class_exists('Plugin_Upgrader')) {
            require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        }

        $skin = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $result = $upgrader->install($package, ['overwrite_package' => true, 'clear_update_cache' => true]);

        if (is_wp_error($result)) {
            throw new LicenseActionException(LicenseErrorCode::INSTALL_FAILED, $result->get_error_message());
        }

        if ($result !== true) {
            global $wp_filesystem;

            if ($wp_filesystem instanceof \WP_Filesystem_Base && is_wp_error($wp_filesystem->errors) && $wp_filesystem->errors->has_errors()) {
                throw new LicenseActionException(LicenseErrorCode::FILESYSTEM_CREDENTIALS_NEEDED, $wp_filesystem->errors->get_error_message());
            }

            $errors = $skin->get_errors();
            $message = is_wp_error($errors) && $errors->has_errors() ? $errors->get_error_message() : 'The package could not be installed.';

            throw new LicenseActionException(LicenseErrorCode::INSTALL_FAILED, $message);
        }

        $installed = $upgrader->plugin_info();

        return is_string($installed) && $installed !== '' ? $installed : $this->pluginBasename;
    }
}
