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

        // Always send Nexus a known-safe admin URL. The original page (which
        // may include a React hash route like #/license) is tunneled through
        // a `wps_return` query param that the callback handler unpacks.
        $redirectUri = admin_url();
        if ($returnUrl !== null && $returnUrl !== '') {
            $redirectUri = add_query_arg('wps_return', rawurlencode($returnUrl), $redirectUri);
        }

        $url = $this->config->apiBaseUrl().'/connect/'.$this->config->productSlug().'/authorize?'.http_build_query([
            'state' => $state,
            'redirect_uri' => $redirectUri,
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
        $accessToken = $response['access_token'];

        $this->storeSession([
            'access_token' => $this->encryptor->encrypt($accessToken),
            'refresh_token' => ! empty($response['refresh_token']) ? $this->encryptor->encrypt($response['refresh_token']) : null,
            'user' => [
                'email' => $user['email'] ?? '',
                'name' => $user['name'] ?? '',
            ],
            'connected_at' => time(),
        ]);

        // Nexus's exchange-code response doesn't include licenses — fetch them
        // separately so we can auto-activate (1 license) or surface a picker (2+).
        $licenses = [];
        try {
            $licensesResponse = $this->client->licenses($accessToken);
            $licenses = $licensesResponse['data'] ?? $licensesResponse['licenses'] ?? [];
        } catch (Exception $e) {
            $this->setFlashError(sprintf(
                __('Could not fetch licenses from your account: %s', $this->config->textDomain()),
                $e->getMessage()
            ));

            return ['connected' => true, 'licenses' => []];
        }

        $count = count($licenses);

        if ($count === 0) {
            $this->setFlashError(__('Your account has no licenses for this product.', $this->config->textDomain()));

            return ['connected' => true, 'licenses' => []];
        }

        if ($count === 1) {
            $key = $licenses[0]['license_key'] ?? '';

            if ($key !== '') {
                try {
                    $licenseManager->activate($key);

                    return ['connected' => true, 'licenses' => $licenses];
                } catch (Exception $e) {
                    // Activation failed (e.g., max_activations reached). Surface
                    // the single license through the picker UI so the user can
                    // see the error inline and retry / pick another site.
                    $this->setPendingChoice($licenses);
                    $this->setFlashError($e->getMessage());

                    return ['connected' => true, 'licenses' => $licenses];
                }
            }
        }

        // 2+ licenses → let the user pick.
        $this->setPendingChoice($licenses);

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

    /**
     * @return array<string, string>|null
     */
    public function getUser(): ?array
    {
        $session = $this->store->get('account');
        $user = $session['user'] ?? null;

        if (! is_array($user) || empty($user['email'])) {
            return null;
        }

        return $user;
    }

    /**
     * @param  array<int, array<string, mixed>>  $licenses
     */
    public function setPendingChoice(array $licenses): void
    {
        $session = $this->store->get('account') ?? [];
        $session['pending_choice'] = $licenses;
        $this->store->set('account', $session);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getPendingChoice(): ?array
    {
        $session = $this->store->get('account');
        $choice = $session['pending_choice'] ?? null;

        if (! is_array($choice) || $choice === []) {
            return null;
        }

        return $choice;
    }

    public function clearPendingChoice(): void
    {
        $session = $this->store->get('account');

        if (! is_array($session) || ! array_key_exists('pending_choice', $session)) {
            return;
        }

        unset($session['pending_choice']);
        $this->store->set('account', $session);
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
