<?php

namespace VeronaLabs\WpPremiumSdk\License;

use Exception;
use RuntimeException;
use VeronaLabs\WpPremiumSdk\Encryption\EncryptorInterface;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\Http\ApiException;
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
        // above, so roll it back before throwing to avoid orphaning the slot, and
        // report whether that worked so the host can warn when it did not.
        $gateError = apply_filters('wp_premium_sdk/activation_gate', null, $licenseData);
        if (is_string($gateError) && $gateError !== '') {
            $rollback = $this->releaseSeat($licenseKey, $domain);

            throw new ActivationVetoedException($gateError, $rollback['removed_remotely'], $rollback['error_code']);
        }

        $this->store->set('license', $licenseData);

        return $this->publicData($licenseData);
    }

    /**
     * Deactivate the current license against the API and clear local data.
     *
     * The local license is always removed — the user asked for it — but the result
     * says whether Nexus heard about it. When `removed_remotely` is false the seat is
     * still taken on the account and `error_code` says why (e.g. network_error), so
     * the host can tell the user to remove the site from their account.
     *
     * With nothing stored there is no seat to release, so that reports success.
     *
     * @param  int  $timeout  Seconds to wait for Nexus; uninstall passes a short one.
     * @return array{removed_remotely: bool, error_code: string|null}
     */
    public function deactivate(int $timeout = ApiClient::DEFAULT_TIMEOUT): array
    {
        $result = ['removed_remotely' => true, 'error_code' => null];

        if ($this->isActivated()) {
            $licenseKey = $this->getLicenseKey();

            $result = $licenseKey
                ? $this->releaseSeat($licenseKey, Request::currentDomain(), $timeout)
                : ['removed_remotely' => false, 'error_code' => LicenseErrorCode::INVALID_KEY];
        }

        $this->store->delete('license');

        return $result;
    }

    /**
     * Release another site's seat on this license (the license page's "Remove"),
     * then refresh the stored license so the site list reflects it.
     *
     * This site is refused: removing it means removing the license here, which is
     * what deactivate() is for.
     *
     * @throws Exception When no license is stored, the domain is this site, or Nexus
     *                   refuses (an ApiException carrying the error code).
     *
     * @return array<int, array<string, mixed>> The refreshed site list (see listSites()).
     */
    public function removeSite(string $domain): array
    {
        $licenseKey = $this->getLicenseKey();

        if (! $licenseKey) {
            throw new RuntimeException('No license is activated on this site.');
        }

        if ($this->isThisSite($domain)) {
            throw new RuntimeException('This site cannot be removed from here; deactivate the license instead.');
        }

        $this->client->deactivate($licenseKey, $domain);
        $this->refreshSites();

        return $this->listSites();
    }

    /**
     * Ask Nexus for the current site list, validating with this site's domain —
     * Nexus only sends `sites` and `buyer` to a domain that holds an activation
     * on the license (a domain-less refreshStatus() gets them as null).
     *
     * A failure changes nothing: the cached list stays and the license state is
     * left to refreshStatus(), so opening the site list can never lock a site out.
     *
     * @return bool Whether Nexus answered.
     */
    public function refreshSites(): bool
    {
        $licenseKey = $this->getLicenseKey();

        if (! $licenseKey) {
            return false;
        }

        try {
            $response = $this->client->validate($licenseKey, Request::currentDomain());
        } catch (Exception $e) {
            return false;
        }

        $this->store->set('license', $this->mapApiResponse($response, $licenseKey, null, $this->store->get('license')));

        return true;
    }

    /**
     * The sites on this license, as Nexus last reported them, each flagged with
     * `this_site`. Empty when the server has not sent a list (older Nexus). Reads
     * the cache only; call refreshSites() first for a fresh list.
     *
     * Each entry: id, domain, site_url, is_counted, activated_at, last_check_at,
     * this_site.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSites(): array
    {
        $sites = $this->store->get('license')['sites'] ?? [];

        if (! is_array($sites)) {
            return [];
        }

        $list = [];

        foreach ($sites as $site) {
            if (! is_array($site)) {
                continue;
            }

            $site['this_site'] = $this->isThisSite((string) ($site['domain'] ?? ''));
            $list[] = $site;
        }

        return $list;
    }

    /**
     * Whether a domain (in any spelling Nexus or a user might use) is this site.
     */
    public function isThisSite(string $domain): bool
    {
        $here = Request::normaliseDomain(Request::currentDomain());

        return $here !== '' && Request::normaliseDomain($domain) === $here;
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
            $this->recordFailedCheck($e, $licenseKey);

            return false;
        }
    }

    /**
     * Refresh the cached license status (status, expiry, features) from the
     * server, WITHOUT the domain-activation check, so a server-side renewal or
     * revocation is reflected on the next dashboard load.
     *
     * A refusal is stored like any other answer: when Nexus says the key is
     * expired, suspended, revoked, disabled, unknown or for another product, the
     * stored license says so too, and isValid() stops passing. Only a failure that
     * says nothing about the license — network down, server broken, unreadable
     * reply, rate limited — keeps the cached license, so a blip never locks out a
     * paying site.
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
            $this->recordFailedCheck($e, $licenseKey);
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
     * Precedence (highest first): suspended/revoked/disabled > expired >
     * over_limit > expiring_soon > not_activated > active. An unrecognized stored
     * status falls through to "invalid". An empty expires_at is a lifetime
     * license — never expiring or expired.
     *
     * over_limit means more sites are activated than the license allows
     * (activation_count > max_activations; max 0 is unlimited). Using exactly the
     * seats paid for, this site among them, is not over the limit. When Nexus
     * reports this site's own seat (`site.is_counted` / `site.active`), that wins:
     * a site that uses no seat is never over the limit, and a site without an
     * activation is over it as soon as every seat is taken.
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

        $site = is_array($data['site'] ?? null) ? $data['site'] : [];

        return [
            'code' => $this->resolveStateCode($rawStatus, $expiredByDate, $daysRemaining, $this->isOverLimit($maxActivations, $activationCount, $site)),
            'days_remaining' => $daysRemaining,
            'raw_status' => $rawStatus,
        ];
    }

    /**
     * Apply notice precedence to the stored license signals and return the
     * single winning state code.
     */
    private function resolveStateCode(string $rawStatus, bool $expiredByDate, ?int $daysRemaining, bool $overLimit): string
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

        // 2. Past expiry, whether reported by status or computed from the date.
        //    Renewing is the fix, and freeing a seat would not help.
        if ($rawStatus === LicenseErrorCode::EXPIRED || $expiredByDate) {
            return LicenseErrorCode::EXPIRED;
        }

        // 3. More sites than seats — manage activations.
        if ($overLimit) {
            return LicenseErrorCode::OVER_LIMIT;
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
     * Whether the license has more sites than it allows, from this site's view.
     *
     * @param  array<string, mixed>  $site  Nexus's `site` block ({active, is_counted}), or empty.
     */
    private function isOverLimit(int $maxActivations, int $activationCount, array $site): bool
    {
        if ($maxActivations <= 0) {
            return false;
        }

        // A development site (staging.*, *.local …) uses no seat, so it cannot be over.
        if (array_key_exists('is_counted', $site) && $site['is_counted'] === false) {
            return false;
        }

        // Not activated here: there is room only while a seat is still free.
        if (array_key_exists('active', $site) && $site['active'] === false) {
            return $activationCount >= $maxActivations;
        }

        // Activated here (or Nexus did not say): this site is one of the counted ones.
        return $activationCount > $maxActivations;
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

        // License-page details (Nexus 2026-10+). Older servers send none of these,
        // and Nexus sends `sites` and `buyer` as null unless the request's domain
        // holds an activation (so a domain-less refresh gets null). Absent or null,
        // each keeps the last known value, then falls back to empty.
        $buyer = $this->mapBuyer($license['buyer'] ?? $response['buyer'] ?? null) ?? ($existing['buyer'] ?? null);
        $sitesRaw = $license['sites'] ?? $response['sites'] ?? null;
        $siteRaw = $license['site'] ?? $response['site'] ?? null;
        $now = time();

        return [
            'license_key' => $this->encryptor->encrypt($licenseKey),
            'status' => $license['status'] ?? 'active',
            // Raw machine-readable code from Nexus, stored verbatim so an
            // unrecognized value survives for classify()/display rather than
            // being collapsed. Empty for the common "active" path.
            // Empty when the server sent none: this is a fresh answer, so an old
            // code must not outlive the problem it described.
            'error_code' => (string) ($license['error_code'] ?? $response['error_code'] ?? ''),
            'license_type' => $licenseType,
            'plan_name' => $planName,
            'tier_slug' => $tierSlug,
            'expires_at' => $license['expires_at'] ?? $response['expires_at'] ?? '',
            'max_activations' => (int) ($license['max_activations'] ?? 0),
            'activation_count' => (int) ($license['activation_count'] ?? 0),
            'customer_name' => $license['customer_name'] ?? ($buyer['name'] ?? ($existing['customer_name'] ?? '')),
            'customer_email' => $license['customer_email'] ?? ($buyer['email'] ?? ($existing['customer_email'] ?? '')),
            'features' => $featureSlugs,
            'renewal' => $renewal,
            'license_id' => $license['license_id'] ?? $response['license_id'] ?? ($existing['license_id'] ?? null),
            'manage_url' => (string) ($license['manage_url'] ?? $response['manage_url'] ?? ($existing['manage_url'] ?? '')),
            'upgrade_url' => (string) ($license['upgrade_url'] ?? $response['upgrade_url'] ?? ($existing['upgrade_url'] ?? '')),
            'buyer' => $buyer,
            'sites' => is_array($sitesRaw) ? $this->mapSites($sitesRaw) : ($existing['sites'] ?? null),
            'site' => is_array($siteRaw) ? $this->mapSiteState($siteRaw) : ($existing['site'] ?? null),
            'activated_at' => $existing['activated_at'] ?? $now,
            // Last attempt to check with Nexus, answered or not.
            'last_validated_at' => $now,
            // Last time Nexus actually answered — what the stored details date from.
            'last_success_at' => $now,
        ];
    }

    /**
     * Release the seat `$domain` holds, reporting rather than throwing.
     *
     * @return array{removed_remotely: bool, error_code: string|null}
     */
    private function releaseSeat(string $licenseKey, string $domain, int $timeout = ApiClient::DEFAULT_TIMEOUT): array
    {
        try {
            $this->client->deactivate($licenseKey, $domain, $timeout);

            return ['removed_remotely' => true, 'error_code' => null];
        } catch (ApiException $e) {
            return ['removed_remotely' => false, 'error_code' => $e->getErrorCode()];
        } catch (Exception $e) {
            return ['removed_remotely' => false, 'error_code' => LicenseErrorCode::UNKNOWN];
        }
    }

    /**
     * Store what a failed check means.
     *
     * A transport failure (or an error with no code) says nothing about the
     * license, so the cached license stays and only the attempt time moves. Any
     * other code is Nexus refusing the key: that answer is stored, negative status
     * and all, so the site stops treating a refused license as active.
     */
    private function recordFailedCheck(Exception $e, string $licenseKey): void
    {
        $existing = $this->store->get('license');

        if (! $existing) {
            return;
        }

        $now = time();

        if (! $e instanceof ApiException || $e->isTransient() || $e->getErrorCode() === LicenseErrorCode::UNKNOWN) {
            $existing['last_validated_at'] = $now;
            $this->store->set('license', $existing);

            return;
        }

        $code = $e->getErrorCode();
        $body = $e->getData();
        $refusedStatus = $this->statusForRefusal($code);

        if (is_array($body['license'] ?? null)) {
            // Nexus described the license alongside the refusal; store that.
            $refused = $this->mapApiResponse($body, $licenseKey, null, $existing);

            if (($refused['status'] ?? '') === LicenseErrorCode::ACTIVE || ($refused['status'] ?? '') === '') {
                $refused['status'] = $refusedStatus;
            }
        } else {
            $refused = $existing;
            $refused['status'] = $refusedStatus;
            $refused['last_validated_at'] = $now;
            $refused['last_success_at'] = $now;

            if (is_array($body['renewal'] ?? null)) {
                $refused['renewal'] = $body['renewal'];
            }
        }

        $refused['error_code'] = $code;
        $this->store->set('license', $refused);
    }

    /**
     * The stored status a refusal code stands for. Anything that is not one of
     * the license states becomes "invalid", which classify() reports as such.
     */
    private function statusForRefusal(string $code): string
    {
        switch ($code) {
            case LicenseErrorCode::LICENSE_EXPIRED:
            case LicenseErrorCode::EXPIRED:
                return LicenseErrorCode::EXPIRED;
            case LicenseErrorCode::LICENSE_SUSPENDED:
            case LicenseErrorCode::SUSPENDED:
                return LicenseErrorCode::SUSPENDED;
            case 'license_revoked':
            case LicenseErrorCode::REVOKED:
                return LicenseErrorCode::REVOKED;
            case LicenseErrorCode::KEY_DISABLED:
            case LicenseErrorCode::DISABLED:
                return LicenseErrorCode::DISABLED;
            default:
                return LicenseErrorCode::INVALID;
        }
    }

    /**
     * @param  mixed  $buyer
     * @return array{name: string, email: string}|null
     */
    private function mapBuyer($buyer): ?array
    {
        if (! is_array($buyer)) {
            return null;
        }

        return [
            'name' => (string) ($buyer['name'] ?? ''),
            'email' => (string) ($buyer['email'] ?? ''),
        ];
    }

    /**
     * Keep the known fields of each site on the license, nothing else.
     *
     * @param  array<int, mixed>  $sites
     * @return array<int, array{id: int|string|null, domain: string, site_url: string, is_counted: bool, activated_at: string|null, last_check_at: string|null}>
     */
    private function mapSites(array $sites): array
    {
        $mapped = [];

        foreach ($sites as $site) {
            if (! is_array($site) || empty($site['domain'])) {
                continue;
            }

            $mapped[] = [
                'id' => $site['id'] ?? null,
                'domain' => (string) $site['domain'],
                'site_url' => (string) ($site['site_url'] ?? ''),
                'is_counted' => (bool) ($site['is_counted'] ?? true),
                'activated_at' => isset($site['activated_at']) ? (string) $site['activated_at'] : null,
                'last_check_at' => isset($site['last_check_at']) ? (string) $site['last_check_at'] : null,
            ];
        }

        return $mapped;
    }

    /**
     * This site's own seat as Nexus sees it. A missing flag stays null ("not said").
     *
     * @param  array<string, mixed>  $site
     * @return array{active: bool|null, is_counted: bool|null}
     */
    private function mapSiteState(array $site): array
    {
        return [
            'active' => array_key_exists('active', $site) ? (bool) $site['active'] : null,
            'is_counted' => array_key_exists('is_counted', $site) ? (bool) $site['is_counted'] : null,
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
