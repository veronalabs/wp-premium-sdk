<?php

namespace VeronaLabs\WpPremiumSdk\License;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Endpoint\AbstractAjaxEndpoint;
use VeronaLabs\WpPremiumSdk\Feature\FeatureInstaller;
use VeronaLabs\WpPremiumSdk\Support\Request;
use VeronaLabs\WpPremiumSdk\Update\PluginUpdater;
use VeronaLabs\WpPremiumSdk\Update\TierPackageInstaller;

/**
 * AJAX dispatcher for license + update actions.
 *
 * Action: wp_ajax_{prefix}_license
 * Sub-actions: activate, deactivate, get_status, check_updates,
 *              update_feature, install_features, list_sites, remove_site,
 *              move_license, install_tier_package
 *
 * When a wp-config constant or the network admin manages the key, this site's
 * license page can view it but not change it: activate and deactivate answer
 * `key_from_constant` / `network_managed`, and on a network-managed subsite so do
 * remove_site and move_license.
 */
class LicenseEndpoints extends AbstractAjaxEndpoint
{
    private LicenseManager $manager;
    private PluginUpdater $updater;
    private FeatureInstaller $installer;
    private KeySource $keySource;
    private AutoActivator $autoActivator;
    private TierPackageInstaller $tierInstaller;

    public function __construct(
        ClientConfig $config,
        LicenseManager $manager,
        PluginUpdater $updater,
        FeatureInstaller $installer,
        KeySource $keySource,
        AutoActivator $autoActivator,
        TierPackageInstaller $tierInstaller
    ) {
        parent::__construct($config);
        $this->manager = $manager;
        $this->updater = $updater;
        $this->installer = $installer;
        $this->keySource = $keySource;
        $this->autoActivator = $autoActivator;
        $this->tierInstaller = $tierInstaller;
    }

    protected function getActionName(): string
    {
        return 'license';
    }

    protected function getSubActions(): array
    {
        return [
            'activate' => 'activate',
            'deactivate' => 'deactivate',
            'get_status' => 'getStatus',
            'check_updates' => 'checkUpdates',
            'update_feature' => 'updateFeature',
            'install_features' => 'installFeatures',
            'list_sites' => 'listSites',
            'remove_site' => 'removeSite',
            'move_license' => 'moveLicense',
            'install_tier_package' => 'installTierPackage',
        ];
    }

    protected function getErrorCode(): string
    {
        return 'license_error';
    }

    /**
     * @throws Exception
     */
    protected function activate(): void
    {
        $this->refuseWhenManaged();

        $licenseKey = Request::get('license_key', '');

        if (! $licenseKey) {
            $this->errorResponse(__('License key is required.', $this->config->textDomain()), $this->getErrorCode());

            return;
        }

        $data = $this->manager->activate($licenseKey);
        $this->updater->flush();

        $this->successResponse(['license' => $data]);
    }

    protected function deactivate(): void
    {
        // Deactivation only tears down the license and flushes the manifest
        // cache — it never touches module files. Modules ship pre-packaged in
        // the tier ZIP and are gated by what exists on disk, so deleting them
        // on deactivate would be data loss. (`removed` is retained as an empty
        // array for response-shape compatibility with the dashboard client.)
        //
        // The license is always removed here; `removed_remotely` says whether Nexus
        // released the seat too, and `error_code` why not, so the UI can tell the
        // user to free it from their account.
        $this->refuseWhenManaged();

        $result = $this->manager->deactivate();
        $this->updater->flush();

        $this->successResponse([
            'removed' => [],
            'removed_remotely' => $result['removed_remotely'],
            'error_code' => $result['error_code'],
        ]);
    }

    /**
     * The sites on this license, each carrying `this_site`. Fetched fresh from
     * Nexus with this site's domain; when Nexus can't be reached the last known
     * list is returned and `fresh` is false.
     */
    protected function listSites(): void
    {
        $fresh = $this->manager->isActivated() && $this->manager->refreshSites();
        $license = $this->manager->getLicenseData() ?? [];

        $this->successResponse([
            'sites' => $this->manager->listSites(),
            'fresh' => $fresh,
            'max_activations' => (int) ($license['max_activations'] ?? 0),
            'activation_count' => (int) ($license['activation_count'] ?? 0),
            'manage_url' => (string) ($license['manage_url'] ?? ''),
        ]);
    }

    /**
     * Release another site's seat with this site's license key. Body: `domain`.
     * This site is refused — removing it is what `deactivate` does.
     *
     * @throws Exception
     */
    protected function removeSite(): void
    {
        $this->refuseWhenNetworkManaged();

        $domain = (string) Request::get('domain', '');

        if ($domain === '') {
            $this->errorResponse(__('Site domain is required.', $this->config->textDomain()), $this->getErrorCode());

            return;
        }

        if (! $this->manager->isActivated()) {
            $this->errorResponse(__('No license is activated on this site.', $this->config->textDomain()), LicenseErrorCode::NOT_ACTIVATED);

            return;
        }

        if ($this->manager->isThisSite($domain)) {
            $this->errorResponse(__('To remove this site, deactivate the license instead.', $this->config->textDomain()), 'this_site');

            return;
        }

        $sites = $this->manager->removeSite($domain);
        $license = $this->manager->getLicenseData() ?? [];

        $this->successResponse([
            'removed' => $domain,
            'sites' => $sites,
            'max_activations' => (int) ($license['max_activations'] ?? 0),
            'activation_count' => (int) ($license['activation_count'] ?? 0),
        ]);
    }

    protected function getStatus(): void
    {
        // Re-check with the server on load so a renewal (or revocation) made on
        // the server side is reflected, instead of trusting the local cache.
        if ($this->manager->isActivated()) {
            $this->manager->refreshStatus();
        }

        $this->successResponse([
            'is_activated' => $this->manager->isActivated(),
            'is_valid' => $this->manager->isValid(),
            'license' => $this->manager->getLicenseData(),
            // The one state the UI should show (see LicenseManager::classify()).
            'state' => $this->manager->classify(),
            'installed_features' => $this->installer->installedModules(),
            // Where the key came from: "constant" (wp-config), "network" (the
            // network admin's key) or "manual" (entered here).
            'source' => $this->manager->getSource() ?? ($this->keySource->managedBy() ?? KeySource::MANUAL),
            // The last failed automatic activation with a constant or network key
            // (attempts, last_attempt_at, retry_at, error_code), or null.
            // Null on a network-managed subsite: seat shortfalls are the network
            // admin's to see (network get_status), not the subsite admin's.
            'auto_activation' => $this->keySource->isNetworkManaged() ? null : $this->autoActivator->lastFailure(),
            // "network" when the plugin is network-activated (the network admin
            // manages the key and this subsite only views it), "site" otherwise.
            'context' => $this->keySource->context(),
            'is_network_managed' => $this->keySource->isNetworkManaged(),
            // {was, now} when this site's domain is not the one the license was
            // activated on (a staging clone or a move), else null.
            'domain_changed' => $this->manager->domainChange(),
            // {installed, licensed} when the cached manifest licenses another tier
            // than the installed build, else null.
            'tier_mismatch' => $this->tierInstaller->tierMismatch(),
        ]);
    }

    /**
     * Move the license to this site's current domain (see domain_changed): activate
     * here, then — only once that worked — release the old domain's seat. Optional
     * body `release_old` ("1" / "0") overrides the default, which releases the old
     * domain only when Nexus counts the new one as a seat.
     *
     * @throws Exception When there is nothing to move or the activation fails; the
     *                   old domain then keeps its seat.
     */
    protected function moveLicense(): void
    {
        $this->refuseWhenNetworkManaged();

        $releaseOld = Request::get('release_old', '');
        $releaseOld = $releaseOld === '' ? null : in_array((string) $releaseOld, ['1', 'true'], true);

        $result = $this->manager->moveLicense($releaseOld);
        $this->updater->flush();

        $this->successResponse($result);
    }

    /**
     * Install the licensed tier's package when it is not the installed build's tier.
     * Needs the `install_plugins` capability on top of the endpoint's own.
     *
     * @throws Exception With `file_mods_disabled`, `filesystem_credentials_needed`,
     *                   `installed_tier_unknown`, `licensed_tier_unknown`,
     *                   `package_unavailable`, `install_failed`, or Nexus's code.
     */
    protected function installTierPackage(): void
    {
        if (! current_user_can('install_plugins')) {
            $this->errorResponse(__('You do not have permission.', $this->config->textDomain()), 'forbidden');

            return;
        }

        $this->successResponse($this->tierInstaller->install());
    }

    /**
     * @throws LicenseActionException When a constant or the network admin manages the key.
     */
    private function refuseWhenManaged(): void
    {
        $code = $this->keySource->refusalCode();

        if ($code === LicenseErrorCode::KEY_FROM_CONSTANT) {
            throw new LicenseActionException($code, __('The license key is set in wp-config.php. Change or delete it there.', $this->config->textDomain()));
        }

        if ($code !== null) {
            $this->refuseWhenNetworkManaged();
        }
    }

    /**
     * @throws LicenseActionException On a subsite of a network-activated plugin.
     */
    private function refuseWhenNetworkManaged(): void
    {
        if ($this->keySource->isNetworkManaged()) {
            throw new LicenseActionException(LicenseErrorCode::NETWORK_MANAGED, __('The license is managed by your network admin.', $this->config->textDomain()));
        }
    }

    protected function checkUpdates(): void
    {
        $manifest = $this->updater->fetchManifest(true);

        $baseUpdate = ['success' => true, 'update_available' => false];
        $featureUpdates = [];

        if ($manifest === null) {
            $baseUpdate = [
                'success' => false,
                'update_available' => false,
                'error' => __('Failed to fetch update manifest.', $this->config->textDomain()),
            ];
        } else {
            if (! empty($manifest['update_available'])) {
                $inner = $manifest['manifest'] ?? [];
                $baseUpdate = [
                    'success' => true,
                    'update_available' => true,
                    'version' => $inner['version'] ?? null,
                    'changelog' => $inner['changelog'] ?? null,
                ];
            } elseif (! empty($manifest['error'])) {
                $baseUpdate['error'] = $manifest['error'];
            }

            $featureUpdates = $this->detectFeatureUpdates($manifest['manifest']['modules'] ?? []);
        }

        $this->successResponse([
            'feature_updates' => [
                'updates_available' => count($featureUpdates) > 0,
                'updates' => $featureUpdates,
            ],
            'base_update' => $baseUpdate,
            'checked_at' => time(),
        ]);
    }

    /**
     * Compare manifest module versions against installed modules on disk.
     *
     * A module is "installed" iff {modules_path}/{slug}/manifest.json exists and
     * declares a version. An entry is emitted only when the on-disk version is
     * strictly less than the manifest version.
     *
     * @param  array<int, array{slug?: string, version?: string, name?: string, changelog?: string}>  $manifestModules
     * @return array<int, array{slug: string, name: string, current_version: string, latest_version: string, changelog?: string}>
     */
    private function detectFeatureUpdates(array $manifestModules): array
    {
        $modulesPath = $this->config->modulesPath();

        if (! $modulesPath || ! is_dir($modulesPath)) {
            return [];
        }

        $updates = [];

        foreach ($manifestModules as $module) {
            $slug = $module['slug'] ?? '';
            $latest = $module['version'] ?? '';

            if ($slug === '' || $latest === '') {
                continue;
            }

            $installedManifestFile = rtrim($modulesPath, '/').'/'.$slug.'/manifest.json';

            if (! is_file($installedManifestFile)) {
                continue;
            }

            $installed = json_decode((string) file_get_contents($installedManifestFile), true);
            $current = is_array($installed) ? (string) ($installed['version'] ?? '') : '';

            if ($current === '' || version_compare($current, $latest, '>=')) {
                continue;
            }

            $entry = [
                'slug' => $slug,
                'name' => $module['name'] ?? $slug,
                'current_version' => $current,
                'latest_version' => $latest,
            ];

            if (! empty($module['changelog'])) {
                $entry['changelog'] = $module['changelog'];
            }

            $updates[] = $entry;
        }

        return $updates;
    }

    /**
     * @throws Exception
     */
    protected function updateFeature(): void
    {
        $slug = Request::get('slug', '');

        if (! $slug) {
            $this->errorResponse(__('Module slug is required.', $this->config->textDomain()), $this->getErrorCode());

            return;
        }

        $manifest = $this->updater->fetchManifest(true);
        $modules = $manifest['manifest']['modules'] ?? [];

        $match = null;

        foreach ($modules as $module) {
            if (($module['slug'] ?? '') === $slug) {
                $match = $module;
                break;
            }
        }

        if (! $match) {
            $this->errorResponse(__('Module not found in latest manifest.', $this->config->textDomain()), $this->getErrorCode());

            return;
        }

        $this->installer->installSingle($match);
        $this->successResponse([
            'installed' => true,
            'slug' => $slug,
            'version' => $match['version'] ?? null,
        ]);
    }

    protected function installFeatures(): void
    {
        $manifest = $this->updater->fetchManifest(true);
        $modules = $manifest['manifest']['modules'] ?? [];

        $result = $this->installer->installMany($modules);
        $result['total'] = count($modules);

        $this->successResponse($result);
    }
}
