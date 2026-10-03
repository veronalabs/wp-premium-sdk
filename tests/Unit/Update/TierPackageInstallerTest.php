<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\SodiumEncryptor;
use VeronaLabs\WpPremiumSdk\Feature\FeatureInstaller;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\License\AutoActivator;
use VeronaLabs\WpPremiumSdk\License\KeySource;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
use VeronaLabs\WpPremiumSdk\License\LicenseEndpoints;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;
use VeronaLabs\WpPremiumSdk\Update\PluginUpdater;
use VeronaLabs\WpPremiumSdk\Update\TierPackageInstaller;

/**
 * Installing the licensed tier's package over a build of another tier (#15).
 */
class TierPackageInstallerTest extends TestCase
{
    private const MANIFEST_KEY = 'wp_premium_sdk_manifest_wp-statistics';

    private const PLUGIN = 'wp-statistics-premium/wp-statistics-premium.php';

    private PluginUpdater $updater;

    private LicenseManager $manager;

    private RecordingTierInstaller $installer;

    private LicenseEndpoints $endpoints;

    protected function setUp(): void
    {
        WpStub::reset();
        $_POST = [];
        $this->build('basic');
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function test_a_mismatch_is_read_from_the_cached_manifest(): void
    {
        WpStub::$siteTransients[self::MANIFEST_KEY] = ['success' => true, 'update_available' => false, 'tier_slug' => 'pro'];

        $this->assertSame(['installed' => 'basic', 'licensed' => 'pro'], $this->installer->tierMismatch());
        $this->assertSame([], WpStub::$requestLog, 'No request for the mismatch.');
    }

    public function test_no_mismatch_when_the_tiers_match_or_are_unknown(): void
    {
        $this->assertNull($this->installer->tierMismatch(), 'Nothing cached.');

        WpStub::$siteTransients[self::MANIFEST_KEY] = ['success' => true, 'tier_slug' => 'basic'];
        $this->assertNull($this->installer->tierMismatch());

        WpStub::$siteTransients[self::MANIFEST_KEY] = ['success' => true, 'update_available' => false];
        $this->assertNull($this->installer->tierMismatch(), 'An older Nexus that names no tier.');

        $this->build('');
        WpStub::$siteTransients[self::MANIFEST_KEY] = ['success' => true, 'tier_slug' => 'pro'];
        $this->assertNull($this->installer->tierMismatch(), 'The host did not say which tier is installed.');
    }

    public function test_get_status_exposes_tier_mismatch(): void
    {
        WpStub::$siteTransients[self::MANIFEST_KEY] = ['success' => true, 'tier_slug' => 'pro'];
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);

        $response = $this->call('get_status');

        $this->assertSame(['installed' => 'basic', 'licensed' => 'pro'], $response['data']['tier_mismatch']);
    }

    public function test_it_installs_the_licensed_package_and_clears_the_cache(): void
    {
        WpStub::$siteTransients[self::MANIFEST_KEY] = ['success' => true, 'tier_slug' => 'pro'];
        WpStub::$siteTransients[self::MANIFEST_KEY.'_failure'] = ['failures' => 1, 'retry_at' => time() + 3600, 'error_code' => 'server_error'];
        $this->queueManifest('pro');

        $response = $this->call('install_tier_package');

        $this->assertTrue($response['success']);
        $this->assertTrue($response['data']['installed']);
        $this->assertSame('basic', $response['data']['installed_tier']);
        $this->assertSame('pro', $response['data']['licensed_tier']);
        $this->assertSame(['https://nexus.test/packages/pro.zip'], $this->installer->packages);

        $url = WpStub::$requestLog[0]['url'];
        $this->assertStringContainsString('/api/v1/wp-statistics/update/manifest', $url);
        $this->assertStringNotContainsString('current_version', $url, 'Asked without the installed version, so the package comes back.');
        $this->assertArrayNotHasKey(self::MANIFEST_KEY, WpStub::$siteTransients);
    }

    public function test_the_same_tier_installs_nothing(): void
    {
        $this->queueManifest('basic');

        $response = $this->call('install_tier_package');

        $this->assertTrue($response['success']);
        $this->assertFalse($response['data']['installed']);
        $this->assertSame([], $this->installer->packages);
    }

    public function test_disabled_file_changes_are_reported_before_asking_nexus(): void
    {
        WpStub::$fileModsAllowed = false;

        $response = $this->call('install_tier_package');

        $this->assertSame(LicenseErrorCode::FILE_MODS_DISABLED, $response['data']['code']);
        $this->assertSame([], WpStub::$requestLog);
    }

    public function test_missing_filesystem_credentials_are_reported_not_asked_for(): void
    {
        WpStub::$filesystemMethod = 'ftpext';

        $response = $this->call('install_tier_package');

        $this->assertSame(LicenseErrorCode::FILESYSTEM_CREDENTIALS_NEEDED, $response['data']['code']);
        $this->assertSame([], WpStub::$requestLog);
    }

    public function test_stored_ftp_credentials_are_used(): void
    {
        WpStub::$filesystemMethod = 'ftpext';
        WpStub::$filesystemCredentials = ['hostname' => 'localhost', 'username' => 'u', 'password' => 'p'];
        $this->queueManifest('pro');

        $response = $this->call('install_tier_package');

        $this->assertTrue($response['data']['installed']);
    }

    public function test_it_needs_install_plugins(): void
    {
        WpStub::$deniedCapabilities = ['install_plugins'];

        $response = $this->call('install_tier_package');

        $this->assertSame('forbidden', $response['data']['code']);
        $this->assertSame([], WpStub::$requestLog);
    }

    public function test_an_unknown_installed_tier_is_refused(): void
    {
        $this->build('');

        $response = $this->call('install_tier_package');

        $this->assertSame(LicenseErrorCode::INSTALLED_TIER_UNKNOWN, $response['data']['code']);
    }

    public function test_a_manifest_without_a_tier_is_refused(): void
    {
        WpStub::queueJson(200, ['success' => true, 'update_available' => true, 'manifest' => ['version' => '15.0.0', 'plugin' => ['url' => 'https://nexus.test/p.zip']]]);

        $response = $this->call('install_tier_package');

        $this->assertSame(LicenseErrorCode::LICENSED_TIER_UNKNOWN, $response['data']['code']);
        $this->assertSame([], $this->installer->packages);
    }

    public function test_a_nexus_refusal_is_passed_on_and_feeds_the_backoff(): void
    {
        WpStub::queueJson(403, ['error_code' => 'license_expired', 'message' => 'Expired']);

        $response = $this->call('install_tier_package');

        $this->assertSame(LicenseErrorCode::LICENSE_EXPIRED, $response['data']['code']);
        $this->assertSame(1, WpStub::$siteTransients[self::MANIFEST_KEY.'_failure']['failures']);
    }

    public function test_a_rate_limit_still_running_is_honoured(): void
    {
        WpStub::$siteTransients[self::MANIFEST_KEY.'_failure'] = ['failures' => 1, 'retry_at' => time() + 600, 'error_code' => 'rate_limited'];

        $response = $this->call('install_tier_package');

        $this->assertSame(LicenseErrorCode::RATE_LIMITED, $response['data']['code']);
        $this->assertEqualsWithDelta(600, $response['data']['retry_after'], 2);
        $this->assertSame([], WpStub::$requestLog);
    }

    private function queueManifest(string $tier): void
    {
        WpStub::queueJson(200, [
            'success' => true,
            'update_available' => true,
            'tier_slug' => $tier,
            'manifest' => [
                'version' => '15.0.0',
                'plugin' => ['slug' => 'wp-statistics', 'version' => '15.0.0', 'url' => 'https://nexus.test/packages/'.$tier.'.zip'],
            ],
        ]);
    }

    private function build(string $installedTier): void
    {
        $config = new ClientConfig([
            'product_slug' => 'wp-statistics',
            'option_key' => 'wp_statistics_premium',
            'oauth_state_prefix' => 'x_',
            'oauth_callback_params' => ['code' => 'c', 'state' => 's'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'td',
            'current_version' => '15.0.0',
            'installed_tier' => $installedTier,
        ]);

        $client = new LicenseClient($config, new ApiClient($config));
        $store = new PremiumStore($config);
        $this->manager = new LicenseManager($client, $store, new SodiumEncryptor('wp_statistics_premium_cipher'));

        if (! $this->manager->isActivated()) {
            $license = ['status' => 'active', 'features' => []];
            WpStub::queueJson(200, ['success' => true, 'license' => $license]);
            WpStub::queueJson(200, ['success' => true, 'license' => $license]);
            $this->manager->activate('KEY-001');
            WpStub::$requestLog = [];
        }

        $this->updater = new PluginUpdater($config, $client, $this->manager, self::PLUGIN);
        $this->installer = new RecordingTierInstaller($config, $this->updater, self::PLUGIN);

        $keySource = new KeySource($config);
        $this->endpoints = new LicenseEndpoints(
            $config,
            $this->manager,
            $this->updater,
            new FeatureInstaller($config),
            $keySource,
            new AutoActivator($this->manager, $keySource, $store),
            $this->installer
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{success: bool, data: mixed, status: int|null}
     */
    private function call(string $subAction, array $body = []): array
    {
        $_POST = array_merge(['sub_action' => $subAction], $body);
        $this->endpoints->dispatch();

        return WpStub::lastJson();
    }
}

/**
 * Records the package instead of running WordPress's upgrader.
 */
class RecordingTierInstaller extends TierPackageInstaller
{
    /** @var array<int, string> */
    public array $packages = [];

    protected function upgrade(string $package): ?string
    {
        $this->packages[] = $package;

        return 'wp-statistics-premium/wp-statistics-premium.php';
    }
}
