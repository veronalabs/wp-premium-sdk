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

    public function test_activate_prefers_the_top_level_expiry_over_the_nested_one(): void
    {
        $license = ['status' => 'active', 'features' => [], 'expires_at' => '2026-11-01T00:00:00+00:00'];
        WpStub::queueJson(200, ['success' => true, 'expires_at' => '2027-11-01T00:00:00+00:00', 'license' => $license]);
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);

        $this->manager->activate('KEY-001');

        $this->assertSame('2027-11-01T00:00:00+00:00', $this->manager->getLicenseData()['expires_at']);
    }

    public function test_a_top_level_null_expiry_is_a_lifetime_license(): void
    {
        $license = ['status' => 'active', 'features' => [], 'expires_at' => '2026-11-01T00:00:00+00:00'];
        WpStub::queueJson(200, ['success' => true, 'expires_at' => null, 'license' => $license]);
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);

        $this->manager->activate('KEY-001');

        $this->assertSame('', $this->manager->getLicenseData()['expires_at']);
        $this->assertNull($this->manager->classify()['days_remaining']);
    }

    public function test_a_validate_reply_without_a_top_level_expiry_uses_the_nested_one(): void
    {
        $this->activateWith('2026-11-01T00:00:00+00:00');

        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active', 'expires_at' => '2027-03-01T00:00:00+00:00']]);
        $this->manager->refreshStatus();

        $this->assertSame('2027-03-01T00:00:00+00:00', $this->manager->getLicenseData()['expires_at']);
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
        WpStub::queueJson(503, ['message' => 'Service unavailable']);

        $this->assertFalse($this->manager->refreshSites());
        $this->assertCount(2, $this->manager->listSites());
        $this->assertTrue($this->manager->isValid(), 'A network failure must never change the license state.');
    }

    public function test_refresh_sites_ignores_an_error_reply_that_is_not_about_this_site(): void
    {
        $this->activateWithSites();
        WpStub::queueJson(403, ['error_code' => 'license_suspended', 'message' => 'License is suspended']);

        $this->assertFalse($this->manager->refreshSites());
        $this->assertTrue($this->manager->isValid(), 'The license state is left to refreshStatus().');
    }

    /**
     * #53: the owner removed this site in their account. refreshSites() is the only
     * scheduled call that asks with this site's domain, so it must keep that answer.
     */
    public function test_refresh_sites_records_that_this_site_was_removed(): void
    {
        $this->activateWithSites();
        WpStub::queueJson(403, ['success' => false, 'error' => 'domain_not_allowed', 'message' => 'Domain not activated', 'error_code' => 'domain_not_allowed']);

        $this->assertFalse($this->manager->refreshSites(), 'Nexus sent no site list, so the list is not fresh.');

        $data = $this->manager->getLicenseData();
        $this->assertSame('active', $data['status']);
        $this->assertSame('domain_not_allowed', $data['error_code']);
        $this->assertFalse($data['site']['active']);
        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED_HERE, $this->manager->classify()['code']);
        $this->assertFalse($this->manager->isValid());

        // The next scheduled domain-less refresh must not bring it back, nor flip the
        // stored code (each flip fires license_changed).
        $cycle = function (): void {
            WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active', 'max_activations' => 3]]);
            $this->manager->refreshStatus();
            WpStub::queueJson(403, ['success' => false, 'error' => 'domain_not_allowed', 'message' => 'Domain not activated', 'error_code' => 'domain_not_allowed']);
            $this->manager->refreshSites();
        };
        $cycle();
        WpStub::$actionLog = [];
        $cycle();

        $this->assertSame('domain_not_allowed', $this->manager->getLicenseData()['error_code']);
        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED_HERE, $this->manager->classify()['code']);
        $this->assertFalse($this->manager->isValid());
        $changes = array_filter(WpStub::$actionLog, fn (array $call): bool => $call[0] === 'wp_premium_sdk/license_changed');
        $this->assertCount(0, $changes, 'A steady state must not fire license_changed.');

        // Activated here again: the domain check clears it.
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active', 'site' => ['active' => true, 'is_counted' => true]]]);
        $this->assertTrue($this->manager->refreshSites());
        $this->assertSame('', $this->manager->getLicenseData()['error_code']);
        $this->assertTrue($this->manager->isValid());
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

    /**
     * #29: a stored key that no longer decrypts (salts rotated, cipher key lost).
     */
    public function test_an_unreadable_key_is_reported_instead_of_failing(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        unset(WpStub::$options['wp_statistics_premium_cipher']); // the key material changes
        $manager = $this->freshManager();
        $calls = count(WpStub::$requestLog);

        $data = $manager->getLicenseData();

        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('license_key_masked', $data);
        $this->assertArrayNotHasKey('license_key', $data);
        $this->assertTrue($manager->isActivated(), 'A key is still stored; deactivate() can clear it.');
        $this->assertNull($manager->getLicenseKey());
        $this->assertFalse($manager->isValid(), 'A key that cannot be read can neither update nor validate.');
        $this->assertSame(LicenseErrorCode::KEY_UNREADABLE, $manager->classify()['code']);
        $this->assertFalse($manager->refreshStatus());
        $this->assertCount($calls, WpStub::$requestLog);
    }

    /**
     * #30: a different key hands the old key's seat on this domain back.
     */
    public function test_replace_key_releases_the_old_keys_seat_after_activating(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00'); // KEY-001
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]); // activate
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]); // validate
        WpStub::queueJson(200, ['success' => true]);                                    // deactivate old

        $result = $this->manager->replaceKey('KEY-002');

        $calls = array_slice(WpStub::$requestLog, -3);
        $this->assertStringContainsString('/api/v1/license/activate', $calls[0]['url']);
        $this->assertSame('KEY-002', json_decode($calls[0]['args']['body'], true)['license_key']);
        $this->assertStringContainsString('/api/v1/license/deactivate', $calls[2]['url']);
        $this->assertSame('KEY-001', json_decode($calls[2]['args']['body'], true)['license_key']);
        $this->assertSame('example.com', json_decode($calls[2]['args']['body'], true)['domain']);
        $this->assertSame(['removed_remotely' => true, 'error_code' => null], $result['released']);
        $this->assertSame('KEY-002', $this->manager->getLicenseKey());
        $this->assertStringEndsWith('-002', $result['license']['license_key_masked']);
    }

    public function test_replace_key_reports_a_seat_it_could_not_hand_back(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueError('Network down');

        $result = $this->manager->replaceKey('KEY-002');

        $this->assertSame(['removed_remotely' => false, 'error_code' => LicenseErrorCode::NETWORK_ERROR], $result['released']);
        $this->assertSame('KEY-002', $this->manager->getLicenseKey());
    }

    public function test_replace_key_with_the_same_key_releases_nothing(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        $calls = count(WpStub::$requestLog);

        $result = $this->manager->replaceKey('KEY-001');

        $this->assertNull($result['released']);
        $this->assertCount($calls + 2, WpStub::$requestLog, 'Only activate and validate.');
    }

    /**
     * #42: Nexus matches keys ignoring case, so the same key in other case is the
     * same license and its seat (just activated) must not be released.
     */
    public function test_replace_key_with_the_same_key_in_other_case_releases_nothing(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00'); // KEY-001
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        $calls = count(WpStub::$requestLog);

        $result = $this->manager->replaceKey(' key-001 ');

        $this->assertNull($result['released']);
        $this->assertCount($calls + 2, WpStub::$requestLog, 'Only activate and validate, no deactivate.');
    }

    public function test_replace_key_uses_the_license_id_when_both_are_known(): void
    {
        $license = ['status' => 'active', 'license_id' => 77, 'features' => []];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        $this->manager->activate('KEY-001');

        // A different key string Nexus resolves to the same license: nothing to release.
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        $this->assertNull($this->manager->replaceKey('KEY-001-ALIAS')['released']);

        // Same key in other case, but Nexus says it is another license: release.
        $other = ['license_id' => 78] + $license;
        WpStub::queueJson(200, ['success' => true, 'license' => $other]);
        WpStub::queueJson(200, ['success' => true, 'license' => $other]);
        WpStub::queueJson(200, ['success' => true]); // deactivate old
        $result = $this->manager->replaceKey('key-001-alias');

        $this->assertSame(['removed_remotely' => true, 'error_code' => null], $result['released']);
    }

    public function test_a_failed_replacement_keeps_the_old_license_and_its_seat(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        WpStub::queueJson(404, ['success' => false, 'error_code' => 'invalid_key', 'message' => 'License not found']);
        $calls = count(WpStub::$requestLog);

        try {
            $this->manager->replaceKey('BAD-KEY');
            $this->fail('A refused key must throw.');
        } catch (\Exception $e) {
            $this->assertCount($calls + 1, WpStub::$requestLog, 'No deactivate for the old key.');
        }

        $this->assertSame('KEY-001', $this->manager->getLicenseKey());
        $this->assertTrue($this->manager->isValid());
    }

    /**
     * #37: on a staging clone, domain_not_allowed is about the address, not the key.
     */
    public function test_domain_not_allowed_keeps_the_status_and_marks_this_site_not_activated(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        WpStub::$homeUrl = 'https://staging.example.com';
        WpStub::queueJson(403, ['success' => false, 'error' => 'domain_not_allowed', 'message' => 'Domain not activated', 'error_code' => 'domain_not_allowed']);

        $this->assertFalse($this->manager->validate());

        $data = $this->manager->getLicenseData();
        $this->assertSame('active', $data['status']);
        $this->assertSame('domain_not_allowed', $data['error_code']);
        $this->assertFalse($data['site']['active']);
        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED_HERE, $this->manager->classify()['code']);
        $this->assertFalse($this->manager->isValid(), 'No updates until it is activated here.');
        $this->assertSame(['was' => 'example.com', 'now' => 'staging.example.com'], $this->manager->domainChange());

        // A domain-less refresh says nothing about this domain, so the state holds.
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        $this->manager->refreshStatus();

        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED_HERE, $this->manager->classify()['code']);
        $this->assertFalse($this->manager->isValid(), 'No updates until it is activated here.');
    }

    /**
     * #42: a staging copy of a one-seat license (1 of 1, the seat is production's)
     * is not activated here, not over the limit.
     */
    public function test_a_staging_copy_of_a_full_one_seat_license_is_not_activated_here(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        $data = $this->store->get('license');
        $data['max_activations'] = 1;
        $data['activation_count'] = 1;
        $this->store->set('license', $data);
        WpStub::$homeUrl = 'https://staging.example.com';
        WpStub::queueJson(403, ['error_code' => 'domain_not_allowed', 'message' => 'Domain not activated']);

        $this->manager->validate();

        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED_HERE, $this->manager->classify()['code']);
        $this->assertFalse($this->manager->isValid());
    }

    public function test_more_sites_than_seats_is_over_limit_even_when_not_activated_here(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        $data = $this->store->get('license');
        $data['max_activations'] = 1;
        $data['activation_count'] = 2;
        $this->store->set('license', $data);
        WpStub::queueJson(403, ['error_code' => 'domain_not_allowed', 'message' => 'Domain not activated']);

        $this->manager->validate();

        $this->assertSame(LicenseErrorCode::OVER_LIMIT, $this->manager->classify()['code']);
    }

    /**
     * #42: a copy on a second production address (not a staging name) that Nexus
     * refuses with domain_not_allowed gets no updates either.
     */
    public function test_a_copy_on_a_second_production_domain_is_not_valid(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        WpStub::$homeUrl = 'https://another-shop.com';
        WpStub::queueJson(403, ['success' => false, 'error_code' => 'domain_not_allowed', 'message' => 'Domain not activated']);

        $this->assertFalse($this->manager->validate());

        $this->assertSame('active', $this->manager->getLicenseData()['status']);
        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED_HERE, $this->manager->classify()['code']);
        $this->assertFalse($this->manager->isValid(), 'No updates until it is activated on its own address.');
        $this->assertSame(['was' => 'example.com', 'now' => 'another-shop.com'], $this->manager->domainChange());
    }

    /**
     * #42: a veto on the scoped gate holds even when an old shared callback
     * returns null (its first argument was always null before).
     */
    public function test_a_shared_gate_returning_null_cannot_cancel_a_scoped_veto(): void
    {
        $this->queueActivationReplies();
        WpStub::queueJson(200, ['success' => true]); // rollback
        add_filter('wp_premium_sdk/wp-statistics/activation_gate', static fn () => 'Tier too low.', 10, 3);
        add_filter('wp_premium_sdk/activation_gate', static fn () => null, 10, 2);

        try {
            $this->manager->activate('KEY-001');
            $this->fail('Expected the scoped veto to hold.');
        } catch (ActivationVetoedException $e) {
            $this->assertSame('Tier too low.', $e->getMessage());
        }

        $this->assertNull($this->manager->getLicenseData());
    }

    public function test_the_shared_gate_starts_from_null_and_can_still_veto(): void
    {
        $this->queueActivationReplies();
        WpStub::queueJson(200, ['success' => true]); // rollback
        $first = 'unset';
        add_filter('wp_premium_sdk/wp-statistics/activation_gate', static fn () => 'Scoped says no.', 10, 3);
        add_filter('wp_premium_sdk/activation_gate', static function ($error) use (&$first) {
            $first = $error;

            return $error;
        }, 10, 2);

        try {
            $this->manager->activate('KEY-001');
            $this->fail('Expected a veto.');
        } catch (ActivationVetoedException $e) {
            $this->assertSame('Scoped says no.', $e->getMessage());
        }

        $this->assertNull($first, 'The shared gate keeps its old contract: null first.');

        // Shared veto alone also blocks.
        WpStub::reset();
        $this->setUp();
        $this->queueActivationReplies();
        WpStub::queueJson(200, ['success' => true]); // rollback
        add_filter('wp_premium_sdk/activation_gate', static fn () => 'Shared says no.', 10, 2);

        $this->expectException(ActivationVetoedException::class);
        $this->manager->activate('KEY-001');
    }

    /**
     * #38: one plugin's gate must not veto another product's activation.
     */
    public function test_a_gate_scoped_to_another_product_is_not_asked(): void
    {
        $this->queueActivationReplies();
        add_filter('wp_premium_sdk/wconvert/activation_gate', static fn () => 'Not for this product.', 10, 3);

        $data = $this->manager->activate('KEY-001');

        $this->assertSame('active', $data['status']);
    }

    public function test_the_products_own_gate_can_veto(): void
    {
        $this->queueActivationReplies();
        WpStub::queueJson(200, ['success' => true]); // rollback
        $seen = null;
        add_filter('wp_premium_sdk/wp-statistics/activation_gate', static function ($error, array $licenseData, string $productSlug) use (&$seen) {
            $seen = $productSlug;

            return 'Tier too low.';
        }, 10, 3);

        try {
            $this->manager->activate('KEY-001');
            $this->fail('Expected the scoped gate to veto.');
        } catch (ActivationVetoedException $e) {
            $this->assertSame('Tier too low.', $e->getMessage());
        }

        $this->assertSame('wp-statistics', $seen);
        $this->assertNull($this->manager->getLicenseData());
    }

    public function test_the_shared_gate_is_told_the_product(): void
    {
        $this->queueActivationReplies();
        $seen = null;
        add_filter('wp_premium_sdk/activation_gate', static function ($error, array $licenseData, string $productSlug) use (&$seen) {
            $seen = $productSlug;

            return $productSlug === 'wp-statistics' ? $error : 'Not mine.';
        }, 10, 3);

        $this->manager->activate('KEY-001');

        $this->assertSame('wp-statistics', $seen);
        $this->assertTrue($this->manager->isValid());
    }

    /**
     * #39: a Retry-After from Nexus is waited out, however often the page asks.
     */
    public function test_refresh_waits_out_a_retry_after(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        WpStub::queueJson(429, ['message' => 'Too Many Attempts.'], ['retry-after' => '600']);
        WpStub::queueJson(429, ['message' => 'Too Many Attempts.'], ['retry-after' => '600']);
        WpStub::queueJson(429, ['message' => 'Too Many Attempts.'], ['retry-after' => '600']);
        $calls = count(WpStub::$requestLog);

        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($this->manager->refreshStatus());
        }

        $this->assertCount($calls + 1, WpStub::$requestLog, 'One validate request inside the Retry-After window.');
        $this->assertGreaterThanOrEqual(time() + 595, $this->manager->retryAt());
        $this->assertTrue($this->manager->isValid(), 'A rate limit says nothing about the license.');
    }

    public function test_a_wait_that_has_passed_no_longer_holds_refreshes_back(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        $data = $this->store->get('license');
        $data['retry_at'] = time() - 1;
        $this->store->set('license', $data);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);

        $this->assertNull($this->manager->retryAt());
        $this->assertTrue($this->manager->refreshStatus());
        $this->assertArrayNotHasKey('retry_at', $this->store->get('license'), 'An answer clears the wait.');
    }

    public function test_refresh_with_a_minimum_interval_skips_a_recent_check(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');
        $calls = count(WpStub::$requestLog);

        $this->assertFalse($this->manager->refreshStatus(LicenseManager::MIN_REFRESH_INTERVAL));
        $this->assertCount($calls, WpStub::$requestLog, 'Checked a moment ago by activate().');

        $this->rewindTimestamps(LicenseManager::MIN_REFRESH_INTERVAL);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);

        $this->assertTrue($this->manager->refreshStatus(LicenseManager::MIN_REFRESH_INTERVAL));
        $this->assertCount($calls + 1, WpStub::$requestLog);
    }

    private function queueActivationReplies(): void
    {
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active', 'tier_slug' => 'growth', 'features' => []]]);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active', 'tier_slug' => 'growth', 'features' => []]]);
    }

    /**
     * A new LicenseManager over the same store, as the next request builds it.
     */
    private function freshManager(): LicenseManager
    {
        $config = new ClientConfig([
            'product_slug' => 'wp-statistics',
            'option_key' => 'wp_statistics_premium',
            'oauth_state_prefix' => 'x_',
            'oauth_callback_params' => ['code' => 'c', 'state' => 's'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'td',
            'current_version' => '15.0.0',
        ]);

        return new LicenseManager(new LicenseClient($config, new ApiClient($config)), $this->store, new SodiumEncryptor('wp_statistics_premium_cipher'));
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
