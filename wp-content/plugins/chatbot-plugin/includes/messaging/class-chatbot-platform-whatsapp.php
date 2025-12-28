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
        // Extract all credentials
        $webhook_base_url = $credentials['webhook_base_url'] ?? '';
        $app_id = $credentials['app_id'] ?? '';
        $app_secret = $credentials['app_secret'] ?? '';
        $business_account_id = $credentials['business_account_id'] ?? '';
        $phone_number_id = $credentials['phone_number_id'] ?? '';
        $access_token = $credentials['access_token'] ?? '';

        // Validate required fields
        if (empty($app_id) || empty($app_secret)) {
            return array('success' => false, 'error' => 'App ID and App Secret are required');
        }
        if (empty($business_account_id)) {
            return array('success' => false, 'error' => 'Business Account ID (WABA ID) is required');
        }
        if (empty($phone_number_id) || empty($access_token)) {
            return array('success' => false, 'error' => 'Phone Number ID and Access Token are required');
        }

        // Generate verify token (always auto-generate for security)
        $verify_token = $this->generate_secret_token($config_id);

        // Validate credentials by testing phone number API
        $validation = $this->validate_credentials($credentials);
        if (!$validation) {
            return array('success' => false, 'error' => 'Invalid credentials. Please check your Phone Number ID and Access Token.');
        }

        // Store all credentials BEFORE subscribing (needed by subscription methods)
        $this->store_option($config_id, 'webhook_base_url', $webhook_base_url);
        $this->store_option($config_id, 'app_id', $app_id);
        $this->store_option($config_id, 'app_secret', $app_secret);
        $this->store_option($config_id, 'business_account_id', $business_account_id);
        $this->store_option($config_id, 'phone_number_id', $phone_number_id);
        $this->store_option($config_id, 'access_token', $access_token);
        $this->store_option($config_id, 'verify_token', $verify_token);
        $this->store_option($config_id, 'phone_info', $validation);

        $webhook_url = $this->get_webhook_url($config_id);

        $this->log('INFO', 'connect', 'Credentials stored, subscribing to webhooks', array(
            'config_id' => $config_id,
            'app_id' => $app_id,
            'business_account_id' => $business_account_id,
            'phone_number_id' => $phone_number_id,
            'webhook_url' => $webhook_url
        ));

        // Try to subscribe to webhooks - but don't fail if it doesn't work (e.g., localhost)
        $webhook_configured = false;
        $webhook_warning = '';

        // Subscribe to app-level webhooks via Graph API
        $subscription_result = $this->subscribe_to_webhooks($config_id);
        if (!$subscription_result['success']) {
            // Check if this is a callback verification error (common for localhost)
            $error_msg = $subscription_result['error'] ?? '';
            if (strpos($error_msg, '2200') !== false || strpos($error_msg, 'Callback verification') !== false) {
                $this->log('WARNING', 'connect', 'Webhook subscription failed - site not publicly accessible', array(
                    'error' => $error_msg
                ));
                $webhook_warning = 'Webhook auto-configuration failed (site not publicly accessible). You can still send messages. To receive messages, deploy to a public server or configure webhooks manually in Meta Dashboard.';
            } else {
                $this->log('WARNING', 'connect', 'Webhook subscription failed', array(
                    'error' => $error_msg
                ));
                $webhook_warning = 'Webhook auto-configuration failed: ' . $error_msg . '. You can configure webhooks manually in Meta Dashboard.';
            }
        } else {
            // Subscribe app to WABA
            $waba_result = $this->subscribe_app_to_waba($config_id);
            if (!$waba_result['success']) {
                $this->log('WARNING', 'connect', 'WABA subscription failed', array(
                    'error' => $waba_result['error'] ?? 'Unknown error'
                ));
                $webhook_warning = 'WABA subscription failed. You may need to configure webhooks manually.';
            } else {
                $webhook_configured = true;
            }
        }

        // Mark as connected - we can still send messages even without webhooks
        $this->store_option($config_id, 'connected', true);
        $this->store_option($config_id, 'webhook_configured', $webhook_configured);

        $this->log('INFO', 'connect', 'WhatsApp connected', array(
            'config_id' => $config_id,
            'phone_number_id' => $phone_number_id,
            'verified_name' => $validation['verified_name'],
            'webhook_configured' => $webhook_configured
        ));

        // Build response message
        if ($webhook_configured) {
            $message = 'WhatsApp connected successfully! Webhooks configured automatically.';
        } else {
            $message = 'WhatsApp connected! ' . $webhook_warning;
        }

        return array(
            'success' => true,
            'message' => $message,
            'phone_info' => $validation,
            'webhook_url' => $webhook_url,
            'verify_token' => $verify_token,
            'webhook_configured' => $webhook_configured,
            'webhook_warning' => $webhook_warning
        );
    }

    /**
     * Disconnect WhatsApp from a configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return bool
     */
    public function disconnect(int $config_id): bool {
        $this->log('INFO', 'disconnect', 'Disconnecting WhatsApp', array('config_id' => $config_id));

        // Unsubscribe from webhooks first (before credentials are deleted)
        $this->unsubscribe_from_webhooks($config_id);

        // Clear all stored credentials
        $this->delete_option($config_id, 'webhook_base_url');
        $this->delete_option($config_id, 'app_id');
        $this->delete_option($config_id, 'app_secret');
        $this->delete_option($config_id, 'business_account_id');
        $this->delete_option($config_id, 'phone_number_id');
        $this->delete_option($config_id, 'access_token');
        $this->delete_option($config_id, 'verify_token');
        $this->delete_option($config_id, 'connected');
        $this->delete_option($config_id, 'webhook_configured');
        $this->delete_option($config_id, 'phone_info');

        $this->log('INFO', 'disconnect', 'WhatsApp disconnected successfully', array('config_id' => $config_id));

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
     * Verify webhook signature using App Secret
     *
     * Meta signs webhook payloads with X-Hub-Signature-256 header using HMAC-SHA256.
     * This prevents spoofed webhook requests.
     *
     * @param WP_REST_Request $request The incoming request
     * @param int $config_id The configuration ID
     * @return bool True if signature is valid or not configured, false otherwise
     */
    private function verify_webhook_signature(WP_REST_Request $request, int $config_id): bool {
        $app_secret = $this->get_option($config_id, 'app_secret', '');

        // If app_secret is not configured, skip verification (backward compatibility)
        if (empty($app_secret)) {
            $this->log('DEBUG', 'verify_signature', 'Skipping signature verification - no app_secret configured');
            return true;
        }

        $signature_header = $request->get_header('X-Hub-Signature-256');

        if (empty($signature_header)) {
            $this->log('WARNING', 'verify_signature', 'Missing X-Hub-Signature-256 header');
            return false;
        }

        // Get raw request body for signature verification
        $raw_body = $request->get_body();

        // Calculate expected signature
        $expected_signature = 'sha256=' . hash_hmac('sha256', $raw_body, $app_secret);

        // Use timing-safe comparison
        $is_valid = hash_equals($expected_signature, $signature_header);

        if (!$is_valid) {
            $this->log('WARNING', 'verify_signature', 'Signature verification failed', array(
                'config_id' => $config_id,
                'expected_prefix' => substr($expected_signature, 0, 20) . '...',
                'received_prefix' => substr($signature_header, 0, 20) . '...'
            ));
        } else {
            $this->log('DEBUG', 'verify_signature', 'Signature verified successfully');
        }

        return $is_valid;
    }

    /**
     * Handle incoming WhatsApp message
     *
     * @param WP_REST_Request $request
     * @param int $config_id
     * @return WP_REST_Response
     */
    private function handle_incoming_message(WP_REST_Request $request, int $config_id): WP_REST_Response {
        // Verify webhook signature first (security)
        if (!$this->verify_webhook_signature($request, $config_id)) {
            $this->log('ERROR', 'handle_message', 'Webhook signature verification failed');
            return new WP_REST_Response(array('error' => 'Invalid signature'), 403);
        }

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
            // Handle status updates (sent, delivered, read, failed)
            if (isset($value['statuses']) && !empty($value['statuses'])) {
                $this->handle_status_update($config_id, $value['statuses']);
            }
            return new WP_REST_Response(array('status' => 'received'), 200);
        }

        $message = $value['messages'][0];
        $contact = $value['contacts'][0] ?? null;
        $message_type = $message['type'] ?? 'unknown';

        $sender_id = $message['from'];
        $user_name = $contact['profile']['name'] ?? 'WhatsApp User';

        // Get credentials for file downloads
        $access_token = $this->get_option($config_id, 'access_token', '');

        // Extract text and files from message
        $message_text = '';
        $files = [];

        switch ($message_type) {
            case 'text':
                $message_text = $message['text']['body'] ?? '';
                break;

            case 'image':
                $caption = $message['image']['caption'] ?? '';
                $media_id = $message['image']['id'] ?? '';
                $mime_type = $message['image']['mime_type'] ?? 'image/jpeg';
                $message_text = $caption ?: 'Please analyze this image.';
                if ($media_id && $access_token) {
                    $file = $this->download_whatsapp_media($access_token, $media_id, $mime_type, 'image.jpg');
                    if ($file) {
                        $files[] = $file;
                    }
                }
                break;

            case 'document':
                $caption = $message['document']['caption'] ?? '';
                $media_id = $message['document']['id'] ?? '';
                $mime_type = $message['document']['mime_type'] ?? 'application/octet-stream';
                $filename = $message['document']['filename'] ?? 'document';
                $message_text = $caption ?: 'Please analyze this document.';
                if ($media_id && $access_token) {
                    $file = $this->download_whatsapp_media($access_token, $media_id, $mime_type, $filename);
                    if ($file) {
                        $files[] = $file;
                    }
                }
                break;

            case 'audio':
            case 'video':
            case 'sticker':
                $this->log('DEBUG', 'handle_message', 'Media type not yet supported for analysis', array('type' => $message_type));
                $this->send_message($config_id, $sender_id, 'Sorry, I can only analyze images and documents at the moment. Please send a photo or document.');
                return new WP_REST_Response(array('status' => 'received'), 200);

            default:
                $this->log('DEBUG', 'handle_message', 'Unknown message type', array('type' => $message_type));
                return new WP_REST_Response(array('status' => 'received'), 200);
        }

        $this->log('INFO', 'handle_message', 'Message received, processing synchronously', array(
            'config_id' => $config_id,
            'sender_id' => substr($sender_id, 0, 6) . '****',
            'user_name' => $user_name,
            'type' => $message_type,
            'has_files' => !empty($files),
        ));

        // Get configuration
        $db = Chatbot_DB::get_instance();
        $config = $db->get_configuration($config_id);

        if (!$config) {
            $this->log('ERROR', 'handle_message', 'Configuration not found', array('config_id' => $config_id));
            return new WP_REST_Response(array('status' => 'error'), 200);
        }

        try {
            // Process the message synchronously (more reliable than async on local dev)
            if (!empty($files)) {
                $ai_response = $this->process_message_with_files($sender_id, $user_name, $message_text, $config, $files);
            } else {
                $ai_response = $this->process_incoming_message($sender_id, $user_name, $message_text, $config);
            }

            // Send the response
            $this->send_message($config_id, $sender_id, $ai_response);

            $this->log('INFO', 'handle_message', 'Message processed and response sent', array(
                'config_id' => $config_id,
                'sender_id' => substr($sender_id, 0, 6) . '****'
            ));
        } catch (Exception $e) {
            $this->log('ERROR', 'handle_message', 'Exception during processing: ' . $e->getMessage());
            $this->send_message($config_id, $sender_id, 'Sorry, I encountered an error processing your message. Please try again.');
        }

        return new WP_REST_Response(array('status' => 'processed'), 200);
    }

    /**
     * Handle message status updates (sent, delivered, read, failed)
     *
     * WhatsApp sends status notifications when messages are delivered or read.
     * This method logs these status updates for analytics/debugging.
     *
     * @param int $config_id The configuration ID
     * @param array $statuses Array of status updates
     */
    private function handle_status_update(int $config_id, array $statuses): void {
        foreach ($statuses as $status) {
            $message_id = $status['id'] ?? 'unknown';
            $status_type = $status['status'] ?? 'unknown';
            $recipient_id = $status['recipient_id'] ?? 'unknown';
            $timestamp = $status['timestamp'] ?? time();

            // Log based on status type
            switch ($status_type) {
                case 'sent':
                    $this->log('DEBUG', 'status_update', 'Message sent', array(
                        'config_id' => $config_id,
                        'message_id' => $message_id,
                        'recipient' => substr($recipient_id, 0, 6) . '****',
                        'timestamp' => $timestamp
                    ));
                    break;

                case 'delivered':
                    $this->log('DEBUG', 'status_update', 'Message delivered', array(
                        'config_id' => $config_id,
                        'message_id' => $message_id,
                        'recipient' => substr($recipient_id, 0, 6) . '****',
                        'timestamp' => $timestamp
                    ));
                    break;

                case 'read':
                    $this->log('DEBUG', 'status_update', 'Message read', array(
                        'config_id' => $config_id,
                        'message_id' => $message_id,
                        'recipient' => substr($recipient_id, 0, 6) . '****',
                        'timestamp' => $timestamp
                    ));
                    break;

                case 'failed':
                    $error_code = $status['errors'][0]['code'] ?? 'unknown';
                    $error_title = $status['errors'][0]['title'] ?? 'Unknown error';

                    $this->log('ERROR', 'status_update', 'Message failed', array(
                        'config_id' => $config_id,
                        'message_id' => $message_id,
                        'recipient' => substr($recipient_id, 0, 6) . '****',
                        'error_code' => $error_code,
                        'error_title' => $error_title,
                        'timestamp' => $timestamp
                    ));
                    break;

                default:
                    $this->log('DEBUG', 'status_update', 'Unknown status type', array(
                        'config_id' => $config_id,
                        'status_type' => $status_type,
                        'message_id' => $message_id
                    ));
            }
        }
    }

    /**
     * Download a media file from WhatsApp Cloud API.
     *
     * @param string $access_token The access token.
     * @param string $media_id     The media ID.
     * @param string $mime_type    The MIME type.
     * @param string $filename     The filename.
     * @return array|null File data or null on error.
     */
    private function download_whatsapp_media(string $access_token, string $media_id, string $mime_type, string $filename): ?array {
        // Validate file type first
        $file_type = $this->get_file_type_from_mime($mime_type);
        if ($file_type === null) {
            $this->log('WARN', 'download_media', 'Unsupported file type', array(
                'mime_type' => $mime_type,
                'filename' => $filename,
            ));
            return null;
        }

        // Step 1: Get the media URL from the media ID
        $response = wp_remote_get(
            "{$this->api_base}/{$media_id}",
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                ),
            )
        );

        if (is_wp_error($response)) {
            $this->log('ERROR', 'download_media', 'Failed to get media URL', $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['url'])) {
            $this->log('ERROR', 'download_media', 'No URL in media response', $body);
            return null;
        }

        $media_url = $body['url'];

        // Step 2: Download the actual file
        $file_response = wp_remote_get(
            $media_url,
            array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                ),
            )
        );

        if (is_wp_error($file_response)) {
            $this->log('ERROR', 'download_media', 'Failed to download file', $file_response->get_error_message());
            return null;
        }

        $file_content = wp_remote_retrieve_body($file_response);

        if (empty($file_content)) {
            $this->log('ERROR', 'download_media', 'Empty file content');
            return null;
        }

        // Check file size (max 20MB for WhatsApp)
        $max_size = 20 * 1024 * 1024;
        if (strlen($file_content) > $max_size) {
            $this->log('WARN', 'download_media', 'File too large', array(
                'size' => strlen($file_content),
                'max' => $max_size,
            ));
            return null;
        }

        $this->log('DEBUG', 'download_media', 'File downloaded successfully', array(
            'media_id' => $media_id,
            'size' => strlen($file_content),
            'type' => $file_type,
        ));

        return array(
            'type' => $file_type,
            'data' => base64_encode($file_content),
            'mime_type' => $mime_type,
            'name' => sanitize_file_name($filename),
        );
    }

    /**
     * Get file type from MIME type.
     *
     * @param string $mime_type The MIME type.
     * @return string|null The file type or null if unsupported.
     */
    private function get_file_type_from_mime(string $mime_type): ?string {
        $mime_type = strtolower($mime_type);

        // Image types
        if (strpos($mime_type, 'image/') === 0) {
            $supported = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            return in_array($mime_type, $supported, true) ? 'image' : null;
        }

        // PDF
        if ($mime_type === 'application/pdf') {
            return 'pdf';
        }

        // Documents
        $document_types = [
            'text/plain',
            'text/csv',
            'text/html',
            'text/markdown',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        if (in_array($mime_type, $document_types, true)) {
            return 'document';
        }

        // Spreadsheets
        $spreadsheet_types = [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        if (in_array($mime_type, $spreadsheet_types, true)) {
            return 'spreadsheet';
        }

        return null;
    }

    /**
     * Process a message with file attachments through the pipeline.
     *
     * @param string $sender_id    The sender's WhatsApp ID.
     * @param string $user_name    The user's name.
     * @param string $message_text The message text.
     * @param object $config       The chatbot configuration.
     * @param array  $files        The file attachments.
     * @return string The AI response.
     */
    private function process_message_with_files(
        string $sender_id,
        string $user_name,
        string $message_text,
        object $config,
        array $files
    ): string {
        // Try to use the new pipeline if available
        try {
            $container = chatbot_container();

            if ($container->has('Chatbot_Message_Pipeline')) {
                $pipeline = $container->make('Chatbot_Message_Pipeline');

                // Create context with files
                $context = new Chatbot_Message_Context(
                    $message_text,
                    $this->get_platform_id(),
                    $user_name,
                    null, // Will find/create conversation
                    $config->id,
                    $sender_id,
                    ['source' => 'whatsapp_webhook'],
                    $files
                );

                $response = $pipeline->process($context);

                if ($response->is_success()) {
                    return $response->get_message();
                }

                $this->log('ERROR', 'process_with_files', 'Pipeline error: ' . $response->get_error());
                return 'Sorry, I could not process your file. Please try again.';
            }
        } catch (Exception $e) {
            $this->log('ERROR', 'process_with_files', 'Exception: ' . $e->getMessage());
        }

        // Fallback: process without files
        return $this->process_incoming_message($sender_id, $user_name, $message_text, $config);
    }

    /**
     * Get the webhook URL for a configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return string
     */
    public function get_webhook_url(int $config_id): string {
        // Check if a custom webhook base URL is set (for tunnels, proxies, etc.)
        $webhook_base_url = $this->get_option($config_id, 'webhook_base_url', '');

        if (!empty($webhook_base_url)) {
            // Use custom base URL - ensure it doesn't have trailing slash
            $webhook_base_url = rtrim($webhook_base_url, '/');
            return $webhook_base_url . '/wp-json/chatbot-plugin/v1/webhook/whatsapp/' . $config_id;
        }

        // Default to WordPress REST URL
        return rest_url("chatbot-plugin/v1/webhook/whatsapp/{$config_id}");
    }

    /**
     * Register webhook programmatically via Graph API
     *
     * @param int $config_id The chatbot configuration ID
     * @param array $credentials Array with credentials
     * @return array
     */
    public function register_webhook(int $config_id, array $credentials): array {
        $webhook_url = $this->get_webhook_url($config_id);
        $verify_token = $this->get_option($config_id, 'verify_token', '');

        // Subscribe to app-level webhooks
        $subscription_result = $this->subscribe_to_webhooks($config_id);
        if (!$subscription_result['success']) {
            return $subscription_result;
        }

        // Subscribe app to WABA
        $waba_result = $this->subscribe_app_to_waba($config_id);
        if (!$waba_result['success']) {
            return $waba_result;
        }

        return array(
            'success' => true,
            'webhook_url' => $webhook_url,
            'verify_token' => $verify_token,
            'message' => 'Webhook registered automatically via Graph API'
        );
    }

    /**
     * Subscribe to app-level webhooks via Graph API
     *
     * This creates a webhook subscription at the META APP level for whatsapp_business_account events.
     * The App Access Token is: {app_id}|{app_secret}
     *
     * @param int $config_id The chatbot configuration ID
     * @return array Success/error response
     */
    private function subscribe_to_webhooks(int $config_id): array {
        $app_id = $this->get_option($config_id, 'app_id', '');
        $app_secret = $this->get_option($config_id, 'app_secret', '');
        $verify_token = $this->get_option($config_id, 'verify_token', '');
        $webhook_url = $this->get_webhook_url($config_id);

        if (empty($app_id) || empty($app_secret)) {
            $this->log('ERROR', 'subscribe_to_webhooks', 'Missing App ID or App Secret');
            return array(
                'success' => false,
                'error' => 'App ID and App Secret are required for webhook subscription'
            );
        }

        // App Access Token format: {app_id}|{app_secret}
        $app_access_token = $app_id . '|' . $app_secret;

        $this->log('INFO', 'subscribe_to_webhooks', 'Subscribing to app webhooks', array(
            'config_id' => $config_id,
            'app_id' => $app_id,
            'webhook_url' => $webhook_url
        ));

        // POST to /{app_id}/subscriptions
        $response = wp_remote_post(
            "{$this->api_base}/{$app_id}/subscriptions",
            array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ),
                'body' => array(
                    'object' => 'whatsapp_business_account',
                    'callback_url' => $webhook_url,
                    'verify_token' => $verify_token,
                    'fields' => 'messages',
                    'include_values' => 'true',
                    'access_token' => $app_access_token
                )
            )
        );

        if (is_wp_error($response)) {
            $this->log('ERROR', 'subscribe_to_webhooks', 'API request failed', $response->get_error_message());
            return array(
                'success' => false,
                'error' => 'Failed to subscribe to webhooks: ' . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200 || isset($body['error'])) {
            $error_message = $body['error']['message'] ?? 'Unknown error';
            $error_code = $body['error']['code'] ?? 0;

            $this->log('ERROR', 'subscribe_to_webhooks', 'Subscription failed', array(
                'status_code' => $status_code,
                'error_code' => $error_code,
                'error' => $error_message
            ));

            return array(
                'success' => false,
                'error' => "Webhook subscription failed: {$error_message} (Code: {$error_code})"
            );
        }

        $this->log('INFO', 'subscribe_to_webhooks', 'App webhooks subscribed successfully', $body);

        return array(
            'success' => true,
            'data' => $body
        );
    }

    /**
     * Subscribe app to WhatsApp Business Account (WABA)
     *
     * This links your Meta App to the specific WABA so it receives webhook events.
     *
     * @param int $config_id The chatbot configuration ID
     * @return array Success/error response
     */
    private function subscribe_app_to_waba(int $config_id): array {
        $waba_id = $this->get_option($config_id, 'business_account_id', '');
        $access_token = $this->get_option($config_id, 'access_token', '');

        if (empty($waba_id) || empty($access_token)) {
            $this->log('ERROR', 'subscribe_app_to_waba', 'Missing Business Account ID or Access Token');
            return array(
                'success' => false,
                'error' => 'Business Account ID and Access Token are required'
            );
        }

        $this->log('INFO', 'subscribe_app_to_waba', 'Subscribing app to WABA', array(
            'config_id' => $config_id,
            'waba_id' => $waba_id
        ));

        // POST to /{waba_id}/subscribed_apps
        $response = wp_remote_post(
            "{$this->api_base}/{$waba_id}/subscribed_apps",
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                )
            )
        );

        if (is_wp_error($response)) {
            $this->log('ERROR', 'subscribe_app_to_waba', 'API request failed', $response->get_error_message());
            return array(
                'success' => false,
                'error' => 'Failed to subscribe app to WABA: ' . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200 || isset($body['error'])) {
            $error_message = $body['error']['message'] ?? 'Unknown error';
            $error_code = $body['error']['code'] ?? 0;

            $this->log('ERROR', 'subscribe_app_to_waba', 'WABA subscription failed', array(
                'status_code' => $status_code,
                'error_code' => $error_code,
                'error' => $error_message
            ));

            return array(
                'success' => false,
                'error' => "WABA subscription failed: {$error_message} (Code: {$error_code})"
            );
        }

        // Check for success: true in response
        if (!isset($body['success']) || $body['success'] !== true) {
            $this->log('WARNING', 'subscribe_app_to_waba', 'Unexpected response', $body);
        }

        $this->log('INFO', 'subscribe_app_to_waba', 'App subscribed to WABA successfully', $body);

        return array(
            'success' => true,
            'data' => $body
        );
    }

    /**
     * Unsubscribe from app-level webhooks
     *
     * @param int $config_id The chatbot configuration ID
     * @return array Success/error response
     */
    private function unsubscribe_from_webhooks(int $config_id): array {
        $app_id = $this->get_option($config_id, 'app_id', '');
        $app_secret = $this->get_option($config_id, 'app_secret', '');

        if (empty($app_id) || empty($app_secret)) {
            return array('success' => true, 'message' => 'No webhook subscription to remove');
        }

        $app_access_token = $app_id . '|' . $app_secret;

        $this->log('INFO', 'unsubscribe_from_webhooks', 'Unsubscribing from webhooks', array(
            'config_id' => $config_id,
            'app_id' => $app_id
        ));

        // DELETE to /{app_id}/subscriptions
        $response = wp_remote_request(
            "{$this->api_base}/{$app_id}/subscriptions",
            array(
                'method' => 'DELETE',
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ),
                'body' => array(
                    'object' => 'whatsapp_business_account',
                    'access_token' => $app_access_token
                )
            )
        );

        if (is_wp_error($response)) {
            $this->log('WARNING', 'unsubscribe_from_webhooks', 'Failed to unsubscribe', $response->get_error_message());
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $this->log('INFO', 'unsubscribe_from_webhooks', 'Webhook unsubscription result', $body);

        return array('success' => true, 'data' => $body);
    }

    /**
     * Get settings fields for admin UI
     *
     * @return array
     */
    public function get_settings_fields(): array {
        return array(
            'webhook_base_url' => array(
                'type' => 'text',
                'label' => __('Public Webhook URL (Optional)', 'chatbot-plugin'),
                'description' => __('If your site is behind a tunnel/proxy (ngrok, cloudflared), enter the public URL here. Leave empty to use your WordPress site URL.', 'chatbot-plugin'),
                'placeholder' => 'https://your-tunnel-url.trycloudflare.com',
                'required' => false
            ),
            'app_id' => array(
                'type' => 'text',
                'label' => __('App ID', 'chatbot-plugin'),
                'description' => __('Your Meta App ID from App Settings > Basic in Meta Developer Dashboard', 'chatbot-plugin'),
                'placeholder' => '123456789012345',
                'required' => true
            ),
            'app_secret' => array(
                'type' => 'password',
                'label' => __('App Secret', 'chatbot-plugin'),
                'description' => __('Your Meta App Secret from App Settings > Basic (click "Show" to reveal)', 'chatbot-plugin'),
                'placeholder' => 'abc123def456...',
                'required' => true
            ),
            'business_account_id' => array(
                'type' => 'text',
                'label' => __('Business Account ID (WABA ID)', 'chatbot-plugin'),
                'description' => __('Your WhatsApp Business Account ID from Meta Business Manager', 'chatbot-plugin'),
                'placeholder' => '123456789012345',
                'required' => true
            ),
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
                'description' => __('Auto-generated token for webhook verification (read-only)', 'chatbot-plugin'),
                'placeholder' => 'Auto-generated',
                'required' => false,
                'readonly' => true
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
            'webhook_base_url' => $this->get_option($config_id, 'webhook_base_url', ''),
            'app_id' => $this->get_option($config_id, 'app_id', ''),
            'app_secret' => $this->get_option($config_id, 'app_secret', ''),
            'business_account_id' => $this->get_option($config_id, 'business_account_id', ''),
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
            'title' => __('WhatsApp Connection Complete', 'chatbot-plugin'),
            'steps' => array(
                __('Webhooks have been configured automatically via the Graph API.', 'chatbot-plugin'),
                __('Your chatbot is now ready to receive WhatsApp messages!', 'chatbot-plugin'),
                sprintf(__('Webhook URL: %s', 'chatbot-plugin'), $webhook_url),
                __('Note: Ensure your WordPress site is accessible via HTTPS for production use.', 'chatbot-plugin')
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
