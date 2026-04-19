# wp-premium-sdk

Shared PHP SDK for VeronaLabs premium WordPress plugins. One dependency covers:

- Nexus license activation, validation, deactivation
- OAuth account login with Nexus
- Unified `/update/manifest` consumption (plugin + modules at one version)
- License-gated module loading
- Hash-verified module install + teardown

PHP-only. No JS/React. Plugins keep their own admin UIs and wire the SDK in via a small bootstrap.

## Install

```bash
composer require veronalabs/wp-premium-sdk
```

## Bootstrap

```php
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Container\PremiumServiceProvider;

$config = new ClientConfig([
    'product_slug'          => 'wp-statistics',
    'option_key'            => 'wp_statistics_premium',
    'oauth_state_prefix'    => 'wp_statistics_oauth_state_',
    'oauth_callback_params' => ['code' => 'wps_oauth_code', 'state' => 'wps_oauth_state'],
    'api_base_url'          => 'https://nexus.test',
    'text_domain'           => 'wp-statistics-premium',
    'current_version'       => WP_STATISTICS_VERSION,
    'ajax_action'           => 'wp_statistics',
    'modules_path'          => __DIR__ . '/pro/modules',
]);

$provider = new PremiumServiceProvider(
    config: $config,
    pluginBasename: 'wp-statistics-premium/wp-statistics-premium.php',
);

$provider->register();
```

After `register()`:
- `wp_ajax_wp_statistics_license` and `wp_ajax_wp_statistics_account` are wired.
- `pre_set_site_transient_update_plugins` surfaces Nexus updates in wp-admin.
- OAuth callback (`?wps_oauth_code=...&wps_oauth_state=...`) is auto-processed.
- Licensed modules under `pro/modules/<slug>/` with a `manifest.json` boot on `init`.

## Plugin-supplied dependencies

### EncryptorInterface

Defaults to `SodiumEncryptor`, which derives a key from WordPress SALTs (or
persists a random key in `wp_options` under `{option_key}_cipher` as a fallback).
Plugins can pass their own implementation to `PremiumServiceProvider` if they want
to share a key with other encrypted data.

### Module manifest

Each module directory needs a `manifest.json`:

```json
{
  "slug": "reports",
  "version": "8.0.0",
  "namespace": "WP_Statistics\\Pro\\Modules\\Reports",
  "main_class": "Reports"
}
```

The resolved class (`namespace\\main_class`) must have a `boot()` method. It is
only instantiated when the active license grants the module slug as a feature.
