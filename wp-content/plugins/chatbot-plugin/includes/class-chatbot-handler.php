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
        
        // Create a new conversation
        $db = Chatbot_DB::get_instance();
        $conversation_id = $db->create_conversation($visitor_name);
        
        if (!$conversation_id) {
            wp_send_json_error(array('message' => 'Error creating conversation.'));
        }
        
        // Add initial welcome message from admin
        $welcome_message = 'Hello ' . $visitor_name . '! How can I help you today?';
        $db->add_message($conversation_id, 'admin', $welcome_message);
        
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
        
        // Add the message to the database
        $db = Chatbot_DB::get_instance();
        $message_id = $db->add_message($conversation_id, $sender_type, $message);
        
        if (!$message_id) {
            wp_send_json_error(array('message' => 'Error saving message.'));
        }
        
        // If it's a user message, generate an automatic admin response
        if ($sender_type === 'user') {
            // Generate a response, passing the conversation ID for context
            $response = $this->generate_response($message, $conversation_id);
            
            // Add AI response to the database
            // Use 'ai' sender type for OpenAI responses to distinguish from human admin messages
            $sender_type = class_exists('Chatbot_OpenAI') && Chatbot_OpenAI::get_instance()->is_configured() ? 'ai' : 'admin';
            $db->add_message($conversation_id, $sender_type, $response);
        }
        
        wp_send_json_success(array(
            'message_id' => $message_id,
            'message_already_displayed' => true
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
        
        wp_send_json_success(array('messages' => $messages));
    }
    
    /**
     * Generate a response based on the input message
     * 
     * Uses OpenAI API if configured, otherwise falls back to simple response logic
     * 
     * @param string $message The user's message
     * @param int $conversation_id The conversation ID
     * @return string The generated response
     */
    private function generate_response($message, $conversation_id = null) {
        // Check if OpenAI integration is available and configured
        if (class_exists('Chatbot_OpenAI')) {
            $openai = Chatbot_OpenAI::get_instance();
            
            if ($openai->is_configured()) {
                // Use OpenAI to generate response based on conversation history
                return $openai->generate_response($conversation_id, $message);
            }
        }
        
        // Fallback to simple response system if OpenAI is not available or configured
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
}

// Initialize the handler
function chatbot_handler_init() {
    return Chatbot_Handler::get_instance();
}
chatbot_handler_init();