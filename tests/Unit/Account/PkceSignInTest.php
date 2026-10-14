<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Account;

use Exception;
use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Account\AccountClient;
use VeronaLabs\WpPremiumSdk\Account\AccountManager;
use VeronaLabs\WpPremiumSdk\Account\Pkce;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\SodiumEncryptor;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

/**
 * The account sign-in uses PKCE: the authorize URL carries only the S256 challenge,
 * the verifier stays on the server with the state, goes to Nexus on the exchange,
 * and is gone afterwards (#47).
 */
class PkceSignInTest extends TestCase
{
    private PremiumStore $store;

    private AccountManager $account;

    private LicenseManager $license;

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

        $http = new ApiClient($config);
        $encryptor = new SodiumEncryptor('wp_statistics_premium_cipher');
        $this->store = new PremiumStore($config);
        $this->license = new LicenseManager(new LicenseClient($config, $http), $this->store, $encryptor);
        $this->account = new AccountManager($config, new AccountClient($config, $http), $this->store, $encryptor);
    }

    public function test_a_verifier_is_long_random_and_url_safe(): void
    {
        $verifier = Pkce::createVerifier();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._~-]{43,128}$/', $verifier);
        $this->assertNotSame($verifier, Pkce::createVerifier());
    }

    public function test_the_challenge_is_base64url_sha256_without_padding(): void
    {
        // Fixed value worked out outside PHP (Python hashlib + urlsafe_b64encode).
        $this->assertSame(
            'bwWFMyPfdG9qreDhH2lmftFx_dFeLDalzcT1gb_j68g',
            Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r7wW1gFWFOEjXk')
        );
    }

    public function test_the_authorize_url_carries_an_s256_challenge_of_the_stored_verifier(): void
    {
        $result = $this->account->getAuthorizeUrl();
        $query = $this->query($result['authorize_url']);

        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['code_challenge']);
        $this->assertSame($result['state'], $query['state']);

        $stored = WpStub::$transients['x_'.$result['state']];
        $this->assertSame(Pkce::challenge($stored['code_verifier']), $query['code_challenge']);
    }

    public function test_the_verifier_never_leaves_the_server_on_the_authorize_url(): void
    {
        $result = $this->account->getAuthorizeUrl();
        $verifier = WpStub::$transients['x_'.$result['state']]['code_verifier'];

        $this->assertStringNotContainsString($verifier, $result['authorize_url']);
        $this->assertArrayNotHasKey('code_verifier', $this->query($result['authorize_url']));
        $this->assertArrayNotHasKey('code_verifier', $result);
    }

    public function test_each_sign_in_gets_its_own_verifier(): void
    {
        $first = $this->account->getAuthorizeUrl();
        $second = $this->account->getAuthorizeUrl();

        $this->assertNotSame(
            $this->query($first['authorize_url'])['code_challenge'],
            $this->query($second['authorize_url'])['code_challenge']
        );
    }

    public function test_the_exchange_sends_the_verifier_and_then_forgets_it(): void
    {
        $result = $this->account->getAuthorizeUrl();
        $verifier = WpStub::$transients['x_'.$result['state']]['code_verifier'];

        $this->queueSignIn();
        $this->account->handleOAuthCallback('code-1', $result['state'], $this->license);

        $exchange = $this->exchangeBody();
        $this->assertSame($verifier, $exchange['code_verifier']);
        $this->assertSame('code-1', $exchange['code']);
        $this->assertArrayNotHasKey('x_'.$result['state'], WpStub::$transients, 'The state and its verifier are deleted after use.');
    }

    public function test_the_same_callback_cannot_exchange_twice(): void
    {
        $result = $this->account->getAuthorizeUrl();
        $this->queueSignIn();
        $this->account->handleOAuthCallback('code-1', $result['state'], $this->license);
        $requests = count(WpStub::$requestLog);

        try {
            $this->account->handleOAuthCallback('code-1', $result['state'], $this->license);
            $this->fail('A used state must be refused.');
        } catch (Exception $e) {
            $this->assertSame($requests, count(WpStub::$requestLog), 'Nothing is sent to Nexus.');
        }
    }

    public function test_an_unknown_or_expired_state_does_not_exchange(): void
    {
        $this->account->getAuthorizeUrl();

        try {
            $this->account->handleOAuthCallback('code-1', 'not-a-state-we-made', $this->license);
            $this->fail('An unknown state must be refused.');
        } catch (Exception $e) {
            $this->assertSame([], WpStub::$requestLog, 'The code is never sent to Nexus.');
        }
    }

    public function test_a_state_kept_before_pkce_still_exchanges_without_a_verifier(): void
    {
        // A sign-in started on the previous version stored `true` as the state.
        WpStub::$transients['x_state-old'] = true;

        $this->queueSignIn();
        $this->account->handleOAuthCallback('code-1', 'state-old', $this->license);

        $this->assertArrayNotHasKey('code_verifier', $this->exchangeBody());
        $this->assertTrue($this->account->isConnected());
    }

    private function queueSignIn(): void
    {
        WpStub::queueJson(200, ['access_token' => 'token-1', 'user' => ['email' => 'buyer@example.com', 'name' => 'Ada Buyer']]);
        WpStub::queueJson(200, ['data' => [['key' => 'KEY-1'], ['key' => 'KEY-2']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeBody(): array
    {
        $call = WpStub::$requestLog[0];
        $this->assertStringContainsString('/api/v1/auth/exchange-code', $call['url']);

        return (array) json_decode((string) $call['args']['body'], true);
    }

    /**
     * @return array<string, string>
     */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
