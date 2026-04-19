<?php

namespace VeronaLabs\WpPremiumSdk\License;

use Exception;
use VeronaLabs\WpPremiumSdk\Encryption\EncryptorInterface;
use VeronaLabs\WpPremiumSdk\Feature\FeatureInstaller;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Support\Request;

/**
 * Stateful orchestrator for license activation/validation/deactivation.
 *
 * License data lives in the 'license' section of the plugin's shared option row
 * via PremiumStore. The raw license key is encrypted at rest through the
 * EncryptorInterface implementation the plugin supplies.
 */
class LicenseManager
{
    public function __construct(
        private LicenseClient $client,
        private PremiumStore $store,
        private EncryptorInterface $encryptor,
        private FeatureInstaller $featureInstaller,
    ) {}

    /**
     * Activate a license key on this site.
     *
     * @throws Exception
     *
     * @return array<string, mixed> Public-safe license data
     */
    public function activate(string $licenseKey): array
    {
        $domain = Request::currentDomain();

        $activateResponse = $this->client->activate($licenseKey, $domain, home_url());

        try {
            $validateResponse = $this->client->validate($licenseKey, $domain);
        } catch (Exception $e) {
            $validateResponse = [];
        }

        $licenseData = $this->mapApiResponse($activateResponse, $licenseKey, $validateResponse);
        $this->store->set('license', $licenseData);

        return $this->publicData($licenseData);
    }

    /**
     * Deactivate the current license against the API and clear local data.
     */
    public function deactivate(): bool
    {
        $licenseKey = $this->getLicenseKey();
        $domain = Request::currentDomain();

        if ($licenseKey) {
            try {
                $this->client->deactivate($licenseKey, $domain);
            } catch (Exception $e) {
                // Best-effort — don't block local teardown on transport errors.
            }
        }

        $this->store->delete('license');

        return true;
    }

    /**
     * Full teardown: deactivate + remove installed modules + clear update cache.
     *
     * @return array{removed: string[]}
     */
    public function deactivateAndCleanup(string $updateCacheKey): array
    {
        $this->deactivate();
        $cleanup = $this->featureInstaller->removeAll();
        delete_transient($updateCacheKey);

        return ['removed' => $cleanup['removed'] ?? []];
    }

    /**
     * Re-validate against the API and refresh local state.
     */
    public function validate(): bool
    {
        $licenseKey = $this->getLicenseKey();

        if (! $licenseKey) {
            return false;
        }

        $domain = Request::currentDomain();

        try {
            $response = $this->client->validate($licenseKey, $domain);

            $existing = $this->store->get('license');
            $licenseData = $this->mapApiResponse($response, $licenseKey, null, $existing);
            $this->store->set('license', $licenseData);

            return ($licenseData['status'] ?? '') === 'active';
        } catch (Exception $e) {
            $existing = $this->store->get('license');

            if ($existing) {
                $existing['last_validated_at'] = time();
                $this->store->set('license', $existing);
            }

            return false;
        }
    }

    public function isActivated(): bool
    {
        $data = $this->store->get('license');

        return ! empty($data) && ! empty($data['license_key']);
    }

    public function isValid(): bool
    {
        if (! $this->isActivated()) {
            return false;
        }

        $data = $this->store->get('license');

        if (($data['status'] ?? '') !== 'active') {
            return false;
        }

        if (! empty($data['expires_at'])) {
            $expiresAt = strtotime($data['expires_at']);

            if ($expiresAt && $expiresAt < time()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLicenseData(): ?array
    {
        $data = $this->store->get('license');

        return $data ? $this->publicData($data) : null;
    }

    /**
     * @return array<int, string>
     */
    public function getFeatures(): array
    {
        return $this->store->get('license')['features'] ?? [];
    }

    public function hasFeature(string $slug): bool
    {
        return in_array($slug, $this->getFeatures(), true);
    }

    public function getLicenseKey(): ?string
    {
        $data = $this->store->get('license');

        if (empty($data['license_key'])) {
            return null;
        }

        return $this->encryptor->decrypt($data['license_key']);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapApiResponse(array $response, string $licenseKey, ?array $supplementary = null, ?array $existing = null): array
    {
        $license = $response['license'] ?? $response['license_details'] ?? $response;
        $features = $license['features'] ?? $response['features'] ?? [];

        if ($supplementary) {
            $suppLicense = $supplementary['license'] ?? $supplementary['license_details'] ?? [];
            $suppFeatures = $suppLicense['features'] ?? $supplementary['features'] ?? [];

            if (! empty($suppFeatures)) {
                $features = $suppFeatures;
            }
        }

        $featureSlugs = [];
        foreach ($features as $feature) {
            if (is_string($feature)) {
                $featureSlugs[] = $feature;
            } elseif (is_array($feature) && ! empty($feature['slug'])) {
                $featureSlugs[] = $feature['slug'];
            }
        }

        $licenseType = $license['license_type'] ?? $license['type'] ?? $response['type'] ?? '';
        $planName = $license['plan_name'] ?? ucfirst($licenseType);

        return [
            'license_key' => $this->encryptor->encrypt($licenseKey),
            'status' => $license['status'] ?? 'active',
            'license_type' => $licenseType,
            'plan_name' => $planName,
            'expires_at' => $license['expires_at'] ?? $response['expires_at'] ?? '',
            'max_activations' => (int) ($license['max_activations'] ?? 0),
            'activation_count' => (int) ($license['activation_count'] ?? 0),
            'customer_name' => $license['customer_name'] ?? ($existing['customer_name'] ?? ''),
            'customer_email' => $license['customer_email'] ?? ($existing['customer_email'] ?? ''),
            'features' => $featureSlugs,
            'activated_at' => $existing['activated_at'] ?? time(),
            'last_validated_at' => time(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function publicData(array $data): array
    {
        unset($data['license_key']);

        return $data;
    }
}
