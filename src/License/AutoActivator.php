<?php

namespace VeronaLabs\WpPremiumSdk\License;

use Exception;
use VeronaLabs\WpPremiumSdk\Http\ApiException;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Support\Request;

/**
 * Activates this site on its own with a key nobody typed on its license page: a
 * wp-config constant (#19) or the network admin's key on a network-activated
 * plugin (#17). Runs on `admin_init`.
 *
 * It activates when no license is stored, when the stored key is not the provided
 * one, or when the stored activation belongs to another domain (a cloned or moved
 * site: this domain is activated, the old one keeps its seat). Each subsite does
 * this for itself, on its own address and with its own seat.
 *
 * A failure is not retried on every page load: the next try waits 1 hour, then 6
 * hours, then 24 hours between tries (longer when Nexus sends Retry-After). The
 * record — attempts, last try, next try, error code — sits in the `auto_activation`
 * section of the store, and a new key starts it over. The key itself is never
 * logged, stored in the record, or sent anywhere but Nexus.
 *
 * A subsite activated with a key of its own before the plugin was network-activated
 * keeps it: the network key never replaces it here.
 *
 * On a network-activated plugin seats are not handed out in visit order: a subsite
 * activates itself only while NetworkLicense::seatsAllowAutoActivation() says so.
 * Otherwise nothing happens here, no failure is recorded, and the subsite's admin
 * sees no seat notice — the network admin decides (activate_all / activate_sites).
 *
 * When the source goes away — the constant is deleted, or the network admin
 * removes the network key — a license that came from it is deactivated here,
 * which releases this site's seat.
 */
class AutoActivator
{
    /** Store section holding the retry record. */
    public const SECTION = 'auto_activation';

    /** Wait after the 1st, 2nd and 3rd+ failure in a row: 1h, 6h, 24h. */
    public const BACKOFF = [3600, 6 * 3600, 24 * 3600];

    private LicenseManager $manager;

    private KeySource $keySource;

    private PremiumStore $store;

    public function __construct(LicenseManager $manager, KeySource $keySource, PremiumStore $store)
    {
        $this->manager = $manager;
        $this->keySource = $keySource;
        $this->store = $store;
    }

    public function register(): void
    {
        add_action('admin_init', [$this, 'run']);
        add_action('wp_initialize_site', [$this, 'rememberNewSite'], 100);
    }

    /**
     * A subsite created while the network key is set may take a seat that is still
     * free, even when there are not enough for every older subsite.
     *
     * @param  object  $site  The new WP_Site.
     */
    public function rememberNewSite($site): void
    {
        $network = $this->keySource->network();

        if ($network === null || ! $this->keySource->isNetworkManaged() || ! $network->hasKey() || ! isset($site->blog_id)) {
            return;
        }

        $network->rememberNewSite((int) $site->blog_id);
    }

    /**
     * Bring this site's license in line with the key it is provided, if any.
     */
    public function run(): void
    {
        $this->adoptMainSiteKey();

        $provided = $this->keySource->provided();

        if ($provided === null) {
            $this->dropLicenseWhoseSourceIsGone();

            return;
        }

        if (! $this->needsActivation($provided['key'])) {
            if ($this->manager->getSource() !== $provided['source']) {
                $this->manager->setSource($provided['source']);
            }

            $this->reset();

            return;
        }

        $network = $this->keySource->network();
        $blogId = (int) get_current_blog_id();

        if ($network !== null && $this->keySource->isNetworkManaged()) {
            // A key this subsite entered itself, before the plugin was network-activated,
            // stays: the network key does not replace it.
            if ($provided['source'] === KeySource::NETWORK && $this->manager->isActivated() && $this->manager->getSource() === KeySource::MANUAL) {
                return;
            }

            if (! $network->seatsAllowAutoActivation($blogId)) {
                return;
            }
        }

        $fingerprint = $this->fingerprint($provided['key']);
        $record = $this->store->get(self::SECTION);
        $sameKey = is_array($record) && ($record['fingerprint'] ?? '') === $fingerprint;

        if ($sameKey && (int) ($record['retry_at'] ?? 0) > time()) {
            return;
        }

        $attempts = $sameKey ? (int) ($record['attempts'] ?? 0) : 0;
        $now = time();

        // Claim the attempt before calling Nexus, so page loads arriving while this
        // one waits for the answer do not try as well.
        $this->store->set(self::SECTION, [
            'fingerprint' => $fingerprint,
            'source' => $provided['source'],
            'attempts' => $attempts,
            'last_attempt_at' => $now,
            'retry_at' => $now + self::BACKOFF[0],
            'error_code' => $sameKey ? ($record['error_code'] ?? null) : null,
        ]);

        $previousKey = $this->manager->getLicenseKey();

        try {
            $this->manager->activate($provided['key'], $provided['source']);
        } catch (Exception $e) {
            $this->recordFailure($fingerprint, $provided['source'], $attempts + 1, $now, $e);

            return;
        }

        $this->reset();

        if ($network !== null) {
            $network->forgetNewSite($blogId);
        }

        // The site used another key before; hand that key's seat back.
        if ($previousKey !== null && $previousKey !== $provided['key']) {
            $this->manager->releaseSeat($previousKey, Request::currentDomain());
        }
    }

    /**
     * The last failed try, for the license page: attempts, last_attempt_at,
     * retry_at, error_code and source. Null when nothing has failed.
     *
     * @return array{attempts: int, last_attempt_at: int, retry_at: int, error_code: string|null, source: string}|null
     */
    public function lastFailure(): ?array
    {
        $record = $this->store->get(self::SECTION);

        if (! is_array($record) || (int) ($record['attempts'] ?? 0) === 0) {
            return null;
        }

        return [
            'attempts' => (int) $record['attempts'],
            'last_attempt_at' => (int) ($record['last_attempt_at'] ?? 0),
            'retry_at' => (int) ($record['retry_at'] ?? 0),
            'error_code' => isset($record['error_code']) ? (string) $record['error_code'] : null,
            'source' => (string) ($record['source'] ?? ''),
        ];
    }

    /**
     * Forget the retry record so the next admin load tries straight away — after the
     * network admin saves a new key, for instance.
     */
    public function reset(): void
    {
        // Only write when there is something to forget: run() calls this on every
        // admin load of a healthy site.
        if ($this->store->get(self::SECTION) !== null) {
            $this->store->delete(self::SECTION);
        }
    }

    private function needsActivation(string $providedKey): bool
    {
        if (! $this->manager->isActivated()) {
            return true;
        }

        if ($this->manager->getLicenseKey() !== $providedKey) {
            return true;
        }

        return $this->manager->domainChange() !== null;
    }

    /**
     * The constant was deleted, or the network admin removed the network key: a
     * license that came from there goes too. A license whose plugin is simply no
     * longer network-activated keeps working and becomes this site's own.
     */
    private function dropLicenseWhoseSourceIsGone(): void
    {
        $source = $this->manager->getSource();

        if ($source === KeySource::CONSTANT) {
            $this->manager->deactivate();
            $this->reset();

            return;
        }

        if ($source === KeySource::NETWORK) {
            if ($this->keySource->isNetworkManaged()) {
                $this->manager->deactivate();
            } else {
                $this->manager->setSource(KeySource::MANUAL);
            }
        }

        $this->reset();
    }

    /**
     * Migration for a network that activated the plugin network-wide after the main
     * site had already entered a key: the main site's key becomes the network key.
     *
     * Runs on the main site only (Network Admin runs there too), because only there
     * can the main site's stored key be read — another subsite may not share its
     * fallback cipher key.
     */
    private function adoptMainSiteKey(): void
    {
        $network = $this->keySource->network();

        if ($network === null || ! $this->keySource->isNetworkManaged() || $network->hasKey() || ! is_main_site()) {
            return;
        }

        if ($this->manager->getSource() === KeySource::CONSTANT) {
            return;
        }

        $key = $this->manager->getLicenseKey();

        if ($key === null) {
            return;
        }

        $network->setKey($key);
        $this->manager->setSource(KeySource::NETWORK);
    }

    private function recordFailure(string $fingerprint, string $source, int $attempts, int $now, Exception $e): void
    {
        $wait = self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1];

        if ($e instanceof ApiException && $e->getRetryAfter() !== null) {
            $wait = max($wait, $e->getRetryAfter());
        }

        if ($e instanceof ApiException || $e instanceof LicenseActionException) {
            $code = $e->getErrorCode();
        } elseif ($e instanceof ActivationVetoedException) {
            $code = 'activation_vetoed';
        } else {
            $code = LicenseErrorCode::UNKNOWN;
        }

        $this->store->set(self::SECTION, [
            'fingerprint' => $fingerprint,
            'source' => $source,
            'attempts' => $attempts,
            'last_attempt_at' => $now,
            'retry_at' => $now + $wait,
            'error_code' => $code,
        ]);
    }

    /**
     * Tells "the same key as last time" from "a new key" without keeping the key.
     */
    private function fingerprint(string $key): string
    {
        return substr(hash('sha256', 'wp-premium-sdk|'.$key), 0, 16);
    }
}
