<?php

namespace VeronaLabs\WpPremiumSdk\Container;

use InvalidArgumentException;
use Throwable;
use VeronaLabs\WpPremiumSdk\Account\AccountBootstrap;
use VeronaLabs\WpPremiumSdk\Account\AccountClient;
use VeronaLabs\WpPremiumSdk\Account\AccountEndpoints;
use VeronaLabs\WpPremiumSdk\Account\AccountManager;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\EncryptorInterface;
use VeronaLabs\WpPremiumSdk\Encryption\SodiumEncryptor;
use VeronaLabs\WpPremiumSdk\Feature\FeatureInstaller;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\License\AutoActivator;
use VeronaLabs\WpPremiumSdk\License\KeySource;
use VeronaLabs\WpPremiumSdk\License\LicenseBootstrap;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
use VeronaLabs\WpPremiumSdk\License\LicenseEndpoints;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\License\NetworkLicense;
use VeronaLabs\WpPremiumSdk\License\NetworkLicenseEndpoints;
use VeronaLabs\WpPremiumSdk\Module\ModuleLoader;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Update\PluginUpdater;
use VeronaLabs\WpPremiumSdk\Update\TierPackageInstaller;

/**
 * Builds the full service graph for a plugin from one ClientConfig.
 *
 * Usage:
 *
 *   $provider = new PremiumServiceProvider(
 *       config: $config,
 *       pluginBasename: 'wp-statistics-premium/wp-statistics-premium.php',
 *       encryptor: null, // defaults to SodiumEncryptor
 *   );
 *   $provider->register();
 *
 * After register(), the SDK has hooked its AJAX endpoints, WP update filter,
 * module loader, and OAuth callback handler. Individual services can still
 * be pulled from the provider for use by the host plugin's admin UI.
 *
 * The SDK registers no activation, deactivation or uninstall hooks of its own.
 * The host's uninstall.php calls uninstall() to release the seat and remove
 * what the SDK stored.
 */
class PremiumServiceProvider
{
    /** Seconds uninstall() waits for Nexus to release the seat. */
    public const UNINSTALL_TIMEOUT = 5;

    private ClientConfig $config;

    private string $pluginBasename;

    private EncryptorInterface $encryptor;

    private EncryptorInterface $networkEncryptor;

    /** The encryptor the host passed, if any (null: SodiumEncryptor per site). */
    private ?EncryptorInterface $hostEncryptor;

    private ApiClient $http;

    private PremiumStore $store;

    private LicenseClient $licenseClient;

    private FeatureInstaller $featureInstaller;

    private LicenseManager $licenseManager;

    private PluginUpdater $pluginUpdater;

    private LicenseEndpoints $licenseEndpoints;

    private LicenseBootstrap $licenseBootstrap;

    private NetworkLicense $networkLicense;

    private KeySource $keySource;

    private AutoActivator $autoActivator;

    private TierPackageInstaller $tierPackageInstaller;

    private NetworkLicenseEndpoints $networkLicenseEndpoints;

    private AccountClient $accountClient;

    private AccountManager $accountManager;

    private AccountEndpoints $accountEndpoints;

    private AccountBootstrap $accountBootstrap;

    private ModuleLoader $moduleLoader;

    public function __construct(
        ClientConfig $config,
        string $pluginBasename,
        ?EncryptorInterface $encryptor = null
    ) {
        if ($pluginBasename === '') {
            throw new InvalidArgumentException('pluginBasename cannot be empty.');
        }

        $this->config = $config;
        $this->pluginBasename = $pluginBasename;
        $this->hostEncryptor = $encryptor;
        $this->encryptor = $encryptor ?? new SodiumEncryptor($this->cipherOptionKey());
        // The network key must read back on every subsite. Salts are shared across a
        // network; the default encryptor's no-salts fallback key is kept network-wide
        // for it. A host-supplied encryptor is trusted to be readable network-wide.
        $this->networkEncryptor = $encryptor ?? new SodiumEncryptor($this->config->optionKey().'_network_cipher', true);

        $this->wire();
    }

    public function register(): void
    {
        $this->licenseBootstrap->register();
        $this->accountBootstrap->register();
        $this->moduleLoader->register();
    }

    /**
     * Remove everything the SDK stored for this site, for the host's uninstall.php.
     *
     * First, when `$releaseSeat` is true and a license is stored, it tells Nexus
     * this site no longer uses its seat — best-effort, with a short timeout, and
     * never throwing, so a slow or unreachable server cannot hold up the uninstall.
     * Then it deletes the SDK's option row (license + account sections), the
     * fallback cipher-key option, and the manifest cache and failure-backoff site
     * transients. On a multisite it also deletes the network key and its fallback
     * cipher key; each subsite's own seat is released by its own uninstall() call.
     *
     * OAuth `state` transients are keyed by a random token and cannot be listed
     * through the WordPress API; they expire on their own within 10 minutes.
     *
     * Needs only the autoloader and a ClientConfig: construct the provider and call
     * this, without register(). On a network, run it once per site inside
     * switch_to_blog(), with a new provider for each site.
     *
     * @return array{removed_remotely: bool, error_code: string|null} Whether Nexus
     *         released the seat (true when there was none to release or
     *         `$releaseSeat` is false).
     */
    public function uninstall(bool $releaseSeat = true): array
    {
        $result = ['removed_remotely' => true, 'error_code' => null];
        $this->store->resetCache();

        if ($releaseSeat) {
            try {
                $result = $this->licenseManager->deactivate(self::UNINSTALL_TIMEOUT);
            } catch (Throwable $e) {
                $result = ['removed_remotely' => false, 'error_code' => LicenseErrorCode::UNKNOWN];
            }
        }

        delete_option($this->config->optionKey());
        delete_option($this->cipherOptionKey());

        foreach ($this->pluginUpdater->cacheKeys() as $key) {
            delete_site_transient($key);
        }

        if (is_multisite()) {
            delete_site_option($this->networkLicense->optionKey());
            delete_site_option($this->networkLicense->cipherOptionKey());
            delete_site_option($this->networkLicense->newSitesOptionKey());
        }

        $this->store->resetCache();

        return $result;
    }

    public function config(): ClientConfig
    {
        return $this->config;
    }

    public function licenseManager(): LicenseManager
    {
        return $this->licenseManager;
    }

    public function accountManager(): AccountManager
    {
        return $this->accountManager;
    }

    public function pluginUpdater(): PluginUpdater
    {
        return $this->pluginUpdater;
    }

    public function featureInstaller(): FeatureInstaller
    {
        return $this->featureInstaller;
    }

    public function moduleLoader(): ModuleLoader
    {
        return $this->moduleLoader;
    }

    public function store(): PremiumStore
    {
        return $this->store;
    }

    public function keySource(): KeySource
    {
        return $this->keySource;
    }

    public function networkLicense(): NetworkLicense
    {
        return $this->networkLicense;
    }

    public function autoActivator(): AutoActivator
    {
        return $this->autoActivator;
    }

    public function tierPackageInstaller(): TierPackageInstaller
    {
        return $this->tierPackageInstaller;
    }

    /**
     * The option SodiumEncryptor keeps its fallback key in (used when wp-config
     * has no salts).
     */
    private function cipherOptionKey(): string
    {
        return $this->config->optionKey().'_cipher';
    }

    private function wire(): void
    {
        $this->http = new ApiClient($this->config);
        $this->store = new PremiumStore($this->config);
        $this->featureInstaller = new FeatureInstaller($this->config);

        $this->networkLicense = new NetworkLicense($this->config, $this->pluginBasename, $this->networkEncryptor);
        $this->keySource = new KeySource($this->config, $this->networkLicense);

        $this->licenseClient = new LicenseClient($this->config, $this->http);
        $this->licenseManager = new LicenseManager($this->licenseClient, $this->store, $this->encryptor, $this->keySource);
        $this->autoActivator = new AutoActivator($this->licenseManager, $this->keySource, $this->store);
        $this->pluginUpdater = new PluginUpdater($this->config, $this->licenseClient, $this->licenseManager, $this->pluginBasename, $this->keySource);
        $this->tierPackageInstaller = new TierPackageInstaller($this->config, $this->pluginUpdater, $this->pluginBasename);
        $this->licenseEndpoints = new LicenseEndpoints(
            $this->config,
            $this->licenseManager,
            $this->pluginUpdater,
            $this->featureInstaller,
            $this->keySource,
            $this->autoActivator,
            $this->tierPackageInstaller
        );
        $this->networkLicenseEndpoints = new NetworkLicenseEndpoints(
            $this->config,
            $this->networkLicense,
            $this->keySource,
            $this->licenseManager,
            $this->autoActivator,
            function (): LicenseManager {
                // Inside switch_to_blog(): a fresh store and, by default, a fresh
                // encryptor, so the subsite's own row and fallback key are used.
                return new LicenseManager(
                    $this->licenseClient,
                    new PremiumStore($this->config),
                    $this->hostEncryptor ?? new SodiumEncryptor($this->cipherOptionKey()),
                    $this->keySource
                );
            }
        );
        $this->licenseBootstrap = new LicenseBootstrap($this->config, $this->licenseEndpoints, $this->pluginUpdater, $this->autoActivator, $this->networkLicenseEndpoints);

        $this->accountClient = new AccountClient($this->config, $this->http);
        $this->accountManager = new AccountManager($this->config, $this->accountClient, $this->store, $this->encryptor);
        $this->accountEndpoints = new AccountEndpoints($this->config, $this->accountManager, $this->licenseManager, $this->accountClient);
        // Activating a license, whichever way, ends an account sign-in in progress.
        $this->licenseManager->onActivated([$this->accountManager, 'endSignIn']);
        $this->accountBootstrap = new AccountBootstrap($this->config, $this->accountManager, $this->licenseManager, $this->accountEndpoints);

        $this->moduleLoader = new ModuleLoader($this->config, $this->licenseManager);
    }
}
