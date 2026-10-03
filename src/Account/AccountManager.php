<?php

namespace VeronaLabs\WpPremiumSdk\Account;

use Exception;
use Throwable;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\EncryptorInterface;
use VeronaLabs\WpPremiumSdk\Http\ApiException;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
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
 * The sign-in is a one-time step, not a stored connection: once a license is
 * activated the session is deleted and everything runs on the license key.
 * isConnected() therefore means "a sign-in is in progress", and the session also
 * lapses on its own when the access token expires (24 hours unless Nexus says
 * otherwise). No refresh token is stored; Nexus does not issue one.
 *
 * Every way out of the picker deletes the session: activating a license (through
 * the picker, or by key — LicenseManager calls endSignIn()), signing out, and the
 * session passing its `expires_at`, which is checked on every read.
 *
 * The session belongs to the WordPress user who started it (`user_id`). Only that
 * user sees it: for any other admin isConnected() is false, there is no user or
 * pending choice, and the picker actions answer `sign_in_other_user`.
 *
 * The access token is encrypted at rest.
 */
class AccountManager
{
    /** How long a sign-in lasts when Nexus does not say: its token lifetime. */
    public const SESSION_TTL = 86400;

    /** Seconds to wait for Nexus to revoke the token when a sign-in ends. */
    public const REVOKE_TIMEOUT = 5;

    private ClientConfig $config;
    private AccountClient $client;
    private PremiumStore $store;
    private EncryptorInterface $encryptor;

    /** Set when this request found the session expired and deleted it. */
    private bool $lapsed = false;

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

        // Tell Nexus which query-param names to use for the code/state it
        // appends to redirect_uri on the way back. Nexus is multi-product, so
        // each product carries its own namespaced params (this product's
        // oauth_callback_params) instead of a hardcoded default — the callback
        // handler reads the code/state under exactly these names.
        $callbackParams = $this->config->oauthCallbackParams();

        $url = $this->config->apiBaseUrl().'/connect/'.$this->config->productSlug().'/authorize?'.http_build_query([
            'state' => $state,
            'redirect_uri' => $redirectUri,
            'code_param' => $callbackParams['code'] ?? 'wps_oauth_code',
            'state_param' => $callbackParams['state'] ?? 'wps_oauth_state',
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
        $now = time();

        $this->storeSession([
            'user_id' => (int) get_current_user_id(),
            'access_token' => $this->encryptor->encrypt($accessToken),
            'user' => [
                'email' => $user['email'] ?? '',
                'name' => $user['name'] ?? '',
            ],
            'connected_at' => $now,
            'expires_at' => $this->sessionExpiry($response, $now),
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
                    // Signed in, license picked and activated: the sign-in has
                    // done its job, so it ends here.
                    $this->endSignIn();

                    return ['connected' => false, 'licenses' => $licenses];
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

    /**
     * Whether a sign-in is in progress: a token is stored and has not expired.
     * False once a license has been activated (the session is deleted then).
     */
    public function isConnected(): bool
    {
        $session = $this->session();

        return ! empty($session['access_token']) && $this->belongsToCurrentUser($session);
    }

    /**
     * The decrypted access token, or null when there is none, it has expired, or
     * another user started the sign-in.
     */
    public function getAccessToken(): ?string
    {
        $session = $this->session();

        if (empty($session['access_token']) || ! $this->belongsToCurrentUser($session)) {
            return null;
        }

        return $this->encryptor->decrypt($session['access_token']);
    }

    /**
     * Whether a sign-in session is stored, or was until this request found it
     * expired (so callers can still say "your sign-in expired").
     */
    public function hasSession(): bool
    {
        return $this->lapsed || ! empty($this->session()['access_token']);
    }

    /**
     * Whether a sign-in is in progress for another WordPress user. Such a session
     * stays out of this user's way: they see the normal activation screen.
     */
    public function isSignInOfOtherUser(): bool
    {
        $session = $this->session();

        return ! empty($session['access_token']) && ! $this->belongsToCurrentUser($session);
    }

    /**
     * End the sign-in locally: token, user, pending choice and flash error all go.
     * Used after a successful activation and when the token turns out to be expired.
     */
    public function clearSession(): void
    {
        $this->store->delete('account');
    }

    /**
     * End a sign-in that has done its job: revoke the token on Nexus (best-effort,
     * short timeout — a failure never undoes or fails the activation that came
     * before), then delete the local session.
     */
    public function endSignIn(): void
    {
        $token = $this->rawAccessToken();

        if ($token) {
            try {
                $this->client->logout($token, self::REVOKE_TIMEOUT);
            } catch (Throwable $e) {
                // Best-effort — the token also expires on its own.
            }
        }

        $this->clearSession();
    }

    /**
     * Whether an API failure means the sign-in itself has expired (a 401, or
     * Nexus's `token_expired`), as opposed to some other refusal.
     */
    public function isSignInExpired(Throwable $e): bool
    {
        return $e instanceof ApiException
            && ($e->getHttpStatus() === 401 || $e->getErrorCode() === LicenseErrorCode::TOKEN_EXPIRED);
    }

    public function logout(): void
    {
        $token = $this->rawAccessToken();

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
        $session = $this->session();

        if (! $this->belongsToCurrentUser($session)) {
            return null;
        }

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
        $session = $this->session();

        if (! $this->belongsToCurrentUser($session)) {
            return null;
        }

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
        $session = $this->session();

        if (! $this->belongsToCurrentUser($session)) {
            return null;
        }

        $error = $session['flash_error'] ?? null;

        if ($error !== null && $session) {
            unset($session['flash_error']);
            $this->store->set('account', $session);
        }

        return $error;
    }

    public function setFlashError(string $message): void
    {
        $session = $this->session() ?? [];
        $session['user_id'] = $session['user_id'] ?? (int) get_current_user_id();
        $session['flash_error'] = $message;
        $this->store->set('account', $session);
    }

    /**
     * The stored session, deleting it first when its token has expired — so an
     * abandoned picker stops counting as a sign-in without waiting for a 401. A row
     * holding only a flash error (no token) is left alone.
     *
     * @return array<string, mixed>|null
     */
    private function session(): ?array
    {
        $session = $this->store->get('account');

        if (is_array($session) && ! empty($session['access_token']) && $this->isExpired($session)) {
            $this->store->delete('account');
            $this->lapsed = true;

            return null;
        }

        return $session;
    }

    /**
     * The token whoever started the sign-in holds, for revoking it on the way out.
     */
    private function rawAccessToken(): ?string
    {
        $session = $this->session();

        return ! empty($session['access_token']) ? $this->encryptor->decrypt($session['access_token']) : null;
    }

    /**
     * A session stored before `user_id` existed belongs to whoever asks.
     *
     * @param  array<string, mixed>|null  $session
     */
    private function belongsToCurrentUser(?array $session): bool
    {
        if (! is_array($session) || ! isset($session['user_id'])) {
            return true;
        }

        return (int) $session['user_id'] === (int) get_current_user_id();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeSession(array $data): void
    {
        $this->store->set('account', $data);
    }

    /**
     * When the new token stops working: Nexus's `expires_at` (timestamp or date)
     * or `expires_in` (seconds) when sent, else the default token lifetime.
     *
     * @param  array<string, mixed>  $response
     */
    private function sessionExpiry(array $response, int $now): int
    {
        if (isset($response['expires_in']) && is_numeric($response['expires_in'])) {
            return $now + (int) $response['expires_in'];
        }

        if (! empty($response['expires_at'])) {
            $expiresAt = is_numeric($response['expires_at'])
                ? (int) $response['expires_at']
                : strtotime((string) $response['expires_at']);

            if ($expiresAt) {
                return $expiresAt;
            }
        }

        return $now + self::SESSION_TTL;
    }

    /**
     * A session stored before expires_at existed lapses a token lifetime after it began.
     *
     * @param  array<string, mixed>  $session
     */
    private function isExpired(array $session): bool
    {
        $expiresAt = isset($session['expires_at'])
            ? (int) $session['expires_at']
            : (int) ($session['connected_at'] ?? 0) + self::SESSION_TTL;

        return $expiresAt <= time();
    }
}
