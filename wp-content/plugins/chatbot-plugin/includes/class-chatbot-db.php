<?php
/**
 * Chatbot Database Handler
 * 
 * Handles database operations for the chatbot
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_DB {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Create a new conversation
     * 
     * @param string $visitor_name The name of the visitor
     * @return int|false The conversation ID or false on failure
     */
    public function create_conversation($visitor_name) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_conversations';
        
        $result = $wpdb->insert(
            $table,
            array(
                'visitor_name' => sanitize_text_field($visitor_name),
            ),
            array('%s')
        );
        
        if ($result === false) {
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Add a message to a conversation
     * 
     * @param int $conversation_id The conversation ID
     * @param string $sender_type The sender type ('user' or 'admin')
     * @param string $message The message content
     * @return int|false The message ID or false on failure
     */
    public function add_message($conversation_id, $sender_type, $message) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_messages';
        
        // Update the conversation's updated_at timestamp
        $conversation_table = $wpdb->prefix . 'chatbot_conversations';
        $wpdb->update(
            $conversation_table,
            array('updated_at' => current_time('mysql')),
            array('id' => $conversation_id),
            array('%s'),
            array('%d')
        );
        
        $result = $wpdb->insert(
            $table,
            array(
                'conversation_id' => intval($conversation_id),
                'sender_type' => sanitize_text_field($sender_type),
                'message' => sanitize_textarea_field($message),
            ),
            array('%d', '%s', '%s')
        );
        
        if ($result === false) {
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get messages for a conversation
     * 
     * @param int $conversation_id The conversation ID
     * @return array An array of message objects
     */
    public function get_messages($conversation_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_messages';
        
        $query = $wpdb->prepare(
            "SELECT * FROM $table WHERE conversation_id = %d ORDER BY timestamp ASC",
            $conversation_id
        );
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Get all conversations
     * 
     * @param int $limit Optional limit of conversations to return
     * @param int $offset Optional offset for pagination
     * @return array An array of conversation objects
     */
    public function get_conversations($limit = 20, $offset = 0) {
        global $wpdb;
        
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $messages_table = $wpdb->prefix . 'chatbot_messages';
        
        $query = $wpdb->prepare(
            "SELECT c.*,
            (SELECT COUNT(*) FROM $messages_table WHERE conversation_id = c.id) as message_count,
            (SELECT MAX(timestamp) FROM $messages_table WHERE conversation_id = c.id) as last_message
            FROM $conversations_table c
            ORDER BY updated_at DESC
            LIMIT %d OFFSET %d",
            $limit,
            $offset
        );
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Get a single conversation by ID
     * 
     * @param int $conversation_id The conversation ID
     * @return object|null The conversation object or null if not found
     */
    public function get_conversation($conversation_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_conversations';
        
        $query = $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $conversation_id
        );
        
        return $wpdb->get_row($query);
    }
    
    /**
     * Set a conversation's active status
     * 
     * @param int $conversation_id The conversation ID
     * @param bool $is_active Whether the conversation is active
     * @return bool Whether the update was successful
     */
    public function set_conversation_active($conversation_id, $is_active) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_conversations';
        
        $result = $wpdb->update(
            $table,
            array('is_active' => $is_active ? 1 : 0),
            array('id' => $conversation_id),
            array('%d'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * Get the total number of conversations
     * 
     * @return int The total number of conversations
     */
    public function get_conversation_count() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_conversations';
        
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    }
}

// Initialize the database handler
function chatbot_db_init() {
    return Chatbot_DB::get_instance();
}
chatbot_db_init();