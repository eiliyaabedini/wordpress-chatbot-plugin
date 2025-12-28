<?php
/**
 * Abstract Messaging Platform Base Class
 *
 * Provides a common interface for messaging platform integrations (Telegram, WhatsApp, etc.)
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Abstract class for messaging platform integrations
 */
abstract class Chatbot_Messaging_Platform {

    /**
     * Message pipeline instance.
     *
     * @var Chatbot_Message_Pipeline|null
     */
    protected $pipeline = null;

    /**
     * Set the message pipeline.
     *
     * @param Chatbot_Message_Pipeline $pipeline The pipeline.
     * @return void
     */
    public function set_pipeline(Chatbot_Message_Pipeline $pipeline): void {
        $this->pipeline = $pipeline;
    }

    /**
     * Get the message pipeline.
     *
     * @return Chatbot_Message_Pipeline|null
     */
    public function get_pipeline(): ?Chatbot_Message_Pipeline {
        return $this->pipeline;
    }

    /**
     * Get the platform identifier (e.g., 'telegram', 'whatsapp')
     *
     * @return string
     */
    abstract public function get_platform_id(): string;

    /**
     * Get the human-readable platform name
     *
     * @return string
     */
    abstract public function get_platform_name(): string;

    /**
     * Get the platform icon (dashicons class or URL)
     *
     * @return string
     */
    abstract public function get_platform_icon(): string;

    /**
     * Validate platform-specific credentials
     *
     * @param array $credentials Platform-specific credentials
     * @return array|false Array with validation result and info, or false on failure
     */
    abstract public function validate_credentials(array $credentials);

    /**
     * Connect the platform for a specific chatbot configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @param array $credentials Platform-specific credentials
     * @return array Result with 'success' boolean and 'message' or 'error'
     */
    abstract public function connect(int $config_id, array $credentials): array;

    /**
     * Disconnect the platform from a chatbot configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return bool Success status
     */
    abstract public function disconnect(int $config_id): bool;

    /**
     * Check if the platform is connected for a configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return bool
     */
    abstract public function is_connected(int $config_id): bool;

    /**
     * Send a message to a recipient
     *
     * @param int $config_id The chatbot configuration ID
     * @param string $recipient_id Platform-specific recipient identifier
     * @param string $message The message to send
     * @return bool Success status
     */
    abstract public function send_message(int $config_id, string $recipient_id, string $message): bool;

    /**
     * Handle incoming webhook request
     *
     * @param WP_REST_Request $request The REST request
     * @return WP_REST_Response
     */
    abstract public function handle_webhook(WP_REST_Request $request): WP_REST_Response;

    /**
     * Get the webhook URL for a configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return string
     */
    abstract public function get_webhook_url(int $config_id): string;

    /**
     * Register/setup the webhook with the platform
     *
     * @param int $config_id The chatbot configuration ID
     * @param array $credentials Platform credentials
     * @return array Result with 'success' boolean
     */
    abstract public function register_webhook(int $config_id, array $credentials): array;

    /**
     * Get platform-specific settings fields for admin UI
     *
     * @return array Array of field definitions
     */
    abstract public function get_settings_fields(): array;

    /**
     * Get stored credentials for a configuration
     *
     * @param int $config_id The chatbot configuration ID
     * @return array
     */
    abstract public function get_stored_credentials(int $config_id): array;

    /**
     * Check if the site is running on localhost
     *
     * @return bool
     */
    public function is_localhost(): bool {
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
     * Get or create a conversation for a platform chat
     *
     * @param string $platform_chat_id The platform-specific chat identifier
     * @param int $config_id The chatbot configuration ID
     * @param string $user_name The user's name
     * @return object|null The conversation object
     */
    protected function get_or_create_conversation(string $platform_chat_id, int $config_id, string $user_name) {
        $db = Chatbot_DB::get_instance();

        // Try to get existing conversation using the generic platform method
        $conversation = $db->get_conversation_by_platform_chat($platform_chat_id, $this->get_platform_id(), $config_id);

        if ($conversation) {
            return $conversation;
        }

        // Get config name for the conversation
        $config = $db->get_configuration($config_id);
        $config_name = $config ? $config->name : null;

        // Create new conversation with platform info
        $conversation_id = $db->create_conversation(
            $user_name . ' (' . $this->get_platform_name() . ')',
            $config_id,
            $config_name,
            null, // telegram_chat_id (legacy, will be deprecated)
            $this->get_platform_id(),
            $platform_chat_id
        );

        if (!$conversation_id) {
            return null;
        }

        return $db->get_conversation($conversation_id);
    }

    /**
     * Process an incoming message and generate AI response
     *
     * Uses the message pipeline if available, otherwise falls back to legacy handler.
     *
     * @param string $platform_chat_id The platform-specific chat identifier
     * @param string $user_name The user's name
     * @param string $message_text The message content
     * @param object $config The chatbot configuration
     * @return string The AI response
     */
    protected function process_incoming_message(string $platform_chat_id, string $user_name, string $message_text, object $config): string {
        // Try to use the pipeline if available
        if ($this->pipeline !== null) {
            return $this->process_via_pipeline($platform_chat_id, $user_name, $message_text, $config);
        }

        // Fall back to legacy processing
        return $this->process_legacy($platform_chat_id, $user_name, $message_text, $config);
    }

    /**
     * Process message through the new pipeline architecture.
     *
     * @param string $platform_chat_id The platform-specific chat identifier
     * @param string $user_name The user's name
     * @param string $message_text The message content
     * @param object $config The chatbot configuration
     * @return string The AI response
     */
    protected function process_via_pipeline(string $platform_chat_id, string $user_name, string $message_text, object $config): string {
        $this->log('INFO', 'process_message', 'Processing via pipeline', [
            'platform_chat_id' => $platform_chat_id,
            'config_id' => $config->id,
        ]);

        // Create message context
        $context = new Chatbot_Message_Context(
            $message_text,
            $this->get_platform_id(),
            $user_name,
            null, // conversation_id - pipeline will resolve
            $config->id,
            $platform_chat_id,
            ['source' => 'platform_webhook']
        );

        // Process through pipeline
        $response = $this->pipeline->process($context);

        if ($response->is_success()) {
            return $response->get_message();
        }

        $this->log('ERROR', 'process_message', 'Pipeline error: ' . $response->get_error());
        return "Sorry, I encountered an error. Please try again.";
    }

    /**
     * Legacy message processing method.
     *
     * @deprecated Use pipeline-based processing instead.
     *
     * @param string $platform_chat_id The platform-specific chat identifier
     * @param string $user_name The user's name
     * @param string $message_text The message content
     * @param object $config The chatbot configuration
     * @return string The AI response
     */
    protected function process_legacy(string $platform_chat_id, string $user_name, string $message_text, object $config): string {
        $db = Chatbot_DB::get_instance();

        // Get or create conversation
        $conversation = $this->get_or_create_conversation($platform_chat_id, $config->id, $user_name);

        if (!$conversation) {
            chatbot_log('ERROR', $this->get_platform_id() . '_process_message', 'Failed to get/create conversation');
            return "Sorry, I encountered an error. Please try again later.";
        }

        // Save the user message
        $db->add_message($conversation->id, 'user', $message_text);

        // Generate AI response
        try {
            $handler = Chatbot_Handler::get_instance();

            // Use the appropriate method based on what's available
            if (method_exists($handler, 'generate_platform_response')) {
                $ai_response = $handler->generate_platform_response($message_text, $conversation->id, $config->id);
            } else {
                // Fall back to telegram method for compatibility
                $ai_response = $handler->generate_telegram_response($message_text, $conversation->id, $config->id);
            }

            if (empty($ai_response)) {
                $ai_response = "I'm sorry, I couldn't generate a response. Please try again.";
            }

            // Save the AI response
            $db->add_message($conversation->id, 'ai', $ai_response);

            return $ai_response;

        } catch (Exception $e) {
            chatbot_log('ERROR', $this->get_platform_id() . '_process_message', 'Exception: ' . $e->getMessage());
            return "Sorry, I encountered an error. Please try again.";
        }
    }

    /**
     * Log a platform-specific message
     *
     * @param string $level Log level (INFO, DEBUG, ERROR, etc.)
     * @param string $context Additional context
     * @param string $message Log message
     * @param mixed $data Optional data
     */
    protected function log(string $level, string $context, string $message, $data = null): void {
        if (function_exists('chatbot_log')) {
            chatbot_log($level, $this->get_platform_id() . '_' . $context, $message, $data);
        }
    }

    /**
     * Generate a secure token for webhook verification
     *
     * @param int $config_id The chatbot configuration ID
     * @return string
     */
    protected function generate_secret_token(int $config_id): string {
        return wp_generate_password(32, false);
    }

    /**
     * Store a platform-specific option
     *
     * @param int $config_id The chatbot configuration ID
     * @param string $key The option key suffix
     * @param mixed $value The value to store
     */
    protected function store_option(int $config_id, string $key, $value): void {
        update_option("chatbot_{$this->get_platform_id()}_{$key}_{$config_id}", $value);
    }

    /**
     * Get a platform-specific option
     *
     * @param int $config_id The chatbot configuration ID
     * @param string $key The option key suffix
     * @param mixed $default Default value
     * @return mixed
     */
    protected function get_option(int $config_id, string $key, $default = null) {
        return get_option("chatbot_{$this->get_platform_id()}_{$key}_{$config_id}", $default);
    }

    /**
     * Delete a platform-specific option
     *
     * @param int $config_id The chatbot configuration ID
     * @param string $key The option key suffix
     */
    protected function delete_option(int $config_id, string $key): void {
        delete_option("chatbot_{$this->get_platform_id()}_{$key}_{$config_id}");
    }
}
