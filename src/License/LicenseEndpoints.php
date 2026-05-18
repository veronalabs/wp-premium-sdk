<?php

namespace VeronaLabs\WpPremiumSdk\License;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Endpoint\AbstractAjaxEndpoint;
use VeronaLabs\WpPremiumSdk\Feature\FeatureInstaller;
use VeronaLabs\WpPremiumSdk\Support\Request;
use VeronaLabs\WpPremiumSdk\Update\PluginUpdater;

/**
 * AJAX dispatcher for license + update actions.
 *
 * Action: wp_ajax_{prefix}_license
 * Sub-actions: activate, deactivate, get_status, check_updates,
 *              update_feature, install_features
 */
class LicenseEndpoints extends AbstractAjaxEndpoint
{
    private LicenseManager $manager;
    private PluginUpdater $updater;
    private FeatureInstaller $installer;

    public function __construct(ClientConfig $config, LicenseManager $manager, PluginUpdater $updater, FeatureInstaller $installer)
    {
        parent::__construct($config);
        $this->manager = $manager;
        $this->updater = $updater;
        $this->installer = $installer;
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
        $cleanup = $this->manager->deactivateAndCleanup($this->updater::MANIFEST_CACHE_KEY_PREFIX.$this->config->productSlug());

        $this->successResponse(['removed' => $cleanup['removed']]);
    }

    protected function getStatus(): void
    {
        $this->successResponse([
            'is_activated' => $this->manager->isActivated(),
            'is_valid' => $this->manager->isValid(),
            'license' => $this->manager->getLicenseData(),
        ]);
    }

    protected function checkUpdates(): void
    {
        $manifest = $this->updater->fetchManifest(true);

        $baseUpdate = ['success' => true, 'update_available' => false];

        if ($manifest === null) {
            $baseUpdate = [
                'success' => false,
                'update_available' => false,
                'error' => __('Failed to fetch update manifest.', $this->config->textDomain()),
            ];
        } elseif (! empty($manifest['update_available'])) {
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

        $this->successResponse([
            'feature_updates' => ['updates_available' => false, 'updates' => []],
            'base_update' => $baseUpdate,
            'checked_at' => time(),
        ]);
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
