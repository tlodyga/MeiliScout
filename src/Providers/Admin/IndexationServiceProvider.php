<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers\Admin;

use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\Indexer;
use Pollora\MeiliScout\Services\IndexingLogger;
use WP_REST_Request;

use function Pollora\MeiliScout\get_template_part;

/**
 * Service provider for managing Meilisearch indexation in the WordPress admin.
 */
class IndexationServiceProvider extends ServiceProvider
{
    /**
     * Registers the service provider's hooks and actions.
     *
     * @return void
     */
    public function register()
    {
        if (! ClientFactory::isConfigured()) {
            return;
        }
        add_action('admin_menu', [$this, 'addIndexationMenu']);
        add_action('admin_post_meiliscout_indexation', [$this, 'handleIndexation']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('meiliscout_process_indexation', [$this, 'processIndexation']);
    }

    /**
     * Registers REST API routes for indexation status.
     *
     * @return void
     */
    public function registerRestRoutes()
    {
        register_rest_route('meiliscout/v1', '/indexation-status', [
            'methods' => 'GET',
            'callback' => [$this, 'getIndexationStatus'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'token' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        // Validate token format: 32 alphanumeric characters
                        return empty($value) || preg_match('/^[a-zA-Z0-9]{32}$/', $value);
                    },
                ],
            ],
        ]);

        register_rest_route('meiliscout/v1', '/indexation-token', [
            'methods' => 'GET',
            'callback' => [$this, 'getCurrentToken'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Returns the current indexation status.
     *
     * @param WP_REST_Request $request The REST request
     * @return \WP_REST_Response
     */
    public function getIndexationStatus(WP_REST_Request $request)
    {
        $token = $request->get_param('token');
        $logger = IndexingLogger::getInstance();

        // If token provided, validate and get specific log
        if (! empty($token)) {
            if (! $logger->isValidToken($token)) {
                return rest_ensure_response([
                    'error' => 'Invalid or expired token',
                    'status' => 'error',
                ]);
            }

            $log = $logger->getLogByToken($token);
            if ($log === null) {
                return rest_ensure_response([
                    'error' => 'Log not found',
                    'status' => 'error',
                ]);
            }

            return rest_ensure_response($log);
        }

        // No token provided, try to get current session log
        $log = $logger->getCurrentLog();
        if ($log !== null) {
            return rest_ensure_response($log);
        }

        // No active session, return empty response
        return rest_ensure_response([
            'status' => 'idle',
            'entries' => [],
        ]);
    }

    /**
     * Returns the current session token if one exists.
     *
     * @return \WP_REST_Response
     */
    public function getCurrentToken()
    {
        $logger = IndexingLogger::getInstance();
        $token = $logger->getToken();

        return rest_ensure_response([
            'token' => $token,
            'has_active_session' => $token !== null,
        ]);
    }

    /**
     * Handles the indexation form submission.
     *
     * @return void
     */
    public function handleIndexation()
    {
        if (! isset($_POST['meiliscout_indexation_nonce']) ||
            ! wp_verify_nonce($_POST['meiliscout_indexation_nonce'], 'meiliscout_indexation_action')) {
            return;
        }

        if (! current_user_can('manage_options')) {
            return;
        }

        // Initialize log with waiting message and get the token
        $indexer = new Indexer();
        $token = $indexer->initializeLog();
        $indexer->log('info', 'Indexation will start soon...');

        // Schedule indexation event
        if (! wp_next_scheduled('meiliscout_process_indexation')) {
            wp_schedule_single_event(time() + 10, 'meiliscout_process_indexation', [
                [
                    'clear_indices' => isset($_POST['clear_indices']),
                    'index_posts' => isset($_POST['index_posts']),
                    'index_taxonomies' => isset($_POST['index_taxonomies']),
                ],
            ]);
        }

        // Redirect with token so the UI can poll for status
        wp_redirect(admin_url('admin.php?page=meiliscout-indexation&token=' . urlencode($token)));
        exit;
    }

    /**
     * Processes the actual indexation.
     *
     * @param  array  $options  Indexation options
     * @return void
     */
    public function processIndexation($options)
    {
        $indexer = new Indexer;
        $indexer->index($options['clear_indices']);
    }

    /**
     * Renders the indexation page.
     *
     * @return void
     */
    public function renderIndexationPage()
    {
        $logger = IndexingLogger::getInstance();

        // Get token from URL or current session
        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : null;
        if ($token && ! $logger->isValidToken($token)) {
            $token = null; // Invalid token, ignore it
        }

        // If no valid token in URL, try to get current session token
        if (! $token) {
            $token = $logger->getToken();
        }

        // Get current log data
        $lastLog = $token ? $logger->getLogByToken($token) : null;

        get_template_part('indexation', [
            'last_log' => $lastLog ?? ['status' => 'idle', 'entries' => []],
            'token' => $token,
        ]);
    }

    /**
     * Adds the indexation submenu page to WordPress admin.
     *
     * @return void
     */
    public function addIndexationMenu()
    {
        add_submenu_page(
            'meiliscout-settings',
            'Indexation',
            'Indexation',
            'manage_options',
            'meiliscout-indexation',
            [$this, 'renderIndexationPage']
        );
    }
}
