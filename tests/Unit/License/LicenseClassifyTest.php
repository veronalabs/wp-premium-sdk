<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\License;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\SodiumEncryptor;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

/**
 * classify() turns stored license signals into a single canonical state code,
 * applying notice precedence and the expiry-warning threshold.
 */
class LicenseClassifyTest extends TestCase
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

    public function test_no_stored_license_is_not_activated(): void
    {
        $result = $this->manager->classify();

        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED, $result['code']);
        $this->assertNull($result['days_remaining']);
        $this->assertSame('', $result['raw_status']);
    }

    public function test_stored_data_without_a_key_is_not_activated(): void
    {
        $this->store->set('license', ['status' => 'active']); // no license_key

        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED, $this->manager->classify()['code']);
    }

    public function test_active_with_far_future_expiry_is_active(): void
    {
        $this->seed(['status' => 'active', 'expires_at' => $this->inDays(120)]);

        $result = $this->manager->classify();

        $this->assertSame(LicenseErrorCode::ACTIVE, $result['code']);
        $this->assertSame(120, $result['days_remaining']);
        $this->assertSame('active', $result['raw_status']);
    }

    public function test_lifetime_license_never_expires(): void
    {
        $this->seed(['status' => 'active', 'expires_at' => '']);

        $result = $this->manager->classify();

        $this->assertSame(LicenseErrorCode::ACTIVE, $result['code']);
        $this->assertNull($result['days_remaining']);
    }

    /**
     * 15 days out is just outside the 14-day window — still active.
     */
    public function test_fifteen_days_is_not_yet_expiring(): void
    {
        $this->seed(['status' => 'active', 'expires_at' => $this->inDays(15)]);

        $result = $this->manager->classify();

        $this->assertSame(LicenseErrorCode::ACTIVE, $result['code']);
        $this->assertSame(15, $result['days_remaining']);
    }

    /**
     * @dataProvider expiringBoundaryProvider
     */
    public function test_inside_the_window_is_expiring_soon(int $days): void
    {
        $this->seed(['status' => 'active', 'expires_at' => $this->inDays($days)]);

        $result = $this->manager->classify();

        $this->assertSame(LicenseErrorCode::EXPIRING_SOON, $result['code']);
        $this->assertSame($days, $result['days_remaining']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function expiringBoundaryProvider(): array
    {
        return [
            '14 days' => [14],
            '7 days' => [7],
            '3 days' => [3],
            '1 day' => [1],
        ];
    }

    public function test_expiry_today_is_expired(): void
    {
        // Expired one second ago — day boundary just crossed.
        $this->seed(['status' => 'active', 'expires_at' => gmdate('c', time() - 1)]);

        $result = $this->manager->classify();

        $this->assertSame(LicenseErrorCode::EXPIRED, $result['code']);
        $this->assertLessThanOrEqual(0, $result['days_remaining']);
    }

    public function test_past_expiry_is_expired(): void
    {
        $this->seed(['status' => 'active', 'expires_at' => $this->inDays(-1)]);

        $this->assertSame(LicenseErrorCode::EXPIRED, $this->manager->classify()['code']);
    }

    public function test_status_expired_is_expired_even_with_future_date(): void
    {
        $this->seed(['status' => 'expired', 'expires_at' => $this->inDays(30)]);

        $this->assertSame(LicenseErrorCode::EXPIRED, $this->manager->classify()['code']);
    }

    public function test_suspended_outranks_expired(): void
    {
        $this->seed(['status' => 'suspended', 'expires_at' => $this->inDays(-10)]);

        $this->assertSame(LicenseErrorCode::SUSPENDED, $this->manager->classify()['code']);
    }

    public function test_revoked_and_disabled_map_through(): void
    {
        $this->seed(['status' => 'revoked', 'expires_at' => $this->inDays(30)]);
        $this->assertSame(LicenseErrorCode::REVOKED, $this->manager->classify()['code']);

        $this->seed(['status' => 'disabled', 'expires_at' => $this->inDays(30)]);
        $this->assertSame(LicenseErrorCode::DISABLED, $this->manager->classify()['code']);
    }

    public function test_a_cancelled_license_is_disabled_not_invalid(): void
    {
        $this->seed(['status' => 'cancelled', 'error_code' => 'key_disabled', 'expires_at' => $this->inDays(60)]);

        $this->assertSame(LicenseErrorCode::DISABLED, $this->manager->classify()['code']);
    }

    public function test_cancelled_alone_or_key_disabled_alone_is_disabled(): void
    {
        $this->seed(['status' => 'cancelled', 'expires_at' => $this->inDays(60)]);
        $this->assertSame(LicenseErrorCode::DISABLED, $this->manager->classify()['code']);

        $this->seed(['status' => 'blocked', 'error_code' => 'key_disabled', 'expires_at' => $this->inDays(60)]);
        $this->assertSame(LicenseErrorCode::DISABLED, $this->manager->classify()['code']);
    }

    public function test_cancelled_outranks_expired(): void
    {
        $this->seed(['status' => 'cancelled', 'error_code' => 'key_disabled', 'expires_at' => $this->inDays(-10)]);

        $this->assertSame(LicenseErrorCode::DISABLED, $this->manager->classify()['code']);
    }

    public function test_unlimited_seats_are_never_over_limit(): void
    {
        // max_activations 0 means "unlimited" — never over limit.
        $this->seed([
            'status' => 'active',
            'expires_at' => $this->inDays(30),
            'max_activations' => 0,
            'activation_count' => 99,
        ]);
        $this->assertSame(LicenseErrorCode::ACTIVE, $this->manager->classify()['code']);
    }

    /**
     * Using exactly the seats paid for, this site among them, is the normal case.
     */
    public function test_three_of_three_with_this_site_is_active(): void
    {
        $this->seed([
            'status' => 'active',
            'expires_at' => $this->inDays(30),
            'max_activations' => 3,
            'activation_count' => 3,
        ]);

        $this->assertSame(LicenseErrorCode::ACTIVE, $this->manager->classify()['code']);
    }

    public function test_four_of_three_is_over_limit(): void
    {
        $this->seed([
            'status' => 'active',
            'expires_at' => $this->inDays(30),
            'max_activations' => 3,
            'activation_count' => 4,
        ]);

        $this->assertSame(LicenseErrorCode::OVER_LIMIT, $this->manager->classify()['code']);
    }

    /**
     * Renewing is the fix for an expired license, so its prompt must not be hidden
     * behind a seat notice; an account hold still outranks both.
     */
    public function test_expired_outranks_over_limit_and_suspended_outranks_both(): void
    {
        $base = ['expires_at' => $this->inDays(-5), 'max_activations' => 2, 'activation_count' => 5];

        $this->seed(['status' => 'active'] + $base);
        $this->assertSame(LicenseErrorCode::EXPIRED, $this->manager->classify()['code']);

        $this->seed(['status' => 'expired', 'expires_at' => $this->inDays(30), 'max_activations' => 3, 'activation_count' => 3]);
        $this->assertSame(LicenseErrorCode::EXPIRED, $this->manager->classify()['code']);

        $this->seed(['status' => 'suspended'] + $base);
        $this->assertSame(LicenseErrorCode::SUSPENDED, $this->manager->classify()['code']);
    }

    public function test_over_limit_outranks_expiring_soon(): void
    {
        $this->seed(['status' => 'active', 'expires_at' => $this->inDays(5), 'max_activations' => 1, 'activation_count' => 2]);

        $this->assertSame(LicenseErrorCode::OVER_LIMIT, $this->manager->classify()['code']);
    }

    /**
     * When Nexus says how this site stands, that wins over the bare counts.
     */
    public function test_nexus_site_block_decides_over_limit(): void
    {
        $full = ['status' => 'active', 'expires_at' => $this->inDays(30), 'max_activations' => 3, 'activation_count' => 3];

        // Not activated here and every seat taken: this site holds no seat, so it
        // is not over the limit; it is not activated here.
        $this->seed($full + ['site' => ['active' => false, 'is_counted' => true]]);
        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED_HERE, $this->manager->classify()['code']);

        // Not activated here, and the other sites alone are more than the seats.
        $this->seed(['activation_count' => 4] + $full + ['site' => ['active' => false, 'is_counted' => true]]);
        $this->assertSame(LicenseErrorCode::OVER_LIMIT, $this->manager->classify()['code']);

        // Activated here and one of the three: fine.
        $this->seed($full + ['site' => ['active' => true, 'is_counted' => true]]);
        $this->assertSame(LicenseErrorCode::ACTIVE, $this->manager->classify()['code']);

        // A development site uses no seat, so it is never over, even at 5 of 3.
        $this->seed(['activation_count' => 5] + $full + ['site' => ['active' => true, 'is_counted' => false]]);
        $this->assertSame(LicenseErrorCode::ACTIVE, $this->manager->classify()['code']);
    }

    /**
     * #55: a subscription that renews itself is not about to end.
     */
    public function test_an_auto_renewing_subscription_is_not_expiring_soon(): void
    {
        $this->seed(['status' => 'active', 'expires_at' => $this->inDays(5), 'renewal' => ['auto_renews' => true, 'subscription_status' => 'active', 'state' => 'expiring_soon']]);

        $result = $this->manager->classify();

        $this->assertSame(LicenseErrorCode::ACTIVE, $result['code']);
        $this->assertSame(5, $result['days_remaining']);
    }

    /**
     * #55: a cancelled subscription, a failed charge or no card still warns.
     *
     * @dataProvider stillEndingRenewalProvider
     *
     * @param  array<string, mixed>  $renewal
     */
    public function test_a_subscription_that_will_not_renew_itself_is_still_expiring_soon(array $renewal): void
    {
        $this->seed(['status' => 'active', 'expires_at' => $this->inDays(5), 'renewal' => $renewal]);

        $this->assertSame(LicenseErrorCode::EXPIRING_SOON, $this->manager->classify()['code']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function stillEndingRenewalProvider(): array
    {
        return [
            'no card' => [['auto_renews' => false, 'subscription_status' => 'active']],
            'cancelled' => [['auto_renews' => true, 'subscription_status' => 'cancelled']],
            'cancelling at period end' => [['auto_renews' => true, 'subscription_status' => 'active', 'cancel_at_period_end' => true]],
            'charge failed' => [['auto_renews' => true, 'subscription_status' => 'past_due', 'state' => 'payment_failed']],
        ];
    }

    public function test_auto_renewal_does_not_hide_an_expired_license(): void
    {
        $this->seed(['status' => 'active', 'expires_at' => $this->inDays(-1), 'renewal' => ['auto_renews' => true, 'subscription_status' => 'active']]);

        $this->assertSame(LicenseErrorCode::EXPIRED, $this->manager->classify()['code']);
    }

    /**
     * #52: classifyLicense() judges any stored section, with the same answer as
     * classify() for this site's row.
     */
    public function test_classify_license_matches_classify_for_the_current_row(): void
    {
        $this->seed(['status' => 'active', 'expires_at' => $this->inDays(10), 'max_activations' => 3, 'activation_count' => 3]);

        $this->assertSame($this->manager->classify(), LicenseManager::classifyLicense($this->store->get('license')));
    }

    public function test_classify_license_judges_at_the_given_time(): void
    {
        $license = ['license_key' => 'enc', 'status' => 'active', 'expires_at' => gmdate('c', 1800000000)];

        $this->assertSame(['code' => LicenseErrorCode::EXPIRING_SOON, 'days_remaining' => 10, 'raw_status' => 'active'], LicenseManager::classifyLicense($license, 1800000000 - 10 * 86400));
        $this->assertSame(LicenseErrorCode::ACTIVE, LicenseManager::classifyLicense($license, 1800000000 - 30 * 86400)['code']);
        $this->assertSame(LicenseErrorCode::EXPIRED, LicenseManager::classifyLicense($license, 1800000000)['code']);
    }

    public function test_classify_license_without_a_key_is_not_activated(): void
    {
        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED, LicenseManager::classifyLicense(['status' => 'active'])['code']);
        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED, LicenseManager::classifyLicense([])['code']);
    }

    /**
     * The `site` block is the row's own site; without it the license is judged as a
     * whole, which is how a host classifies the network's license.
     */
    public function test_classify_license_reads_the_site_block_only_when_given(): void
    {
        $license = ['license_key' => 'enc', 'status' => 'active', 'max_activations' => 1, 'activation_count' => 1, 'site' => ['active' => false]];

        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED_HERE, LicenseManager::classifyLicense($license)['code']);

        unset($license['site']);

        $this->assertSame(LicenseErrorCode::ACTIVE, LicenseManager::classifyLicense($license)['code']);
    }

    public function test_classify_license_never_reports_key_unreadable(): void
    {
        $this->seed(['status' => 'active']);
        $data = $this->store->get('license');
        $data['license_key'] = 'not-a-ciphertext';
        $this->store->set('license', $data);

        $this->assertSame(LicenseErrorCode::KEY_UNREADABLE, $this->manager->classify()['code']);
        $this->assertSame(LicenseErrorCode::ACTIVE, LicenseManager::classifyLicense($data)['code']);
    }

    public function test_unrecognized_status_falls_through_to_invalid(): void
    {
        $this->seed(['status' => 'frozen', 'expires_at' => $this->inDays(30)]);

        $this->assertSame(LicenseErrorCode::INVALID, $this->manager->classify()['code']);
    }

    /**
     * Seed the license store with a stored shape (always with a key so it counts
     * as activated). The key is encrypted for real: one that does not decrypt is
     * `key_unreadable`.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seed(array $overrides): void
    {
        $key = (new SodiumEncryptor('wp_statistics_premium_cipher'))->encrypt('KEY-0001');

        $this->store->set('license', array_merge(['license_key' => $key], $overrides));
    }

    /**
     * An ISO-8601 timestamp N days from now, nudged 1h inside the day so
     * ceil() lands on exactly N (and -N for past dates).
     */
    private function inDays(int $days): string
    {
        $offset = $days >= 0 ? ($days * 86400 - 3600) : ($days * 86400 + 3600);

        return gmdate('c', time() + $offset);
    }
}
