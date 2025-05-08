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
                'status' => 'active'
            ),
            array('%s', '%s')
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
     * @param string $sender_type The sender type ('user', 'admin', 'ai', 'system')
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
     * Get conversations by status
     * 
     * @param string $status The status to filter by (all, active, ended, archived)
     * @param int $limit Optional limit of conversations to return
     * @param int $offset Optional offset for pagination
     * @return array An array of conversation objects
     */
    public function get_conversations_by_status($status = 'all', $limit = 20, $offset = 0) {
        global $wpdb;
        
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $messages_table = $wpdb->prefix . 'chatbot_messages';
        
        $where = '';
        if ($status !== 'all') {
            $where = $wpdb->prepare("WHERE c.status = %s", $status);
        }
        
        $query = $wpdb->prepare(
            "SELECT c.*,
            (SELECT COUNT(*) FROM $messages_table WHERE conversation_id = c.id) as message_count,
            (SELECT MAX(timestamp) FROM $messages_table WHERE conversation_id = c.id) as last_message
            FROM $conversations_table c
            $where
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
     * Set a conversation's status
     * 
     * @param int $conversation_id The conversation ID
     * @param string $status The new status (active, ended, archived)
     * @param bool $update_timestamp Whether to update the corresponding timestamp field
     * @return bool Whether the update was successful
     */
    public function set_conversation_status($conversation_id, $status, $update_timestamp = true) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_conversations';
        $update_data = array(
            'status' => sanitize_text_field($status),
            'updated_at' => current_time('mysql')
        );
        $update_format = array('%s', '%s');
        
        // Set the is_active flag based on status
        if ($status === 'active') {
            $update_data['is_active'] = 1;
            $update_format[] = '%d';
        } else {
            $update_data['is_active'] = 0;
            $update_format[] = '%d';
        }
        
        // Set the appropriate timestamp field based on status
        if ($update_timestamp) {
            switch ($status) {
                case 'ended':
                    $update_data['ended_at'] = current_time('mysql');
                    $update_format[] = '%s';
                    break;
                    
                case 'archived':
                    $update_data['archived_at'] = current_time('mysql');
                    $update_format[] = '%s';
                    break;
            }
        }
        
        $result = $wpdb->update(
            $table,
            $update_data,
            array('id' => $conversation_id),
            $update_format,
            array('%d')
        );
        
        return $result !== false;
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
     * Archive a conversation
     * 
     * @param int $conversation_id The conversation ID
     * @return bool Whether the update was successful
     */
    public function archive_conversation($conversation_id) {
        return $this->set_conversation_status($conversation_id, 'archived', true);
    }
    
    /**
     * Delete a conversation and all its messages
     * 
     * @param int $conversation_id The conversation ID
     * @return bool Whether the deletion was successful
     */
    public function delete_conversation($conversation_id) {
        global $wpdb;
        
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $messages_table = $wpdb->prefix . 'chatbot_messages';
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        // Delete messages first
        $messages_deleted = $wpdb->delete(
            $messages_table,
            array('conversation_id' => $conversation_id),
            array('%d')
        );
        
        // Then delete the conversation
        $conversation_deleted = $wpdb->delete(
            $conversations_table,
            array('id' => $conversation_id),
            array('%d')
        );
        
        // Commit or rollback
        if ($messages_deleted !== false && $conversation_deleted !== false) {
            $wpdb->query('COMMIT');
            return true;
        } else {
            $wpdb->query('ROLLBACK');
            return false;
        }
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
    
    /**
     * Get the total number of conversations by status
     * 
     * @param string $status The status to filter by (all, active, ended, archived)
     * @return int The total number of conversations
     */
    public function get_conversation_count_by_status($status = 'all') {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_conversations';
        
        if ($status === 'all') {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        } else {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", $status));
        }
    }
}

// Initialize the database handler
function chatbot_db_init() {
    return Chatbot_DB::get_instance();
}
chatbot_db_init();