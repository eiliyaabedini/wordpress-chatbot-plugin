<?php
/**
 * WhatsApp Messaging Platform Implementation
 *
 * Handles WhatsApp Cloud API integration using Meta's official API
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * WhatsApp Cloud API platform implementation
 */
class Chatbot_Platform_WhatsApp extends Chatbot_Messaging_Platform {

    /**
     * WhatsApp Cloud API base URL
     */
    private $api_base = 'https://graph.facebook.com/v22.0';

    /**
     * Constructor - register AJAX handlers
     */
    public function __construct() {
        add_action('wp_ajax_chatbot_whatsapp_send_test', array($this, 'ajax_send_test_message'));
    }

    /**
     * AJAX handler for sending a test message
     */
    public function ajax_send_test_message(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_whatsapp_send_test') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized request'));
            return;
        }

        $config_id = isset($_POST['config_id']) ? intval($_POST['config_id']) : 0;
        $phone_number = isset($_POST['phone_number']) ? sanitize_text_field($_POST['phone_number']) : '';
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';

        if (!$config_id || !$phone_number || !$message) {
            wp_send_json_error(array('message' => 'Missing required parameters'));
            return;
        }

        if (!$this->is_connected($config_id)) {
            wp_send_json_error(array('message' => 'WhatsApp is not connected'));
            return;
        }

        $this->log('INFO', 'ajax_send_test_message', 'Sending test message', array(
            'config_id' => $config_id,
            'recipient' => $phone_number
        ));

        $result = $this->send_message($config_id, $phone_number, $message);

        if ($result) {
            wp_send_json_success(array('message' => 'Message sent successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to send message. Check if the recipient has messaged your WhatsApp Business number first.'));
        }
    }

    /**
     * Get the platform identifier
     *
     * @return string
     */
    public function get_platform_id(): string {
        return 'whatsapp';
    }

    /**
     * Get the human-readable platform name
     *
     * @return string
     */
    public function get_platform_name(): string {
        return 'WhatsApp';
    }

    /**
     * Get the platform icon
     *
     * @return string
     */
    public function get_platform_icon(): string {
        return 'dashicons-whatsapp';
    }

    /**
     * Validate WhatsApp Cloud API credentials
     *
     * @param array $credentials Array with 'phone_number_id' and 'access_token' keys
     * @return array|false Validation result with phone info, or false on failure
     */
    public function validate_credentials(array $credentials) {
        $phone_number_id = $credentials['phone_number_id'] ?? '';
        $access_token = $credentials['access_token'] ?? '';

        if (empty($phone_number_id) || empty($access_token)) {
            return false;
        }

        // Test the credentials by getting phone number info
        $response = wp_remote_get(
            "{$this->api_base}/{$phone_number_id}?fields=verified_name,display_phone_number,quality_rating",
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token
                )
            )
        );

        if (is_wp_error($response)) {
            $this->log('ERROR', 'validate_credentials', 'API request failed', $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200 || isset($body['error'])) {
            $this->log('ERROR', 'validate_credentials', 'API error', $body['error'] ?? 'Unknown error');
            return false;
        }

        return array(
            'phone_number_id' => $phone_number_id,
            'verified_name' => $body['verified_name'] ?? '',
            'display_phone_number' => $body['display_phone_number'] ?? '',
            'quality_rating' => $body['quality_rating'] ?? 'UNKNOWN'
        );
    }

    /**
     * Connect WhatsApp to a chatbot configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @param array $credentials Array with credentials
     * @return array
     */
    public function connect(int $config_id, array $credentials): array {
        $phone_number_id = $credentials['phone_number_id'] ?? '';
        $access_token = $credentials['access_token'] ?? '';
        $verify_token = $credentials['verify_token'] ?? '';

        if (empty($phone_number_id) || empty($access_token)) {
            return array('success' => false, 'error' => 'Phone Number ID and Access Token are required');
        }

        // Generate verify token if not provided
        if (empty($verify_token)) {
            $verify_token = $this->generate_secret_token($config_id);
        }

        // Validate credentials
        $validation = $this->validate_credentials($credentials);

        if (!$validation) {
            return array('success' => false, 'error' => 'Invalid credentials. Please check your Phone Number ID and Access Token.');
        }

        // Store credentials
        $this->store_option($config_id, 'phone_number_id', $phone_number_id);
        $this->store_option($config_id, 'access_token', $access_token);
        $this->store_option($config_id, 'verify_token', $verify_token);
        $this->store_option($config_id, 'connected', true);
        $this->store_option($config_id, 'phone_info', $validation);

        // Note: Unlike Telegram, WhatsApp webhooks are configured in Meta's App Dashboard,
        // not via API. We just store our verify token for the verification handshake.

        $webhook_url = $this->get_webhook_url($config_id);

        $this->log('INFO', 'connect', 'WhatsApp connected successfully', array(
            'config_id' => $config_id,
            'phone_number_id' => $phone_number_id,
            'verified_name' => $validation['verified_name']
        ));

        return array(
            'success' => true,
            'message' => 'WhatsApp connected successfully!',
            'phone_info' => $validation,
            'webhook_url' => $webhook_url,
            'verify_token' => $verify_token,
            'setup_instructions' => $this->get_setup_instructions($webhook_url, $verify_token)
        );
    }

    /**
     * Disconnect WhatsApp from a configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return bool
     */
    public function disconnect(int $config_id): bool {
        // Clear stored credentials
        $this->delete_option($config_id, 'phone_number_id');
        $this->delete_option($config_id, 'access_token');
        $this->delete_option($config_id, 'verify_token');
        $this->delete_option($config_id, 'connected');
        $this->delete_option($config_id, 'phone_info');

        $this->log('INFO', 'disconnect', 'WhatsApp disconnected', array('config_id' => $config_id));

        return true;
    }

    /**
     * Check if WhatsApp is connected for a configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return bool
     */
    public function is_connected(int $config_id): bool {
        return (bool) $this->get_option($config_id, 'connected', false);
    }

    /**
     * Send a message via WhatsApp
     *
     * @param int $config_id The chatbot configuration ID
     * @param string $recipient_id The recipient's WhatsApp ID (phone number)
     * @param string $message The message to send
     * @return bool
     */
    public function send_message(int $config_id, string $recipient_id, string $message): bool {
        $phone_number_id = $this->get_option($config_id, 'phone_number_id', '');
        $access_token = $this->get_option($config_id, 'access_token', '');

        if (empty($phone_number_id) || empty($access_token)) {
            $this->log('ERROR', 'send_message', 'Missing credentials', array('config_id' => $config_id));
            return false;
        }

        // WhatsApp has a 4096 character limit for text messages
        if (strlen($message) > 4096) {
            // Split into chunks
            $chunks = str_split($message, 4000);
            $success = true;
            foreach ($chunks as $chunk) {
                if (!$this->send_whatsapp_message($phone_number_id, $access_token, $recipient_id, $chunk)) {
                    $success = false;
                }
            }
            return $success;
        }

        return $this->send_whatsapp_message($phone_number_id, $access_token, $recipient_id, $message);
    }

    /**
     * Handle incoming WhatsApp webhook
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_webhook(WP_REST_Request $request): WP_REST_Response {
        $config_id = intval($request->get_param('config_id'));

        // Handle webhook verification (GET request)
        if ($request->get_method() === 'GET') {
            return $this->handle_webhook_verification($request, $config_id);
        }

        // Handle incoming messages (POST request)
        return $this->handle_incoming_message($request, $config_id);
    }

    /**
     * Handle WhatsApp webhook verification
     *
     * @param WP_REST_Request $request
     * @param int $config_id
     * @return WP_REST_Response
     */
    private function handle_webhook_verification(WP_REST_Request $request, int $config_id): WP_REST_Response {
        $mode = $request->get_param('hub_mode');
        $token = $request->get_param('hub_verify_token');
        $challenge = $request->get_param('hub_challenge');

        $stored_token = $this->get_option($config_id, 'verify_token', '');

        $this->log('DEBUG', 'webhook_verification', 'Verification request', array(
            'config_id' => $config_id,
            'mode' => $mode,
            'token_match' => ($token === $stored_token)
        ));

        if ($mode === 'subscribe' && $token === $stored_token) {
            $this->log('INFO', 'webhook_verification', 'Webhook verified successfully');
            // Return challenge as plain text (WhatsApp requirement)
            // Must bypass WP_REST_Response as it JSON-encodes the body
            header('Content-Type: text/plain');
            echo $challenge;
            exit;
        }

        $this->log('WARNING', 'webhook_verification', 'Verification failed');
        return new WP_REST_Response('Verification failed', 403);
    }

    /**
     * Handle incoming WhatsApp message
     *
     * @param WP_REST_Request $request
     * @param int $config_id
     * @return WP_REST_Response
     */
    private function handle_incoming_message(WP_REST_Request $request, int $config_id): WP_REST_Response {
        $body = $request->get_json_params();

        // Validate the webhook payload structure
        if (!isset($body['object']) || $body['object'] !== 'whatsapp_business_account') {
            return new WP_REST_Response(array('status' => 'ignored'), 200);
        }

        if (!isset($body['entry'][0]['changes'][0]['value'])) {
            return new WP_REST_Response(array('status' => 'ignored'), 200);
        }

        $value = $body['entry'][0]['changes'][0]['value'];

        // Check if this is a message notification
        if (!isset($value['messages']) || empty($value['messages'])) {
            // Could be a status update, acknowledge it
            return new WP_REST_Response(array('status' => 'received'), 200);
        }

        $message = $value['messages'][0];
        $contact = $value['contacts'][0] ?? null;

        // Only handle text messages for now
        if ($message['type'] !== 'text') {
            $this->log('DEBUG', 'handle_message', 'Non-text message received', array('type' => $message['type']));
            return new WP_REST_Response(array('status' => 'received'), 200);
        }

        $sender_id = $message['from'];
        $message_text = $message['text']['body'] ?? '';
        $user_name = $contact['profile']['name'] ?? 'WhatsApp User';

        $this->log('INFO', 'handle_message', 'Message received', array(
            'config_id' => $config_id,
            'sender_id' => substr($sender_id, 0, 6) . '****',
            'user_name' => $user_name
        ));

        // Get configuration
        $db = Chatbot_DB::get_instance();
        $config = $db->get_configuration($config_id);

        if (!$config) {
            $this->log('ERROR', 'handle_message', 'Configuration not found', array('config_id' => $config_id));
            return new WP_REST_Response(array('status' => 'error'), 500);
        }

        // Process the message and generate AI response
        $ai_response = $this->process_incoming_message($sender_id, $user_name, $message_text, $config);

        // Send the response
        $this->send_message($config_id, $sender_id, $ai_response);

        return new WP_REST_Response(array('status' => 'processed'), 200);
    }

    /**
     * Get the webhook URL for a configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return string
     */
    public function get_webhook_url(int $config_id): string {
        return rest_url("chatbot-plugin/v1/webhook/whatsapp/{$config_id}");
    }

    /**
     * Register webhook (not used for WhatsApp - configured in Meta Dashboard)
     *
     * @param int $config_id The chatbot configuration ID
     * @param array $credentials Array with credentials
     * @return array
     */
    public function register_webhook(int $config_id, array $credentials): array {
        // WhatsApp webhooks are configured manually in Meta's App Dashboard
        // This method just returns the URL and instructions

        $webhook_url = $this->get_webhook_url($config_id);
        $verify_token = $this->get_option($config_id, 'verify_token', '');

        return array(
            'success' => true,
            'webhook_url' => $webhook_url,
            'verify_token' => $verify_token,
            'message' => 'Configure this webhook URL in your Meta App Dashboard'
        );
    }

    /**
     * Get settings fields for admin UI
     *
     * @return array
     */
    public function get_settings_fields(): array {
        return array(
            'phone_number_id' => array(
                'type' => 'text',
                'label' => __('Phone Number ID', 'chatbot-plugin'),
                'description' => __('Your WhatsApp Business Phone Number ID from Meta Developer Dashboard', 'chatbot-plugin'),
                'placeholder' => '123456789012345',
                'required' => true
            ),
            'access_token' => array(
                'type' => 'password',
                'label' => __('Access Token', 'chatbot-plugin'),
                'description' => __('Permanent Access Token from Meta Developer Dashboard (use System User token for production)', 'chatbot-plugin'),
                'placeholder' => 'EAAxxxxxxx...',
                'required' => true
            ),
            'verify_token' => array(
                'type' => 'text',
                'label' => __('Verify Token', 'chatbot-plugin'),
                'description' => __('A custom string you create to verify webhook requests (will be auto-generated if empty)', 'chatbot-plugin'),
                'placeholder' => 'Leave empty to auto-generate',
                'required' => false
            )
        );
    }

    /**
     * Get stored credentials for a configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return array
     */
    public function get_stored_credentials(int $config_id): array {
        return array(
            'phone_number_id' => $this->get_option($config_id, 'phone_number_id', ''),
            'access_token' => $this->get_option($config_id, 'access_token', ''),
            'verify_token' => $this->get_option($config_id, 'verify_token', ''),
            'phone_info' => $this->get_option($config_id, 'phone_info', array())
        );
    }

    /**
     * Send a WhatsApp message via Cloud API
     *
     * @param string $phone_number_id The business phone number ID
     * @param string $access_token The access token
     * @param string $recipient The recipient's phone number
     * @param string $message The message text
     * @return bool
     */
    private function send_whatsapp_message(string $phone_number_id, string $access_token, string $recipient, string $message): bool {
        $response = wp_remote_post(
            "{$this->api_base}/{$phone_number_id}/messages",
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array(
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $recipient,
                    'type' => 'text',
                    'text' => array(
                        'preview_url' => true,
                        'body' => $message
                    )
                ))
            )
        );

        if (is_wp_error($response)) {
            $this->log('ERROR', 'send_message', 'API request failed', $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200 || isset($body['error'])) {
            $this->log('ERROR', 'send_message', 'API error', array(
                'status' => $status_code,
                'error' => $body['error'] ?? 'Unknown error'
            ));
            return false;
        }

        $this->log('DEBUG', 'send_message', 'Message sent successfully', array(
            'message_id' => $body['messages'][0]['id'] ?? 'unknown'
        ));

        return true;
    }

    /**
     * Get setup instructions for WhatsApp webhook
     *
     * @param string $webhook_url The webhook URL
     * @param string $verify_token The verify token
     * @return array
     */
    private function get_setup_instructions(string $webhook_url, string $verify_token): array {
        return array(
            'title' => __('Complete WhatsApp Setup', 'chatbot-plugin'),
            'steps' => array(
                __('1. Go to Meta Developer Dashboard (developers.facebook.com)', 'chatbot-plugin'),
                __('2. Select your WhatsApp Business App', 'chatbot-plugin'),
                __('3. Go to WhatsApp > Configuration > Webhook', 'chatbot-plugin'),
                sprintf(__('4. Set Callback URL to: %s', 'chatbot-plugin'), $webhook_url),
                sprintf(__('5. Set Verify Token to: %s', 'chatbot-plugin'), $verify_token),
                __('6. Subscribe to "messages" webhook field', 'chatbot-plugin'),
                __('7. Click "Verify and Save"', 'chatbot-plugin')
            ),
            'webhook_url' => $webhook_url,
            'verify_token' => $verify_token
        );
    }

    /**
     * Check if access token is valid and not expired
     *
     * @param int $config_id The configuration ID
     * @return bool
     */
    public function is_token_valid(int $config_id): bool {
        $credentials = $this->get_stored_credentials($config_id);

        if (empty($credentials['phone_number_id']) || empty($credentials['access_token'])) {
            return false;
        }

        $validation = $this->validate_credentials(array(
            'phone_number_id' => $credentials['phone_number_id'],
            'access_token' => $credentials['access_token']
        ));

        return $validation !== false;
    }

    /**
     * Get business profile information
     *
     * @param int $config_id The configuration ID
     * @return array|false
     */
    public function get_business_profile(int $config_id) {
        $phone_number_id = $this->get_option($config_id, 'phone_number_id', '');
        $access_token = $this->get_option($config_id, 'access_token', '');

        if (empty($phone_number_id) || empty($access_token)) {
            return false;
        }

        $response = wp_remote_get(
            "{$this->api_base}/{$phone_number_id}/whatsapp_business_profile?fields=about,address,description,email,profile_picture_url,websites,vertical",
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token
                )
            )
        );

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return false;
        }

        return $body['data'][0] ?? false;
    }
}
