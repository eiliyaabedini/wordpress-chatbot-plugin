<?php
/**
 * WordPress Message Repository Implementation
 *
 * Implements message persistence using WordPress database.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_WP_Message_Repository
 *
 * WordPress-specific implementation of the Message Repository.
 */
class Chatbot_WP_Message_Repository implements Chatbot_Message_Repository {

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Messages table name.
     *
     * @var string
     */
    private $table;

    /**
     * Conversations table name (for updating timestamps).
     *
     * @var string
     */
    private $conversations_table;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'chatbot_messages';
        $this->conversations_table = $wpdb->prefix . 'chatbot_conversations';
    }

    /**
     * {@inheritdoc}
     */
    public function save($conversation_id, $sender_type, $message) {
        // Update conversation's updated_at timestamp
        $this->wpdb->update(
            $this->conversations_table,
            array('updated_at' => current_time('mysql')),
            array('id' => $conversation_id),
            array('%s'),
            array('%d')
        );

        $result = $this->wpdb->insert(
            $this->table,
            array(
                'conversation_id' => intval($conversation_id),
                'sender_type' => sanitize_text_field($sender_type),
                'message' => sanitize_textarea_field($message),
            ),
            array('%d', '%s', '%s')
        );

        if ($result === false) {
            if (function_exists('chatbot_log')) {
                chatbot_log('ERROR', 'message_repository', 'Failed to save message', array(
                    'error' => $this->wpdb->last_error,
                    'conversation_id' => $conversation_id
                ));
            }
            return null;
        }

        return $this->wpdb->insert_id;
    }

    /**
     * {@inheritdoc}
     */
    public function get_for_conversation($conversation_id) {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE conversation_id = %d ORDER BY timestamp ASC",
            $conversation_id
        );
        return $this->wpdb->get_results($query);
    }

    /**
     * {@inheritdoc}
     */
    public function get_recent($conversation_id, $limit = 10) {
        // Get last N messages in chronological order
        $query = $this->wpdb->prepare(
            "SELECT * FROM (
                SELECT * FROM {$this->table}
                WHERE conversation_id = %d
                ORDER BY timestamp DESC
                LIMIT %d
            ) sub ORDER BY timestamp ASC",
            $conversation_id,
            $limit
        );
        return $this->wpdb->get_results($query);
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
    public function update($id, array $data) {
        $update_data = array();
        $formats = array();

        if (isset($data['message'])) {
            $update_data['message'] = sanitize_textarea_field($data['message']);
            $formats[] = '%s';
        }

        if (isset($data['sender_type'])) {
            $update_data['sender_type'] = sanitize_text_field($data['sender_type']);
            $formats[] = '%s';
        }

        if (empty($update_data)) {
            return false;
        }

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
        $result = $this->wpdb->delete(
            $this->table,
            array('id' => $id),
            array('%d')
        );
        return $result !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete_for_conversation($conversation_id) {
        $result = $this->wpdb->delete(
            $this->table,
            array('conversation_id' => $conversation_id),
            array('%d')
        );
        return $result !== false ? $result : 0;
    }

    /**
     * {@inheritdoc}
     */
    public function count_for_conversation($conversation_id) {
        $query = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE conversation_id = %d",
            $conversation_id
        );
        return (int) $this->wpdb->get_var($query);
    }

    /**
     * {@inheritdoc}
     */
    public function get_by_sender_type($conversation_id, $sender_type) {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE conversation_id = %d AND sender_type = %s
             ORDER BY timestamp ASC",
            $conversation_id,
            $sender_type
        );
        return $this->wpdb->get_results($query);
    }

    /**
     * {@inheritdoc}
     */
    public function get_last($conversation_id) {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE conversation_id = %d
             ORDER BY timestamp DESC
             LIMIT 1",
            $conversation_id
        );
        return $this->wpdb->get_row($query);
    }

    /**
     * {@inheritdoc}
     */
    public function get_by_date_range($start_date, $end_date, $conversation_id = null) {
        $where = "WHERE timestamp >= %s AND timestamp <= %s";
        $params = array($start_date, $end_date);

        if ($conversation_id !== null) {
            $where .= " AND conversation_id = %d";
            $params[] = intval($conversation_id);
        }

        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} {$where} ORDER BY timestamp ASC",
            $params
        );

        return $this->wpdb->get_results($query);
    }
}
