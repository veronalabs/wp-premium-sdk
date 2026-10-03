<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Support;

use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Container\PremiumServiceProvider;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

/**
 * Builds the SDK the way a host does — one PremiumServiceProvider — and calls its
 * AJAX endpoints through the hooks register() adds, so tests exercise the real
 * wiring. A new provider stands for a new request.
 */
trait BuildsSdk
{
    /**
     * @param  array<string, mixed>  $extra  Extra ClientConfig keys.
     */
    protected function provider(array $extra = []): PremiumServiceProvider
    {
        return new PremiumServiceProvider(new ClientConfig(array_merge([
            'product_slug' => 'wp-statistics',
            'option_key' => SdkFixture::OPTION,
            'oauth_state_prefix' => 'x_',
            'oauth_callback_params' => ['code' => 'c', 'state' => 's'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'td',
            'current_version' => '15.0.0',
        ], $extra)), SdkFixture::PLUGIN);
    }

    /**
     * Dispatch `wp_ajax_wp-statistics_{action}` with a sub_action and body, and
     * return the JSON payload it sent.
     *
     * @param  array<string, mixed>  $body
     * @return array{success: bool, data: mixed, status: int|null}
     */
    protected function ajax(PremiumServiceProvider $provider, string $action, string $subAction, array $body = []): array
    {
        $filters = WpStub::$filters;
        WpStub::$filters = [];
        $provider->register();
        $handlers = WpStub::$filters['wp_ajax_wp-statistics_'.$action][10] ?? [];
        WpStub::$filters = $filters;

        $this->assertNotEmpty($handlers, "No handler registered for {$action}.");

        $_POST = array_merge(['sub_action' => $subAction], $body);
        $count = count(WpStub::$jsonResponses);
        $handlers[0]();
        $_POST = [];

        $this->assertGreaterThan($count, count(WpStub::$jsonResponses), 'The handler sent no response.');

        return WpStub::lastJson();
    }

    /**
     * Queue the two replies an activation reads (activate, then validate).
     *
     * @param  array<string, mixed>  $license
     */
    protected function queueActivation(array $license = []): void
    {
        $license = array_merge(['status' => 'active', 'features' => []], $license);
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
    }

    /**
     * The decoded body of the n-th last request.
     *
     * @return array{url: string, body: array<string, mixed>}
     */
    protected function request(int $fromEnd = 1): array
    {
        $call = WpStub::$requestLog[count(WpStub::$requestLog) - $fromEnd];

        return ['url' => $call['url'], 'body' => (array) json_decode((string) ($call['args']['body'] ?? ''), true)];
    }

    /**
     * The request paths made, in order (e.g. "/api/v1/license/activate").
     *
     * @return array<int, string>
     */
    protected function paths(): array
    {
        return array_map(static function (array $call): string {
            return (string) parse_url($call['url'], PHP_URL_PATH);
        }, WpStub::$requestLog);
    }
}
