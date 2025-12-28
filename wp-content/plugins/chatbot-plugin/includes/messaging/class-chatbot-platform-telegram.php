<?php
/**
 * Telegram Messaging Platform Implementation
 *
 * Handles Telegram bot integration using the new platform architecture
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Telegram platform implementation
 */
class Chatbot_Platform_Telegram extends Chatbot_Messaging_Platform {

    /**
     * Telegram API base URL
     */
    private $api_base = 'https://api.telegram.org/bot';

    /**
     * Get the platform identifier
     *
     * @return string
     */
    public function get_platform_id(): string {
        return 'telegram';
    }

    /**
     * Get the human-readable platform name
     *
     * @return string
     */
    public function get_platform_name(): string {
        return 'Telegram';
    }

    /**
     * Get the platform icon
     *
     * @return string
     */
    public function get_platform_icon(): string {
        return 'dashicons-format-status';
    }

    /**
     * Validate Telegram bot token
     *
     * @param array $credentials Array with 'bot_token' key
     * @return array|false Bot info on success, false on failure
     */
    public function validate_credentials(array $credentials) {
        $token = $credentials['bot_token'] ?? '';

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
     * Connect Telegram bot to a chatbot configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @param array $credentials Array with 'bot_token' key
     * @return array
     */
    public function connect(int $config_id, array $credentials): array {
        $bot_token = $credentials['bot_token'] ?? '';

        if (empty($bot_token)) {
            return array('success' => false, 'error' => 'Bot token is required');
        }

        // Validate the token
        $bot_info = $this->validate_credentials($credentials);

        if (!$bot_info) {
            return array('success' => false, 'error' => 'Invalid bot token');
        }

        $is_localhost = $this->is_localhost();
        $use_polling = false;

        if ($is_localhost) {
            // Delete any existing webhook and use polling mode
            $this->delete_telegram_webhook($bot_token);
            $use_polling = true;
            $this->store_option($config_id, 'polling', true);
            $this->store_option($config_id, 'update_id', 0);
        } else {
            // Register webhook
            $webhook_result = $this->register_webhook($config_id, $credentials);

            if (!$webhook_result['success']) {
                return $webhook_result;
            }

            $this->store_option($config_id, 'polling', false);
        }

        // Save the bot token to the configuration
        $db = Chatbot_DB::get_instance();
        $config = $db->get_configuration($config_id);

        if (!$config) {
            return array('success' => false, 'error' => 'Configuration not found');
        }

        $result = $db->update_configuration(
            $config_id,
            $config->name,
            $config->system_prompt,
            $config->knowledge,
            $config->persona,
            $config->knowledge_sources,
            $bot_token // telegram_bot_token
        );

        if (!$result) {
            return array('success' => false, 'error' => 'Failed to save bot token');
        }

        $message = $use_polling
            ? 'Telegram bot connected in POLLING mode (local development).'
            : 'Telegram bot connected successfully!';

        return array(
            'success' => true,
            'message' => $message,
            'bot_info' => $bot_info,
            'mode' => $use_polling ? 'polling' : 'webhook',
            'is_localhost' => $is_localhost
        );
    }

    /**
     * Disconnect Telegram bot from a configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return bool
     */
    public function disconnect(int $config_id): bool {
        $db = Chatbot_DB::get_instance();
        $config = $db->get_configuration($config_id);

        if (!$config || empty($config->telegram_bot_token)) {
            return false;
        }

        // Unregister webhook
        $this->unregister_webhook($config->telegram_bot_token, $config_id);

        // Clear the token from configuration
        $result = $db->update_configuration(
            $config_id,
            $config->name,
            $config->system_prompt,
            $config->knowledge,
            $config->persona,
            $config->knowledge_sources,
            '' // Clear telegram_bot_token
        );

        // Clean up options
        $this->delete_option($config_id, 'polling');
        $this->delete_option($config_id, 'update_id');
        $this->delete_option($config_id, 'last_update');

        return $result !== false;
    }

    /**
     * Check if Telegram is connected for a configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return bool
     */
    public function is_connected(int $config_id): bool {
        $db = Chatbot_DB::get_instance();
        $config = $db->get_configuration($config_id);

        return $config && !empty($config->telegram_bot_token);
    }

    /**
     * Send a message via Telegram
     *
     * @param int $config_id The chatbot configuration ID
     * @param string $recipient_id The Telegram chat ID
     * @param string $message The message to send
     * @return bool
     */
    public function send_message(int $config_id, string $recipient_id, string $message): bool {
        $db = Chatbot_DB::get_instance();
        $config = $db->get_configuration($config_id);

        if (!$config || empty($config->telegram_bot_token)) {
            return false;
        }

        return $this->send_telegram_message($config->telegram_bot_token, $recipient_id, $message);
    }

    /**
     * Handle incoming Telegram webhook
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_webhook(WP_REST_Request $request): WP_REST_Response {
        $config_id = intval($request->get_param('config_id'));

        // Validate secret token
        $stored_secret = get_option("chatbot_telegram_secret_{$config_id}");
        $header_secret = $request->get_header('X-Telegram-Bot-Api-Secret-Token');

        if (empty($stored_secret) || $stored_secret !== $header_secret) {
            return new WP_REST_Response(array('error' => 'Invalid secret token'), 403);
        }

        $db = Chatbot_DB::get_instance();
        $config = $db->get_configuration($config_id);

        if (!$config || empty($config->telegram_bot_token)) {
            return new WP_REST_Response(array('error' => 'Configuration not found'), 404);
        }

        $update = $request->get_json_params();
        $message = $update['message'] ?? null;

        if (!$message) {
            return new WP_REST_Response(array('ok' => true), 200);
        }

        $chat_id = (string) ($message['chat']['id'] ?? '');
        $user_name = $message['from']['first_name'] ?? 'Telegram User';

        // Extract text and files from message
        $message_text = $message['text'] ?? $message['caption'] ?? '';
        $files = $this->extract_files_from_message($message, $config->telegram_bot_token);

        // Skip if no text and no files
        if (empty($message_text) && empty($files)) {
            return new WP_REST_Response(array('ok' => true), 200);
        }

        // Handle /start command
        if ($message_text === '/start') {
            $this->send_telegram_message($config->telegram_bot_token, $chat_id, "Hello! I'm an AI assistant. How can I help you today? You can send me text, images, PDFs, and documents!");
            return new WP_REST_Response(array('ok' => true), 200);
        }

        // Use default prompt if only files are sent
        if (empty($message_text) && !empty($files)) {
            $message_text = 'Please analyze the attached file(s).';
        }

        // Process the message with files
        $ai_response = $this->process_message_with_files($chat_id, $user_name, $message_text, $config, $files);

        // Send the response
        $this->send_telegram_message($config->telegram_bot_token, $chat_id, $ai_response);

        return new WP_REST_Response(array('ok' => true), 200);
    }

    /**
     * Extract files from a Telegram message.
     *
     * @param array  $message   The Telegram message.
     * @param string $bot_token The bot token.
     * @return array Array of file data.
     */
    private function extract_files_from_message(array $message, string $bot_token): array {
        $files = [];

        // Handle photo (array of sizes, get the largest)
        if (!empty($message['photo'])) {
            $photo = end($message['photo']); // Get largest size
            $file_data = $this->download_telegram_file($bot_token, $photo['file_id'], 'image/jpeg', 'photo.jpg');
            if ($file_data) {
                $files[] = $file_data;
            }
        }

        // Handle document
        if (!empty($message['document'])) {
            $doc = $message['document'];
            $mime_type = $doc['mime_type'] ?? 'application/octet-stream';
            $file_name = $doc['file_name'] ?? 'document';
            $file_data = $this->download_telegram_file($bot_token, $doc['file_id'], $mime_type, $file_name);
            if ($file_data) {
                $files[] = $file_data;
            }
        }

        return $files;
    }

    /**
     * Download a file from Telegram.
     *
     * @param string $bot_token The bot token.
     * @param string $file_id   The Telegram file ID.
     * @param string $mime_type The MIME type.
     * @param string $file_name The original filename.
     * @return array|null File data or null on error.
     */
    private function download_telegram_file(string $bot_token, string $file_id, string $mime_type, string $file_name): ?array {
        // Get file path from Telegram
        $response = wp_remote_get($this->api_base . $bot_token . '/getFile?file_id=' . urlencode($file_id), [
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->log('ERROR', 'download_file', 'Failed to get file path: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['ok']) || !$body['ok'] || !isset($body['result']['file_path'])) {
            $this->log('ERROR', 'download_file', 'Invalid getFile response', $body);
            return null;
        }

        $file_path = $body['result']['file_path'];

        // Download the file
        $file_url = "https://api.telegram.org/file/bot{$bot_token}/{$file_path}";
        $file_response = wp_remote_get($file_url, [
            'timeout' => 60,
        ]);

        if (is_wp_error($file_response)) {
            $this->log('ERROR', 'download_file', 'Failed to download file: ' . $file_response->get_error_message());
            return null;
        }

        $file_content = wp_remote_retrieve_body($file_response);
        if (empty($file_content)) {
            $this->log('ERROR', 'download_file', 'Empty file content');
            return null;
        }

        // Check file size (max 20MB for Telegram)
        if (strlen($file_content) > 20 * 1024 * 1024) {
            $this->log('WARN', 'download_file', 'File too large', ['size' => strlen($file_content)]);
            return null;
        }

        // Determine file type
        $file_type = $this->get_file_type_from_mime($mime_type);
        if ($file_type === null) {
            $this->log('WARN', 'download_file', 'Unsupported file type', ['mime_type' => $mime_type]);
            return null;
        }

        $this->log('INFO', 'download_file', 'Downloaded file from Telegram', [
            'file_name' => $file_name,
            'file_type' => $file_type,
            'size' => strlen($file_content),
        ]);

        return [
            'type' => $file_type,
            'data' => base64_encode($file_content),
            'mime_type' => $mime_type,
            'name' => $file_name,
        ];
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
     * Process a message with file attachments.
     *
     * @param string $chat_id      The Telegram chat ID.
     * @param string $user_name    The user's name.
     * @param string $message_text The message text.
     * @param object $config       The chatbot configuration.
     * @param array  $files        Array of file data.
     * @return string The AI response.
     */
    private function process_message_with_files(string $chat_id, string $user_name, string $message_text, object $config, array $files): string {
        // If we have pipeline with files support, use it
        if ($this->pipeline !== null && !empty($files)) {
            $context = new Chatbot_Message_Context(
                $message_text,
                $this->get_platform_id(),
                $user_name,
                null,
                $config->id,
                $chat_id,
                ['source' => 'telegram_webhook'],
                $files
            );

            $response = $this->pipeline->process($context);

            if ($response->is_success()) {
                return $response->get_message();
            }

            $this->log('ERROR', 'process_message', 'Pipeline error: ' . $response->get_error());
            return "Sorry, I encountered an error processing your request. Please try again.";
        }

        // Fall back to standard processing (without files)
        return $this->process_incoming_message($chat_id, $user_name, $message_text, $config);
    }

    /**
     * Get the webhook URL for a configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return string
     */
    public function get_webhook_url(int $config_id): string {
        // Use the new unified webhook URL format
        return rest_url("chatbot-plugin/v1/webhook/telegram/{$config_id}");
    }

    /**
     * Get legacy webhook URL for backward compatibility
     *
     * @param int $config_id The chatbot configuration ID
     * @return string
     */
    public function get_legacy_webhook_url(int $config_id): string {
        return rest_url("chatbot-plugin/v1/telegram-webhook/{$config_id}");
    }

    /**
     * Register webhook with Telegram
     *
     * @param int $config_id The chatbot configuration ID
     * @param array $credentials Array with 'bot_token' key
     * @return array
     */
    public function register_webhook(int $config_id, array $credentials): array {
        $bot_token = $credentials['bot_token'] ?? '';

        // Use legacy URL for backward compatibility
        $webhook_url = $this->get_legacy_webhook_url($config_id);

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
                'error' => $body['description'] ?? 'Unknown error'
            );
        }

        // Store the secret token
        update_option("chatbot_telegram_secret_{$config_id}", $secret);

        return array(
            'success' => true,
            'webhook_url' => $webhook_url
        );
    }

    /**
     * Get settings fields for admin UI
     *
     * @return array
     */
    public function get_settings_fields(): array {
        return array(
            'bot_token' => array(
                'type' => 'text',
                'label' => __('Bot Token', 'chatbot-plugin'),
                'description' => __('Get your bot token from @BotFather on Telegram', 'chatbot-plugin'),
                'placeholder' => '123456789:ABCdefGHIjklMNOpqrsTUVwxyz',
                'required' => true
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
        $db = Chatbot_DB::get_instance();
        $config = $db->get_configuration($config_id);

        if (!$config) {
            return array();
        }

        return array(
            'bot_token' => $config->telegram_bot_token ?? '',
            'polling_mode' => (bool) $this->get_option($config_id, 'polling', false)
        );
    }

    /**
     * Delete webhook from Telegram
     *
     * @param string $bot_token The bot token
     * @return bool
     */
    private function delete_telegram_webhook(string $bot_token): bool {
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
     *
     * @param string $bot_token The bot token
     * @param int $config_id The configuration ID
     * @return bool
     */
    private function unregister_webhook(string $bot_token, int $config_id): bool {
        $response = wp_remote_post($this->api_base . $bot_token . '/deleteWebhook', array(
            'timeout' => 10,
            'body' => array('drop_pending_updates' => true)
        ));

        delete_option("chatbot_telegram_secret_{$config_id}");

        return !is_wp_error($response);
    }

    /**
     * Send a message via Telegram API
     *
     * @param string $bot_token The bot token
     * @param string $chat_id The chat ID
     * @param string $message The message
     * @return bool
     */
    private function send_telegram_message(string $bot_token, string $chat_id, string $message): bool {
        // Handle long messages by splitting them
        if (strlen($message) > 4096) {
            $chunks = str_split($message, 4000);
            foreach ($chunks as $chunk) {
                $this->send_telegram_message($bot_token, $chat_id, $chunk);
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
     * Poll for updates (used in localhost/development)
     *
     * @param object $config The chatbot configuration
     */
    public function poll_for_updates(object $config): void {
        $config_id = $config->id;
        $bot_token = $config->telegram_bot_token;

        // Prevent concurrent polling
        $lock_key = "chatbot_telegram_polling_lock_{$config_id}";
        if (get_transient($lock_key)) {
            return;
        }

        set_transient($lock_key, true, 60);

        try {
            $last_update_id = (int) $this->get_option($config_id, 'last_update', 0);
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

                $this->store_option($config_id, 'last_update', $update_id);
                $this->process_polling_update($update, $config);
            }
        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Get updates from Telegram using long polling
     *
     * @param string $bot_token The bot token
     * @param int $offset The update offset
     * @return array
     */
    private function get_updates(string $bot_token, int $offset = 0): array {
        $params = array(
            'timeout' => 5,
            'allowed_updates' => json_encode(array('message'))
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

        return $body['result'] ?? array();
    }

    /**
     * Process a single update from polling
     *
     * @param array $update The update data
     * @param object $config The chatbot configuration
     */
    private function process_polling_update(array $update, object $config): void {
        if (!isset($update['message']['text'])) {
            return;
        }

        $chat_id = (string) $update['message']['chat']['id'];
        $user_name = $update['message']['from']['first_name'] ?? 'Telegram User';
        $message_text = $update['message']['text'];

        // Handle /start command
        if ($message_text === '/start') {
            $this->send_telegram_message($config->telegram_bot_token, $chat_id, "Hello! I'm an AI assistant. How can I help you today?");
            return;
        }

        // Process the message and get AI response
        $ai_response = $this->process_incoming_message($chat_id, $user_name, $message_text, $config);

        // Send the response
        $this->send_telegram_message($config->telegram_bot_token, $chat_id, $ai_response);
    }
}
