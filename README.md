# WP Premium SDK

> Shared PHP SDK for VeronaLabs premium WordPress plugins.
> One Composer dependency covers license activation, OAuth account login, unified update manifests, hash-verified module install, and license-gated module loading.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue.svg)](LICENSE)

---

## Why

Every premium WordPress plugin needs the same plumbing: check a license key against a central server, let the user connect a SaaS account, fetch the latest plugin + module versions, and install module ZIPs with integrity checks. Doing it once per plugin fragments fixes across codebases.

**WP Premium SDK** puts that plumbing in one PHP-only Composer package, driven entirely by a `ClientConfig` DTO. Plugins supply values (product slug, API URL, option key) and receive a fully wired service graph.

The SDK is the client side of **Nexus** — the VeronaLabs release + licensing server — but is backend-agnostic enough that a different server speaking the same contract would slot in.

---

## Install

```bash
composer require veronalabs/wp-premium-sdk
```

| Dependency | Version |
|---|---|
| PHP | `>=7.4` |
| ext-sodium | any |
| ext-json | any |
| WordPress | 6.0+ (in target plugins) |

---

## Quick start

```php
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Container\PremiumServiceProvider;

$config = new ClientConfig([
    'product_slug'          => 'wp-statistics',
    'option_key'            => 'wp_statistics_premium',
    'oauth_state_prefix'    => 'wp_statistics_oauth_state_',
    'oauth_callback_params' => ['code' => 'wps_oauth_code', 'state' => 'wps_oauth_state'],
    'api_base_url'          => 'https://nexus.veronalabs.com',
    'text_domain'           => 'wp-statistics-premium',
    'current_version'       => WP_STATISTICS_VERSION,
    'ajax_action'           => 'wp_statistics',
    'modules_path'          => __DIR__ . '/pro/modules',
]);

$sdk = new PremiumServiceProvider(
    config:         $config,
    pluginBasename: 'wp-statistics-premium/wp-statistics-premium.php',
);

$sdk->register();
```

After `register()`:

- `wp_ajax_{prefix}_license` and `wp_ajax_{prefix}_account` AJAX actions are live.
- `pre_set_site_transient_update_plugins` is filtered, so Nexus updates surface in **Dashboard → Updates**.
- OAuth callback query params (e.g. `?wps_oauth_code=...&wps_oauth_state=...`) are auto-detected on admin page loads.
- Any module under `pro/modules/<slug>/` with a valid `manifest.json` is booted on `init` — only if the current license grants that slug as a feature.

---

## Features

| Feature | Entry point | Summary |
|---|---|---|
| License activation / validation | `LicenseManager` | Activate, validate, deactivate against the Nexus license API; key is encrypted at rest. |
| OAuth account login | `AccountManager` | User connects their Nexus account; auto-activates their license for this product. |
| Unified update manifest | `PluginUpdater` + `LicenseClient::fetchManifest()` | One call returns plugin + every licensed module at the same version. |
| WordPress-native updates | `PluginUpdater::injectPluginUpdate()` | Updates appear in the standard WP Updates screen. |
| Module installer | `FeatureInstaller` | Signed URL → download → SHA-256 verify → extract to `modules/<slug>/`. |
| License-gated module loading | `ModuleLoader` | Skips modules the license doesn't unlock; boots the rest on `init`. |
| AJAX endpoints | `LicenseEndpoints`, `AccountEndpoints` | Ready-to-consume admin actions for React/JS frontends. |
| Encrypted secrets at rest | `SodiumEncryptor` (pluggable) | Libsodium secretbox, keyed off WP SALTs by default. |
| Single-option storage | `PremiumStore` | All SDK state in one `wp_options` row, split into named sections. |

---

## Configuration — `ClientConfig`

`ClientConfig` is the **only** way the SDK learns anything plugin-specific. Construction is validating — missing required keys throw `InvalidArgumentException`.

### Required keys

| Key | Purpose | Example |
|---|---|---|
| `product_slug` | Matches Nexus `Product.slug`. Used in manifest URL and API payloads. | `wp-statistics` |
| `option_key` | Row name in `wp_options` for all SDK state (license + account sections). | `wp_statistics_premium` |
| `oauth_state_prefix` | Transient key prefix for OAuth CSRF state tokens. | `wp_statistics_oauth_state_` |
| `oauth_callback_params` | Query-param names used when Nexus redirects back. Must contain `code` + `state`. | `['code' => 'wps_oauth_code', 'state' => 'wps_oauth_state']` |
| `api_base_url` | Nexus server base URL (trailing slash optional). | `https://nexus.veronalabs.com` |
| `text_domain` | Plugin text domain for translation helpers. | `wp-statistics-premium` |
| `current_version` | The plugin's installed version — drives "is there an update?" comparisons. | `WP_STATISTICS_VERSION` |

### Optional keys

| Key | Default | Purpose |
|---|---|---|
| `ajax_action` | `product_slug` | AJAX hook prefix. Actions become `wp_ajax_{prefix}_license`, `wp_ajax_{prefix}_account`. |
| `modules_path` | `''` | Absolute path to `pro/modules/`. Required for `ModuleLoader` / `FeatureInstaller`. |

---

## Container — `PremiumServiceProvider`

Builds the full service graph from one `ClientConfig`.

```php
$sdk = new PremiumServiceProvider(
    config:         $config,
    pluginBasename: 'my-plugin/my-plugin.php',
    encryptor:      null, // optional — defaults to SodiumEncryptor
);
```

### Accessors

| Method | Returns |
|---|---|
| `config()` | The `ClientConfig` this instance was built with. |
| `licenseManager()` | `LicenseManager` — the license state machine. |
| `accountManager()` | `AccountManager` — OAuth account flow. |
| `pluginUpdater()` | `PluginUpdater` — manifest cache + WP update injection. |
| `featureInstaller()` | `FeatureInstaller` — ZIP download + extract. |
| `moduleLoader()` | `ModuleLoader` — runtime module discovery. |
| `store()` | `PremiumStore` — low-level section storage. |
| `register()` | Hooks everything into WordPress. Call once per request. |

Admin UIs typically call `$sdk->licenseManager()->getLicenseData()` and `$sdk->accountManager()->isConnected()` to render their state.

---

## Core methods

### `LicenseManager`

```php
$license = $sdk->licenseManager();

$license->activate('KEY-ABCD-1234');       // → public-safe license data array
$license->validate();                      // → bool (re-checks with Nexus)
$license->deactivate();                    // → bool (clears local + informs Nexus)
$license->deactivateAndCleanup($cacheKey); // → ['removed' => [...]]

$license->isActivated();                    // bool
$license->isValid();                        // bool — active + not expired
$license->hasFeature('pro-reports');        // bool
$license->getFeatures();                    // ['pro-reports', 'api', ...]
$license->getLicenseData();                 // public snapshot, no raw key
$license->getLicenseKey();                  // decrypted raw key — internal use only
```

Keys are encrypted via the injected `EncryptorInterface` before storage and decrypted on read.

### `AccountManager`

```php
$account = $sdk->accountManager();

$account->getAuthorizeUrl();    // ['authorize_url' => '...', 'state' => '...']
$account->handleOAuthCallback($code, $state, $license);
$account->isConnected();        // bool
$account->getAccessToken();     // decrypted Bearer token, or null
$account->logout();             // voids session + informs Nexus
$account->consumeFlashError();  // one-shot error message for the UI
$account->setFlashError($msg);
```

Typical flow:

1. User clicks "Connect" → JS calls the `init_oauth` AJAX sub-action → receives `authorize_url`.
2. Browser navigates to Nexus.
3. Nexus redirects back with `?{code_param}=...&{state_param}=...`.
4. `AccountBootstrap::handleOAuthCallback()` detects the params on `admin_init`, exchanges the code for a token, and (best-effort) auto-activates the user's first license for this product.
5. JS refreshes `get_status` AJAX to show the connected state.

### `PluginUpdater`

```php
$updater = $sdk->pluginUpdater();

$manifest = $updater->fetchManifest();           // array — cached ~12h
$manifest = $updater->fetchManifest(force: true); // bypass cache
$updater->flush();                                // invalidate cache
```

`fetchManifest()` returns the Nexus response verbatim:

```php
[
    'success'          => true,
    'update_available' => true,
    'manifest' => [
        'version'     => '8.0.0',
        'released_at' => '2026-04-19T12:00:00+00:00',
        'changelog'   => "### What's new\n- ...",
        'plugin'      => ['slug' => 'wp-statistics-premium', 'version' => '8.0.0', 'url' => '...', 'hash' => '...', 'size' => 123456],
        'modules'     => [
            ['slug' => 'reports', 'version' => '8.0.0', 'url' => '...', 'hash' => '...', 'size' => 45678],
            // only modules the license unlocks
        ],
    ],
]
```

### `FeatureInstaller`

```php
$installer = $sdk->featureInstaller();

$installer->installSingle($asset);  // install one module (from the manifest)
$installer->installMany($assets);   // ['installed' => [...], 'failed' => ['slug' => 'reason']]
$installer->removeAll();            // ['removed' => [...]] — used on deactivate
```

- When `$asset['hash']` is provided, SHA-256 of the downloaded ZIP must match before extraction. Mismatches throw.
- Extraction target: `{modules_path}/{slug}/`. An existing folder at the same slug is replaced.
- ZIPs whose top-level folder doesn't match the slug are normalized (renamed to `{slug}`).

### `ModuleLoader`

```php
$loader = $sdk->moduleLoader();

$loader->register();             // hooks into `init` priority 20
$loader->discover();             // [{slug, version, namespace, main_class, path}, ...]
$loader->loadLicensedModules();  // boots modules whose slug is licensed
```

Each module directory should contain:

```json
{
    "slug":       "reports",
    "version":    "8.0.0",
    "namespace":  "WP_Statistics\\Pro\\Modules\\Reports",
    "main_class": "Reports"
}
```

The resolved class must expose `public function boot(): void`. It is instantiated only when `LicenseManager::hasFeature($slug)` returns `true`.

### `PremiumStore`

Low-level — most consumers won't touch it directly.

```php
$store = $sdk->store();

$store->set('license', [...]);
$store->get('license');             // array|null
$store->delete('license');
$store->clear();                    // wipes everything

$store->setOAuthState('random');    // 10-min CSRF transient
$store->verifyOAuthState('random'); // one-time consume
```

---

## AJAX endpoints

### License (`wp_ajax_{prefix}_license`)

POST `sub_action` values:

| `sub_action` | Purpose |
|---|---|
| `activate` | Body: `license_key`. Activates + stores. |
| `deactivate` | Removes local license + installed modules. |
| `get_status` | Returns `is_activated`, `is_valid`, `license` snapshot. |
| `check_updates` | Forces a manifest fetch and returns it. |
| `update_feature` | Body: `slug`. Installs the latest version of one licensed module. |
| `install_features` | Installs **all** licensed modules from the latest manifest. |

Every call requires an `_ajax_nonce` of `{ajax_action}_license` and the WP capability `manage_options`.

### Account (`wp_ajax_{prefix}_account`)

| `sub_action` | Purpose |
|---|---|
| `init_oauth` | Returns `authorize_url` + `state`. |
| `logout` | Clears the account session. |
| `get_status` | Returns `connected` + any OAuth flash error. |

Nonce: `{ajax_action}_account`. Capability: `manage_options`.

---

## Pluggable encryptor

Default is `SodiumEncryptor`, which:

1. Derives a key from WP SALTs (`AUTH_KEY`, `SECURE_AUTH_KEY`, …) when defined.
2. Falls back to a random key persisted in `wp_options` under `{option_key}_cipher`.

Swap it by implementing `EncryptorInterface`:

```php
use VeronaLabs\WpPremiumSdk\Encryption\EncryptorInterface;

final class MyEncryptor implements EncryptorInterface
{
    public function encrypt(string $plaintext): string { /* ... */ }
    public function decrypt(string $ciphertext): ?string { /* ... */ }
}

$sdk = new PremiumServiceProvider(
    config:         $config,
    pluginBasename: '...',
    encryptor:      new MyEncryptor,
);
```

Useful when a plugin already ships an encryptor you want to reuse.

---

## Data model

Everything the SDK stores lives in one `wp_options` row (keyed by `ClientConfig::optionKey()`), split into two sections:

```php
[
    'license' => [
        'license_key'       => '<sodium ciphertext>',
        'status'            => 'active',
        'license_type'      => 'pro',
        'plan_name'         => 'Pro',
        'expires_at'        => '2027-04-19T00:00:00Z',
        'max_activations'   => 3,
        'activation_count'  => 1,
        'customer_name'     => 'Jane Doe',
        'customer_email'    => 'jane@example.com',
        'features'          => ['reports', 'api'],
        'activated_at'      => 1713484800,
        'last_validated_at' => 1713484800,
    ],
    'account' => [
        'access_token'  => '<sodium ciphertext>',
        'refresh_token' => '<sodium ciphertext|null>',
        'user'          => ['email' => 'jane@example.com', 'name' => 'Jane Doe'],
        'connected_at'  => 1713484800,
        'flash_error'   => null,
    ],
]
```

OAuth CSRF state tokens are short-lived transients keyed by `{oauth_state_prefix}{state}` (10-minute TTL).

Manifest responses are cached in a site transient keyed by `wp_premium_sdk_manifest_{product_slug}` (12-hour TTL).

---

## Testing

```bash
composer install
composer test
```

The SDK ships with a thin WordPress function stub layer (`tests/WpStub.php`) so unit tests run without a full WP install. Current coverage:

- `ClientConfig` — required keys, defaults, validation failures.
- `SodiumEncryptor` — round-trip, ciphertext uniqueness, tamper detection, fallback key persistence.
- `PremiumStore` — section isolation, one-time OAuth state, clear semantics.
- `ApiClient` — URL building, query string, JSON body, error mapping, local-TLD SSL bypass.
- `LicenseClient` — activate payload shape, manifest endpoint URL + bearer header + optional `current_version`.

Add your own tests under `tests/Unit/<area>/` — queue fake HTTP responses with `WpStub::queueJson()` / `WpStub::queueError()` between calls.

---

## Versioning

This package follows [SemVer](https://semver.org/). Pre-1.0 releases are published as `v1.0.0-beta.N` tags on GitHub; they may introduce breaking changes.

Track a released version:

```json
"require": {
    "veronalabs/wp-premium-sdk": "^1.0@beta"
}
```

Or pin to a specific beta for reproducibility:

```json
"require": {
    "veronalabs/wp-premium-sdk": "1.0.0-beta.1"
}
```

---

## License

[GPL-2.0+](LICENSE) — same license family as WordPress.

---

## Related projects

- **Nexus** — the Laravel-based release + licensing server this SDK consumes.
- **wp-statistics-premium** — reference consumer, adopts the SDK through its `PremiumServiceProvider`.
- **wp-sms-premium** — adopts the SDK for unified licensing + updates.
