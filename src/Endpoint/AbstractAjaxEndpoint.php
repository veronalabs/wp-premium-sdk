<?php

namespace VeronaLabs\WpPremiumSdk\Endpoint;

use Exception;
use Throwable;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;

/**
 * Base class for WP AJAX action dispatchers.
 *
 * Registers `wp_ajax_{prefix}_{actionName}` and routes the POSTed `sub_action`
 * parameter to a protected method defined by the subclass. Handles JSON response
 * formatting and exception → error response mapping so subclass handlers can
 * just throw on failure.
 */
abstract class AbstractAjaxEndpoint
{
    public function __construct(protected ClientConfig $config) {}

    public function register(): void
    {
        $hook = 'wp_ajax_'.$this->config->ajaxAction().'_'.$this->getActionName();
        add_action($hook, [$this, 'dispatch']);
    }

    public function dispatch(): void
    {
        if (! current_user_can('manage_options')) {
            $this->errorResponse(__('You do not have permission.', $this->config->textDomain()), 'forbidden');

            return;
        }

        check_ajax_referer($this->config->ajaxAction().'_'.$this->getActionName(), 'nonce');

        $subAction = isset($_POST['sub_action']) ? sanitize_text_field(wp_unslash($_POST['sub_action'])) : '';
        $handlers = $this->getSubActions();

        if (! isset($handlers[$subAction])) {
            $this->errorResponse(__('Unknown sub_action.', $this->config->textDomain()), $this->getErrorCode());

            return;
        }

        try {
            $this->{$handlers[$subAction]}();
        } catch (Throwable $e) {
            $this->errorResponse($e->getMessage(), $this->getErrorCode());
        }
    }

    /**
     * Last slug of the ajax hook, e.g. "license" → wp_ajax_{prefix}_license.
     */
    abstract protected function getActionName(): string;

    /**
     * Map of POST sub_action values to protected method names.
     *
     * @return array<string, string>
     */
    abstract protected function getSubActions(): array;

    abstract protected function getErrorCode(): string;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function successResponse(array $data = []): void
    {
        wp_send_json_success($data);
    }

    protected function errorResponse(string $message, string $code = 'error', int $status = 400): void
    {
        wp_send_json_error([
            'code' => $code,
            'message' => $message,
        ], $status);
    }
}
