<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\License;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\SodiumEncryptor;
use VeronaLabs\WpPremiumSdk\Feature\FeatureInstaller;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
use VeronaLabs\WpPremiumSdk\License\LicenseEndpoints;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;
use VeronaLabs\WpPremiumSdk\Update\PluginUpdater;

/**
 * The license AJAX sub-actions, through the same dispatch() WordPress calls.
 */
class LicenseEndpointsTest extends TestCase
{
    private LicenseManager $manager;

    private LicenseEndpoints $endpoints;

    protected function setUp(): void
    {
        WpStub::reset();
        $_POST = [];

        $config = new ClientConfig([
            'product_slug' => 'wp-statistics',
            'option_key' => 'wp_statistics_premium',
            'oauth_state_prefix' => 'x_',
            'oauth_callback_params' => ['code' => 'c', 'state' => 's'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'td',
            'current_version' => '15.0.0',
        ]);

        $client = new LicenseClient($config, new ApiClient($config));
        $this->manager = new LicenseManager($client, new PremiumStore($config), new SodiumEncryptor('wp_statistics_premium_cipher'));
        $updater = new PluginUpdater($config, $client, $this->manager, 'wp-statistics-premium/wp-statistics-premium.php');

        $this->endpoints = new LicenseEndpoints($config, $this->manager, $updater, new FeatureInstaller($config));
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function test_get_status_returns_the_classified_state(): void
    {
        $this->activate(['max_activations' => 3, 'activation_count' => 3]);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active', 'max_activations' => 3, 'activation_count' => 3]]);

        $response = $this->call('get_status');

        $this->assertTrue($response['success']);
        $this->assertSame(LicenseErrorCode::ACTIVE, $response['data']['state']['code']);
        $this->assertArrayHasKey('days_remaining', $response['data']['state']);
    }

    public function test_get_status_without_a_license_is_not_activated(): void
    {
        $response = $this->call('get_status');

        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED, $response['data']['state']['code']);
    }

    public function test_deactivate_reports_whether_the_seat_was_released(): void
    {
        $this->activate();
        WpStub::queueError('Network down');

        $response = $this->call('deactivate');

        $this->assertTrue($response['success']);
        $this->assertSame([], $response['data']['removed']);
        $this->assertFalse($response['data']['removed_remotely']);
        $this->assertSame(LicenseErrorCode::NETWORK_ERROR, $response['data']['error_code']);
    }

    public function test_list_sites_fetches_fresh_and_marks_this_site(): void
    {
        $this->activate();
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active', 'sites' => [
            ['id' => 1, 'domain' => 'example.com', 'is_counted' => true],
            ['id' => 2, 'domain' => 'dev.example.com', 'is_counted' => false],
        ]]]);

        $response = $this->call('list_sites');

        $call = WpStub::$requestLog[count(WpStub::$requestLog) - 1];
        $this->assertStringContainsString('/api/v1/license/validate', $call['url']);
        $this->assertSame('example.com', json_decode($call['args']['body'], true)['domain']);
        $this->assertTrue($response['success']);
        $this->assertTrue($response['data']['fresh']);
        $this->assertTrue($response['data']['sites'][0]['this_site']);
        $this->assertFalse($response['data']['sites'][1]['this_site']);
        $this->assertFalse($response['data']['sites'][1]['is_counted']);
    }

    public function test_list_sites_falls_back_to_the_cache_when_nexus_is_down(): void
    {
        $this->activate(['sites' => [['id' => 1, 'domain' => 'example.com']]]);
        WpStub::queueError('Network down');

        $response = $this->call('list_sites');

        $this->assertFalse($response['data']['fresh']);
        $this->assertCount(1, $response['data']['sites']);
    }

    public function test_remove_site_refuses_this_site(): void
    {
        $this->activate();
        $calls = count(WpStub::$requestLog);

        $response = $this->call('remove_site', ['domain' => 'example.com']);

        $this->assertFalse($response['success']);
        $this->assertSame('this_site', $response['data']['code']);
        $this->assertCount($calls, WpStub::$requestLog);
    }

    public function test_remove_site_releases_another_site(): void
    {
        $this->activate(['sites' => [
            ['id' => 1, 'domain' => 'example.com'],
            ['id' => 2, 'domain' => 'other.com'],
        ]]);
        WpStub::queueJson(200, ['success' => true]);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active', 'sites' => [['id' => 1, 'domain' => 'example.com']]]]);

        $response = $this->call('remove_site', ['domain' => 'other.com']);

        $this->assertTrue($response['success']);
        $this->assertSame('other.com', $response['data']['removed']);
        $this->assertCount(1, $response['data']['sites']);
    }

    public function test_remove_site_passes_a_nexus_refusal_through(): void
    {
        $this->activate();
        WpStub::queueJson(404, ['message' => 'Activation not found', 'error_code' => 'activation_not_found']);

        $response = $this->call('remove_site', ['domain' => 'other.com']);

        $this->assertFalse($response['success']);
        $this->assertSame('activation_not_found', $response['data']['code']);
    }

    public function test_remove_site_without_a_license(): void
    {
        $response = $this->call('remove_site', ['domain' => 'other.com']);

        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED, $response['data']['code']);
    }

    public function test_a_rate_limited_action_reports_retry_after(): void
    {
        WpStub::queueJson(429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '60']);

        $response = $this->call('activate', ['license_key' => 'KEY-001']);

        $this->assertFalse($response['success']);
        $this->assertSame(LicenseErrorCode::RATE_LIMITED, $response['data']['code']);
        $this->assertSame(60, $response['data']['retry_after']);
    }

    public function test_a_vetoed_activation_reports_the_rollback(): void
    {
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueError('Network down');
        add_filter('wp_premium_sdk/activation_gate', static fn () => 'Tier too low.', 10, 2);

        $response = $this->call('activate', ['license_key' => 'KEY-001']);

        $this->assertFalse($response['success']);
        $this->assertSame('Tier too low.', $response['data']['message']);
        $this->assertFalse($response['data']['removed_remotely']);
        $this->assertSame(LicenseErrorCode::NETWORK_ERROR, $response['data']['rollback_error_code']);
    }

    /**
     * @param  array<string, mixed>  $license
     */
    private function activate(array $license = []): void
    {
        $license += ['status' => 'active', 'features' => []];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        $this->manager->activate('KEY-001');
    }

    /**
     * @param  array<string, string>  $params
     * @return array{success: bool, data: mixed, status: int|null}
     */
    private function call(string $subAction, array $params = []): array
    {
        $_POST = ['sub_action' => $subAction] + $params;
        $this->endpoints->dispatch();

        return WpStub::lastJson();
    }
}
