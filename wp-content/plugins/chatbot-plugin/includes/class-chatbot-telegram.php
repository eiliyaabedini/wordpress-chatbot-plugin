<?php
/**
 * Chatbot Telegram Integration
 *
 * Handles Telegram bot integration for chatbot configurations
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_Telegram {

    private static $instance = null;

    /**
     * Telegram API base URL
     */
    private $api_base = 'https://api.telegram.org/bot';

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        // Register REST API endpoint for webhook
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));

        // Register AJAX handlers
        add_action('wp_ajax_chatbot_telegram_connect', array($this, 'ajax_connect'));
        add_action('wp_ajax_chatbot_telegram_disconnect', array($this, 'ajax_disconnect'));
        add_action('wp_ajax_chatbot_telegram_test', array($this, 'ajax_test'));
        add_action('wp_ajax_chatbot_telegram_poll', array($this, 'ajax_poll_now'));

        // Unschedule any old polling cron
        $timestamp = wp_next_scheduled('chatbot_telegram_poll_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'chatbot_telegram_poll_cron');
        }
    }

    /**
     * Register REST API endpoint for Telegram webhook
     */
    public function register_webhook_endpoint() {
        register_rest_route('chatbot-plugin/v1', '/telegram-webhook/(?P<config_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * Validate a Telegram bot token by calling getMe
     *
     * @param string $token The bot token
     * @return array|false Bot info on success, false on failure
     */
    public function validate_token($token) {
        if (empty($token)) {
            return false;
        }

        $response = wp_remote_get($this->api_base . $token . '/getMe', array(
            'timeout' => 10
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['ok']) || $body['ok'] !== true) {
            return false;
        }

        return $body['result'];
    }

    /**
     * Generate a secure secret token for webhook verification
     */
    public function generate_secret_token($config_id) {
        return wp_generate_password(32, false);
    }

    /**
     * Check if site is running on localhost
     */
    public function is_localhost() {
        $site_url = site_url();
        $host = parse_url($site_url, PHP_URL_HOST);

        $localhost_patterns = array('localhost', '127.0.0.1', '::1', '0.0.0.0');

        if (in_array($host, $localhost_patterns)) {
            return true;
        }

        if (preg_match('/\.(local|localhost|ddev\.site|test|dev|example)$/i', $host)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register webhook with Telegram
     */
    public function register_webhook($config_id, $bot_token) {
        $webhook_url = rest_url("chatbot-plugin/v1/telegram-webhook/{$config_id}");

        if ($this->is_localhost()) {
            return array(
                'success' => false,
                'error' => 'Telegram webhooks require a publicly accessible URL. Your site is running on localhost.'
            );
        }

        if (strpos($webhook_url, 'https://') !== 0) {
            return array(
                'success' => false,
                'error' => 'HTTPS is required for Telegram webhooks.'
            );
        }

        $secret = $this->generate_secret_token($config_id);

        $response = wp_remote_post($this->api_base . $bot_token . '/setWebhook', array(
            'timeout' => 15,
            'body' => array(
                'url' => $webhook_url,
                'secret_token' => $secret,
                'allowed_updates' => json_encode(array('message')),
                'drop_pending_updates' => true
            )
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['ok']) || $body['ok'] !== true) {
            return array(
                'success' => false,
                'error' => isset($body['description']) ? $body['description'] : 'Unknown error'
            );
        }

        update_option("chatbot_telegram_secret_{$config_id}", $secret);

        return array(
            'success' => true,
            'webhook_url' => $webhook_url
        );
    }

    /**
     * Delete webhook from Telegram (for polling mode)
     */
    public function delete_telegram_webhook($bot_token) {
        $response = wp_remote_post($this->api_base . $bot_token . '/deleteWebhook', array(
            'timeout' => 10,
            'body' => array('drop_pending_updates' => false)
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['ok']) && $body['ok'] === true;
    }

    /**
     * Unregister webhook from Telegram
     */
    public function unregister_webhook($bot_token, $config_id) {
        $response = wp_remote_post($this->api_base . $bot_token . '/deleteWebhook', array(
            'timeout' => 10,
            'body' => array('drop_pending_updates' => true)
        ));

        delete_option("chatbot_telegram_secret_{$config_id}");

        return !is_wp_error($response);
    }

    /**
     * Handle incoming webhook from Telegram
     */
    public function handle_webhook($request) {
        $config_id = intval($request->get_param('config_id'));

        // Validate secret token
        $stored_secret = get_option("chatbot_telegram_secret_{$config_id}");
        $header_secret = $request->get_header('X-Telegram-Bot-Api-Secret-Token');

        if (empty($stored_secret) || $stored_secret !== $header_secret) {
            return new WP_Error('unauthorized', 'Invalid secret token', array('status' => 403));
        }

        $db = Chatbot_DB::get_instance();
        $config = $db->get_configuration($config_id);

        if (!$config || empty($config->telegram_bot_token)) {
            return new WP_Error('not_found', 'Configuration not found', array('status' => 404));
        }

        $update = $request->get_json_params();

        if (!isset($update['message']['text'])) {
            return new WP_REST_Response(array('ok' => true), 200);
        }

        $chat_id = $update['message']['chat']['id'];
        $user_name = $update['message']['from']['first_name'] ?? 'Telegram User';
        $message_text = $update['message']['text'];

        // Handle /start command
        if ($message_text === '/start') {
            $this->send_message($config->telegram_bot_token, $chat_id, "Hello! I'm an AI assistant. How can I help you today?");
            return new WP_REST_Response(array('ok' => true), 200);
        }

        $conversation = $this->get_or_create_conversation($chat_id, $config_id, $user_name);

        if (!$conversation) {
            $this->send_message($config->telegram_bot_token, $chat_id, "Sorry, I encountered an error. Please try again later.");
            return new WP_REST_Response(array('ok' => true), 200);
        }

        $db->add_message($conversation->id, 'user', $message_text);

        try {
            $handler = Chatbot_Handler::get_instance();
            $ai_response = $handler->generate_telegram_response($message_text, $conversation->id, $config_id);

            if (empty($ai_response)) {
                $ai_response = "I'm sorry, I couldn't generate a response. Please try again.";
            }

            $db->add_message($conversation->id, 'ai', $ai_response);
            $this->send_message($config->telegram_bot_token, $chat_id, $ai_response);

        } catch (Exception $e) {
            chatbot_log('ERROR', 'telegram_webhook', 'Exception: ' . $e->getMessage());
            $this->send_message($config->telegram_bot_token, $chat_id, "Sorry, I encountered an error. Please try again.");
        }

        return new WP_REST_Response(array('ok' => true), 200);
    }

    /**
     * Get or create a conversation for a Telegram chat
     */
    public function get_or_create_conversation($telegram_chat_id, $config_id, $user_name) {
        $db = Chatbot_DB::get_instance();

        $conversation = $db->get_conversation_by_telegram_chat($telegram_chat_id, $config_id);

        if ($conversation) {
            return $conversation;
        }

        $config = $db->get_configuration($config_id);
        $config_name = $config ? $config->name : null;

        $conversation_id = $db->create_conversation(
            $user_name . ' (Telegram)',
            $config_id,
            $config_name,
            $telegram_chat_id
        );

        if (!$conversation_id) {
            return null;
        }

        return $db->get_conversation($conversation_id);
    }

    /**
     * Send a message via Telegram
     */
    public function send_message($bot_token, $chat_id, $message) {
        if (strlen($message) > 4096) {
            $chunks = str_split($message, 4000);
            foreach ($chunks as $chunk) {
                $this->send_message($bot_token, $chat_id, $chunk);
            }
            return true;
        }

        $response = wp_remote_post($this->api_base . $bot_token . '/sendMessage', array(
            'timeout' => 10,
            'body' => array(
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => 'Markdown'
            )
        ));

        if (is_wp_error($response)) {
            // Retry without Markdown
            $response = wp_remote_post($this->api_base . $bot_token . '/sendMessage', array(
                'timeout' => 10,
                'body' => array(
                    'chat_id' => $chat_id,
                    'text' => $message
                )
            ));
            return !is_wp_error($response);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['ok']) || $body['ok'] !== true) {
            // Retry without Markdown if parse error
            if (isset($body['description']) && strpos($body['description'], 'parse') !== false) {
                $response = wp_remote_post($this->api_base . $bot_token . '/sendMessage', array(
                    'timeout' => 10,
                    'body' => array(
                        'chat_id' => $chat_id,
                        'text' => $message
                    )
                ));
                return !is_wp_error($response);
            }
            return false;
        }

        return true;
    }

    /**
     * AJAX handler for connecting a Telegram bot
     */
    public function ajax_connect() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_telegram_connect') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized request'));
            return;
        }

        $config_id = isset($_POST['config_id']) ? intval($_POST['config_id']) : 0;
        $bot_token = isset($_POST['bot_token']) ? sanitize_text_field($_POST['bot_token']) : '';

        if (!$config_id || !$bot_token) {
            wp_send_json_error(array('message' => 'Missing configuration ID or bot token'));
            return;
        }

        $bot_info = $this->validate_token($bot_token);

        if (!$bot_info) {
            wp_send_json_error(array('message' => 'Invalid bot token. Please check and try again.'));
            return;
        }

        $is_localhost = $this->is_localhost();
        $use_polling = false;

        if ($is_localhost) {
            $this->delete_telegram_webhook($bot_token);
            $use_polling = true;
            update_option("chatbot_telegram_polling_{$config_id}", true);
            update_option("chatbot_telegram_update_id_{$config_id}", 0);
        } else {
            $webhook_result = $this->register_webhook($config_id, $bot_token);

            if (!$webhook_result['success']) {
                wp_send_json_error(array('message' => $webhook_result['error']));
                return;
            }

            update_option("chatbot_telegram_polling_{$config_id}", false);
        }

        $db = Chatbot_DB::get_instance();
        $config = $db->get_configuration($config_id);

        if (!$config) {
            wp_send_json_error(array('message' => 'Configuration not found'));
            return;
        }

        $result = $db->update_configuration(
            $config_id,
            $config->name,
            $config->system_prompt,
            $config->knowledge,
            $config->persona,
            $config->knowledge_sources,
            $bot_token
        );

        if (!$result) {
            wp_send_json_error(array('message' => 'Failed to save bot token'));
            return;
        }

        $message = $use_polling
            ? 'Telegram bot connected in POLLING mode (local development).'
            : 'Telegram bot connected successfully!';

        wp_send_json_success(array(
            'message' => $message,
            'bot_info' => $bot_info,
            'mode' => $use_polling ? 'polling' : 'webhook',
            'is_localhost' => $is_localhost
        ));
    }

    /**
     * AJAX handler for disconnecting a Telegram bot
     */
    public function ajax_disconnect() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_telegram_disconnect') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized request'));
            return;
        }

        $config_id = isset($_POST['config_id']) ? intval($_POST['config_id']) : 0;

        if (!$config_id) {
            wp_send_json_error(array('message' => 'Missing configuration ID'));
            return;
        }

        $db = Chatbot_DB::get_instance();
        $config = $db->get_configuration($config_id);

        if (!$config || empty($config->telegram_bot_token)) {
            wp_send_json_error(array('message' => 'No Telegram bot connected'));
            return;
        }

        $this->unregister_webhook($config->telegram_bot_token, $config_id);

        $result = $db->update_configuration(
            $config_id,
            $config->name,
            $config->system_prompt,
            $config->knowledge,
            $config->persona,
            $config->knowledge_sources,
            ''
        );

        if (!$result) {
            wp_send_json_error(array('message' => 'Failed to disconnect bot'));
            return;
        }

        wp_send_json_success(array('message' => 'Telegram bot disconnected'));
    }

    /**
     * AJAX handler for testing Telegram connection
     */
    public function ajax_test() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_telegram_test') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized request'));
            return;
        }

        $bot_token = isset($_POST['bot_token']) ? sanitize_text_field($_POST['bot_token']) : '';

        if (!$bot_token) {
            wp_send_json_error(array('message' => 'Missing bot token'));
            return;
        }

        $bot_info = $this->validate_token($bot_token);

        if (!$bot_info) {
            wp_send_json_error(array('message' => 'Invalid bot token'));
            return;
        }

        wp_send_json_success(array(
            'message' => 'Connection successful!',
            'bot_info' => $bot_info
        ));
    }

    /**
     * Get webhook info from Telegram
     */
    public function get_webhook_info($bot_token) {
        $response = wp_remote_get($this->api_base . $bot_token . '/getWebhookInfo', array(
            'timeout' => 10
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['ok']) || $body['ok'] !== true) {
            return false;
        }

        return $body['result'];
    }

    /**
     * Poll a single bot for new messages (for local development)
     */
    public function poll_single_bot($config) {
        $bot_token = $config->telegram_bot_token;
        $config_id = $config->id;

        // Prevent concurrent polling
        $lock_key = "chatbot_telegram_polling_lock_{$config_id}";
        if (get_transient($lock_key)) {
            return;
        }

        set_transient($lock_key, true, 60);

        try {
            $last_update_id = get_option("chatbot_telegram_last_update_{$config_id}", 0);
            $updates = $this->get_updates($bot_token, $last_update_id);

            if (empty($updates)) {
                delete_transient($lock_key);
                return;
            }

            foreach ($updates as $update) {
                $update_id = $update['update_id'];

                if ($update_id <= $last_update_id) {
                    continue;
                }

                update_option("chatbot_telegram_last_update_{$config_id}", $update_id);
                $this->process_polling_update($update, $config);
            }
        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Get updates from Telegram using long polling
     */
    public function get_updates($bot_token, $offset = 0) {
        $params = array(
            'timeout' => 5,
            'allowed_updates' => json_encode(array('message')),
        );

        if ($offset > 0) {
            $params['offset'] = $offset + 1;
        }

        $response = wp_remote_post($this->api_base . $bot_token . '/getUpdates', array(
            'timeout' => 30,
            'body' => $params
        ));

        if (is_wp_error($response)) {
            return array();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['ok']) || $body['ok'] !== true) {
            return array();
        }

        return isset($body['result']) ? $body['result'] : array();
    }

    /**
     * Process a single update from polling
     */
    public function process_polling_update($update, $config) {
        if (!isset($update['message']['text'])) {
            return;
        }

        $chat_id = $update['message']['chat']['id'];
        $user_name = isset($update['message']['from']['first_name']) ? $update['message']['from']['first_name'] : 'Telegram User';
        $message_text = $update['message']['text'];
        $config_id = $config->id;

        // Handle /start command
        if ($message_text === '/start') {
            $this->send_message($config->telegram_bot_token, $chat_id, "Hello! I'm an AI assistant. How can I help you today?");
            return;
        }

        $conversation = $this->get_or_create_conversation($chat_id, $config_id, $user_name);

        if (!$conversation) {
            $this->send_message($config->telegram_bot_token, $chat_id, "Sorry, I encountered an error. Please try again later.");
            return;
        }

        $db = Chatbot_DB::get_instance();
        $db->add_message($conversation->id, 'user', $message_text);

        try {
            $handler = Chatbot_Handler::get_instance();
            $ai_response = $handler->generate_telegram_response($message_text, $conversation->id, $config_id);

            if (empty($ai_response)) {
                $ai_response = "I'm sorry, I couldn't generate a response. Please try again.";
            }

            $db->add_message($conversation->id, 'ai', $ai_response);
            $this->send_message($config->telegram_bot_token, $chat_id, $ai_response);

        } catch (Exception $e) {
            chatbot_log('ERROR', 'telegram_poll', 'Exception: ' . $e->getMessage());
            $this->send_message($config->telegram_bot_token, $chat_id, "Sorry, I encountered an error. Please try again.");
        }
    }

    /**
     * AJAX handler for manual polling trigger
     */
    public function ajax_poll_now() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_telegram_poll') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized request'));
            return;
        }

        $config_id = isset($_POST['config_id']) ? intval($_POST['config_id']) : 0;

        if (!$config_id) {
            wp_send_json_error(array('message' => 'Missing configuration ID'));
            return;
        }

        $db = Chatbot_DB::get_instance();
        $config = $db->get_configuration($config_id);

        if (!$config || empty($config->telegram_bot_token)) {
            wp_send_json_error(array('message' => 'No Telegram bot connected'));
            return;
        }

        $last_update_before = get_option("chatbot_telegram_last_update_{$config_id}", 0);
        $this->poll_single_bot($config);
        $last_update_after = get_option("chatbot_telegram_last_update_{$config_id}", 0);

        $messages_processed = ($last_update_after > $last_update_before) ? ($last_update_after - $last_update_before) : 0;

        wp_send_json_success(array(
            'message' => "Processed {$messages_processed} message(s).",
            'messages_processed' => $messages_processed
        ));
    }
}

// Initialize the Telegram handler
function chatbot_telegram_init() {
    return Chatbot_Telegram::get_instance();
}
add_action('init', 'chatbot_telegram_init');
