<?php
/**
 * Chatbot Message Handler
 * 
 * Handles incoming chatbot messages and generates responses
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_Handler {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_chatbot_start_conversation', array($this, 'start_conversation'));
        add_action('wp_ajax_nopriv_chatbot_start_conversation', array($this, 'start_conversation'));
        
        add_action('wp_ajax_chatbot_send_message', array($this, 'send_message'));
        add_action('wp_ajax_nopriv_chatbot_send_message', array($this, 'send_message'));
        
        add_action('wp_ajax_chatbot_get_messages', array($this, 'get_messages'));
        add_action('wp_ajax_nopriv_chatbot_get_messages', array($this, 'get_messages'));
        
        add_action('wp_ajax_chatbot_end_conversation', array($this, 'end_conversation'));
        add_action('wp_ajax_nopriv_chatbot_end_conversation', array($this, 'end_conversation'));
    }
    
    /**
     * Start a new conversation
     */
    public function start_conversation() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-plugin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }
        
        // Get visitor name
        $visitor_name = isset($_POST['visitor_name']) ? sanitize_text_field($_POST['visitor_name']) : '';
        
        if (empty($visitor_name)) {
            wp_send_json_error(array('message' => 'No visitor name provided.'));
        }
        
        // Get chatbot config name if provided, default to "Default" if empty
        $config_name = isset($_POST['config_name']) ? sanitize_text_field($_POST['config_name']) : 'Default';
        
        // Initialize database handler
        $db = Chatbot_DB::get_instance();
        
        // If a specific chatbot configuration is requested, get its ID and name
        $chatbot_config_id = null;
        $chatbot_config = null;
        
        // Always try to get the requested configuration
        $chatbot_config = $db->get_configuration_by_name($config_name);
        
        // If not found, fall back to Default Configuration
        if (!$chatbot_config) {
            chatbot_log('WARNING', 'start_conversation', "Chatbot configuration not found: $config_name, falling back to default");
            $chatbot_config = $db->get_configuration_by_name('Default');
        }
        
        // If we have a config, get its ID
        if ($chatbot_config) {
            $chatbot_config_id = $chatbot_config->id;
            chatbot_log('INFO', 'start_conversation', "Using chatbot config: {$chatbot_config->name} (ID: $chatbot_config_id)");
        } else {
            // If no configuration found at all, log this issue
            chatbot_log('ERROR', 'start_conversation', "No chatbot configurations found, including default");
        }
        
        // Create a new conversation with the chatbot config information
        $conversation_id = $db->create_conversation(
            $visitor_name, 
            $chatbot_config_id, 
            $chatbot_config ? $chatbot_config->name : null
        );
        
        if (!$conversation_id) {
            wp_send_json_error(array('message' => 'Error creating conversation.'));
        }
        
        // Store configuration in session for easy access
        if ($chatbot_config) {
            // Make sure session is started
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['chatbot_config_' . $conversation_id] = $chatbot_config;
            chatbot_log('INFO', 'start_conversation', "Stored chatbot config in session: $conversation_id");
            
            // Also store the config name in the first message for backup recovery
            $first_message = '<div class="chatbot-system-config-metadata" style="display:none;" data-config-name="' . esc_attr($config_name) . '"></div>';
            $db->add_message($conversation_id, 'system', $first_message);
        }
        
        // Add initial welcome message from admin using customized greeting from settings
        // If no custom chat greeting is defined, fall back to default
        $default_greeting = 'Hello %s! How can I help you today?';
        $welcome_format = get_option('chatbot_chat_greeting', $default_greeting);
        $welcome_message = sprintf($welcome_format, $visitor_name);
        $db->add_message($conversation_id, 'admin', $welcome_message);
        
        // Trigger conversation created hook for notifications
        do_action('chatbot_conversation_created', $conversation_id, array(
            'visitor_name' => $visitor_name,
            'first_message' => $welcome_message
        ));
        
        chatbot_log('INFO', 'start_conversation', 'Conversation created and notifications triggered', 
            array('conversation_id' => $conversation_id, 'visitor_name' => $visitor_name));
        
        wp_send_json_success(array(
            'conversation_id' => $conversation_id,
            'message' => 'Conversation started.'
        ));
    }
    
    /**
     * Send a message in a conversation
     */
    public function send_message() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-plugin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }

        // Get parameters
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        $sender_type = isset($_POST['sender_type']) ? sanitize_text_field($_POST['sender_type']) : 'user';

        if (empty($message) || empty($conversation_id)) {
            wp_send_json_error(array('message' => 'Missing required parameters.'));
        }

        // Check rate limits only for user messages
        if ($sender_type === 'user' && class_exists('Chatbot_Rate_Limiter')) {
            $rate_limiter = Chatbot_Rate_Limiter::get_instance();
            $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

            // Check if rate limit is exceeded and message length is acceptable
            $rate_check = $rate_limiter->can_send_message($session_id, $message);
            if (!$rate_check['allowed']) {
                $log_type = ($rate_check['reason'] === 'message_too_long') ? 'message_length' : 'rate_limit';

                if (function_exists('chatbot_log')) {
                    chatbot_log('WARN', $log_type, 'Message rejected', array(
                        'reason' => $rate_check['reason'],
                        'session_id' => $session_id,
                        'message_length' => isset($rate_check['current_length']) ? $rate_check['current_length'] : null,
                        'max_length' => isset($rate_check['max_length']) ? $rate_check['max_length'] : null
                    ));
                }

                wp_send_json_error(array(
                    'message' => $rate_check['message'],
                    'rate_limited' => true,
                    'reason' => $rate_check['reason'],
                    'current_length' => isset($rate_check['current_length']) ? $rate_check['current_length'] : null,
                    'max_length' => isset($rate_check['max_length']) ? $rate_check['max_length'] : null
                ));
                return;
            }
        }

        // Check if the conversation is active
        $db = Chatbot_DB::get_instance();
        $conversation = $db->get_conversation($conversation_id);

        if (!$conversation || $conversation->status !== 'active') {
            wp_send_json_error(array('message' => 'Conversation is not active.'));
        }

        // Add the message to the database
        $message_id = $db->add_message($conversation_id, $sender_type, $message);

        if (!$message_id) {
            wp_send_json_error(array('message' => 'Error saving message.'));
        }

        // Increment rate limit counters for user messages
        if ($sender_type === 'user' && class_exists('Chatbot_Rate_Limiter')) {
            $rate_limiter = Chatbot_Rate_Limiter::get_instance();
            $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
            $rate_limiter->increment_rate_counters($session_id);
        }
        
        // If it's a user message, generate an automatic admin response
        if ($sender_type === 'user') {
            // Generate a response, passing the conversation ID for context
            $response = $this->generate_response($message, $conversation_id);
            
            // Add AI response to the database
            // Use 'ai' sender type for AI responses to distinguish from human admin messages
            $sender_type = 'admin'; // Default to admin

            if (class_exists('Chatbot_AI')) {
                $ai_instance = Chatbot_AI::get_instance();
                $ai_instance->refresh_settings();

                if ($ai_instance->is_configured()) {
                    $sender_type = 'ai';
                } else {
                    // Log that AIPass is not connected
                    chatbot_log('WARN', 'send_message', 'AIPass not connected, using fallback responses');
                }
            }

            $ai_message_id = $db->add_message($conversation_id, $sender_type, $response);

            // Return AI response directly so frontend can display it immediately
            wp_send_json_success(array(
                'message_id' => $message_id,
                'ai_response' => $response,
                'ai_sender_type' => $sender_type,
                'ai_message_id' => $ai_message_id
            ));
            return;
        }

        wp_send_json_success(array(
            'message_id' => $message_id
        ));
    }
    
    /**
     * Get all messages for a conversation
     */
    public function get_messages() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-plugin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }
        
        // Get conversation ID
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        
        if (empty($conversation_id)) {
            wp_send_json_error(array('message' => 'No conversation ID provided.'));
        }
        
        // Get messages from the database
        $db = Chatbot_DB::get_instance();
        $messages = $db->get_messages($conversation_id);

        // Filter out 'function' type messages - they are for admin visibility only
        $messages = array_values(array_filter($messages, function($msg) {
            return $msg->sender_type !== 'function';
        }));

        // Get conversation status
        $conversation = $db->get_conversation($conversation_id);
        $conversation_status = $conversation ? $conversation->status : 'unknown';

        wp_send_json_success(array(
            'messages' => $messages,
            'conversation_status' => $conversation_status
        ));
    }
    
    /**
     * End an active conversation
     */
    public function end_conversation() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-plugin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }
        
        // Get conversation ID
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        
        if (empty($conversation_id)) {
            wp_send_json_error(array('message' => 'No conversation ID provided.'));
        }
        
        // Update the conversation status
        $db = Chatbot_DB::get_instance();
        $result = $db->set_conversation_status($conversation_id, 'ended', true);
        
        if (!$result) {
            wp_send_json_error(array('message' => 'Failed to end conversation.'));
        }
        
        // Add a system message about the conversation ending
        $db->add_message($conversation_id, 'system', 'This conversation has been ended by the user.');
        
        wp_send_json_success(array(
            'message' => 'Conversation ended successfully.'
        ));
    }
    
    /**
     * Generate a response based on the input message
     * 
     * Uses AIPass if connected, otherwise falls back to simple response logic
     * 
     * @param string $message The user's message
     * @param int $conversation_id The conversation ID
     * @return string The generated response
     */
    private function generate_response($message, $conversation_id = null) {
        // Check if AI integration is available
        if (class_exists('Chatbot_AI')) {
            $ai = Chatbot_AI::get_instance();

            // Get the chatbot configuration if it exists in session
            $chatbot_config = null;

            // Ensure session is started (with output buffering to prevent header issues)
            if (session_status() === PHP_SESSION_NONE) {
                if (!headers_sent()) {
                    @session_start();
                }
            }

            // ALWAYS fetch fresh config from database to get latest n8n_settings
            // Don't rely on session as it may have stale data
            $db = Chatbot_DB::get_instance();
            global $wpdb;
            $conv_table = $wpdb->prefix . 'chatbot_conversations';
            $conversation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $conv_table WHERE id = %d",
                $conversation_id
            ));

            if ($conversation && !empty($conversation->chatbot_config_id)) {
                $chatbot_config = $db->get_configuration($conversation->chatbot_config_id);
                if ($chatbot_config) {
                    // Update session with fresh config
                    $_SESSION['chatbot_config_' . $conversation_id] = $chatbot_config;
                    chatbot_log('INFO', 'generate_response_handler', 'Fetched fresh config from database: ' . $chatbot_config->name);
                }
            } elseif ($conversation && !empty($conversation->chatbot_config_name)) {
                $chatbot_config = $db->get_configuration_by_name($conversation->chatbot_config_name);
                if ($chatbot_config) {
                    $_SESSION['chatbot_config_' . $conversation_id] = $chatbot_config;
                    chatbot_log('INFO', 'generate_response_handler', 'Fetched fresh config by name: ' . $chatbot_config->name);
                }
            }

            // Fallback to session if database lookup failed
            if ($chatbot_config === null && isset($_SESSION['chatbot_config_' . $conversation_id])) {
                $chatbot_config = $_SESSION['chatbot_config_' . $conversation_id];
                chatbot_log('INFO', 'generate_response_handler', 'Using session fallback for conversation: ' . $conversation_id);
            }

            // If still no config, try fallback: search messages for data-config-name
            if ($chatbot_config === null) {
                $messages = $db->get_messages($conversation_id);
                foreach ($messages as $msg) {
                    if (strpos($msg->message, 'data-config-name=') !== false) {
                        preg_match('/data-config-name="([^"]+)"/', $msg->message, $matches);
                        if (!empty($matches[1])) {
                            $config_name = $matches[1];
                            $chatbot_config = $db->get_configuration_by_name($config_name);
                            if ($chatbot_config) {
                                $_SESSION['chatbot_config_' . $conversation_id] = $chatbot_config;
                                chatbot_log('INFO', 'generate_response_handler', 'Retrieved config from message: ' . $config_name);
                                break;
                            }
                        }
                    }
                }
            }

            if ($chatbot_config === null) {
                chatbot_log('WARNING', 'generate_response_handler', 'No chatbot config found for conversation: ' . $conversation_id);
            } else {
                chatbot_log('INFO', 'generate_response_handler', 'Using config: ' . (isset($chatbot_config->name) ? $chatbot_config->name : 'UNKNOWN'));
            }

            // Use AI to generate response based on conversation history
            // Pass the custom system prompt if available
            return $ai->generate_response($conversation_id, $message, $chatbot_config);
        }
        
        // Fallback to simple response system if AI is not available or configured
        $message_lower = strtolower($message);
        
        // Simple response system
        if (strpos($message_lower, 'hello') !== false || strpos($message_lower, 'hi') !== false) {
            return 'Hello! How can I help you today?';
        } elseif (strpos($message_lower, 'help') !== false) {
            return 'I can help answer questions about our products, services, or website. What would you like to know?';
        } elseif (strpos($message_lower, 'thank') !== false) {
            return 'You\'re welcome! Is there anything else I can help with?';
        } elseif (strpos($message_lower, 'bye') !== false || strpos($message_lower, 'goodbye') !== false) {
            return 'Goodbye! Have a great day!';
        } else {
            // Default responses
            $default_responses = array(
                'I\'m not sure I understand. Could you please rephrase that?',
                'Interesting question! Let me think about that.',
                'I don\'t have that information yet, but I\'m learning!',
                'Could you provide more details about your question?',
                'That\'s a good question. Let me find the answer for you.'
            );
            
            // Return a random default response
            return $default_responses[array_rand($default_responses)];
        }
    }

    /**
     * Generate a response for Telegram messages.
     *
     * @deprecated 1.7.0 Use Chatbot_Message_Pipeline instead via Chatbot_Platform_Telegram.
     * @see Chatbot_Platform_Telegram::process_incoming_message()
     *
     * @param string $message The user's message
     * @param int $conversation_id The conversation ID
     * @param int $config_id The chatbot configuration ID
     * @return string The generated response
     */
    public function generate_telegram_response($message, $conversation_id, $config_id) {
        return $this->generate_platform_response($message, $conversation_id, $config_id);
    }

    /**
     * Generate a response for any messaging platform.
     *
     * @deprecated 1.7.0 Use Chatbot_Message_Pipeline instead via platform classes.
     * @see Chatbot_Messaging_Platform::process_incoming_message()
     *
     * This legacy method is kept for backward compatibility. New code should use
     * the message pipeline architecture via Chatbot_Platform_Web, Chatbot_Platform_Telegram,
     * or Chatbot_Platform_WhatsApp classes.
     *
     * @param string $message The user's message
     * @param int $conversation_id The conversation ID
     * @param int $config_id The chatbot configuration ID
     * @return string The generated response
     */
    public function generate_platform_response($message, $conversation_id, $config_id) {
        $db = Chatbot_DB::get_instance();
        $chatbot_config = $db->get_configuration($config_id);

        if (!$chatbot_config) {
            return "I'm sorry, I'm not configured properly. Please contact the administrator.";
        }

        if (!class_exists('Chatbot_AI')) {
            return "I'm sorry, AI integration is not available.";
        }

        $ai = Chatbot_AI::get_instance();

        if (!$ai->is_configured()) {
            return "I'm sorry, the AI service is not configured. Please contact the administrator.";
        }

        return $ai->generate_response($conversation_id, $message, $chatbot_config);
    }
}

// Initialize the handler
function chatbot_handler_init() {
    return Chatbot_Handler::get_instance();
}
chatbot_handler_init();