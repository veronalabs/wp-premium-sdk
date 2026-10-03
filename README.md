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
| `license_key_constant` | `''` | Name of a wp-config constant that may hold the license key, e.g. `WP_STATISTICS_LICENSE_KEY`. See [Managed keys](#managed-keys). |
| `installed_tier` | `''` | Tier of the build that is installed (`basic`, `pro` …). Needed for `tier_mismatch` and `install_tier_package`. |

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
| `keySource()` | `KeySource` — where the key comes from (`constant`, `network`, `manual`). |
| `networkLicense()` | `NetworkLicense` — the network-wide key of a network-activated plugin. |
| `autoActivator()` | `AutoActivator` — activates with a constant or network key on `admin_init`. |
| `tierPackageInstaller()` | `TierPackageInstaller` — installs the licensed tier's package. |
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

It first tells Nexus this site no longer uses its seat — best-effort, with a 5-second timeout, never throwing — then deletes the option row (license + account), the `{option_key}_cipher` fallback key, and the manifest cache and failure-backoff site transients. On a multisite it also deletes the network key (`{option_key}_network`) and its `{option_key}_network_cipher` fallback key. It returns `['removed_remotely' => bool, 'error_code' => ?string]`.

OAuth `state` transients cannot be listed through the WordPress API; they expire on their own within 10 minutes.

On a network, run it once per site inside `switch_to_blog()`, building a new provider for each site, since every subsite holds its own license.

---

## Core methods

### `LicenseManager`

```php
$license = $sdk->licenseManager();

$license->activate('KEY-ABCD-1234'); // → public-safe license data array (refused while the key is managed elsewhere)
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

$license->getSource();               // 'manual' | 'constant' | 'network' | null
$license->domainChange();            // ['was' => 'example.com', 'now' => 'staging.example.com'] | null
$license->moveLicense();             // activate here, then release the old domain (see move_license)
```

Keys are encrypted via the injected `EncryptorInterface` before storage and decrypted on read.

`deactivate()` always removes the license locally. When Nexus could not be told (`removed_remotely: false`), the seat is still taken on the account and `error_code` says why, so the UI can ask the user to remove the site from their account.

A failed check keeps the cached license only when the failure says nothing about the license (network down, server error, unreadable reply, rate limited). When Nexus refuses the key — expired, suspended, revoked, disabled, invalid, wrong product — the refusal is stored and `isValid()` stops passing. See [docs/nexus-license-error-codes.md](docs/nexus-license-error-codes.md).

The `wp_premium_sdk/activation_gate` filter can veto an activation by returning an error string. The SDK then hands the seat back and throws `ActivationVetoedException` (a `RuntimeException`) whose `removedRemotely()` / `rollbackErrorCode()` say whether that worked.

`activate()` stores `activated_domain`, the normalised domain it activated. When `home_url()` later normalises to something else — a staging copy cloned from production, or a site moved to a new address — `domainChange()` reports both. `moveLicense()` activates the current domain first and releases the old one only once that worked. Whether the new domain uses a seat is Nexus's call (`license.site.is_counted` in the activate reply); by default the old domain is released only when the new one is counted, so a development copy never cuts production off.

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

Every way out of the picker deletes the session: activating a license by any path (the provider hooks `endSignIn()` to `LicenseManager::onActivated()`, so a key typed on the license page, a wp-config constant or a network key ends it too), signing out, and the session passing its `expires_at` — checked on every read, so an abandoned picker stops counting without waiting for a 401.

The exchange sends `device_name` (this site's domain). The session stores `user_id`, the WordPress user who started it; only that user sees it. Any other admin gets `connected: false` and no user from `get_status` (the normal activation screen), and `fetch_licenses`, `activate_license` and `logout` answer `sign_in_other_user`.

No refresh token is stored. The session lapses on its own after the token lifetime (Nexus's `expires_in`/`expires_at`, else 24 hours). If Nexus answers 401 or `token_expired` during the picker, the session is cleared and the action fails with `account_expired`, so the UI can ask the user to sign in again.

### `PluginUpdater`

```php
$updater = $sdk->pluginUpdater();

$manifest = $updater->fetchManifest();           // array — cached ~12h
$manifest = $updater->fetchManifest(force: true); // bypass cache and backoff
$updater->flush();                                // invalidate cache and backoff
```

On a network-activated plugin the check is network-wide, like the plugin files and the update transient: it uses the network (or wp-config) key and counts as licensed when the main site's activation is valid, whichever subsite triggers it. A subsite without a seat cannot make the update disappear for the network. Single sites and plugins activated site by site use their own license as before.

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

## Managed keys

### From a wp-config constant

Agencies setting up many sites can put the key in wp-config instead of visiting each license page:

```php
// The host passes the constant's name:
new ClientConfig([/* … */ 'license_key_constant' => 'WP_STATISTICS_LICENSE_KEY']);

// wp-config.php on each site:
define('WP_STATISTICS_LICENSE_KEY', 'XXXX-XXXX-XXXX-XXXX');
```

On `admin_init`, when the constant is defined and the site has no license, a different key, or a license activated on another domain, the SDK activates the constant's key. A failure waits 1 hour, then 6 hours, then 24 hours between tries (longer when Nexus sends `Retry-After`); a new key starts over. `get_status` reports `source: "constant"` and the last failure as `auto_activation: {attempts, last_attempt_at, retry_at, error_code, source}`. `activate` and `deactivate` refuse with `key_from_constant`. Deleting the constant removes the license on the next admin load and releases the seat. The key is never logged, echoed or kept in the retry record.

### On a network-activated plugin

Every subsite still counts as its own site (see [Which site is licensed](#which-site-is-licensed)); what changes is that the network admin enters the key once:

- The key is stored network-wide in the site option `{option_key}_network`, encrypted. Salts are shared across a network, so every subsite can read it; without salts the fallback cipher key lives in the site option `{option_key}_network_cipher`.
- Each subsite activates on its own `home_url()` with its own seat and keeps its activation in its own option row.
- **Seats are not handed out in visit order.** A subsite activates itself on admin load only when the license has a free seat for every subsite still waiting (or is unlimited), or — for a subsite created after the key was entered (`wp_initialize_site`) — while any seat is left. While nobody knows the seat count yet, only the main site tries. Otherwise nothing happens on its own: the network admin chooses with `activate_all` or `activate_sites`.
- Subsite license pages are view-only and carry no seat notice: `get_status` reports `context: "network"`, `is_network_managed: true` and `auto_activation: null`; an unseated subsite is simply `not_activated`. `activate`, `deactivate`, `remove_site` and `move_license` refuse with `network_managed`. The shortfall is shown in Network Admin only.
- Updates are network-wide: see [`PluginUpdater`](#pluginupdater).
- Migration: when the plugin becomes network-activated and no network key exists yet, the main site's stored key is adopted (on the main site or in Network Admin).
- When the network admin removes the key, the main site's license goes at once and each other subsite's on its next admin load, releasing its seat. When the plugin is network-deactivated instead, subsites keep their licenses as their own.
- A wp-config constant still wins over the network key.

The host's Network Admin page talks to `wp_ajax_{prefix}_network_license` (below).

---

## AJAX endpoints

### License (`wp_ajax_{prefix}_license`)

POST `sub_action` values:

| `sub_action` | Purpose |
|---|---|
| `activate` | Body: `license_key`. Activates + stores. |
| `deactivate` | Removes the local license (modules stay). Returns `removed: []`, `removed_remotely`, `error_code`. |
| `get_status` | Refreshes from Nexus, then returns `is_activated`, `is_valid`, `license` snapshot, `state` (the `classify()` result), `installed_features`, `source` (`manual` / `constant` / `network`), `auto_activation` (last failed automatic activation or null; always null on a network-managed subsite), `context` (`site` / `network`), `is_network_managed`, `domain_changed` (`{was, now}` or null) and `tier_mismatch` (`{installed, licensed}` from the cached manifest, or null). |
| `list_sites` | Fetches the license's sites from Nexus (cached list when it can't be reached, `fresh: false`). Returns `sites` (each with `this_site`), `max_activations`, `activation_count`, `manage_url`. |
| `remove_site` | Body: `domain`. Releases that site's seat with this site's license key, then returns the refreshed `sites`. This site is refused with code `this_site` — use `deactivate`. |
| `move_license` | Activates the current domain, then releases the old one (see `domain_changed`). Optional body `release_old` (`1`/`0`) overrides the default, which releases only when Nexus counts the new domain. Returns `activated: {domain, is_counted}`, `released: {domain, attempted, removed_remotely, error_code}`, `license`. A failed activation is an error and leaves the old domain's seat alone. Refused with `domain_unchanged` when there is nothing to move. |
| `install_tier_package` | Needs `install_plugins` too. Fetches the manifest fresh (without `current_version`, so the package comes back even when the version is current); when its `tier_slug` differs from `installed_tier`, installs its package over the plugin with `Plugin_Upgrader`, then clears the cached manifest. Returns `installed`, `installed_tier`, `licensed_tier`, `version`, `plugin_file`. Errors: `file_mods_disabled`, `filesystem_credentials_needed` (never prompts), `installed_tier_unknown`, `licensed_tier_unknown`, `package_unavailable`, `install_failed`, `rate_limited` (while a server-requested wait runs) or Nexus's code. |
| `check_updates` | Forces a manifest fetch and returns it. |
| `update_feature` | Body: `slug`. Installs the latest version of one licensed module. |
| `install_features` | Installs **all** licensed modules from the latest manifest. |

Every call requires an `_ajax_nonce` of `{ajax_action}_license` and the WP capability `manage_options`.

Errors answer `{code, message}` with HTTP 400, plus `renewal` when Nexus attached one, `retry_after` (seconds) when rate limited, and `removed_remotely` / `rollback_error_code` when the activation gate vetoed an activation.

### Network license (`wp_ajax_{prefix}_network_license`)

Registered on multisite only. Nonce `{ajax_action}_network_license`; capability `manage_network_options`.

| `sub_action` | Purpose |
|---|---|
| `get_status` | `context: "network"`, `is_network_activated`, `source`, `has_key`, `license_key_masked`, `updated_at`; the seat summary `subsites_total`, `subsites_active`, `subsites_waiting`, `seats_max` (0 = unlimited, null = not known yet), `seats_left` (null when unlimited or unknown); and `subsites` (up to 500): `blog_id`, `domain`, `holds_seat`, `is_activated`, `status`, `error_code`, `source`, `site`, `last_success_at`, `auto_activation_error`, `retry_at`. Read from each subsite's row, without asking Nexus. |
| `activate_all` | Activates every subsite still waiting, each inside `switch_to_blog()` on its own address. Refused with `not_enough_seats` plus `needed` and `left` when the seats cannot cover them all. Returns the seat summary and `results`: `{blog_id, domain, activated, error_code, is_counted}` per subsite; one failure does not stop the rest. |
| `activate_sites` | Body: `blog_ids` (array or comma-separated). Same as `activate_all` for the picked subsites only. |
| `save_key` | Body: `license_key`. Activates the main site with it, then stores it network-wide. A refused key (invalid, expired, wrong product …) is not stored; a key with no seat left, or an unreachable server, is stored and `main_site_error_code` says why. |
| `remove_key` | Deletes the network key and deactivates the main site; other subsites follow on their next admin load. |

`save_key` and `remove_key` refuse with `key_from_constant` when the wp-config constant is set, and with `not_network_activated` when the plugin is not network-activated.

### Account (`wp_ajax_{prefix}_account`)

| `sub_action` | Purpose |
|---|---|
| `init_oauth` | Returns `authorize_url` + `state`. |
| `logout` | Clears the account session. |
| `get_status` | Returns `connected` (a sign-in is in progress) + any OAuth flash error. |
| `fetch_licenses` | Lists the signed-in user's licenses for the picker. Fails with `account_expired` when the sign-in has lapsed, `sign_in_other_user` when another admin started it. |
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
        'source'            => 'manual',      // or 'constant' / 'network': where the key came from
        'activated_domain'  => 'example.com', // normalised domain it was activated on
        'last_validated_at' => 1713484800,    // last attempt to check, answered or not
        'last_success_at'   => 1713484800,    // last time Nexus answered — what the details date from
    ],
    'auto_activation' => [                    // only while an automatic activation keeps failing
        'fingerprint'     => 'a1b2c3d4e5f60718', // hash prefix telling a new key from the same one
        'source'          => 'constant',
        'attempts'        => 2,
        'last_attempt_at' => 1713484800,
        'retry_at'        => 1713506400,
        'error_code'      => 'activation_limit_reached',
    ],
    'account' => [                            // only while a sign-in is in progress
        'user_id'        => 1,                // the WordPress user who started it; only they see it
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

A network-activated plugin also keeps the network key in the site option `{option_key}_network` (`['license_key' => '<ciphertext>', 'updated_at' => int]`) and the ids of subsites created since in `{option_key}_network_new_sites`.

Manifest responses are cached in a site transient keyed by `wp_premium_sdk_manifest_{product_slug}` (12-hour TTL); failed fetches are tracked in `wp_premium_sdk_manifest_{product_slug}_failure`.

### Which site is licensed

Each installation is licensed on its own `home_url()` (scheme, `www.` and trailing slash dropped, path kept). On a multisite network **every subsite counts as its own site** and uses its own seat, whether the plugin is network-activated or not. `Request::useNetworkLicenceFor()` is deprecated and does nothing. A network-activated plugin shares one key across the network (see [Managed keys](#managed-keys)), but each subsite still activates it on its own address and seat.

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
