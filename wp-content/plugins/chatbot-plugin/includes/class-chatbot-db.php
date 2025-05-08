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
     * @param int|null $chatbot_config_id Optional chatbot configuration ID
     * @param string|null $chatbot_config_name Optional chatbot configuration name
     * @return int|false The conversation ID or false on failure
     */
    public function create_conversation($visitor_name, $chatbot_config_id = null, $chatbot_config_name = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_conversations';
        
        $data = array(
            'visitor_name' => sanitize_text_field($visitor_name),
            'status' => 'active'
        );
        
        $formats = array('%s', '%s');
        
        // Add chatbot configuration if provided
        if (!empty($chatbot_config_id)) {
            $data['chatbot_config_id'] = intval($chatbot_config_id);
            $formats[] = '%d';
        }
        
        if (!empty($chatbot_config_name)) {
            $data['chatbot_config_name'] = sanitize_text_field($chatbot_config_name);
            $formats[] = '%s';
        }
        
        $result = $wpdb->insert(
            $table,
            $data,
            $formats
        );
        
        if ($result === false) {
            chatbot_log('ERROR', 'create_conversation', 'Failed to create conversation', $wpdb->last_error);
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
     * Get conversations by status and/or chatbot configuration
     * 
     * @param string $status The status to filter by (all, active, ended, archived)
     * @param int $limit Optional limit of conversations to return
     * @param int $offset Optional offset for pagination
     * @param int|null $chatbot_config_id Optional chatbot configuration ID to filter by
     * @return array An array of conversation objects
     */
    public function get_conversations_by_status($status = 'all', $limit = 20, $offset = 0, $chatbot_config_id = null) {
        global $wpdb;
        
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $messages_table = $wpdb->prefix . 'chatbot_messages';
        
        $where_conditions = array();
        $query_params = array();
        
        // Add status condition if not 'all'
        if ($status !== 'all') {
            $where_conditions[] = "c.status = %s";
            $query_params[] = $status;
        }
        
        // Add chatbot configuration condition if specified
        if ($chatbot_config_id !== null) {
            $where_conditions[] = "c.chatbot_config_id = %d";
            $query_params[] = intval($chatbot_config_id);
        }
        
        // Build WHERE clause
        $where = '';
        if (!empty($where_conditions)) {
            $where = "WHERE " . implode(' AND ', $where_conditions);
        }
        
        // Add pagination parameters
        $query_params[] = $limit;
        $query_params[] = $offset;
        
        $query = $wpdb->prepare(
            "SELECT c.*,
            (SELECT COUNT(*) FROM $messages_table WHERE conversation_id = c.id) as message_count,
            (SELECT MAX(timestamp) FROM $messages_table WHERE conversation_id = c.id) as last_message
            FROM $conversations_table c
            $where
            ORDER BY updated_at DESC
            LIMIT %d OFFSET %d",
            $query_params
        );
        
        chatbot_log('DEBUG', 'get_conversations_by_status', 'Query', $query);
        
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
     * Get the total number of conversations by status and/or configuration
     * 
     * @param string $status The status to filter by (all, active, ended, archived)
     * @param int|null $chatbot_config_id Optional chatbot configuration ID to filter by
     * @return int The total number of conversations
     */
    public function get_conversation_count_by_status($status = 'all', $chatbot_config_id = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_conversations';
        
        $where_conditions = array();
        $query_params = array();
        
        // Add status condition if not 'all'
        if ($status !== 'all') {
            $where_conditions[] = "status = %s";
            $query_params[] = $status;
        }
        
        // Add chatbot configuration condition if specified
        if ($chatbot_config_id !== null) {
            $where_conditions[] = "chatbot_config_id = %d";
            $query_params[] = intval($chatbot_config_id);
        }
        
        // Build WHERE clause
        $where = '';
        if (!empty($where_conditions)) {
            $where = "WHERE " . implode(' AND ', $where_conditions);
        }
        
        $query = "SELECT COUNT(*) FROM $table $where";
        
        if (!empty($query_params)) {
            $query = $wpdb->prepare($query, $query_params);
        }
        
        return (int) $wpdb->get_var($query);
    }
    
    /**
     * Get count of conversations for a specific chatbot configuration
     * 
     * @param int $chatbot_config_id The chatbot configuration ID
     * @return int The number of conversations
     */
    public function get_conversation_count_by_chatbot($chatbot_config_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_conversations';
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE chatbot_config_id = %d",
            $chatbot_config_id
        ));
    }
    
    /**
     * Create a new chatbot configuration
     * 
     * @param string $name Configuration name
     * @param string $system_prompt System prompt for the chatbot
     * @return int|false The configuration ID or false on failure
     */
    public function add_configuration($name, $system_prompt) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_configurations';
        
        // Log the input parameters for debugging
        chatbot_log('DEBUG', 'add_configuration', 'Adding new chatbot configuration', array(
            'name' => $name,
            'system_prompt_length' => strlen($system_prompt)
        ));
        
        // Prepare data with sanitization
        $data = array(
            'name' => sanitize_text_field($name),
            'system_prompt' => sanitize_textarea_field($system_prompt),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        $formats = array('%s', '%s', '%s', '%s');
        
        // Make sure the table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        if (!$table_exists) {
            chatbot_log('ERROR', 'add_configuration', 'Table does not exist', $table);
            return false;
        }
        
        // Check input values
        foreach ($data as $key => $value) {
            if ($value === null) {
                chatbot_log('ERROR', 'add_configuration', "Invalid value for $key: NULL is not allowed");
                return false;
            }
        }
        
        // Direct check for name uniqueness
        $name = $data['name'];
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE name = %s",
            $name
        ));
        
        if ($exists > 0) {
            chatbot_log('ERROR', 'add_configuration', 'A chatbot with this name already exists', $name);
            return false;
        }
        
        // Attempt the insert
        $result = $wpdb->insert($table, $data, $formats);
        
        if ($result === false) {
            // Log the error for debugging
            chatbot_log('ERROR', 'add_configuration', 'Failed to add chatbot configuration', array(
                'wpdb_error' => $wpdb->last_error,
                'wpdb_query' => $wpdb->last_query,
                'data' => $data,
                'formats' => $formats
            ));
            return false;
        }
        
        $insert_id = $wpdb->insert_id;
        chatbot_log('INFO', 'add_configuration', 'Successfully added chatbot configuration', array('id' => $insert_id));
        
        return $insert_id;
    }
    
    /**
     * Update a chatbot configuration
     * 
     * @param int $id Configuration ID
     * @param string $name Configuration name
     * @param string $system_prompt System prompt for the chatbot
     * @return bool Whether the update was successful
     */
    public function update_configuration($id, $name, $system_prompt) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_configurations';
        
        $result = $wpdb->update(
            $table,
            array(
                'name' => sanitize_text_field($name),
                'system_prompt' => sanitize_textarea_field($system_prompt),
                'updated_at' => current_time('mysql')
            ),
            array('id' => $id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * Get a single chatbot configuration by ID
     * 
     * @param int $id Configuration ID
     * @return object|null The configuration object or null if not found
     */
    public function get_configuration($id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_configurations';
        
        $query = $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        );
        
        return $wpdb->get_row($query);
    }
    
    /**
     * Get a chatbot configuration by name
     * 
     * @param string $name Configuration name
     * @return object|null The configuration object or null if not found
     */
    public function get_configuration_by_name($name) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_configurations';
        
        $query = $wpdb->prepare(
            "SELECT * FROM $table WHERE name = %s",
            $name
        );
        
        return $wpdb->get_row($query);
    }
    
    /**
     * Check if a configuration name already exists
     * 
     * @param string $name Configuration name
     * @param int $exclude_id Optional ID to exclude from the check
     * @return bool Whether the name exists
     */
    public function configuration_name_exists($name, $exclude_id = 0) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_configurations';
        
        if ($exclude_id > 0) {
            $query = $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE name = %s AND id != %d",
                $name, $exclude_id
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE name = %s",
                $name
            );
        }
        
        return (int) $wpdb->get_var($query) > 0;
    }
    
    /**
     * Get all chatbot configurations
     * 
     * @return array An array of configuration objects
     */
    public function get_configurations() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_configurations';
        
        return $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC");
    }
    
    /**
     * Delete a chatbot configuration
     * 
     * @param int $id Configuration ID
     * @return bool Whether the deletion was successful
     */
    public function delete_configuration($id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'chatbot_configurations';
        
        $result = $wpdb->delete(
            $table,
            array('id' => $id),
            array('%d')
        );
        
        return $result !== false;
    }
}

// Initialize the database handler
function chatbot_db_init() {
    return Chatbot_DB::get_instance();
}
chatbot_db_init();