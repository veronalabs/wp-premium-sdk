<?php

namespace VeronaLabs\WpPremiumSdk\Account;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\EncryptorInterface;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;

/**
 * Orchestrates the OAuth account login flow with Nexus.
 *
 * 1. getAuthorizeUrl() → redirect user to Nexus /connect/{product}/authorize
 * 2. handleOAuthCallback() → exchange code for token, store session
 * 3. activateLicense() → pick a license from the user's Nexus account
 *    and activate it on this site via LicenseManager
 *
 * Access + refresh tokens are encrypted at rest.
 */
class AccountManager
{
    private ClientConfig $config;
    private AccountClient $client;
    private PremiumStore $store;
    private EncryptorInterface $encryptor;

    public function __construct(ClientConfig $config, AccountClient $client, PremiumStore $store, EncryptorInterface $encryptor)
    {
        $this->config = $config;
        $this->client = $client;
        $this->store = $store;
        $this->encryptor = $encryptor;
    }

    /**
     * @return array{authorize_url: string, state: string}
     */
    public function getAuthorizeUrl(?string $returnUrl = null): array
    {
        $state = bin2hex(random_bytes(16));
        $this->store->setOAuthState($state);

        $url = $this->config->apiBaseUrl().'/connect/'.$this->config->productSlug().'/authorize?'.http_build_query([
            'state' => $state,
            'redirect_uri' => $returnUrl ?? admin_url(),
        ]);

        return ['authorize_url' => $url, 'state' => $state];
    }

    /**
     * @throws Exception
     *
     * @return array<string, mixed>
     */
    public function handleOAuthCallback(string $code, string $state, LicenseManager $licenseManager): array
    {
        if (! $this->store->verifyOAuthState($state)) {
            throw new Exception(__('Invalid or expired OAuth state.', $this->config->textDomain()));
        }

        $response = $this->client->exchangeCode($code);

        if (empty($response['access_token'])) {
            throw new Exception(__('Nexus did not return an access token.', $this->config->textDomain()));
        }

        $user = $response['user'] ?? [];
        $licenses = $response['licenses'] ?? [];

        $this->storeSession([
            'access_token' => $this->encryptor->encrypt($response['access_token']),
            'refresh_token' => ! empty($response['refresh_token']) ? $this->encryptor->encrypt($response['refresh_token']) : null,
            'user' => [
                'email' => $user['email'] ?? '',
                'name' => $user['name'] ?? '',
            ],
            'connected_at' => time(),
        ]);

        // Auto-activate first license for this product if present
        if (! empty($licenses[0]['license_key'])) {
            try {
                $licenseManager->activate($licenses[0]['license_key']);
            } catch (Exception $e) {
                // Leave unactivated; UI will prompt the user.
            }
        }

        return ['connected' => true, 'licenses' => $licenses];
    }

    public function isConnected(): bool
    {
        $session = $this->store->get('account');

        return ! empty($session['access_token']);
    }

    public function getAccessToken(): ?string
    {
        $session = $this->store->get('account');

        if (empty($session['access_token'])) {
            return null;
        }

        return $this->encryptor->decrypt($session['access_token']);
    }

    public function logout(): void
    {
        $token = $this->getAccessToken();

        if ($token) {
            try {
                $this->client->logout($token);
            } catch (Exception $e) {
                // Best-effort.
            }
        }

        $this->store->delete('account');
    }

    public function consumeFlashError(): ?string
    {
        $session = $this->store->get('account');
        $error = $session['flash_error'] ?? null;

        if ($error !== null && $session) {
            unset($session['flash_error']);
            $this->store->set('account', $session);
        }

        return $error;
    }

    public function setFlashError(string $message): void
    {
        $session = $this->store->get('account') ?? [];
        $session['flash_error'] = $message;
        $this->store->set('account', $session);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeSession(array $data): void
    {
        $this->store->set('account', $data);
    }
}
