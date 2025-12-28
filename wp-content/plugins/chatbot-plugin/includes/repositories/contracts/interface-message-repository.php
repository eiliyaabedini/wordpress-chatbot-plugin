<?php
/**
 * Message Repository Interface
 *
 * Defines the contract for message data access.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Interface Chatbot_Message_Repository
 *
 * Contract for managing message persistence.
 */
interface Chatbot_Message_Repository {

    /**
     * Save a new message.
     *
     * @param int    $conversation_id The conversation ID.
     * @param string $sender_type     The sender type (user|ai|admin|system).
     * @param string $message         The message content.
     * @return int|null The new message ID or null on failure.
     */
    public function save($conversation_id, $sender_type, $message);

    /**
     * Get all messages for a conversation.
     *
     * @param int $conversation_id The conversation ID.
     * @return array Array of message objects.
     */
    public function get_for_conversation($conversation_id);

    /**
     * Get recent messages for a conversation.
     *
     * @param int $conversation_id The conversation ID.
     * @param int $limit           Maximum number of messages to return.
     * @return array Array of message objects, ordered from oldest to newest.
     */
    public function get_recent($conversation_id, $limit = 10);

    /**
     * Find a message by ID.
     *
     * @param int $id The message ID.
     * @return object|null The message object or null if not found.
     */
    public function find($id);

    /**
     * Update a message.
     *
     * @param int   $id   The message ID.
     * @param array $data The data to update.
     * @return bool True on success, false on failure.
     */
    public function update($id, array $data);

    /**
     * Delete a message.
     *
     * @param int $id The message ID.
     * @return bool True on success, false on failure.
     */
    public function delete($id);

    /**
     * Delete all messages for a conversation.
     *
     * @param int $conversation_id The conversation ID.
     * @return int Number of deleted messages.
     */
    public function delete_for_conversation($conversation_id);

    /**
     * Count messages for a conversation.
     *
     * @param int $conversation_id The conversation ID.
     * @return int The message count.
     */
    public function count_for_conversation($conversation_id);

    /**
     * Get messages by sender type for a conversation.
     *
     * @param int    $conversation_id The conversation ID.
     * @param string $sender_type     The sender type.
     * @return array Array of message objects.
     */
    public function get_by_sender_type($conversation_id, $sender_type);

    /**
     * Get the last message in a conversation.
     *
     * @param int $conversation_id The conversation ID.
     * @return object|null The last message or null if none exist.
     */
    public function get_last($conversation_id);

    /**
     * Get messages within a date range.
     *
     * @param string   $start_date      Start date (Y-m-d H:i:s format).
     * @param string   $end_date        End date (Y-m-d H:i:s format).
     * @param int|null $conversation_id Optional conversation ID filter.
     * @return array Array of message objects.
     */
    public function get_by_date_range($start_date, $end_date, $conversation_id = null);
}
