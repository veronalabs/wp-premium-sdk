<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Account;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Account\AccountBootstrap;
use VeronaLabs\WpPremiumSdk\Account\AccountClient;
use VeronaLabs\WpPremiumSdk\Account\AccountEndpoints;
use VeronaLabs\WpPremiumSdk\Account\AccountManager;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\SodiumEncryptor;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

/**
 * The account sign-in is a one-time step: sign in, pick a license, activate, and
 * the session is gone (#10).
 */
class AccountSignInTest extends TestCase
{
    private PremiumStore $store;

    private AccountManager $account;

    private LicenseManager $license;

    private AccountEndpoints $endpoints;

    private AccountBootstrap $bootstrap;

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

        $http = new ApiClient($config);
        $encryptor = new SodiumEncryptor('wp_statistics_premium_cipher');
        $this->store = new PremiumStore($config);
        $accountClient = new AccountClient($config, $http);

        $this->license = new LicenseManager(new LicenseClient($config, $http), $this->store, $encryptor);
        $this->account = new AccountManager($config, $accountClient, $this->store, $encryptor);
        $this->endpoints = new AccountEndpoints($config, $this->account, $this->license, $accountClient);
        $this->bootstrap = new AccountBootstrap($config, $this->account, $this->license, $this->endpoints);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
    }

    public function test_sign_in_stores_no_refresh_token_and_an_expiry(): void
    {
        $this->signIn(['refresh_token' => 'never-used'], [['key' => 'KEY-1'], ['key' => 'KEY-2']]);

        $session = $this->store->get('account');
        $this->assertArrayNotHasKey('refresh_token', $session);
        $this->assertEqualsWithDelta(time() + AccountManager::SESSION_TTL, $session['expires_at'], 2);
        $this->assertTrue($this->account->isConnected());
        $this->assertCount(2, $this->account->getPendingChoice());
    }

    public function test_the_server_expiry_is_used_when_sent(): void
    {
        $this->signIn(['expires_in' => 600], [['key' => 'KEY-1'], ['key' => 'KEY-2']]);

        $this->assertEqualsWithDelta(time() + 600, $this->store->get('account')['expires_at'], 2);
    }

    public function test_a_single_license_is_activated_and_the_sign_in_ends(): void
    {
        $license = ['status' => 'active', 'features' => []];

        $this->signIn([], [['key' => 'KEY-1']], static function () use ($license) {
            WpStub::queueJson(200, ['success' => true, 'license' => $license]); // activate
            WpStub::queueJson(200, ['success' => true, 'license' => $license]); // validate
            WpStub::queueJson(200, ['success' => true]);                        // logout
        });

        $this->assertTrue($this->license->isActivated());
        $this->assertRevoked();
        $this->assertNull($this->store->get('account'), 'Token, user and pending choice must all be gone.');
        $this->assertFalse($this->account->isConnected());
    }

    public function test_picking_a_license_ends_the_sign_in(): void
    {
        $this->signIn([], [['key' => 'KEY-1'], ['key' => 'KEY-2']]);
        $this->account->setFlashError('left over');

        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueJson(200, ['success' => true]); // logout
        $response = $this->call('activate_license', ['license_key' => 'KEY-2']);

        $this->assertTrue($response['success']);
        $this->assertNull($this->store->get('account'));
        $this->assertTrue($this->license->isActivated());
        $this->assertRevoked();
    }

    public function test_a_failed_revoke_never_fails_the_activation(): void
    {
        $this->signIn([], [['key' => 'KEY-1'], ['key' => 'KEY-2']]);

        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueError('Operation timed out'); // logout
        $response = $this->call('activate_license', ['license_key' => 'KEY-2']);

        $this->assertTrue($response['success']);
        $this->assertTrue($this->license->isActivated());
        $this->assertNull($this->store->get('account'), 'The local session goes even when Nexus could not be told.');
        $this->assertRevoked();
    }

    /**
     * The last call was a short-timeout token revoke with the sign-in's token.
     */
    private function assertRevoked(): void
    {
        $call = WpStub::$requestLog[count(WpStub::$requestLog) - 1];
        $this->assertStringContainsString('/api/v1/auth/logout', $call['url']);
        $this->assertSame('Bearer token-1', $call['args']['headers']['Authorization']);
        $this->assertSame(AccountManager::REVOKE_TIMEOUT, $call['args']['timeout']);
    }

    public function test_an_expired_session_is_not_connected(): void
    {
        $this->signIn([], [['key' => 'KEY-1'], ['key' => 'KEY-2']]);
        $session = $this->store->get('account');
        $session['expires_at'] = time() - 1;
        $this->store->set('account', $session);

        $this->assertFalse($this->account->isConnected());
        $this->assertNull($this->account->getAccessToken());
    }

    public function test_a_session_from_before_expires_at_lapses_a_day_after_it_began(): void
    {
        $this->store->set('account', ['access_token' => 'enc', 'connected_at' => time() - AccountManager::SESSION_TTL - 1]);

        $this->assertFalse($this->account->isConnected());
    }

    /**
     * @dataProvider expiredTokenAnswers
     */
    public function test_an_expired_token_during_the_picker_clears_the_sign_in(int $status, array $body): void
    {
        $this->signIn([], [['key' => 'KEY-1'], ['key' => 'KEY-2']]);
        WpStub::queueJson($status, $body);

        $response = $this->call('fetch_licenses');

        $this->assertFalse($response['success']);
        $this->assertSame(LicenseErrorCode::ACCOUNT_EXPIRED, $response['data']['code']);
        $this->assertNull($this->store->get('account'));
    }

    /**
     * @return array<string, array{int, array<string, mixed>}>
     */
    public function expiredTokenAnswers(): array
    {
        return [
            'plain 401' => [401, ['message' => 'Unauthenticated.']],
            'token_expired' => [401, ['error_code' => 'token_expired', 'message' => 'Token expired.']],
        ];
    }

    public function test_a_lapsed_session_reports_account_expired(): void
    {
        $this->store->set('account', ['access_token' => 'enc', 'connected_at' => time() - AccountManager::SESSION_TTL - 1]);

        $response = $this->call('fetch_licenses');

        $this->assertSame(LicenseErrorCode::ACCOUNT_EXPIRED, $response['data']['code']);
        $this->assertNull($this->store->get('account'));
        $this->assertCount(0, WpStub::$requestLog);
    }

    public function test_other_failures_during_the_picker_keep_the_sign_in(): void
    {
        $this->signIn([], [['key' => 'KEY-1'], ['key' => 'KEY-2']]);
        WpStub::queueError('Network down');

        $response = $this->call('fetch_licenses');

        $this->assertSame(LicenseErrorCode::NETWORK_ERROR, $response['data']['code']);
        $this->assertTrue($this->account->isConnected());
    }

    // --- #28: the sign-in return handler -------------------------------------

    public function test_a_request_without_the_capability_never_reaches_the_sign_in_return(): void
    {
        WpStub::$currentUserId = 0;
        WpStub::$deniedCapabilities = ['manage_options'];
        $_GET = ['c' => 'x', 's' => 'y'];

        $this->bootstrap->handleOAuthCallback();

        $this->assertNull($this->store->get('account'), 'Nothing is written to the option row.');
        $this->assertCount(0, WpStub::$requestLog);
    }

    public function test_a_sign_in_error_is_shown_to_any_admin(): void
    {
        $this->account->setFlashError('This sign-in code is not valid for this product.', LicenseErrorCode::WRONG_PRODUCT);

        WpStub::$currentUserId = 2;
        $this->assertSame('This sign-in code is not valid for this product.', $this->account->consumeFlashError());
    }

    // --- #32: sign-in outcomes carry a code ----------------------------------

    public function test_an_account_without_licenses_reports_no_licenses(): void
    {
        $this->signIn([], []);

        $status = $this->call('get_status')['data'];

        $this->assertSame(LicenseErrorCode::NO_LICENSES, $status['oauth_error_code']);
        $this->assertSame('Your account has no licenses for this product.', $status['oauth_error']);
        $this->assertSame([], $status['oauth_error_extra']);
        $this->assertNull($this->call('get_status')['data']['oauth_error_code'], 'The error is shown once.');
    }

    public function test_a_failed_single_license_activation_reports_its_code_and_extras(): void
    {
        $renewal = ['url' => 'https://nexus.test/renew', 'coupon' => 'BACK10'];

        $this->signIn([], [['id' => 11, 'key' => 'KEY-1']], static function () use ($renewal) {
            WpStub::queueJson(403, ['error_code' => 'license_expired', 'message' => 'License expired.', 'renewal' => $renewal]);
        });

        $status = $this->call('get_status')['data'];

        $this->assertSame(LicenseErrorCode::LICENSE_EXPIRED, $status['oauth_error_code']);
        $this->assertSame('License expired.', $status['oauth_error']);
        $this->assertSame(['renewal' => $renewal], $status['oauth_error_extra']);
    }

    public function test_a_failed_license_fetch_reports_its_code(): void
    {
        $this->store->setOAuthState('state-1');
        WpStub::queueJson(200, ['access_token' => 'token-1', 'user' => ['email' => 'buyer@example.com']]);
        WpStub::queueJson(429, ['message' => 'Slow down.'], ['retry-after' => '30']);

        $this->account->handleOAuthCallback('code-1', 'state-1', $this->license);
        $status = $this->call('get_status')['data'];

        $this->assertSame(LicenseErrorCode::RATE_LIMITED, $status['oauth_error_code']);
        $this->assertSame(['retry_after' => 30], $status['oauth_error_extra']);
        $this->assertStringStartsWith('Could not fetch licenses from your account: ', $status['oauth_error']);
    }

    public function test_a_failure_without_a_code_reports_account_error(): void
    {
        $this->store->setOAuthState('state-1');

        try {
            $this->account->handleOAuthCallback('code-1', 'wrong-state', $this->license);
            $this->fail('A wrong state must throw.');
        } catch (\Exception $e) {
            $this->account->setFlashErrorFrom($e);
        }

        $this->assertSame(
            ['code' => AccountManager::ERROR_CODE, 'message' => 'Invalid or expired OAuth state.', 'extra' => []],
            $this->account->consumeFlashErrorDetail()
        );
    }

    // --- #36: keys stay on the server ----------------------------------------

    public function test_the_picker_gets_ids_and_masked_keys_never_the_keys(): void
    {
        $this->signIn([], [['id' => 11, 'key' => 'WCV-AAAA-1111-SECRET'], ['id' => 12, 'key' => 'WCV-BBBB-2222-SECRET']]);
        WpStub::queueJson(200, ['data' => [['id' => 11, 'key' => 'WCV-AAAA-1111-SECRET'], ['id' => 12, 'key' => 'WCV-BBBB-2222-SECRET']]]);

        $licenses = $this->call('fetch_licenses')['data']['licenses'];
        $status = $this->call('get_status')['data'];

        foreach ([$licenses, $status['pending_choice']] as $list) {
            $this->assertSame(['11', '12'], array_column($list, 'id'));
            $this->assertSame('••••••••••••••••CRET', $list[0]['license_key_masked']);
            $this->assertStringNotContainsString('SECRET-', (string) wp_json_encode($list));
            $this->assertStringNotContainsString('1111-SECRET', (string) wp_json_encode($list));
        }
    }

    public function test_the_pending_choice_is_stored_without_keys(): void
    {
        $this->signIn([], [['id' => 11, 'key' => 'WCV-AAAA-1111-SECRET'], ['id' => 12, 'key' => 'WCV-BBBB-2222-SECRET']]);

        $row = (string) wp_json_encode(WpStub::$options['wp_statistics_premium']);

        $this->assertStringNotContainsString('1111-SECRET', $row);
        $this->assertStringNotContainsString('2222-SECRET', $row);
        $this->assertCount(2, $this->store->get('account')['pending_choice']);
    }

    public function test_a_pending_choice_stored_with_keys_is_returned_without_them(): void
    {
        $this->store->set('account', ['user_id' => 1, 'access_token' => 'enc', 'expires_at' => time() + 60, 'pending_choice' => [['id' => 11, 'key' => 'KEY-SECRET-1', 'license_key' => 'KEY-SECRET-1']]]);

        $choice = $this->account->getPendingChoice();

        $this->assertArrayNotHasKey('key', $choice[0]);
        $this->assertArrayNotHasKey('license_key', $choice[0]);
        $this->assertSame('11', $choice[0]['id']);
    }

    public function test_a_pending_choice_stored_with_keys_is_rewritten_without_them(): void
    {
        $this->store->set('account', ['access_token' => 'enc', 'expires_at' => time() + 60, 'pending_choice' => [['id' => 11, 'key' => 'KEY-SECRET-1'], ['id' => 12, 'license_key' => 'KEY-SECRET-2']]]);

        $this->assertTrue($this->account->isConnected(), 'Any read of the session cleans the row.');

        $row = (string) wp_json_encode(WpStub::$options['wp_statistics_premium']);
        $this->assertStringNotContainsString('KEY-SECRET', $row);
        $stored = $this->store->get('account')['pending_choice'];
        $this->assertSame(['11', '12'], array_column($stored, 'id'));
        $this->assertSame('••••••••ET-1', $stored[0]['license_key_masked']);
        $this->assertSame('enc', $this->store->get('account')['access_token'], 'The rest of the session is kept.');
    }

    public function test_picking_by_license_id_activates_the_key_looked_up_on_the_server(): void
    {
        $this->signIn([], [['id' => 11, 'key' => 'KEY-1'], ['id' => 12, 'key' => 'KEY-2']]);

        WpStub::queueJson(200, ['data' => [['id' => 11, 'key' => 'KEY-1'], ['id' => 12, 'key' => 'KEY-2']]]);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueJson(200, ['success' => true]); // logout
        $response = $this->call('activate_license', ['license_id' => '12']);

        $this->assertTrue($response['success']);
        $this->assertTrue($this->license->isActivated());
        $this->assertSame('KEY-2', $this->requestBody(3)['license_key'], 'The activation used the key of license 12.');
        $this->assertNull($this->store->get('account'));
    }

    public function test_a_license_id_not_on_the_account_is_refused(): void
    {
        $this->signIn([], [['id' => 11, 'key' => 'KEY-1'], ['id' => 12, 'key' => 'KEY-2']]);
        WpStub::queueJson(200, ['data' => [['id' => 11, 'key' => 'KEY-1']]]);

        $response = $this->call('activate_license', ['license_id' => '99']);

        $this->assertFalse($response['success']);
        $this->assertSame('account_error', $response['data']['code']);
        $this->assertFalse($this->license->isActivated());
    }

    // --- #31: free a seat during the sign-in ---------------------------------

    public function test_a_signed_in_owner_can_free_a_seat_and_then_activate(): void
    {
        $full = [['id' => 11, 'key' => 'KEY-1', 'activations' => [['domain' => 'old.example']]]];
        $freed = [['id' => 11, 'key' => 'KEY-1', 'activations' => []]];

        $this->signIn([], $full, static function () {
            WpStub::queueJson(403, ['error_code' => 'activation_limit_reached', 'message' => 'No seats left.']);
        });
        $this->assertSame(LicenseErrorCode::ACTIVATION_LIMIT_REACHED, $this->call('get_status')['data']['oauth_error_code']);

        WpStub::queueJson(200, ['data' => $full]);        // look up the key
        WpStub::queueJson(200, ['success' => true]);       // license/deactivate
        WpStub::queueJson(200, ['data' => $freed]);       // the refreshed list
        $response = $this->call('remove_site', ['license_id' => '11', 'domain' => 'old.example']);

        $this->assertTrue($response['success']);
        $this->assertSame('old.example', $response['data']['removed']);
        $this->assertSame([], $response['data']['licenses'][0]['activations']);
        $this->assertArrayNotHasKey('key', $response['data']['licenses'][0]);
        $deactivate = $this->requestBody(2);
        $this->assertStringContainsString('/api/v1/license/deactivate', WpStub::$requestLog[count(WpStub::$requestLog) - 2]['url']);
        $this->assertSame(['KEY-1', 'old.example'], [$deactivate['license_key'], $deactivate['domain']]);
        $this->assertSame([], $this->account->getPendingChoice()[0]['activations'], 'The pending choice is refreshed.');

        WpStub::queueJson(200, ['data' => $freed]);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        WpStub::queueJson(200, ['success' => true]); // logout
        $this->assertTrue($this->call('activate_license', ['license_id' => '11'])['success']);
        $this->assertTrue($this->license->isActivated());
    }

    public function test_removing_this_site_is_refused(): void
    {
        $this->signIn([], [['id' => 11, 'key' => 'KEY-1'], ['id' => 12, 'key' => 'KEY-2']]);
        $requests = count(WpStub::$requestLog);

        $response = $this->call('remove_site', ['license_id' => '11', 'domain' => 'example.com']);

        $this->assertSame('this_site', $response['data']['code']);
        $this->assertCount($requests, WpStub::$requestLog);
    }

    public function test_an_admin_who_cannot_install_plugins_cannot_remove_a_site(): void
    {
        $this->signIn([], [['id' => 11, 'key' => 'KEY-1'], ['id' => 12, 'key' => 'KEY-2']]);
        WpStub::$currentUserId = 2;
        WpStub::$deniedCapabilities = [AccountEndpoints::REMOVE_SITE_CAPABILITY];
        $requests = count(WpStub::$requestLog);

        $response = $this->call('remove_site', ['license_id' => '11', 'domain' => 'old.example']);

        $this->assertFalse($response['success']);
        $this->assertSame('forbidden', $response['data']['code']);
        $this->assertCount($requests, WpStub::$requestLog, 'Nothing is sent to Nexus.');
        $this->assertTrue($this->account->isConnected(), 'The sign-in stays for the other actions.');
        $this->assertCount(2, $this->call('get_status')['data']['pending_choice'], 'The same admin still sees the sign-in.');
    }

    public function test_the_sign_in_return_uses_the_account_endpoints_capability(): void
    {
        $this->assertSame('manage_options', $this->endpoints->capability());
    }

    public function test_a_refused_removal_reports_the_servers_code(): void
    {
        $this->signIn([], [['id' => 11, 'key' => 'KEY-1'], ['id' => 12, 'key' => 'KEY-2']]);
        WpStub::queueJson(200, ['data' => [['id' => 11, 'key' => 'KEY-1']]]);
        WpStub::queueJson(403, ['error_code' => 'license_suspended', 'message' => 'Suspended.']);

        $response = $this->call('remove_site', ['license_id' => '11', 'domain' => 'old.example']);

        $this->assertFalse($response['success']);
        $this->assertSame(LicenseErrorCode::LICENSE_SUSPENDED, $response['data']['code']);
    }

    public function test_another_admin_can_remove_a_site_from_the_sites_sign_in(): void
    {
        $full = [['id' => 11, 'key' => 'KEY-1', 'activations' => [['domain' => 'old.example']]], ['id' => 12, 'key' => 'KEY-2']];
        $this->signIn([], $full);
        WpStub::$currentUserId = 2;

        WpStub::queueJson(200, ['data' => $full]);        // look up the key
        WpStub::queueJson(200, ['success' => true]);       // license/deactivate
        WpStub::queueJson(200, ['data' => $full]);        // the refreshed list
        $response = $this->call('remove_site', ['license_id' => '11', 'domain' => 'old.example']);

        $this->assertTrue($response['success']);
        $this->assertSame('old.example', $response['data']['removed']);
    }

    /**
     * The decoded body of the n-th last request.
     *
     * @return array<string, mixed>
     */
    private function requestBody(int $fromEnd): array
    {
        return (array) json_decode((string) (WpStub::$requestLog[count(WpStub::$requestLog) - $fromEnd]['args']['body'] ?? ''), true);
    }

    /**
     * Run the OAuth callback with a queued exchange-code reply and license list.
     *
     * @param  array<string, mixed>  $exchange
     * @param  array<int, array<string, mixed>>  $licenses
     */
    private function signIn(array $exchange, array $licenses, ?callable $afterLicenses = null): void
    {
        $this->store->setOAuthState('state-1');
        WpStub::queueJson(200, $exchange + ['access_token' => 'token-1', 'user' => ['email' => 'buyer@example.com', 'name' => 'Ada Buyer']]);
        WpStub::queueJson(200, ['data' => $licenses]);

        if ($afterLicenses) {
            $afterLicenses();
        }

        $this->account->handleOAuthCallback('code-1', 'state-1', $this->license);
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
