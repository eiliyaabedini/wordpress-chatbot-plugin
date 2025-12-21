<?php
/**
 * Messaging Platform Manager
 *
 * Factory/Registry for managing messaging platform integrations
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Manages messaging platform registrations and provides access to platform instances
 */
class Chatbot_Messaging_Manager {

    /**
     * Singleton instance
     *
     * @var Chatbot_Messaging_Manager|null
     */
    private static $instance = null;

    /**
     * Registered platform classes
     *
     * @var array<string, string>
     */
    private $platforms = array();

    /**
     * Platform instances (lazy-loaded)
     *
     * @var array<string, Chatbot_Messaging_Platform>
     */
    private $instances = array();

    /**
     * Get singleton instance
     *
     * @return Chatbot_Messaging_Manager
     */
    public static function get_instance(): Chatbot_Messaging_Manager {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - registers built-in platforms
     */
    private function __construct() {
        // Register REST API routes
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Register AJAX handlers for all platforms
        add_action('wp_ajax_chatbot_platform_connect', array($this, 'ajax_connect'));
        add_action('wp_ajax_chatbot_platform_disconnect', array($this, 'ajax_disconnect'));
        add_action('wp_ajax_chatbot_platform_test', array($this, 'ajax_test'));
    }

    /**
     * Register a messaging platform
     *
     * @param string $platform_id Unique platform identifier
     * @param string $class_name Fully qualified class name extending Chatbot_Messaging_Platform
     * @return bool Success status
     */
    public function register_platform(string $platform_id, string $class_name): bool {
        if (isset($this->platforms[$platform_id])) {
            chatbot_log('WARNING', 'messaging_manager', "Platform already registered: {$platform_id}");
            return false;
        }

        if (!class_exists($class_name)) {
            chatbot_log('ERROR', 'messaging_manager', "Platform class not found: {$class_name}");
            return false;
        }

        if (!is_subclass_of($class_name, 'Chatbot_Messaging_Platform')) {
            chatbot_log('ERROR', 'messaging_manager', "Class does not extend Chatbot_Messaging_Platform: {$class_name}");
            return false;
        }

        $this->platforms[$platform_id] = $class_name;
        chatbot_log('INFO', 'messaging_manager', "Registered platform: {$platform_id}");

        return true;
    }

    /**
     * Get a platform instance
     *
     * @param string $platform_id The platform identifier
     * @return Chatbot_Messaging_Platform|null
     */
    public function get_platform(string $platform_id): ?Chatbot_Messaging_Platform {
        if (!isset($this->platforms[$platform_id])) {
            return null;
        }

        // Lazy-load the instance
        if (!isset($this->instances[$platform_id])) {
            $class_name = $this->platforms[$platform_id];
            $this->instances[$platform_id] = new $class_name();
        }

        return $this->instances[$platform_id];
    }

    /**
     * Get all registered platform IDs
     *
     * @return array<string>
     */
    public function get_registered_platforms(): array {
        return array_keys($this->platforms);
    }

    /**
     * Get all platform instances
     *
     * @return array<string, Chatbot_Messaging_Platform>
     */
    public function get_all_platforms(): array {
        $platforms = array();
        foreach (array_keys($this->platforms) as $platform_id) {
            $platforms[$platform_id] = $this->get_platform($platform_id);
        }
        return $platforms;
    }

    /**
     * Check if a platform is registered
     *
     * @param string $platform_id The platform identifier
     * @return bool
     */
    public function has_platform(string $platform_id): bool {
        return isset($this->platforms[$platform_id]);
    }

    /**
     * Register REST API routes for all platforms
     */
    public function register_rest_routes(): void {
        // Generic webhook endpoint that routes to the appropriate platform
        register_rest_route('chatbot-plugin/v1', '/webhook/(?P<platform>[a-z]+)/(?P<config_id>\d+)', array(
            'methods' => array('GET', 'POST'),
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true',
            'args' => array(
                'platform' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return $this->has_platform($param);
                    }
                ),
                'config_id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && intval($param) > 0;
                    }
                )
            )
        ));

        // Keep legacy Telegram endpoint for backward compatibility
        register_rest_route('chatbot-plugin/v1', '/telegram-webhook/(?P<config_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_legacy_telegram_webhook'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * Handle incoming webhook from any platform
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_webhook(WP_REST_Request $request): WP_REST_Response {
        $platform_id = $request->get_param('platform');
        $platform = $this->get_platform($platform_id);

        if (!$platform) {
            return new WP_REST_Response(array('error' => 'Unknown platform'), 404);
        }

        return $platform->handle_webhook($request);
    }

    /**
     * Handle legacy Telegram webhook for backward compatibility
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_legacy_telegram_webhook(WP_REST_Request $request): WP_REST_Response {
        $platform = $this->get_platform('telegram');

        if (!$platform) {
            return new WP_REST_Response(array('error' => 'Telegram platform not registered'), 500);
        }

        return $platform->handle_webhook($request);
    }

    /**
     * AJAX handler for connecting a platform
     */
    public function ajax_connect(): void {
        $platform_id = isset($_POST['platform']) ? sanitize_text_field($_POST['platform']) : '';
        $nonce_action = "chatbot_{$platform_id}_connect";

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], $nonce_action) || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized request'));
            return;
        }

        $platform = $this->get_platform($platform_id);

        if (!$platform) {
            wp_send_json_error(array('message' => 'Unknown platform: ' . $platform_id));
            return;
        }

        $config_id = isset($_POST['config_id']) ? intval($_POST['config_id']) : 0;

        if (!$config_id) {
            wp_send_json_error(array('message' => 'Missing configuration ID'));
            return;
        }

        // Get credentials from POST data (platform-specific)
        $credentials = isset($_POST['credentials']) ? $_POST['credentials'] : array();

        // Sanitize credentials
        if (is_array($credentials)) {
            array_walk_recursive($credentials, function(&$value) {
                $value = sanitize_text_field($value);
            });
        }

        // Validate credentials
        $validation = $platform->validate_credentials($credentials);

        if ($validation === false) {
            wp_send_json_error(array('message' => 'Invalid credentials'));
            return;
        }

        // Connect the platform
        $result = $platform->connect($config_id, $credentials);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => $result['error'] ?? 'Connection failed'));
        }
    }

    /**
     * AJAX handler for disconnecting a platform
     */
    public function ajax_disconnect(): void {
        $platform_id = isset($_POST['platform']) ? sanitize_text_field($_POST['platform']) : '';
        $nonce_action = "chatbot_{$platform_id}_disconnect";

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], $nonce_action) || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized request'));
            return;
        }

        $platform = $this->get_platform($platform_id);

        if (!$platform) {
            wp_send_json_error(array('message' => 'Unknown platform: ' . $platform_id));
            return;
        }

        $config_id = isset($_POST['config_id']) ? intval($_POST['config_id']) : 0;

        if (!$config_id) {
            wp_send_json_error(array('message' => 'Missing configuration ID'));
            return;
        }

        $result = $platform->disconnect($config_id);

        if ($result) {
            wp_send_json_success(array('message' => ucfirst($platform_id) . ' disconnected successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to disconnect'));
        }
    }

    /**
     * AJAX handler for testing platform credentials
     */
    public function ajax_test(): void {
        $platform_id = isset($_POST['platform']) ? sanitize_text_field($_POST['platform']) : '';
        $nonce_action = "chatbot_{$platform_id}_test";

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], $nonce_action) || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized request'));
            return;
        }

        $platform = $this->get_platform($platform_id);

        if (!$platform) {
            wp_send_json_error(array('message' => 'Unknown platform: ' . $platform_id));
            return;
        }

        // Get credentials from POST data
        $credentials = isset($_POST['credentials']) ? $_POST['credentials'] : array();

        // Sanitize credentials
        if (is_array($credentials)) {
            array_walk_recursive($credentials, function(&$value) {
                $value = sanitize_text_field($value);
            });
        }

        $validation = $platform->validate_credentials($credentials);

        if ($validation === false) {
            wp_send_json_error(array('message' => 'Invalid credentials'));
        } else {
            wp_send_json_success(array(
                'message' => 'Connection successful!',
                'info' => $validation
            ));
        }
    }

    /**
     * Get connected platforms for a configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return array<string, array> Array of connected platforms with their status
     */
    public function get_connected_platforms(int $config_id): array {
        $connected = array();

        foreach ($this->get_all_platforms() as $platform_id => $platform) {
            if ($platform->is_connected($config_id)) {
                $connected[$platform_id] = array(
                    'name' => $platform->get_platform_name(),
                    'icon' => $platform->get_platform_icon(),
                    'credentials' => $platform->get_stored_credentials($config_id)
                );
            }
        }

        return $connected;
    }

    /**
     * Disconnect all platforms for a configuration (used when deleting config)
     *
     * @param int $config_id The chatbot configuration ID
     */
    public function disconnect_all(int $config_id): void {
        foreach ($this->get_all_platforms() as $platform_id => $platform) {
            if ($platform->is_connected($config_id)) {
                $platform->disconnect($config_id);
                chatbot_log('INFO', 'messaging_manager', "Disconnected {$platform_id} for config {$config_id}");
            }
        }
    }
}

/**
 * Initialize the messaging manager and get instance
 *
 * @return Chatbot_Messaging_Manager
 */
function chatbot_messaging_manager(): Chatbot_Messaging_Manager {
    return Chatbot_Messaging_Manager::get_instance();
}
