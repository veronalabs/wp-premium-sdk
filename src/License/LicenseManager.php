<?php

namespace VeronaLabs\WpPremiumSdk\License;

use Exception;
use VeronaLabs\WpPremiumSdk\Encryption\EncryptorInterface;
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
    /** Warn when a license expires within this many days. */
    public const EXPIRY_WARNING_DAYS = 14;

    private const SECONDS_PER_DAY = 86400;

    private LicenseClient $client;
    private PremiumStore $store;
    private EncryptorInterface $encryptor;

    public function __construct(LicenseClient $client, PremiumStore $store, EncryptorInterface $encryptor)
    {
        $this->client = $client;
        $this->store = $store;
        $this->encryptor = $encryptor;
    }

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

        // Generic veto seam: a host plugin can block activation (e.g. a license
        // tier lower than the installed build) by returning a non-empty error
        // string. Nexus already created the DomainActivation in client->activate()
        // above, so roll it back before throwing to avoid orphaning the slot.
        $gateError = apply_filters('wp_premium_sdk/activation_gate', null, $licenseData);
        if (is_string($gateError) && $gateError !== '') {
            try {
                $this->client->deactivate($licenseKey, $domain);
            } catch (Exception $e) {
                // Best-effort rollback — surface the gate error regardless.
            }

            throw new \RuntimeException($gateError);
        }

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

    /**
     * Refresh the cached license status (status, expiry, features) from the
     * server, WITHOUT the domain-activation check, so a server-side renewal or
     * revocation is reflected on the next dashboard load. Updates the cache on
     * success; leaves it untouched on failure (offline, transient, or the
     * license is genuinely still invalid).
     */
    public function refreshStatus(): void
    {
        $licenseKey = $this->getLicenseKey();

        if (! $licenseKey) {
            return;
        }

        try {
            $response = $this->client->validate($licenseKey, '');
            $existing = $this->store->get('license');
            $this->store->set('license', $this->mapApiResponse($response, $licenseKey, null, $existing));
        } catch (Exception $e) {
            // Keep the cached license; a failed refresh must not lock out a valid site.
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
     * Classify the stored license into a single canonical state code, applying
     * notice precedence and the expiry-warning threshold. Language-neutral: the
     * host plugin maps the returned code to a translatable message under its own
     * text domain (see LicenseErrorCode and docs/nexus-license-error-codes.md).
     *
     * Precedence (highest first): suspended/revoked/disabled > over_limit >
     * expired > expiring_soon > not_activated > active. An unrecognized stored
     * status falls through to "invalid". An empty expires_at is a lifetime
     * license — never expiring or expired.
     *
     * `days_remaining` is ceil((expires_at - now) / day): null when there is no
     * expiry, <= 0 once expired, otherwise the whole days left.
     *
     * @return array{code: string, days_remaining: int|null, raw_status: string}
     */
    public function classify(): array
    {
        $data = $this->store->get('license');

        if (empty($data) || empty($data['license_key'])) {
            return [
                'code' => LicenseErrorCode::NOT_ACTIVATED,
                'days_remaining' => null,
                'raw_status' => '',
            ];
        }

        $rawStatus = (string) ($data['status'] ?? '');
        $expiresAt = (string) ($data['expires_at'] ?? '');
        $maxActivations = (int) ($data['max_activations'] ?? 0);
        $activationCount = (int) ($data['activation_count'] ?? 0);

        $daysRemaining = null;
        $expiredByDate = false;

        if ($expiresAt !== '') {
            $expiresTs = strtotime($expiresAt);

            if ($expiresTs !== false) {
                $diff = $expiresTs - time();
                $daysRemaining = (int) ceil($diff / self::SECONDS_PER_DAY);
                $expiredByDate = $diff <= 0;
            }
        }

        return [
            'code' => $this->resolveStateCode($rawStatus, $expiredByDate, $daysRemaining, $maxActivations, $activationCount),
            'days_remaining' => $daysRemaining,
            'raw_status' => $rawStatus,
        ];
    }

    /**
     * Apply notice precedence to the stored license signals and return the
     * single winning state code.
     */
    private function resolveStateCode(string $rawStatus, bool $expiredByDate, ?int $daysRemaining, int $maxActivations, int $activationCount): string
    {
        // 1. Account-level holds — different action (contact support), so they
        //    outrank everything else.
        if ($rawStatus === LicenseErrorCode::SUSPENDED) {
            return LicenseErrorCode::SUSPENDED;
        }

        if ($rawStatus === LicenseErrorCode::REVOKED) {
            return LicenseErrorCode::REVOKED;
        }

        if ($rawStatus === LicenseErrorCode::DISABLED) {
            return LicenseErrorCode::DISABLED;
        }

        // 2. No activation slots left — manage activations.
        if ($maxActivations > 0 && $activationCount >= $maxActivations) {
            return LicenseErrorCode::OVER_LIMIT;
        }

        // 3. Past expiry, whether reported by status or computed from the date.
        if ($rawStatus === LicenseErrorCode::EXPIRED || $expiredByDate) {
            return LicenseErrorCode::EXPIRED;
        }

        // 4. Approaching expiry.
        if ($daysRemaining !== null && $daysRemaining > 0 && $daysRemaining <= self::EXPIRY_WARNING_DAYS) {
            return LicenseErrorCode::EXPIRING_SOON;
        }

        // 5. Healthy — an activated license with no explicit status defaults to active.
        if ($rawStatus === LicenseErrorCode::ACTIVE || $rawStatus === '') {
            return LicenseErrorCode::ACTIVE;
        }

        // 6. Stored status present but unrecognized — safe catch-all.
        return LicenseErrorCode::INVALID;
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

    /**
     * The cached renewal offer block emitted by Nexus (state, days_remaining,
     * renew_url with coupon pre-applied, offer, subscription_status), or null
     * when the license has no renewal concept or none has been cached yet.
     * Read-only cache access — the notice and refresh consumers never hit the
     * network for this.
     *
     * @return array<string, mixed>|null
     */
    public function getRenewal(): ?array
    {
        return $this->store->get('license')['renewal'] ?? null;
    }

    public function hasFeature(string $slug): bool
    {
        $features = $this->getFeatures();

        // A lone '*' (build-time "all modules" wildcard) entitles every module.
        return in_array('*', $features, true) || in_array($slug, $features, true);
    }

    /**
     * The licensed tier slug (e.g. 'basic', 'pro', 'elite') as reported by
     * Nexus, or null when unknown. Drives tier display and build reconciliation
     * on the host side.
     */
    public function getTier(): ?string
    {
        $tier = $this->store->get('license')['tier_slug'] ?? '';

        return $tier !== '' ? $tier : null;
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

        // Nexus sends the entitled tier under tier_slug (at the license object or
        // top level); fall back to the cached value so a refresh that omits it
        // doesn't wipe the known tier.
        $tierSlug = $license['tier_slug'] ?? $response['tier_slug'] ?? ($existing['tier_slug'] ?? '');

        // The renewal offer block (state, renew_url with coupon pre-applied,
        // discount offer). Rides the validate-success response; fall back to the
        // cached value so a refresh that omits it preserves a still-valid coupon.
        $renewal = $license['renewal'] ?? $response['renewal'] ?? ($existing['renewal'] ?? null);

        return [
            'license_key' => $this->encryptor->encrypt($licenseKey),
            'status' => $license['status'] ?? 'active',
            // Raw machine-readable code from Nexus, stored verbatim so an
            // unrecognized value survives for classify()/display rather than
            // being collapsed. Empty for the common "active" path.
            'error_code' => (string) ($license['error_code'] ?? $response['error_code'] ?? ($existing['error_code'] ?? '')),
            'license_type' => $licenseType,
            'plan_name' => $planName,
            'tier_slug' => $tierSlug,
            'expires_at' => $license['expires_at'] ?? $response['expires_at'] ?? '',
            'max_activations' => (int) ($license['max_activations'] ?? 0),
            'activation_count' => (int) ($license['activation_count'] ?? 0),
            'customer_name' => $license['customer_name'] ?? ($existing['customer_name'] ?? ''),
            'customer_email' => $license['customer_email'] ?? ($existing['customer_email'] ?? ''),
            'features' => $featureSlugs,
            'renewal' => $renewal,
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
        if (! empty($data['license_key'])) {
            try {
                $data['license_key_masked'] = $this->maskKey($this->encryptor->decrypt($data['license_key']));
            } catch (Exception $e) {
                // Corrupt/unreadable key — omit the masked value rather than fail.
            }
        }

        unset($data['license_key']);

        return $data;
    }

    /**
     * Mask a license key for display, revealing only the last four characters.
     */
    private function maskKey(string $key): string
    {
        $length = strlen($key);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', $length - 4).substr($key, -4);
    }
}
