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

    public function test_over_limit_only_when_max_activations_positive(): void
    {
        // max_activations 0 means "unlimited" — never over limit.
        $this->seed([
            'status' => 'active',
            'expires_at' => $this->inDays(30),
            'max_activations' => 0,
            'activation_count' => 99,
        ]);
        $this->assertSame(LicenseErrorCode::ACTIVE, $this->manager->classify()['code']);

        $this->seed([
            'status' => 'active',
            'expires_at' => $this->inDays(30),
            'max_activations' => 3,
            'activation_count' => 3,
        ]);
        $this->assertSame(LicenseErrorCode::OVER_LIMIT, $this->manager->classify()['code']);
    }

    public function test_over_limit_outranks_expired_but_not_suspended(): void
    {
        $base = ['expires_at' => $this->inDays(-5), 'max_activations' => 2, 'activation_count' => 5];

        $this->seed(['status' => 'active'] + $base);
        $this->assertSame(LicenseErrorCode::OVER_LIMIT, $this->manager->classify()['code']);

        $this->seed(['status' => 'suspended'] + $base);
        $this->assertSame(LicenseErrorCode::SUSPENDED, $this->manager->classify()['code']);
    }

    public function test_unrecognized_status_falls_through_to_invalid(): void
    {
        $this->seed(['status' => 'frozen', 'expires_at' => $this->inDays(30)]);

        $this->assertSame(LicenseErrorCode::INVALID, $this->manager->classify()['code']);
    }

    /**
     * Seed the license store with a stored shape (always with a key so it counts
     * as activated). classify() never decrypts the key, so any non-empty value works.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seed(array $overrides): void
    {
        $this->store->set('license', array_merge(['license_key' => 'enc-key'], $overrides));
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
