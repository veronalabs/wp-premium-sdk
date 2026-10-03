<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Account;

use PHPUnit\Framework\TestCase;
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
    }

    protected function tearDown(): void
    {
        $_POST = [];
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
