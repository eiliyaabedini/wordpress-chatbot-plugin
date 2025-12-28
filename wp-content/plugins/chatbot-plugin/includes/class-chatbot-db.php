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

    /**
     * Conversation repository instance.
     *
     * @since 1.7.0
     * @var Chatbot_Conversation_Repository|null
     */
    private $conversation_repository = null;

    /**
     * Message repository instance.
     *
     * @since 1.7.0
     * @var Chatbot_Message_Repository|null
     */
    private $message_repository = null;

    /**
     * Configuration repository instance.
     *
     * @since 1.7.0
     * @var Chatbot_Configuration_Repository|null
     */
    private $configuration_repository = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inject repository dependencies.
     *
     * This method allows the DI container to inject repositories,
     * enabling delegation of database operations while maintaining
     * backward compatibility with direct usage.
     *
     * @since 1.7.0
     * @param Chatbot_Conversation_Repository $conversation_repo The conversation repository.
     * @param Chatbot_Message_Repository      $message_repo      The message repository.
     * @param Chatbot_Configuration_Repository $config_repo      The configuration repository.
     * @return void
     */
    public function set_repositories(
        Chatbot_Conversation_Repository $conversation_repo,
        Chatbot_Message_Repository $message_repo,
        Chatbot_Configuration_Repository $config_repo
    ) {
        $this->conversation_repository = $conversation_repo;
        $this->message_repository = $message_repo;
        $this->configuration_repository = $config_repo;

        if (function_exists('chatbot_log')) {
            chatbot_log('DEBUG', 'chatbot_db', 'Repositories injected into Chatbot_DB');
        }
    }

    /**
     * Check if repositories are available for delegation.
     *
     * @since 1.7.0
     * @return bool True if all repositories are injected.
     */
    private function has_repositories() {
        return $this->conversation_repository !== null
            && $this->message_repository !== null
            && $this->configuration_repository !== null;
    }
    
    /**
     * Create a new conversation
     *
     * @param string $visitor_name The name of the visitor
     * @param int|null $chatbot_config_id Optional chatbot configuration ID
     * @param string|null $chatbot_config_name Optional chatbot configuration name
     * @param int|null $telegram_chat_id Optional Telegram chat ID (legacy, use platform_type/platform_chat_id instead)
     * @param string|null $platform_type Optional platform type ('telegram', 'whatsapp', etc.)
     * @param string|null $platform_chat_id Optional platform-specific chat ID
     * @return int|false The conversation ID or false on failure
     */
    public function create_conversation($visitor_name, $chatbot_config_id = null, $chatbot_config_name = null, $telegram_chat_id = null, $platform_type = null, $platform_chat_id = null) {
        // Delegate to repository if available
        if ($this->conversation_repository !== null) {
            $data = array(
                'visitor_name' => $visitor_name,
                'status' => 'active',
            );

            if (!empty($chatbot_config_id)) {
                $data['chatbot_config_id'] = $chatbot_config_id;
            }
            if (!empty($chatbot_config_name)) {
                $data['chatbot_config_name'] = $chatbot_config_name;
            }
            if (!empty($telegram_chat_id)) {
                $data['telegram_chat_id'] = $telegram_chat_id;
            }
            if (!empty($platform_type)) {
                $data['platform_type'] = $platform_type;
            }
            if (!empty($platform_chat_id)) {
                $data['platform_chat_id'] = $platform_chat_id;
            }

            $result = $this->conversation_repository->create($data);
            return $result !== null ? $result : false;
        }

        // Legacy implementation for backward compatibility
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

        // Add Telegram chat ID if provided (legacy support)
        if (!empty($telegram_chat_id)) {
            $data['telegram_chat_id'] = intval($telegram_chat_id);
            $formats[] = '%d';
        }

        // Add platform type and chat ID for new messaging platforms
        if (!empty($platform_type)) {
            $data['platform_type'] = sanitize_text_field($platform_type);
            $formats[] = '%s';
        }

        if (!empty($platform_chat_id)) {
            $data['platform_chat_id'] = sanitize_text_field($platform_chat_id);
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
        // Delegate to repository if available
        if ($this->message_repository !== null) {
            $result = $this->message_repository->save($conversation_id, $sender_type, $message);
            return $result !== null ? $result : false;
        }

        // Legacy implementation for backward compatibility
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
        // Delegate to repository if available
        if ($this->message_repository !== null) {
            return $this->message_repository->get_for_conversation($conversation_id);
        }

        // Legacy implementation for backward compatibility
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
        
        // Log query execution without exposing the full query
        chatbot_log('DEBUG', 'get_conversations_by_status', 'Executing query', array(
            'status_filter' => $status,
            'limit' => $limit,
            'offset' => $offset,
            'has_config_filter' => $chatbot_config_id !== null
        ));
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Get a single conversation by ID
     *
     * @param int $conversation_id The conversation ID
     * @return object|null The conversation object or null if not found
     */
    public function get_conversation($conversation_id) {
        // Delegate to repository if available
        if ($this->conversation_repository !== null) {
            return $this->conversation_repository->find($conversation_id);
        }

        // Legacy implementation for backward compatibility
        global $wpdb;

        $table = $wpdb->prefix . 'chatbot_conversations';

        $query = $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $conversation_id
        );

        return $wpdb->get_row($query);
    }

    /**
     * Get a conversation by Telegram chat ID and config ID
     *
     * @param int $telegram_chat_id The Telegram chat ID
     * @param int $config_id The chatbot configuration ID
     * @return object|null The conversation object or null if not found
     */
    public function get_conversation_by_telegram_chat($telegram_chat_id, $config_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'chatbot_conversations';

        $query = $wpdb->prepare(
            "SELECT * FROM $table WHERE telegram_chat_id = %d AND chatbot_config_id = %d AND status = 'active' ORDER BY updated_at DESC LIMIT 1",
            $telegram_chat_id,
            $config_id
        );

        return $wpdb->get_row($query);
    }

    /**
     * Get a conversation by platform chat ID, platform type, and config ID
     *
     * @param string $platform_chat_id The platform-specific chat identifier
     * @param string $platform_type The platform type ('telegram', 'whatsapp', etc.)
     * @param int $config_id The chatbot configuration ID
     * @return object|null The conversation object or null if not found
     */
    public function get_conversation_by_platform_chat($platform_chat_id, $platform_type, $config_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'chatbot_conversations';

        $query = $wpdb->prepare(
            "SELECT * FROM $table WHERE platform_chat_id = %s AND platform_type = %s AND chatbot_config_id = %d AND status = 'active' ORDER BY updated_at DESC LIMIT 1",
            $platform_chat_id,
            $platform_type,
            $config_id
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
        // Delegate to repository if available
        if ($this->conversation_repository !== null) {
            return $this->conversation_repository->delete($conversation_id);
        }

        // Legacy implementation for backward compatibility
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
        $query = "SELECT COUNT(*) FROM $table";

        // While table names can't be parameterized, this is a safer pattern
        // that maintains consistent use of prepare() throughout the codebase
        return (int) $wpdb->get_var($query);
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
        
        // Base query with no parameters
        $query = "SELECT COUNT(*) FROM $table";

        // If there are conditions, add them with proper preparation
        if (!empty($query_params)) {
            $query = $wpdb->prepare("SELECT COUNT(*) FROM $table $where", $query_params);
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
     * @param string $system_prompt System prompt for the chatbot (for backward compatibility)
     * @param string $knowledge Knowledge base content for the chatbot
     * @param string $persona Personality and tone information for the chatbot
     * @param string $knowledge_sources JSON array of WordPress post IDs to use as knowledge
     * @param string $telegram_bot_token Telegram bot token for this configuration
     * @return int|false The configuration ID or false on failure
     */
    public function add_configuration($name, $system_prompt, $knowledge = '', $persona = '', $knowledge_sources = '', $telegram_bot_token = '') {
        global $wpdb;

        $table = $wpdb->prefix . 'chatbot_configurations';

        // If knowledge and persona are empty but system_prompt is provided, use system_prompt for both
        if (empty($knowledge) && !empty($system_prompt)) {
            $knowledge = $system_prompt;
        }

        if (empty($persona) && !empty($system_prompt)) {
            $persona = "You are a helpful, friendly, and professional assistant. Respond to user inquiries in a conversational tone while maintaining accuracy and being concise.";
        }

        // Log the input parameters for debugging
        chatbot_log('DEBUG', 'add_configuration', 'Adding new chatbot configuration', array(
            'name' => $name,
            'system_prompt_length' => strlen($system_prompt),
            'knowledge_length' => strlen($knowledge),
            'persona_length' => strlen($persona),
            'knowledge_sources' => $knowledge_sources,
            'has_telegram_token' => !empty($telegram_bot_token)
        ));

        // Prepare data with sanitization
        $data = array(
            'name' => sanitize_text_field($name),
            'system_prompt' => sanitize_textarea_field($system_prompt),
            'knowledge' => sanitize_textarea_field($knowledge),
            'persona' => sanitize_textarea_field($persona),
            'knowledge_sources' => $knowledge_sources, // JSON string, no sanitization needed for valid JSON
            'telegram_bot_token' => sanitize_text_field($telegram_bot_token),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );

        $formats = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');
        
        // Make sure the table exists
        // We can't parameterize table names in WordPress, but we can reduce risk
        // by using a prepared dummy query that won't actually be executed
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $wpdb->prefix . 'chatbot_configurations'
        ));

        if (!$table_exists) {
            chatbot_log('ERROR', 'add_configuration', 'Table does not exist', array('table_name' => 'chatbot_configurations'));
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
            // Sanitize the error information to prevent exposing database structure
            chatbot_log('ERROR', 'add_configuration', 'Failed to add chatbot configuration', array(
                'wpdb_error_type' => $wpdb->last_error ? 'DB Error occurred' : 'No DB error',
                'error_with_field' => $this->identify_problem_field($data, $wpdb->last_error)
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
     * @param string $system_prompt System prompt for the chatbot (for backward compatibility)
     * @param string $knowledge Knowledge base content for the chatbot
     * @param string $persona Personality and tone information for the chatbot
     * @param string $knowledge_sources JSON array of WordPress post IDs to use as knowledge
     * @param string $telegram_bot_token Telegram bot token for this configuration
     * @param string $n8n_settings JSON string of n8n integration settings
     * @return bool Whether the update was successful
     */
    public function update_configuration($id, $name, $system_prompt, $knowledge = '', $persona = '', $knowledge_sources = '', $telegram_bot_token = '', $n8n_settings = '') {
        global $wpdb;

        $table = $wpdb->prefix . 'chatbot_configurations';

        // If knowledge and persona are empty but system_prompt is provided, use system_prompt for both
        if (empty($knowledge) && !empty($system_prompt)) {
            $knowledge = $system_prompt;
        }

        if (empty($persona) && !empty($system_prompt)) {
            $persona = "You are a helpful, friendly, and professional assistant. Respond to user inquiries in a conversational tone while maintaining accuracy and being concise.";
        }

        // Log the update operation
        chatbot_log('DEBUG', 'update_configuration', 'Updating chatbot configuration', array(
            'id' => $id,
            'name' => $name,
            'system_prompt_length' => strlen($system_prompt),
            'knowledge_length' => strlen($knowledge),
            'persona_length' => strlen($persona),
            'knowledge_sources' => $knowledge_sources,
            'has_telegram_token' => !empty($telegram_bot_token),
            'has_n8n_settings' => !empty($n8n_settings)
        ));

        $result = $wpdb->update(
            $table,
            array(
                'name' => sanitize_text_field($name),
                'system_prompt' => sanitize_textarea_field($system_prompt),
                'knowledge' => sanitize_textarea_field($knowledge),
                'persona' => sanitize_textarea_field($persona),
                'knowledge_sources' => $knowledge_sources, // JSON string
                'telegram_bot_token' => sanitize_text_field($telegram_bot_token),
                'n8n_settings' => $n8n_settings, // JSON string
                'updated_at' => current_time('mysql')
            ),
            array('id' => $id),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
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
        // Delegate to repository if available
        if ($this->configuration_repository !== null) {
            return $this->configuration_repository->find($id);
        }

        // Legacy implementation for backward compatibility
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
        // Delegate to repository if available
        if ($this->configuration_repository !== null) {
            return $this->configuration_repository->find_by_name($name);
        }

        // Legacy implementation for backward compatibility
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
        // Delegate to repository if available
        if ($this->configuration_repository !== null) {
            return $this->configuration_repository->name_exists($name, $exclude_id);
        }

        // Legacy implementation for backward compatibility
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
        // Delegate to repository if available
        if ($this->configuration_repository !== null) {
            return $this->configuration_repository->get_all();
        }

        // Legacy implementation for backward compatibility
        global $wpdb;

        $table = $wpdb->prefix . 'chatbot_configurations';
        $query = "SELECT * FROM $table ORDER BY name ASC";

        // While table names can't be parameterized, using consistent
        // code patterns across the codebase is a good security practice
        return $wpdb->get_results($query);
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

    /**
     * Extract content from a WordPress post for use as knowledge
     *
     * @param int $post_id The post ID
     * @return array|null Post data with content, or null if not available
     */
    public function extract_post_content($post_id) {
        $post = get_post($post_id);

        // Handle deleted or non-published content
        if (!$post || $post->post_status !== 'publish') {
            chatbot_log('DEBUG', 'extract_post_content', 'Post not available', array(
                'post_id' => $post_id,
                'exists' => $post ? 'yes' : 'no',
                'status' => $post ? $post->post_status : 'N/A'
            ));
            return null;
        }

        // Get post type label (Post, Page, Product, etc.)
        $post_type_obj = get_post_type_object($post->post_type);
        $type_label = $post_type_obj ? $post_type_obj->labels->singular_name : 'Content';

        // Extract clean content - remove shortcodes first, then strip HTML
        $content = strip_shortcodes($post->post_content);
        $content = wp_strip_all_tags($content);

        // Clean up extra whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        // Calculate approximate token count (1 token ≈ 4 characters)
        $token_count = ceil(strlen($content) / 4);

        return array(
            'id' => $post_id,
            'title' => $post->post_title,
            'type' => $type_label,
            'url' => get_permalink($post_id),
            'content' => $content,
            'token_count' => $token_count
        );
    }

    /**
     * Get formatted knowledge content from selected WordPress posts/pages
     *
     * @param string $sources_json JSON string containing array of post IDs
     * @return string Formatted knowledge content for system prompt
     */
    public function get_knowledge_from_sources($sources_json) {
        if (empty($sources_json)) {
            return '';
        }

        // Decode the JSON array of post IDs
        $post_ids = json_decode($sources_json, true);

        if (!is_array($post_ids) || empty($post_ids)) {
            chatbot_log('DEBUG', 'get_knowledge_from_sources', 'No valid post IDs found', array(
                'sources_json' => substr($sources_json, 0, 100)
            ));
            return '';
        }

        chatbot_log('INFO', 'get_knowledge_from_sources', 'Extracting knowledge from WordPress content', array(
            'post_count' => count($post_ids)
        ));

        $knowledge_parts = array();
        $total_tokens = 0;
        $max_tokens = 100000; // 100k token limit

        foreach ($post_ids as $post_id) {
            $post_data = $this->extract_post_content(intval($post_id));

            if (!$post_data) {
                continue; // Skip unavailable posts
            }

            // Check if adding this post would exceed the token limit
            if ($total_tokens + $post_data['token_count'] > $max_tokens) {
                chatbot_log('WARNING', 'get_knowledge_from_sources', 'Token limit reached, skipping remaining posts', array(
                    'total_tokens' => $total_tokens,
                    'skipped_post_id' => $post_id
                ));
                break;
            }

            // Format the content for this post
            $formatted = sprintf(
                "--- %s: %s ---\nURL: %s\n%s\n",
                $post_data['type'],
                $post_data['title'],
                $post_data['url'],
                $post_data['content']
            );

            $knowledge_parts[] = $formatted;
            $total_tokens += $post_data['token_count'];
        }

        if (empty($knowledge_parts)) {
            return '';
        }

        // Add header with instruction about citing sources
        $knowledge = "IMPORTANT: When answering questions using this content, always cite the source URL so users can learn more.\n\n";
        $knowledge .= implode("\n", $knowledge_parts);

        chatbot_log('INFO', 'get_knowledge_from_sources', 'Knowledge extracted successfully', array(
            'posts_included' => count($knowledge_parts),
            'total_tokens' => $total_tokens
        ));

        return $knowledge;
    }

    /**
     * Search WordPress posts/pages for knowledge source selection
     *
     * @param string $search Search term
     * @param int $limit Maximum results to return
     * @return array Array of posts with id, title, type, and token_count
     */
    public function search_posts_for_knowledge($search = '', $limit = 50) {
        $args = array(
            'post_type' => 'any', // All public post types
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'title',
            'order' => 'ASC'
        );

        if (!empty($search)) {
            $args['s'] = sanitize_text_field($search);
        }

        // Get all public post types to search
        $public_post_types = get_post_types(array('public' => true), 'names');
        // Remove attachment from the list
        unset($public_post_types['attachment']);
        $args['post_type'] = array_values($public_post_types);

        $posts = get_posts($args);
        $results = array();

        foreach ($posts as $post) {
            $post_type_obj = get_post_type_object($post->post_type);
            $type_label = $post_type_obj ? $post_type_obj->labels->singular_name : 'Content';

            // Calculate approximate token count
            $content = strip_shortcodes($post->post_content);
            $content = wp_strip_all_tags($content);
            $token_count = ceil(strlen($content) / 4);

            $results[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => $type_label,
                'type_slug' => $post->post_type,
                'url' => get_permalink($post->ID),
                'token_count' => $token_count
            );
        }

        return $results;
    }

    /**
     * Get token count for selected knowledge sources
     *
     * @param string $sources_json JSON string containing array of post IDs
     * @return int Total token count
     */
    public function get_knowledge_sources_token_count($sources_json) {
        if (empty($sources_json)) {
            return 0;
        }

        $post_ids = json_decode($sources_json, true);

        if (!is_array($post_ids) || empty($post_ids)) {
            return 0;
        }

        $total_tokens = 0;

        foreach ($post_ids as $post_id) {
            $post_data = $this->extract_post_content(intval($post_id));
            if ($post_data) {
                $total_tokens += $post_data['token_count'];
            }
        }

        return $total_tokens;
    }

    /**
     * Helper function to identify which field might be causing database errors
     * without exposing sensitive database information
     *
     * @param array $data The data being inserted/updated
     * @param string $error_message The database error message
     * @return string Sanitized field name that might be causing the issue
     */
    private function identify_problem_field($data, $error_message) {
        // Don't return the raw error message, just check for common patterns
        if (empty($error_message)) {
            return 'unknown';
        }

        $field_issues = array();

        // Check for common error patterns without exposing details
        foreach ($data as $field => $value) {
            // Check if the field name appears in the error message
            if (stripos($error_message, $field) !== false) {
                $field_issues[] = $field;
            }

            // Check for null values in non-nullable fields
            if ($value === null && stripos($error_message, 'null') !== false &&
                stripos($error_message, 'not') !== false) {
                $field_issues[] = $field . '(null_issue)';
            }

            // Check for duplicate entry issues
            if (stripos($error_message, 'duplicate') !== false &&
                $field == 'name' && !empty($value)) {
                return 'name(duplicate)';
            }
        }

        if (!empty($field_issues)) {
            return implode(',', $field_issues);
        }

        return 'unknown_field_issue';
    }
}

// Initialize the database handler
function chatbot_db_init() {
    return Chatbot_DB::get_instance();
}
chatbot_db_init();