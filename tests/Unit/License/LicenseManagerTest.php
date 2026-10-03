<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\License;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\SodiumEncryptor;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\License\ActivationVetoedException;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

class LicenseManagerTest extends TestCase
{
    private PremiumStore $store;

    private LicenseManager $manager;

    protected function setUp(): void
    {
        WpStub::reset();

        $config = new ClientConfig([
            'product_slug' => 'wp-statistics',
            'option_key' => 'wp_statistics_premium',
            'oauth_state_prefix' => 'x_',
            'oauth_callback_params' => ['code' => 'c', 'state' => 's'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'td',
            'current_version' => '15.0.0',
        ]);

        $this->store = new PremiumStore($config);
        $this->manager = new LicenseManager(
            new LicenseClient($config, new ApiClient($config)),
            $this->store,
            new SodiumEncryptor('wp_statistics_premium_cipher'),
        );
    }

    public function test_has_feature_matches_only_listed_slugs(): void
    {
        $this->store->set('license', ['features' => ['entry-pages', 'goals']]);

        $this->assertTrue($this->manager->hasFeature('goals'));
        $this->assertFalse($this->manager->hasFeature('events'));
    }

    public function test_wildcard_feature_entitles_every_module(): void
    {
        $this->store->set('license', ['features' => ['*']]);

        $this->assertTrue($this->manager->hasFeature('entry-pages'));
        $this->assertTrue($this->manager->hasFeature('any-other-module'));
    }

    public function test_refresh_status_pulls_renewed_expiry_from_server(): void
    {
        $this->activateWith('2020-01-01T00:00:00+00:00');

        // Renewal happened on the server.
        WpStub::queueJson(200, ['success' => true, 'license' => [
            'status' => 'active',
            'expires_at' => '2027-01-01T00:00:00+00:00',
            'features' => ['entry-pages'],
        ]]);

        $this->manager->refreshStatus();

        $this->assertSame('2027-01-01T00:00:00+00:00', $this->manager->getLicenseData()['expires_at']);
    }

    public function test_refresh_status_validates_without_a_domain(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');

        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        $this->manager->refreshStatus();

        $lastBody = json_decode(WpStub::$requestLog[count(WpStub::$requestLog) - 1]['args']['body'], true);
        $this->assertSame('', $lastBody['domain'] ?? 'MISSING');
    }

    public function test_refresh_status_keeps_cache_on_api_failure(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');

        WpStub::queueError('Network down');
        $this->manager->refreshStatus();

        $this->assertSame('2027-01-01T00:00:00+00:00', $this->manager->getLicenseData()['expires_at']);
    }

    public function test_activate_captures_renewal_block_from_response(): void
    {
        $renewal = [
            'subscription_status' => 'active',
            'renews_at' => '2027-01-01T00:00:00+00:00',
            'days_remaining' => 5,
            'state' => 'expiring_soon',
            'renew_url' => 'https://nexus.test/checkout?coupon=RENEW-1',
            'offer' => ['code' => 'RENEW-1', 'discount_type' => 'percentage', 'discount_value' => 20],
        ];
        $license = ['status' => 'active', 'features' => [], 'expires_at' => '2027-01-01T00:00:00+00:00', 'renewal' => $renewal];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // activate
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // validate

        $data = $this->manager->activate('KEY-001');

        $this->assertSame($renewal, $data['renewal'], 'Public license data must expose the renewal block.');
        $this->assertSame($renewal, $this->manager->getRenewal());
    }

    public function test_get_renewal_is_null_when_none_cached(): void
    {
        $this->store->set('license', ['license_key' => 'enc', 'status' => 'active']);

        $this->assertNull($this->manager->getRenewal());
    }

    public function test_refresh_without_renewal_preserves_cached_offer(): void
    {
        $renewal = [
            'subscription_status' => 'active',
            'renews_at' => '2027-01-01T00:00:00+00:00',
            'days_remaining' => 5,
            'state' => 'expiring_soon',
            'renew_url' => 'https://nexus.test/checkout?coupon=RENEW-1',
            'offer' => ['code' => 'RENEW-1', 'discount_type' => 'percentage', 'discount_value' => 20],
        ];
        $license = ['status' => 'active', 'features' => [], 'expires_at' => '2027-01-01T00:00:00+00:00', 'renewal' => $renewal];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // activate
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // validate
        $this->manager->activate('KEY-001');

        // A later background refresh omits the renewal block entirely.
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active', 'expires_at' => '2027-01-01T00:00:00+00:00']]);
        $this->manager->refreshStatus();

        $this->assertSame($renewal, $this->manager->getRenewal(), 'A refresh that omits renewal must keep the cached coupon.');
    }

    public function test_get_license_data_exposes_masked_key_not_raw(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');

        $data = $this->manager->getLicenseData();

        $this->assertArrayNotHasKey('license_key', $data, 'Raw key must never reach the UI.');
        $this->assertArrayHasKey('license_key_masked', $data);
        $this->assertStringEndsWith('-001', $data['license_key_masked']);
        $this->assertStringContainsString('•', $data['license_key_masked']);
    }

    public function test_activate_captures_tier_slug_from_response(): void
    {
        $license = ['status' => 'active', 'tier_slug' => 'pro', 'features' => [], 'expires_at' => '2027-01-01T00:00:00+00:00'];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // activate
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // validate

        $data = $this->manager->activate('KEY-001');

        $this->assertSame('pro', $data['tier_slug'] ?? null, 'Public license data must expose the Nexus tier_slug.');
        $this->assertSame('pro', $this->manager->getTier());
    }

    public function test_activation_gate_veto_throws_and_rolls_back(): void
    {
        $license = ['status' => 'active', 'tier_slug' => 'basic', 'features' => []];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // activate
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // validate
        WpStub::queueJson(200, ['success' => true]);                        // deactivate (rollback)

        add_filter('wp_premium_sdk/activation_gate', static function ($error, array $licenseData) {
            return ($licenseData['tier_slug'] ?? '') === 'basic' ? 'Tier too low for this build.' : $error;
        }, 10, 2);

        try {
            $this->manager->activate('KEY-001');
            $this->fail('Expected the activation gate to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Tier too low for this build.', $e->getMessage());
            $this->assertInstanceOf(ActivationVetoedException::class, $e);
            $this->assertTrue($e->removedRemotely());
            $this->assertNull($e->rollbackErrorCode());
        }

        $this->assertNull($this->manager->getLicenseData(), 'A vetoed activation must not persist license data.');

        $last = WpStub::$requestLog[count(WpStub::$requestLog) - 1];
        $this->assertStringContainsString('/api/v1/license/deactivate', $last['url'], 'Veto must roll back the Nexus activation.');
    }

    public function test_activation_gate_allows_when_filter_returns_null(): void
    {
        $license = ['status' => 'active', 'tier_slug' => 'elite', 'features' => []];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // activate
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // validate

        add_filter('wp_premium_sdk/activation_gate', static fn ($error) => $error, 10, 2);

        $data = $this->manager->activate('KEY-001');

        $this->assertSame('elite', $data['tier_slug']);
        $this->assertSame('elite', $this->manager->getTier());
    }

    public function test_veto_reports_a_rollback_that_never_reached_nexus(): void
    {
        $license = ['status' => 'active', 'tier_slug' => 'basic', 'features' => []];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // activate
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // validate
        WpStub::queueError('Connection reset');                             // deactivate (rollback)

        add_filter('wp_premium_sdk/activation_gate', static fn () => 'Tier too low.', 10, 2);

        try {
            $this->manager->activate('KEY-001');
            $this->fail('Expected the activation gate to throw.');
        } catch (ActivationVetoedException $e) {
            $this->assertFalse($e->removedRemotely(), 'The seat is still taken on Nexus.');
            $this->assertSame(LicenseErrorCode::NETWORK_ERROR, $e->rollbackErrorCode());
        }
    }

    public function test_deactivate_reports_a_server_it_could_not_reach_but_still_clears_locally(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        WpStub::queueError('Network down');

        $result = $this->manager->deactivate();

        $this->assertSame(['removed_remotely' => false, 'error_code' => LicenseErrorCode::NETWORK_ERROR], $result);
        $this->assertNull($this->manager->getLicenseData(), 'The user asked for removal; it happens locally regardless.');
    }

    public function test_deactivate_reports_success_when_nexus_released_the_seat(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        WpStub::queueJson(200, ['success' => true]);

        $this->assertSame(['removed_remotely' => true, 'error_code' => null], $this->manager->deactivate());
        $this->assertNull($this->manager->getLicenseData());
    }

    public function test_deactivate_with_nothing_stored_makes_no_call(): void
    {
        $this->assertSame(['removed_remotely' => true, 'error_code' => null], $this->manager->deactivate());
        $this->assertSame([], WpStub::$requestLog);
    }

    /**
     * The core of #6: a key Nexus refuses must stop reading as active.
     */
    public function test_refresh_stores_a_refusal(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');

        WpStub::queueJson(404, ['success' => false, 'error_code' => 'invalid_key', 'message' => 'License not found']);
        $this->manager->refreshStatus();

        $data = $this->manager->getLicenseData();
        $this->assertSame('invalid', $data['status']);
        $this->assertSame('invalid_key', $data['error_code']);
        $this->assertFalse($this->manager->isValid());
        $this->assertSame(LicenseErrorCode::INVALID, $this->manager->classify()['code']);
    }

    public function test_refresh_maps_refusal_codes_to_license_states(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');

        WpStub::queueJson(403, ['error_code' => 'license_suspended', 'message' => 'Suspended']);
        $this->manager->refreshStatus();
        $this->assertSame(LicenseErrorCode::SUSPENDED, $this->manager->classify()['code']);

        WpStub::queueJson(403, ['error_code' => 'wrong_product', 'message' => 'Wrong product']);
        $this->manager->refreshStatus();
        $this->assertSame('wrong_product', $this->manager->getLicenseData()['error_code']);
        $this->assertFalse($this->manager->isValid());
    }

    public function test_refresh_stores_the_license_nexus_sends_with_a_refusal(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');

        WpStub::queueJson(403, [
            'error_code' => 'license_expired',
            'message' => 'Expired',
            'license' => ['status' => 'expired', 'expires_at' => '2025-01-01T00:00:00+00:00'],
        ]);
        $this->manager->refreshStatus();

        $data = $this->manager->getLicenseData();
        $this->assertSame('expired', $data['status']);
        $this->assertSame('2025-01-01T00:00:00+00:00', $data['expires_at']);
        $this->assertSame('license_expired', $data['error_code']);
    }

    /**
     * Rate limits and broken servers say nothing about the license.
     *
     * @dataProvider transientFailures
     */
    public function test_refresh_keeps_cache_on_transient_failures(callable $queue): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        $before = $this->manager->getLicenseData();

        $queue();
        $this->manager->refreshStatus();

        $after = $this->manager->getLicenseData();
        $this->assertSame('active', $after['status']);
        $this->assertSame($before['last_success_at'], $after['last_success_at']);
        $this->assertTrue($this->manager->isValid());
    }

    /**
     * @return array<string, array{callable}>
     */
    public function transientFailures(): array
    {
        return [
            'network' => [static function () { WpStub::queueError('Network down'); }],
            'rate limited' => [static function () { WpStub::queueJson(429, ['message' => 'Too Many Attempts.']); }],
            'error page' => [static function () { WpStub::$responseQueue[] = [502, '<html>Bad Gateway</html>', []]; }],
            'no code' => [static function () { WpStub::queueJson(422, ['message' => 'The domain field is required.']); }],
        ];
    }

    /**
     * Nexus answers a suspended key 200 with success: false and the status.
     */
    public function test_refresh_stores_a_status_answered_with_200(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');

        WpStub::queueJson(200, ['success' => false, 'error_code' => 'license_suspended', 'license' => ['status' => 'suspended']]);
        $this->manager->refreshStatus();

        $this->assertSame(LicenseErrorCode::SUSPENDED, $this->manager->classify()['code']);
        $this->assertSame('license_suspended', $this->manager->getLicenseData()['error_code']);
    }

    public function test_an_old_error_code_does_not_outlive_a_recovery(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');

        WpStub::queueJson(200, ['success' => false, 'error_code' => 'license_suspended', 'license' => ['status' => 'suspended']]);
        $this->manager->refreshStatus();

        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        $this->manager->refreshStatus();

        $this->assertSame('', $this->manager->getLicenseData()['error_code']);
        $this->assertTrue($this->manager->isValid());
    }

    public function test_failed_validate_moves_last_attempt_but_not_last_success(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        $this->rewindTimestamps(3600);

        WpStub::queueError('Network down');
        $this->assertFalse($this->manager->validate());

        $data = $this->manager->getLicenseData();
        $this->assertGreaterThanOrEqual(time() - 5, $data['last_validated_at']);
        $this->assertLessThan(time() - 3000, $data['last_success_at'], 'last_success_at must keep the time Nexus last answered.');
    }

    public function test_a_successful_check_sets_last_success_at(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        $this->rewindTimestamps(3600);

        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        $this->manager->refreshStatus();

        $this->assertGreaterThanOrEqual(time() - 5, $this->manager->getLicenseData()['last_success_at']);
    }

    public function test_license_page_details_are_stored(): void
    {
        $license = [
            'status' => 'active',
            'features' => [],
            'license_id' => 42,
            'manage_url' => 'https://nexus.test/account/licenses/42',
            'upgrade_url' => 'https://nexus.test/upgrade/42',
            'buyer' => ['name' => 'Ada Buyer', 'email' => 'buyer@example.com'],
            'site' => ['active' => true, 'is_counted' => true],
            'sites' => [
                ['id' => 1, 'domain' => 'example.com', 'site_url' => 'https://example.com', 'is_counted' => true, 'activated_at' => '2026-09-01T00:00:00+00:00', 'last_check_at' => null],
                ['id' => 2, 'domain' => 'staging.example.com', 'site_url' => 'https://staging.example.com', 'is_counted' => false, 'activated_at' => '2026-09-02T00:00:00+00:00', 'last_check_at' => null],
            ],
            'renewal' => ['state' => 'active', 'is_trial' => true, 'trial_ends_at' => '2026-10-10T00:00:00+00:00', 'grace_ends_at' => null, 'auto_renews' => true],
        ];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);

        $data = $this->manager->activate('KEY-001');

        $this->assertSame(42, $data['license_id']);
        $this->assertSame('https://nexus.test/account/licenses/42', $data['manage_url']);
        $this->assertSame('https://nexus.test/upgrade/42', $data['upgrade_url']);
        $this->assertSame(['name' => 'Ada Buyer', 'email' => 'buyer@example.com'], $data['buyer']);
        $this->assertSame('buyer@example.com', $data['customer_email']);
        $this->assertSame(['active' => true, 'is_counted' => true], $data['site']);
        $this->assertCount(2, $data['sites']);
        $this->assertFalse($data['sites'][1]['is_counted']);
        $this->assertTrue($data['renewal']['is_trial']);
    }

    public function test_an_older_server_without_details_still_works(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');

        $data = $this->manager->getLicenseData();
        $this->assertNull($data['sites']);
        $this->assertNull($data['site']);
        $this->assertNull($data['buyer']);
        $this->assertSame('', $data['manage_url']);
        $this->assertSame([], $this->manager->listSites());
    }

    /**
     * Nexus nulls `sites` and `buyer` for a domain-less check; that must not wipe them.
     */
    public function test_a_refresh_with_null_sites_and_buyer_keeps_the_last_known(): void
    {
        $this->activateWithSites();
        $data = $this->store->get('license');
        $data['buyer'] = ['name' => 'Ada Buyer', 'email' => 'buyer@example.com'];
        $this->store->set('license', $data);

        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active', 'sites' => null, 'buyer' => null]]);
        $this->manager->refreshStatus();

        $after = $this->manager->getLicenseData();
        $this->assertCount(2, $after['sites']);
        $this->assertSame(['name' => 'Ada Buyer', 'email' => 'buyer@example.com'], $after['buyer']);
    }

    public function test_refresh_sites_failure_keeps_list_and_state(): void
    {
        $this->activateWithSites();
        WpStub::queueJson(403, ['error_code' => 'domain_not_allowed', 'message' => 'Domain not activated']);

        $this->assertFalse($this->manager->refreshSites());
        $this->assertCount(2, $this->manager->listSites());
        $this->assertTrue($this->manager->isValid(), 'Listing sites must never change the license state.');
    }

    public function test_list_sites_flags_this_site(): void
    {
        WpStub::$homeUrl = 'https://www.example.com/';
        $this->activateWithSites();

        $sites = $this->manager->listSites();

        $this->assertTrue($sites[0]['this_site']);
        $this->assertFalse($sites[1]['this_site']);
    }

    public function test_remove_site_releases_that_domain_then_refreshes(): void
    {
        $this->activateWithSites();

        WpStub::queueJson(200, ['success' => true]); // deactivate
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active', 'activation_count' => 1, 'sites' => [
            ['id' => 1, 'domain' => 'example.com', 'is_counted' => true],
        ]]]); // validate with this site's domain (refresh)

        $sites = $this->manager->removeSite('other.com');

        $calls = array_slice(WpStub::$requestLog, -2);
        $this->assertStringContainsString('/api/v1/license/deactivate', $calls[0]['url']);
        $this->assertSame('other.com', json_decode($calls[0]['args']['body'], true)['domain']);
        $this->assertSame('KEY-001', json_decode($calls[0]['args']['body'], true)['license_key']);
        $this->assertStringContainsString('/api/v1/license/validate', $calls[1]['url']);
        $this->assertSame('example.com', json_decode($calls[1]['args']['body'], true)['domain'], 'Nexus only lists sites to an activated domain.');
        $this->assertCount(1, $sites);
        $this->assertSame(1, $this->manager->getLicenseData()['activation_count']);
    }

    public function test_remove_site_refuses_this_site(): void
    {
        $this->activateWithSites();
        $calls = count(WpStub::$requestLog);

        try {
            $this->manager->removeSite('https://example.com/');
            $this->fail('Removing this site must go through deactivate().');
        } catch (\RuntimeException $e) {
            $this->assertCount($calls, WpStub::$requestLog, 'No call to Nexus.');
        }
    }

    private function activateWithSites(): void
    {
        $license = ['status' => 'active', 'features' => [], 'activation_count' => 2, 'max_activations' => 3, 'sites' => [
            ['id' => 1, 'domain' => 'example.com', 'site_url' => 'https://example.com', 'is_counted' => true],
            ['id' => 2, 'domain' => 'other.com', 'site_url' => 'https://other.com', 'is_counted' => true],
        ]];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        $this->manager->activate('KEY-001');
    }

    /**
     * Move the stored check timestamps into the past, so a later write is visible.
     */
    private function rewindTimestamps(int $seconds): void
    {
        $data = $this->store->get('license');
        $data['last_validated_at'] -= $seconds;
        $data['last_success_at'] -= $seconds;
        $this->store->set('license', $data);
    }

    /**
     * Seed the cache via activate() (which makes an activate + a validate call).
     */
    private function activateWith(string $expiresAt): void
    {
        $license = ['status' => 'active', 'expires_at' => $expiresAt, 'features' => ['entry-pages']];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        $this->manager->activate('KEY-001');
    }
}
