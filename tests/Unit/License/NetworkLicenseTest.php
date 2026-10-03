<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\License;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\License\KeySource;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\Tests\Support\BuildsSdk;
use VeronaLabs\WpPremiumSdk\Tests\Support\SdkFixture;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

/**
 * One key for a network-activated plugin, entered once in Network Admin; each
 * subsite activates on its own address with its own seat, seats are never handed
 * out in visit order, and updates are network-wide (#17, #7).
 *
 * Blog 1 is the main site (example.com), blog 2 shop.example.com, blog 3 example.com/b.
 */
class NetworkLicenseTest extends TestCase
{
    use BuildsSdk;

    private const NETWORK_OPTION = 'wp_statistics_premium_network';

    protected function setUp(): void
    {
        WpStub::reset();
        $_POST = [];
        WpStub::$isMultisite = true;
        WpStub::$networkActivatedPlugins = [SdkFixture::PLUGIN];
        WpStub::$blogHomeUrls = [1 => 'https://example.com', 2 => 'https://shop.example.com', 3 => 'https://example.com/b'];
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function test_the_network_admin_saves_the_key_once(): void
    {
        $this->queueActivation();
        $sdk = $this->provider();

        $response = $this->ajax($sdk, 'network_license', 'save_key', ['license_key' => 'NET-KEY-0001']);

        $this->assertTrue($response['success']);
        $this->assertSame('••••••••0001', $response['data']['license_key_masked']);
        $this->assertSame('/api/v1/license/activate', $this->paths()[0], 'The main site is activated with it.');
        $this->assertSame(KeySource::NETWORK, $sdk->licenseManager()->getSource());

        $this->assertArrayHasKey(self::NETWORK_OPTION, WpStub::$siteOptions);
        $this->assertStringNotContainsString('NET-KEY-0001', serialize(WpStub::$siteOptions), 'Stored encrypted.');
        $this->assertSame('NET-KEY-0001', $this->provider()->networkLicense()->getKey());
    }

    public function test_a_refused_key_is_not_saved(): void
    {
        WpStub::queueJson(422, ['error_code' => 'invalid_key', 'message' => 'Invalid']);

        $response = $this->ajax($this->provider(), 'network_license', 'save_key', ['license_key' => 'BAD-KEY']);

        $this->assertFalse($response['success']);
        $this->assertSame(LicenseErrorCode::INVALID_KEY, $response['data']['code']);
        $this->assertArrayNotHasKey(self::NETWORK_OPTION, WpStub::$siteOptions);
    }

    public function test_a_key_out_of_seats_is_saved_and_says_so(): void
    {
        WpStub::queueJson(409, ['error_code' => 'activation_limit_reached', 'message' => 'No seats']);

        $response = $this->ajax($this->provider(), 'network_license', 'save_key', ['license_key' => 'NET-KEY-0002']);

        $this->assertTrue($response['success']);
        $this->assertSame(LicenseErrorCode::ACTIVATION_LIMIT_REACHED, $response['data']['main_site_error_code']);
        $this->assertSame('NET-KEY-0002', $this->provider()->networkLicense()->getKey());
    }

    public function test_network_endpoints_need_manage_network_options(): void
    {
        WpStub::$deniedCapabilities = ['manage_network_options'];

        $response = $this->ajax($this->provider(), 'network_license', 'save_key', ['license_key' => 'NET-KEY-0003']);

        $this->assertSame('forbidden', $response['data']['code']);
        $this->assertSame([], WpStub::$requestLog);
    }

    public function test_a_subsite_activates_itself_when_there_are_seats_for_every_subsite(): void
    {
        $this->networkKey('NET-KEY-0004', 3, 1);
        WpStub::switchBlog(2);
        $this->queueActivation(['site' => ['active' => true, 'is_counted' => true]]);

        $sdk = $this->provider();
        $sdk->autoActivator()->run();

        $activate = $this->request(2)['body'];
        $this->assertSame('NET-KEY-0004', $activate['license_key']);
        $this->assertSame('shop.example.com', $activate['domain']);
        $this->assertSame('https://shop.example.com', $activate['site_url']);
        $this->assertSame(KeySource::NETWORK, $sdk->licenseManager()->getSource());
        $this->assertArrayHasKey('license', WpStub::$options[SdkFixture::OPTION], 'The activation is kept per site.');
    }

    public function test_without_seats_for_every_subsite_nothing_happens_on_its_own(): void
    {
        $this->networkKey('NET-KEY-0005', 2, 1);
        WpStub::switchBlog(2);

        $sdk = $this->provider();
        $sdk->autoActivator()->run();

        $this->assertSame([], WpStub::$requestLog, 'Two subsites wait for one seat: the network admin chooses.');
        $this->assertNull($sdk->autoActivator()->lastFailure());

        $status = $this->ajax($sdk, 'license', 'get_status');
        $this->assertNull($status['data']['auto_activation']);
        $this->assertSame(LicenseErrorCode::NOT_ACTIVATED, $status['data']['state']['code'], 'No seat-shortfall state for the subsite admin.');
        $this->assertTrue($status['data']['is_network_managed']);
    }

    public function test_a_new_subsite_takes_a_seat_while_one_is_left(): void
    {
        $this->networkKey('NET-KEY-0006', 2, 1);
        $this->provider()->autoActivator()->rememberNewSite((object) ['blog_id' => 3]);

        WpStub::switchBlog(2);
        $this->provider()->autoActivator()->run();
        $this->assertSame([], WpStub::$requestLog, 'An older subsite still waits.');

        WpStub::switchBlog(3);
        $this->queueActivation(['max_activations' => 2, 'activation_count' => 2]);
        $sdk = $this->provider();
        $sdk->autoActivator()->run();

        $this->assertSame('example.com/b', $this->request(2)['body']['domain']);
        $this->assertTrue($sdk->licenseManager()->isActivated());
        $this->assertFalse($sdk->networkLicense()->isNewSite(3));
    }

    public function test_an_unlimited_license_activates_every_subsite(): void
    {
        $this->networkKey('NET-KEY-0007', 0, 1);
        WpStub::switchBlog(3);
        $this->queueActivation();

        $sdk = $this->provider();
        $sdk->autoActivator()->run();

        $this->assertTrue($sdk->licenseManager()->isActivated());
    }

    public function test_a_subsite_views_but_cannot_change_the_license(): void
    {
        $this->networkKey('NET-KEY-0008', 5, 1);
        WpStub::switchBlog(2);
        $this->queueActivation();
        $sdk = $this->provider();
        $sdk->autoActivator()->run();
        $calls = count(WpStub::$requestLog);

        foreach (['activate' => ['license_key' => 'MINE'], 'deactivate' => [], 'remove_site' => ['domain' => 'other.com'], 'move_license' => []] as $subAction => $body) {
            $response = $this->ajax($sdk, 'license', $subAction, $body);
            $this->assertSame(LicenseErrorCode::NETWORK_MANAGED, $response['data']['code'], $subAction);
        }

        $this->assertCount($calls, WpStub::$requestLog);

        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        $status = $this->ajax($sdk, 'license', 'get_status');
        $this->assertTrue($status['data']['is_network_managed']);
        $this->assertSame('network', $status['data']['context']);
        $this->assertSame(KeySource::NETWORK, $status['data']['source']);
        $this->assertTrue($status['data']['is_activated']);
    }

    public function test_a_plugin_activated_site_by_site_is_not_network_managed(): void
    {
        WpStub::$networkActivatedPlugins = [];
        $this->queueActivation();

        $response = $this->ajax($this->provider(), 'license', 'activate', ['license_key' => 'SITE-KEY']);
        $this->assertTrue($response['success']);

        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        $status = $this->ajax($this->provider(), 'license', 'get_status');
        $this->assertSame('site', $status['data']['context']);
        $this->assertFalse($status['data']['is_network_managed']);
    }

    public function test_network_get_status_reports_seats_and_each_subsite(): void
    {
        $this->networkKey('NET-KEY-0009', 2, 1);
        WpStub::$blogOptions[3][SdkFixture::OPTION] = ['auto_activation' => ['attempts' => 2, 'retry_at' => 123, 'error_code' => 'activation_limit_reached']];

        $response = $this->ajax($this->provider(), 'network_license', 'get_status');
        $data = $response['data'];

        $this->assertSame('network', $data['context']);
        $this->assertTrue($data['has_key']);
        $this->assertSame('••••••••0009', $data['license_key_masked']);
        $this->assertSame(3, $data['subsites_total']);
        $this->assertSame(1, $data['subsites_active']);
        $this->assertSame(2, $data['subsites_waiting']);
        $this->assertSame(2, $data['seats_max']);
        $this->assertSame(1, $data['seats_left']);
        $this->assertTrue($data['subsites'][0]['holds_seat']);
        $this->assertSame('example.com', $data['subsites'][0]['domain']);
        $this->assertFalse($data['subsites'][1]['is_activated']);
        $this->assertSame('activation_limit_reached', $data['subsites'][2]['auto_activation_error']);
        $this->assertArrayNotHasKey('license', $data['subsites'][0], 'The raw row (ciphertext) stays out.');
    }

    public function test_activate_all_refuses_with_counts_when_seats_are_short(): void
    {
        $this->networkKey('NET-KEY-0010', 2, 1);
        $calls = count(WpStub::$requestLog);

        $response = $this->ajax($this->provider(), 'network_license', 'activate_all');

        $this->assertFalse($response['success']);
        $this->assertSame(LicenseErrorCode::NOT_ENOUGH_SEATS, $response['data']['code']);
        $this->assertSame(2, $response['data']['needed']);
        $this->assertSame(1, $response['data']['left']);
        $this->assertCount($calls, WpStub::$requestLog);
    }

    public function test_activate_all_activates_each_waiting_subsite_on_its_own_address(): void
    {
        $this->networkKey('NET-KEY-0011', 5, 1);
        WpStub::$requestLog = [];
        $this->queueActivation(['site' => ['active' => true, 'is_counted' => true], 'max_activations' => 5, 'activation_count' => 2]);
        $this->queueActivation(['site' => ['active' => true, 'is_counted' => false], 'max_activations' => 5, 'activation_count' => 2]);

        $response = $this->ajax($this->provider(), 'network_license', 'activate_all');

        $this->assertTrue($response['success']);
        $this->assertSame(['shop.example.com', 'example.com/b'], array_column($response['data']['results'], 'domain'));
        $this->assertSame([true, true], array_column($response['data']['results'], 'activated'));
        $this->assertSame([true, false], array_column($response['data']['results'], 'is_counted'));
        $this->assertSame('shop.example.com', $this->request(4)['body']['domain']);
        $this->assertSame('example.com/b', $this->request(2)['body']['domain']);
        $this->assertSame(1, WpStub::$currentBlogId, 'Back on the main site.');
        $this->assertSame('example.com/b', WpStub::$blogOptions[3][SdkFixture::OPTION]['license']['activated_domain']);
        $this->assertSame(3, $response['data']['subsites_active']);
    }

    public function test_activate_sites_activates_only_the_picked_ones(): void
    {
        $this->networkKey('NET-KEY-0012', 2, 1);
        WpStub::$requestLog = [];
        $this->queueActivation();

        $response = $this->ajax($this->provider(), 'network_license', 'activate_sites', ['blog_ids' => ['3']]);

        $this->assertTrue($response['success']);
        $this->assertSame([3], array_column($response['data']['results'], 'blog_id'));
        $this->assertSame('example.com/b', $this->request(2)['body']['domain']);
        $this->assertArrayNotHasKey(SdkFixture::OPTION, WpStub::$blogOptions[2] ?? []);
    }

    public function test_a_failed_subsite_is_reported_without_stopping_the_rest(): void
    {
        $this->networkKey('NET-KEY-0013', 5, 1);
        WpStub::queueJson(409, ['error_code' => 'activation_limit_reached', 'message' => 'No seats']);
        $this->queueActivation();

        $response = $this->ajax($this->provider(), 'network_license', 'activate_all');

        $this->assertSame([false, true], array_column($response['data']['results'], 'activated'));
        $this->assertSame(LicenseErrorCode::ACTIVATION_LIMIT_REACHED, $response['data']['results'][0]['error_code']);
    }

    public function test_updates_use_the_network_key_whichever_subsite_asks(): void
    {
        $this->networkKey('NET-KEY-0014', 1, 1);
        WpStub::switchBlog(2);
        WpStub::queueJson(200, ['success' => true, 'update_available' => true, 'manifest' => ['version' => '16.0.0', 'plugin' => ['url' => 'https://nexus.test/p.zip']]]);

        $transient = $this->provider()->pluginUpdater()->injectPluginUpdate((object) ['response' => []]);

        $this->assertArrayHasKey(SdkFixture::PLUGIN, $transient->response, 'A subsite without a seat still sees the update.');
        $this->assertStringContainsString('license_key=NET-KEY-0014', WpStub::$requestLog[count(WpStub::$requestLog) - 1]['url']);
    }

    public function test_no_update_when_the_main_site_is_not_licensed(): void
    {
        $this->networkKey('NET-KEY-0015', 1, 1);
        WpStub::$options[SdkFixture::OPTION]['license']['status'] = 'expired';
        WpStub::switchBlog(2);
        $calls = count(WpStub::$requestLog);

        $transient = $this->provider()->pluginUpdater()->injectPluginUpdate((object) ['response' => []]);

        $this->assertSame([], $transient->response);
        $this->assertCount($calls, WpStub::$requestLog);
    }

    public function test_removing_the_network_key_deactivates_the_main_site_now_and_subsites_later(): void
    {
        $this->networkKey('NET-KEY-0016', 5, 1);
        WpStub::queueJson(200, ['success' => true]);

        $response = $this->ajax($this->provider(), 'network_license', 'remove_key');

        $this->assertTrue($response['success']);
        $this->assertTrue($response['data']['removed_remotely']);
        $this->assertArrayNotHasKey(self::NETWORK_OPTION, WpStub::$siteOptions);
        $this->assertFalse($this->provider()->licenseManager()->isActivated());

        // A subsite that still holds an activation from the network key drops it.
        WpStub::switchBlog(2);
        $this->queueActivation();
        $this->provider()->licenseManager()->activate('NET-KEY-0016', KeySource::NETWORK);
        WpStub::queueJson(200, ['success' => true]);

        $sdk = $this->provider();
        $sdk->autoActivator()->run();

        $this->assertSame('/api/v1/license/deactivate', $this->paths()[count(WpStub::$requestLog) - 1]);
        $this->assertFalse($sdk->licenseManager()->isActivated());
    }

    public function test_a_network_license_survives_network_deactivation_as_the_sites_own(): void
    {
        $this->queueActivation();
        $this->provider()->licenseManager()->activate('NET-KEY-0017', KeySource::NETWORK);
        WpStub::$networkActivatedPlugins = [];
        $calls = count(WpStub::$requestLog);

        $sdk = $this->provider();
        $sdk->autoActivator()->run();

        $this->assertCount($calls, WpStub::$requestLog);
        $this->assertSame(KeySource::MANUAL, $sdk->licenseManager()->getSource());
    }

    public function test_the_main_sites_key_becomes_the_network_key(): void
    {
        WpStub::$networkActivatedPlugins = [];
        $this->queueActivation();
        $this->provider()->licenseManager()->activate('MAIN-KEY-0001');
        WpStub::$networkActivatedPlugins = [SdkFixture::PLUGIN];
        $calls = count(WpStub::$requestLog);

        $sdk = $this->provider();
        $sdk->autoActivator()->run();

        $this->assertSame('MAIN-KEY-0001', $sdk->networkLicense()->getKey());
        $this->assertSame(KeySource::NETWORK, $sdk->licenseManager()->getSource());
        $this->assertCount($calls, WpStub::$requestLog, 'Already activated with that key.');
    }

    public function test_only_the_main_site_adopts_its_key(): void
    {
        WpStub::$networkActivatedPlugins = [];
        WpStub::switchBlog(2);
        $this->queueActivation();
        $this->provider()->licenseManager()->activate('SUB-KEY-0001');
        WpStub::$networkActivatedPlugins = [SdkFixture::PLUGIN];

        $sdk = $this->provider();
        $sdk->autoActivator()->run();

        $this->assertNull($sdk->networkLicense()->getKey());
    }

    public function test_a_constant_wins_over_the_network_key(): void
    {
        define('SDK_TEST_KEY_NETWORK', 'CONST-NET-0001');
        $sdk = $this->provider(['license_key_constant' => 'SDK_TEST_KEY_NETWORK']);
        $sdk->networkLicense()->setKey('NET-KEY-0018');
        $this->queueActivation();

        $sdk->autoActivator()->run();

        $this->assertSame('CONST-NET-0001', $sdk->licenseManager()->getLicenseKey(), 'The main site goes first, seats unknown.');

        $response = $this->ajax($sdk, 'network_license', 'save_key', ['license_key' => 'NET-KEY-0019']);
        $this->assertSame(LicenseErrorCode::KEY_FROM_CONSTANT, $response['data']['code']);
    }

    public function test_without_salts_the_network_key_uses_a_network_wide_cipher_key(): void
    {
        $this->provider()->networkLicense()->setKey('NET-KEY-0020');

        $this->assertArrayHasKey(self::NETWORK_OPTION.'_cipher', WpStub::$siteOptions);
        $this->assertArrayNotHasKey(SdkFixture::OPTION.'_network_cipher', WpStub::$options);

        // Another subsite has its own options table but reads the same key.
        WpStub::switchBlog(2);
        $this->assertSame('NET-KEY-0020', $this->provider()->networkLicense()->getKey());
    }

    public function test_uninstall_also_removes_the_network_key(): void
    {
        $this->provider()->networkLicense()->setKey('NET-KEY-0021');
        $this->provider()->networkLicense()->rememberNewSite(3);

        $this->provider()->uninstall(false);

        $this->assertArrayNotHasKey(self::NETWORK_OPTION, WpStub::$siteOptions);
        $this->assertArrayNotHasKey(self::NETWORK_OPTION.'_cipher', WpStub::$siteOptions);
        $this->assertArrayNotHasKey(self::NETWORK_OPTION.'_new_sites', WpStub::$siteOptions);
    }

    public function test_a_subsite_with_its_own_key_is_not_waiting_for_a_seat(): void
    {
        $this->ownKeyOnBlog3();
        $this->networkKey('NET-KEY-0022', 3, 1);

        $data = $this->ajax($this->provider(), 'network_license', 'get_status')['data'];

        $this->assertSame(1, $data['subsites_active']);
        $this->assertSame(1, $data['subsites_own_key']);
        $this->assertSame(1, $data['subsites_waiting'], 'Only shop.example.com waits.');
        $this->assertTrue($data['subsites'][2]['has_own_key']);
        $this->assertFalse($data['subsites'][2]['holds_seat']);
        $this->assertFalse($data['subsites'][1]['has_own_key']);
    }

    public function test_activate_all_leaves_a_subsites_own_key_alone(): void
    {
        $this->ownKeyOnBlog3();
        $this->networkKey('NET-KEY-0023', 2, 1);
        $this->queueActivation();

        $response = $this->ajax($this->provider(), 'network_license', 'activate_all');

        $this->assertTrue($response['success'], 'One seat left covers the one subsite truly waiting.');
        $this->assertSame([2], array_column($response['data']['results'], 'blog_id'));
        $this->assertSame(KeySource::MANUAL, WpStub::$blogOptions[3][SdkFixture::OPTION]['license']['source']);
    }

    public function test_activate_sites_skips_a_subsite_with_its_own_key(): void
    {
        $this->ownKeyOnBlog3();
        $this->networkKey('NET-KEY-0024', 5, 1);

        $response = $this->ajax($this->provider(), 'network_license', 'activate_sites', ['blog_ids' => ['3']]);

        $this->assertTrue($response['success']);
        $this->assertSame([], $response['data']['results']);
        $this->assertSame([], WpStub::$requestLog);
    }

    public function test_a_subsite_keeps_its_own_key_on_its_next_admin_load(): void
    {
        $this->ownKeyOnBlog3();
        $this->networkKey('NET-KEY-0025', 5, 1);

        WpStub::switchBlog(3);
        $sdk = $this->provider();
        $sdk->autoActivator()->run();

        $this->assertSame([], WpStub::$requestLog);
        $this->assertSame('OWN-KEY-0003', $sdk->licenseManager()->getLicenseKey());
        $this->assertSame(KeySource::MANUAL, $sdk->licenseManager()->getSource());
    }

    /**
     * Blog 3 activates a key of its own while the plugin is still activated site by
     * site; then the plugin is network-activated and the main site is current again.
     */
    private function ownKeyOnBlog3(): void
    {
        WpStub::$networkActivatedPlugins = [];
        WpStub::switchBlog(3);
        $this->queueActivation();
        $this->assertTrue($this->ajax($this->provider(), 'license', 'activate', ['license_key' => 'OWN-KEY-0003'])['success']);
        WpStub::switchBlog(1);
        WpStub::$networkActivatedPlugins = [SdkFixture::PLUGIN];
        WpStub::$requestLog = [];
    }

    /**
     * On the main site: save the network key, Nexus answering with these seat counts.
     */
    private function networkKey(string $key, int $maxActivations, int $activationCount): void
    {
        $this->queueActivation(['max_activations' => $maxActivations, 'activation_count' => $activationCount]);
        $response = $this->ajax($this->provider(), 'network_license', 'save_key', ['license_key' => $key]);
        $this->assertTrue($response['success']);
        WpStub::$requestLog = [];
    }
}
