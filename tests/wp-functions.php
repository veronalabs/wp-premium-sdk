<?php

/**
 * Thin WordPress function stubs backed by VeronaLabs\WpPremiumSdk\Tests\WpStub.
 *
 * Loaded once by tests/bootstrap.php. Not a full mock library — just enough
 * surface area for the SDK classes under test.
 */

use VeronaLabs\WpPremiumSdk\Tests\WpStub;

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '') {}

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        WpStub::$filters[$tag][$priority][] = $callback;

        return true;
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args)
    {
        if (empty(WpStub::$filters[$tag])) {
            return $value;
        }

        $byPriority = WpStub::$filters[$tag];
        ksort($byPriority);

        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }

        return $value;
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

if (! function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $existing);
        $merged = array_merge($existing, $args);
        $query = http_build_query($merged);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '').($query ? '?'.$query : '');
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return WpStub::$homeUrl.$path;
    }
}

if (! function_exists('network_home_url')) {
    function network_home_url(string $path = ''): string
    {
        return WpStub::$networkHomeUrl.$path;
    }
}

if (! function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return WpStub::$isMultisite;
    }
}

if (! function_exists('is_plugin_active_for_network')) {
    function is_plugin_active_for_network(string $plugin): bool
    {
        return in_array($plugin, WpStub::$networkActivatedPlugins, true);
    }
}

if (! function_exists('get_plugins')) {
    function get_plugins(): array
    {
        return [];
    }
}

if (! function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return WpStub::$options[$key] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $key, $value, $autoload = null): bool
    {
        WpStub::$options[$key] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $key): bool
    {
        unset(WpStub::$options[$key]);

        return true;
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $key)
    {
        return WpStub::$transients[$key] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $key, $value, int $ttl = 0): bool
    {
        WpStub::$transients[$key] = $value;

        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        unset(WpStub::$transients[$key]);

        return true;
    }
}

if (! function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = [])
    {
        return _sdk_stub_http('GET', $url, $args);
    }
}

if (! function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = [])
    {
        return _sdk_stub_http('POST', $url, $args);
    }
}

if (! function_exists('_sdk_stub_http')) {
    function _sdk_stub_http(string $method, string $url, array $args)
    {
        WpStub::$requestLog[] = ['method' => $method, 'url' => $url, 'args' => $args];

        $next = array_shift(WpStub::$responseQueue);

        if ($next === null) {
            return new WP_Error('no_response_queued', 'No stubbed response queued for '.$method.' '.$url);
        }

        [$status, $body, $headers] = $next;

        if ($body instanceof WP_Error) {
            return $body;
        }

        return [
            'response' => ['code' => $status, 'message' => 'OK'],
            'headers' => $headers,
            'body' => $body,
        ];
    }
}

if (! function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string
    {
        return $response['body'] ?? '';
    }
}

if (! function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response): int
    {
        return (int) ($response['response']['code'] ?? 0);
    }
}
