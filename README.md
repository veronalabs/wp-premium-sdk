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
| OAuth account login | `AccountManager` | One-time sign-in to pick and activate a license; the session is deleted once a license is active. |
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
| `uninstall(bool $releaseSeat = true)` | Releases this site's seat and deletes everything the SDK stored. For the host's `uninstall.php`. |

Admin UIs typically call `$sdk->licenseManager()->getLicenseData()` and `$sdk->licenseManager()->classify()` to render their state.

### Uninstalling

The SDK registers no activation, deactivation or uninstall hooks. Call `uninstall()` from the plugin's own `uninstall.php`; it needs only the autoloader and the config, not `register()`:

```php
// uninstall.php
defined('WP_UNINSTALL_PLUGIN') || exit;

require __DIR__ . '/vendor/autoload.php';

$config = new ClientConfig([/* the same values the plugin boots with */]);
$sdk    = new PremiumServiceProvider($config, 'my-plugin/my-plugin.php');
$sdk->uninstall(); // or uninstall(false) to keep the seat on the account
```

It first tells Nexus this site no longer uses its seat — best-effort, with a 5-second timeout, never throwing — then deletes the option row (license + account), the `{option_key}_cipher` fallback key, and the manifest cache and failure-backoff site transients. It returns `['removed_remotely' => bool, 'error_code' => ?string]`.

OAuth `state` transients cannot be listed through the WordPress API; they expire on their own within 10 minutes.

On a network, run it once per site inside `switch_to_blog()`, building a new provider for each site, since every subsite holds its own license.

---

## Core methods

### `LicenseManager`

```php
$license = $sdk->licenseManager();

$license->activate('KEY-ABCD-1234'); // → public-safe license data array
$license->validate();                // → bool (re-checks with Nexus, with this site's domain)
$license->refreshStatus();           // re-reads status/expiry from Nexus, no domain check
$license->deactivate();              // → ['removed_remotely' => bool, 'error_code' => ?string]

$license->isActivated();             // bool
$license->isValid();                 // bool — active + not expired
$license->classify();                // ['code' => 'active'|'expired'|..., 'days_remaining' => ?int, 'raw_status' => string]
$license->hasFeature('pro-reports'); // bool
$license->getFeatures();             // ['pro-reports', 'api', ...]
$license->getTier();                 // 'pro' | null
$license->getRenewal();              // cached renewal block | null
$license->getLicenseData();          // public snapshot, no raw key
$license->getLicenseKey();           // decrypted raw key — internal use only

$license->refreshSites();            // → bool; fetches the site list (validate with this site's domain)
$license->listSites();               // cached sites, each with this_site
$license->removeSite('other.com');   // releases another site's seat, then refreshes the list
```

Keys are encrypted via the injected `EncryptorInterface` before storage and decrypted on read.

`deactivate()` always removes the license locally. When Nexus could not be told (`removed_remotely: false`), the seat is still taken on the account and `error_code` says why, so the UI can ask the user to remove the site from their account.

A failed check keeps the cached license only when the failure says nothing about the license (network down, server error, unreadable reply, rate limited). When Nexus refuses the key — expired, suspended, revoked, disabled, invalid, wrong product — the refusal is stored and `isValid()` stops passing. See [docs/nexus-license-error-codes.md](docs/nexus-license-error-codes.md).

The `wp_premium_sdk/activation_gate` filter can veto an activation by returning an error string. The SDK then hands the seat back and throws `ActivationVetoedException` (a `RuntimeException`) whose `removedRemotely()` / `rollbackErrorCode()` say whether that worked.

### `AccountManager`

```php
$account = $sdk->accountManager();

$account->getAuthorizeUrl();    // ['authorize_url' => '...', 'state' => '...']
$account->handleOAuthCallback($code, $state, $license);
$account->isConnected();        // bool — a sign-in is in progress and its token has not expired
$account->getAccessToken();     // decrypted Bearer token, or null (none, or expired)
$account->endSignIn();          // revokes the token (best-effort) and ends the sign-in
$account->clearSession();       // ends the sign-in locally only
$account->logout();             // voids session + informs Nexus
$account->consumeFlashError();  // one-shot error message for the UI
$account->setFlashError($msg);
```

The sign-in is a one-time step, not a stored connection:

1. User clicks "Sign in" → JS calls the `init_oauth` AJAX sub-action → receives `authorize_url`.
2. Browser navigates to Nexus.
3. Nexus redirects back with `?{code_param}=...&{state_param}=...`.
4. `AccountBootstrap::handleOAuthCallback()` detects the params on `admin_init` and exchanges the code for a token. With one license it activates it straight away; with several it stores them as a pending choice for the picker.
5. Once a license is activated (here or through `activate_license`), the token is revoked on Nexus (best-effort, 5-second timeout, never failing the activation) and the session — token, user, pending choice, flash error — is deleted. From then on everything runs on the license key.

No refresh token is stored. The session lapses on its own after the token lifetime (Nexus's `expires_in`/`expires_at`, else 24 hours). If Nexus answers 401 or `token_expired` during the picker, the session is cleared and the action fails with `account_expired`, so the UI can ask the user to sign in again.

### `PluginUpdater`

```php
$updater = $sdk->pluginUpdater();

$manifest = $updater->fetchManifest();           // array — cached ~12h
$manifest = $updater->fetchManifest(force: true); // bypass cache and backoff
$updater->flush();                                // invalidate cache and backoff
```

A failed fetch is remembered too: the next attempt waits 1h, then 3h, 6h and at most 12h while failures continue (longer when the server sends `Retry-After`), so a refused or unreachable site does not ask on every update check.

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
$installer->installedModules();     // ['slug' => 'version', ...] — what is on disk
```

Modules are never deleted on deactivate: they ship in the tier ZIP and are gated by the license.

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
| `deactivate` | Removes the local license (modules stay). Returns `removed: []`, `removed_remotely`, `error_code`. |
| `get_status` | Refreshes from Nexus, then returns `is_activated`, `is_valid`, `license` snapshot, `state` (the `classify()` result), `installed_features`. |
| `list_sites` | Fetches the license's sites from Nexus (cached list when it can't be reached, `fresh: false`). Returns `sites` (each with `this_site`), `max_activations`, `activation_count`, `manage_url`. |
| `remove_site` | Body: `domain`. Releases that site's seat with this site's license key, then returns the refreshed `sites`. This site is refused with code `this_site` — use `deactivate`. |
| `check_updates` | Forces a manifest fetch and returns it. |
| `update_feature` | Body: `slug`. Installs the latest version of one licensed module. |
| `install_features` | Installs **all** licensed modules from the latest manifest. |

Every call requires an `_ajax_nonce` of `{ajax_action}_license` and the WP capability `manage_options`.

Errors answer `{code, message}` with HTTP 400, plus `renewal` when Nexus attached one, `retry_after` (seconds) when rate limited, and `removed_remotely` / `rollback_error_code` when the activation gate vetoed an activation.

### Account (`wp_ajax_{prefix}_account`)

| `sub_action` | Purpose |
|---|---|
| `init_oauth` | Returns `authorize_url` + `state`. |
| `logout` | Clears the account session. |
| `get_status` | Returns `connected` (a sign-in is in progress) + any OAuth flash error. |
| `fetch_licenses` | Lists the signed-in user's licenses for the picker. Fails with `account_expired` when the sign-in has lapsed. |
| `activate_license` | Body: `license_key`. Activates it and ends the sign-in. |

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
        'status'            => 'active',      // or expired / suspended / revoked / disabled / invalid
        'error_code'        => '',            // Nexus's code from the last answer, '' when none
        'license_type'      => 'pro',
        'plan_name'         => 'Pro',
        'tier_slug'         => 'pro',
        'expires_at'        => '2027-04-19T00:00:00Z',
        'max_activations'   => 3,
        'activation_count'  => 1,
        'customer_name'     => 'Ada Buyer',
        'customer_email'    => 'buyer@example.com',
        'features'          => ['reports', 'api'],
        'renewal'           => [              // null when Nexus sends none
            'state' => 'active', 'days_remaining' => 120, 'renew_url' => '...', 'offer' => null,
            'subscription_status' => 'active',
            'is_trial' => false, 'trial_ends_at' => null, 'grace_ends_at' => null, 'auto_renews' => true,
        ],
        'license_id'        => 42,            // null on older Nexus
        'manage_url'        => 'https://...', // '' on older Nexus
        'upgrade_url'       => 'https://...',
        'buyer'             => ['name' => 'Ada Buyer', 'email' => 'buyer@example.com'], // or null
        'sites'             => [              // null until Nexus sends a list
            ['id' => 1, 'domain' => 'example.com', 'site_url' => 'https://example.com',
             'is_counted' => true, 'activated_at' => '...', 'last_check_at' => '...'],
        ],
        'site'              => ['active' => true, 'is_counted' => true], // this site's seat, or null
        'activated_at'      => 1713484800,
        'last_validated_at' => 1713484800,    // last attempt to check, answered or not
        'last_success_at'   => 1713484800,    // last time Nexus answered — what the details date from
    ],
    'account' => [                            // only while a sign-in is in progress
        'access_token'   => '<sodium ciphertext>',
        'user'           => ['email' => 'buyer@example.com', 'name' => 'Ada Buyer'],
        'connected_at'   => 1713484800,
        'expires_at'     => 1713571200,
        'pending_choice' => [/* licenses for the picker */],
        'flash_error'    => null,
    ],
]
```

`sites`, `buyer`, `license_id`, `manage_url`, `upgrade_url` and `site` keep their last known value when a reply leaves them out or sends null (Nexus sends `sites` and `buyer` only to a domain activated on the license).

The `get_status` sub-action adds `state`, the `classify()` result: `{code, days_remaining, raw_status}`.

OAuth CSRF state tokens are short-lived transients keyed by `{oauth_state_prefix}{state}` (10-minute TTL).

Manifest responses are cached in a site transient keyed by `wp_premium_sdk_manifest_{product_slug}` (12-hour TTL); failed fetches are tracked in `wp_premium_sdk_manifest_{product_slug}_failure`.

### Which site is licensed

Each installation is licensed on its own `home_url()` (scheme, `www.` and trailing slash dropped, path kept). On a multisite network **every subsite counts as its own site** and uses its own seat, whether the plugin is network-activated or not. `Request::useNetworkLicenceFor()` is deprecated and does nothing.

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
- `LicenseManager` — classify precedence, refusal vs. transient failures, deactivate results, site list, stored details.
- `LicenseEndpoints` / `AccountEndpoints` — AJAX sub-actions through `dispatch()`.
- `PluginUpdater` — manifest failure backoff.
- `PremiumServiceProvider::uninstall()`.

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
