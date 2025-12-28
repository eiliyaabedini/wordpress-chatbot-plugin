<?php
/**
 * WordPress Conversation Repository Implementation
 *
 * Implements conversation persistence using WordPress database.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_WP_Conversation_Repository
 *
 * WordPress-specific implementation of the Conversation Repository.
 */
class Chatbot_WP_Conversation_Repository implements Chatbot_Conversation_Repository {

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Conversations table name.
     *
     * @var string
     */
    private $table;

    /**
     * Messages table name (for joins).
     *
     * @var string
     */
    private $messages_table;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'chatbot_conversations';
        $this->messages_table = $wpdb->prefix . 'chatbot_messages';
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $data) {
        $insert_data = array(
            'visitor_name' => sanitize_text_field($data['visitor_name'] ?? 'Visitor'),
            'status' => sanitize_text_field($data['status'] ?? 'active'),
        );
        $formats = array('%s', '%s');

        if (!empty($data['chatbot_config_id'])) {
            $insert_data['chatbot_config_id'] = intval($data['chatbot_config_id']);
            $formats[] = '%d';
        }

        if (!empty($data['chatbot_config_name'])) {
            $insert_data['chatbot_config_name'] = sanitize_text_field($data['chatbot_config_name']);
            $formats[] = '%s';
        }

        if (!empty($data['platform_type'])) {
            $insert_data['platform_type'] = sanitize_text_field($data['platform_type']);
            $formats[] = '%s';
        }

        if (!empty($data['platform_chat_id'])) {
            $insert_data['platform_chat_id'] = sanitize_text_field($data['platform_chat_id']);
            $formats[] = '%s';
        }

        // Legacy Telegram support
        if (!empty($data['telegram_chat_id'])) {
            $insert_data['telegram_chat_id'] = intval($data['telegram_chat_id']);
            $formats[] = '%d';
        }

        $result = $this->wpdb->insert($this->table, $insert_data, $formats);

        if ($result === false) {
            if (function_exists('chatbot_log')) {
                chatbot_log('ERROR', 'conversation_repository', 'Failed to create conversation', array(
                    'error' => $this->wpdb->last_error
                ));
            }
            return null;
        }

        return $this->wpdb->insert_id;
    }

    /**
     * {@inheritdoc}
     */
    public function find($id) {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        );
        return $this->wpdb->get_row($query);
    }

    /**
     * {@inheritdoc}
     */
    public function find_by_platform($platform_chat_id, $platform_type, $config_id) {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE platform_chat_id = %s
             AND platform_type = %s
             AND chatbot_config_id = %d
             ORDER BY updated_at DESC
             LIMIT 1",
            $platform_chat_id,
            $platform_type,
            $config_id
        );
        return $this->wpdb->get_row($query);
    }

    /**
     * {@inheritdoc}
     */
    public function find_active_by_platform($platform_chat_id, $platform_type, $config_id) {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE platform_chat_id = %s
             AND platform_type = %s
             AND chatbot_config_id = %d
             AND status = 'active'
             ORDER BY updated_at DESC
             LIMIT 1",
            $platform_chat_id,
            $platform_type,
            $config_id
        );
        return $this->wpdb->get_row($query);
    }

    /**
     * {@inheritdoc}
     */
    public function update($id, array $data) {
        $update_data = array();
        $formats = array();

        $allowed_fields = array(
            'visitor_name' => '%s',
            'status' => '%s',
            'chatbot_config_id' => '%d',
            'platform_type' => '%s',
            'platform_chat_id' => '%s',
            'is_active' => '%d',
        );

        foreach ($allowed_fields as $field => $format) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
                $formats[] = $format;
            }
        }

        // Always update updated_at
        $update_data['updated_at'] = current_time('mysql');
        $formats[] = '%s';

        $result = $this->wpdb->update(
            $this->table,
            $update_data,
            array('id' => $id),
            $formats,
            array('%d')
        );

        return $result !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id) {
        $this->wpdb->query('START TRANSACTION');

        try {
            // Delete messages first
            $this->wpdb->delete(
                $this->messages_table,
                array('conversation_id' => $id),
                array('%d')
            );

            // Delete conversation
            $result = $this->wpdb->delete(
                $this->table,
                array('id' => $id),
                array('%d')
            );

            if ($result === false) {
                $this->wpdb->query('ROLLBACK');
                return false;
            }

            $this->wpdb->query('COMMIT');
            return true;

        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get_by_status($status, $limit = 50, $offset = 0, $config_id = null) {
        $where_conditions = array();
        $query_params = array();

        if ($status !== 'all') {
            $where_conditions[] = "c.status = %s";
            $query_params[] = $status;
        }

        if ($config_id !== null) {
            $where_conditions[] = "c.chatbot_config_id = %d";
            $query_params[] = intval($config_id);
        }

        $where = '';
        if (!empty($where_conditions)) {
            $where = "WHERE " . implode(' AND ', $where_conditions);
        }

        $query_params[] = $limit;
        $query_params[] = $offset;

        $query = $this->wpdb->prepare(
            "SELECT c.*,
             (SELECT COUNT(*) FROM {$this->messages_table} WHERE conversation_id = c.id) as message_count,
             (SELECT MAX(timestamp) FROM {$this->messages_table} WHERE conversation_id = c.id) as last_message
             FROM {$this->table} c
             {$where}
             ORDER BY updated_at DESC
             LIMIT %d OFFSET %d",
            $query_params
        );

        return $this->wpdb->get_results($query);
    }

    /**
     * {@inheritdoc}
     */
    public function count_by_status($status, $config_id = null) {
        $where_conditions = array();
        $query_params = array();

        if ($status !== 'all') {
            $where_conditions[] = "status = %s";
            $query_params[] = $status;
        }

        if ($config_id !== null) {
            $where_conditions[] = "chatbot_config_id = %d";
            $query_params[] = intval($config_id);
        }

        $where = '';
        if (!empty($where_conditions)) {
            $where = "WHERE " . implode(' AND ', $where_conditions);
        }

        if (empty($query_params)) {
            $query = "SELECT COUNT(*) FROM {$this->table} {$where}";
            return (int) $this->wpdb->get_var($query);
        }

        $query = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} {$where}",
            $query_params
        );

        return (int) $this->wpdb->get_var($query);
    }

    /**
     * {@inheritdoc}
     */
    public function end_conversation($id) {
        return $this->update($id, array(
            'status' => 'ended',
            'is_active' => 0,
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function get_by_config($config_id, $limit = 50, $offset = 0) {
        $query = $this->wpdb->prepare(
            "SELECT c.*,
             (SELECT COUNT(*) FROM {$this->messages_table} WHERE conversation_id = c.id) as message_count
             FROM {$this->table} c
             WHERE c.chatbot_config_id = %d
             ORDER BY updated_at DESC
             LIMIT %d OFFSET %d",
            $config_id,
            $limit,
            $offset
        );

        return $this->wpdb->get_results($query);
    }

    /**
     * {@inheritdoc}
     */
    public function get_by_date_range($start_date, $end_date, $config_id = null) {
        $where = "WHERE created_at >= %s AND created_at <= %s";
        $params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');

        if ($config_id !== null) {
            $where .= " AND chatbot_config_id = %d";
            $params[] = intval($config_id);
        }

        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} {$where} ORDER BY created_at ASC",
            $params
        );

        return $this->wpdb->get_results($query);
    }
}
