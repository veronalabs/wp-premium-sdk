<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;

class ClientConfigTest extends TestCase
{
    public function test_exposes_all_required_fields(): void
    {
        $config = $this->makeConfig();

        $this->assertSame('wp-statistics', $config->productSlug());
        $this->assertSame('wp_statistics_premium', $config->optionKey());
        $this->assertSame('wp_statistics_oauth_state_', $config->oauthStatePrefix());
        $this->assertSame(['code' => 'wps_oauth_code', 'state' => 'wps_oauth_state'], $config->oauthCallbackParams());
        $this->assertSame('https://nexus.test', $config->apiBaseUrl());
        $this->assertSame('wp-statistics-premium', $config->textDomain());
        $this->assertSame('15.0.0', $config->currentVersion());
    }

    public function test_defaults_ajax_action_to_product_slug_when_unset(): void
    {
        $config = $this->makeConfig();

        $this->assertSame('wp-statistics', $config->ajaxAction());
    }

    public function test_respects_explicit_ajax_action(): void
    {
        $config = $this->makeConfig(['ajax_action' => 'wps']);

        $this->assertSame('wps', $config->ajaxAction());
    }

    public function test_defaults_modules_path_to_empty_string(): void
    {
        $config = $this->makeConfig();

        $this->assertSame('', $config->modulesPath());
    }

    public function test_trims_trailing_slash_from_api_base_url(): void
    {
        $config = $this->makeConfig(['api_base_url' => 'https://nexus.test/']);

        $this->assertSame('https://nexus.test', $config->apiBaseUrl());
    }

    public function test_throws_when_required_key_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ClientConfig missing required key: option_key');

        new ClientConfig([
            'product_slug' => 'x',
            'oauth_state_prefix' => 'x_',
            'oauth_callback_params' => ['code' => 'a', 'state' => 'b'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'x',
            'current_version' => '1.0.0',
        ]);
    }

    public function test_throws_when_oauth_callback_params_malformed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('oauth_callback_params');

        new ClientConfig([
            'product_slug' => 'x',
            'option_key' => 'x_opts',
            'oauth_state_prefix' => 'x_',
            'oauth_callback_params' => ['code' => 'only_code'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'x',
            'current_version' => '1.0.0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeConfig(array $overrides = []): ClientConfig
    {
        return new ClientConfig(array_merge([
            'product_slug' => 'wp-statistics',
            'option_key' => 'wp_statistics_premium',
            'oauth_state_prefix' => 'wp_statistics_oauth_state_',
            'oauth_callback_params' => ['code' => 'wps_oauth_code', 'state' => 'wps_oauth_state'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'wp-statistics-premium',
            'current_version' => '15.0.0',
        ], $overrides));
    }
}
