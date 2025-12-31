<?php
/**
 * Chatbot Embed API
 *
 * REST API endpoints for embedding chatbot on external websites.
 * Uses token-based authentication instead of WordPress nonces for cross-origin requests.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Handles REST API endpoints for external website embedding
 */
class Chatbot_Embed_API {

    /**
     * Singleton instance
     *
     * @var Chatbot_Embed_API|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return Chatbot_Embed_API
     */
    public static function get_instance(): Chatbot_Embed_API {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // CORS is handled in chatbot-plugin.php at plugin load time
        // This ensures CORS headers are sent before WordPress REST API processes requests
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        $namespace = 'chatbot-plugin/v1';

        // Note: OPTIONS/CORS preflight is handled at plugin load time in chatbot-plugin.php
        // This ensures CORS headers are sent before WordPress REST API processes the request

        // Get chatbot configuration (for initial load)
        register_rest_route($namespace, '/embed/(?P<token>[a-f0-9]{64})/config', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_config'),
            'permission_callback' => '__return_true',
            'args' => $this->get_token_args(),
        ));

        // Initialize/start conversation
        register_rest_route($namespace, '/embed/(?P<token>[a-f0-9]{64})/init', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_init'),
            'permission_callback' => '__return_true',
            'args' => $this->get_token_args(),
        ));

        // Send message and get AI response
        register_rest_route($namespace, '/embed/(?P<token>[a-f0-9]{64})/message', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_message'),
            'permission_callback' => '__return_true',
            'args' => $this->get_token_args(),
        ));

        // Get conversation messages (for polling)
        register_rest_route($namespace, '/embed/(?P<token>[a-f0-9]{64})/messages', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_messages'),
            'permission_callback' => '__return_true',
            'args' => $this->get_token_args(),
        ));

        // End conversation
        register_rest_route($namespace, '/embed/(?P<token>[a-f0-9]{64})/end', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_end'),
            'permission_callback' => '__return_true',
            'args' => $this->get_token_args(),
        ));

    }

    /**
     * Get token validation arguments
     *
     * @return array
     */
    private function get_token_args(): array {
        return array(
            'token' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return preg_match('/^[a-f0-9]{64}$/', $param);
                },
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    /**
     * Add CORS headers to response
     */
    private function add_cors_headers(): void {
        // Allow requests from any origin for embed functionality
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Session-ID');
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * Handle CORS preflight requests
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_preflight(WP_REST_Request $request): WP_REST_Response {
        $this->add_cors_headers();
        return new WP_REST_Response(null, 204);
    }

    /**
     * Validate embed token and get configuration
     *
     * @param string $token
     * @return object|null Configuration object or null if invalid
     */
    private function validate_token(string $token): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'chatbot_configurations';

        $config = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE embed_token = %s AND embed_enabled = 1",
            $token
        ));

        if (!$config) {
            return null;
        }

        return $config;
    }

    /**
     * Get session ID from request
     *
     * @param WP_REST_Request $request
     * @return string|null
     */
    private function get_session_id(WP_REST_Request $request): ?string {
        // Try header first
        $session_id = $request->get_header('X-Session-ID');

        // Fall back to body parameter
        if (empty($session_id)) {
            $session_id = $request->get_param('session_id');
        }

        return !empty($session_id) ? sanitize_text_field($session_id) : null;
    }

    /**
     * Handle config request - returns chatbot configuration for initial setup
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_config(WP_REST_Request $request): WP_REST_Response {
        $this->add_cors_headers();

        $token = $request->get_param('token');
        $config = $this->validate_token($token);

        if (!$config) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Invalid or disabled embed token',
            ), 401);
        }


        // Get greeting from chatbot configuration
        $greeting_template = isset($config->greeting) && !empty($config->greeting)
            ? $config->greeting
            : 'Hello! How can I help you today?';

        return new WP_REST_Response(array(
            'success' => true,
            'chatbot_name' => $config->name,
            'greeting' => $greeting_template,
            'primary_color' => get_option('chatbot_primary_color', '#4a6cf7'),
        ), 200);
    }

    /**
     * Handle init request - starts a new conversation
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_init(WP_REST_Request $request): WP_REST_Response {
        $this->add_cors_headers();

        $token = $request->get_param('token');
        $config = $this->validate_token($token);

        if (!$config) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Invalid or disabled embed token',
            ), 401);
        }

        $visitor_name = sanitize_text_field($request->get_param('visitor_name') ?? 'Visitor');

        // Generate unique session ID - conversation will be created on first message
        $session_id = wp_generate_uuid4();

        // Get greeting from chatbot configuration with visitor name
        $greeting_template = isset($config->greeting) && !empty($config->greeting)
            ? $config->greeting
            : 'Hello %s! How can I help you today?';
        $greeting = sprintf($greeting_template, $visitor_name);


        return new WP_REST_Response(array(
            'success' => true,
            'session_id' => $session_id,
            'chatbot_name' => $config->name,
            'greeting' => $greeting,
        ), 200);
    }

    /**
     * Handle message request - sends message and gets AI response
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_message(WP_REST_Request $request): WP_REST_Response {
        $this->add_cors_headers();

        $token = $request->get_param('token');
        $config = $this->validate_token($token);

        if (!$config) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Invalid or disabled embed token',
            ), 401);
        }

        $session_id = $this->get_session_id($request);
        if (empty($session_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Session ID required',
            ), 400);
        }

        $message = sanitize_textarea_field($request->get_param('message') ?? '');
        if (empty($message)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Message is required',
            ), 400);
        }

        // Enforce message length limit
        $max_length = intval(get_option('chatbot_max_message_length', 500));
        if (strlen($message) > $max_length) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => "Message exceeds maximum length of {$max_length} characters",
            ), 400);
        }

        // Find conversation by session_id (stored in platform_chat_id)
        $db = Chatbot_DB::get_instance();
        $conversation = $this->get_conversation_by_session($session_id, $config->id);

        // Create conversation on first message (lazy creation - avoids empty conversations)
        if (!$conversation) {
            $visitor_name = sanitize_text_field($request->get_param('visitor_name') ?? 'Visitor');
            $conversation_id = $db->create_conversation(
                $visitor_name,
                $config->id,
                $config->name,
                null,           // telegram_chat_id
                'embed',        // platform_type
                $session_id     // platform_chat_id (use session_id for embed)
            );

            if (!$conversation_id) {
                chatbot_log('ERROR', 'embed_api', 'Failed to create conversation for embed');
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => 'Failed to create conversation',
                ), 500);
            }


            // Re-fetch the conversation object
            $conversation = $this->get_conversation_by_session($session_id, $config->id);
        }

        // Check if conversation is still active
        if ($conversation->status !== 'active') {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Conversation has ended. Please start a new conversation.',
            ), 400);
        }

        // Apply rate limiting using session_id as user identifier
        $rate_limiter = Chatbot_Rate_Limiter::get_instance();
        $rate_check = $rate_limiter->can_send_message($session_id, $message);
        if (!$rate_check['allowed']) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $rate_check['message'] ?? 'Rate limit exceeded. Please wait a moment before sending another message.',
            ), 429);
        }

        // Increment rate counters after successful check
        $rate_limiter->increment_rate_counters($session_id);

        // Save user message
        $db->add_message($conversation->id, 'user', $message);

        try {
            // Generate AI response
            $ai = Chatbot_AI::get_instance();


            $response = $ai->generate_response($conversation->id, $message, $config);

            if (empty($response)) {
                chatbot_log('WARNING', 'embed_api', 'AI returned empty response');
                $response = "I'm sorry, I couldn't process your request. Please try again.";
            }

            // Save AI response
            $db->add_message($conversation->id, 'ai', $response);


            return new WP_REST_Response(array(
                'success' => true,
                'response' => $response,
                'conversation_id' => $conversation->id,
            ), 200);

        } catch (\Exception $e) {
            chatbot_log('ERROR', 'embed_api', 'Exception in generate_response: ' . $e->getMessage(), array(
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ));

            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'An error occurred while processing your message. Please try again.',
                'debug' => WP_DEBUG ? $e->getMessage() : null,
            ), 500);
        } catch (\Error $e) {
            chatbot_log('ERROR', 'embed_api', 'Fatal error in generate_response: ' . $e->getMessage(), array(
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ));

            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'A server error occurred. Please try again later.',
                'debug' => WP_DEBUG ? $e->getMessage() : null,
            ), 500);
        }
    }

    /**
     * Handle get messages request - returns conversation messages
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_get_messages(WP_REST_Request $request): WP_REST_Response {
        $this->add_cors_headers();

        $token = $request->get_param('token');
        $config = $this->validate_token($token);

        if (!$config) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Invalid or disabled embed token',
            ), 401);
        }

        $session_id = $this->get_session_id($request);
        if (empty($session_id)) {
            // Also try query parameter
            $session_id = sanitize_text_field($request->get_param('session_id') ?? '');
        }

        if (empty($session_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Session ID required',
            ), 400);
        }

        $conversation = $this->get_conversation_by_session($session_id, $config->id);

        // No conversation yet means user never sent a message - return empty (not error)
        if (!$conversation) {
            return new WP_REST_Response(array(
                'success' => true,
                'messages' => array(),
                'conversation_status' => null,
            ), 200);
        }

        // Get messages for this conversation
        $db = Chatbot_DB::get_instance();
        $messages = $db->get_messages($conversation->id);

        // Format messages for response, filtering out function call messages
        $formatted_messages = array();
        foreach ($messages as $msg) {
            // Skip function call messages - they're internal AI operations, not user-facing
            if ($this->is_function_call_message($msg->message)) {
                continue;
            }

            $formatted_messages[] = array(
                'id' => $msg->id,
                'sender_type' => $msg->sender_type,
                'message' => $msg->message,
                'timestamp' => $msg->timestamp,
            );
        }

        return new WP_REST_Response(array(
            'success' => true,
            'messages' => $formatted_messages,
            'conversation_status' => $conversation->status,
        ), 200);
    }

    /**
     * Handle end conversation request
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_end(WP_REST_Request $request): WP_REST_Response {
        $this->add_cors_headers();

        $token = $request->get_param('token');
        $config = $this->validate_token($token);

        if (!$config) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Invalid or disabled embed token',
            ), 401);
        }

        $session_id = $this->get_session_id($request);
        if (empty($session_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Session ID required',
            ), 400);
        }

        $conversation = $this->get_conversation_by_session($session_id, $config->id);

        if (!$conversation) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Conversation not found',
            ), 404);
        }

        // End the conversation
        $db = Chatbot_DB::get_instance();
        $db->end_conversation($conversation->id);


        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Conversation ended',
        ), 200);
    }

    /**
     * Get conversation by session ID
     *
     * @param string $session_id
     * @param int $config_id
     * @return object|null
     */
    private function get_conversation_by_session(string $session_id, int $config_id): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'chatbot_conversations';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table
             WHERE platform_type = 'embed'
             AND platform_chat_id = %s
             AND chatbot_config_id = %d
             ORDER BY created_at DESC
             LIMIT 1",
            $session_id,
            $config_id
        ));
    }

    /**
     * Generate a new embed token
     *
     * @return string 64-character hex token
     */
    public static function generate_token(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Check if a message is a function call message (internal AI operation)
     * These should not be shown to users in chat history
     *
     * @param string $message The message content
     * @return bool True if this is a function call message
     */
    private function is_function_call_message(string $message): bool {
        // Function call messages contain these patterns
        $function_call_patterns = array(
            '🔧 Function Call:',
            'Function Call:',
            'Status: ✅ SUCCESS',
            'Status: ❌ FAILED',
        );

        foreach ($function_call_patterns as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
