<?php
/**
 * Conversation Repository Interface
 *
 * Defines the contract for conversation data access.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Interface Chatbot_Conversation_Repository
 *
 * Contract for managing conversation persistence.
 */
interface Chatbot_Conversation_Repository {

    /**
     * Create a new conversation.
     *
     * @param array $data Conversation data including:
     *                    - visitor_name: string
     *                    - chatbot_config_id: int
     *                    - platform_type: string (web|telegram|whatsapp)
     *                    - platform_chat_id: string
     *                    - status: string (active|ended)
     * @return int|null The new conversation ID or null on failure.
     */
    public function create(array $data);

    /**
     * Find a conversation by ID.
     *
     * @param int $id The conversation ID.
     * @return object|null The conversation object or null if not found.
     */
    public function find($id);

    /**
     * Find a conversation by platform identifiers.
     *
     * @param string $platform_chat_id The platform-specific chat ID.
     * @param string $platform_type    The platform type (telegram|whatsapp|web).
     * @param int    $config_id        The chatbot configuration ID.
     * @return object|null The conversation object or null if not found.
     */
    public function find_by_platform($platform_chat_id, $platform_type, $config_id);

    /**
     * Find an active conversation by platform identifiers.
     *
     * @param string $platform_chat_id The platform-specific chat ID.
     * @param string $platform_type    The platform type.
     * @param int    $config_id        The chatbot configuration ID.
     * @return object|null The active conversation object or null if not found.
     */
    public function find_active_by_platform($platform_chat_id, $platform_type, $config_id);

    /**
     * Update a conversation.
     *
     * @param int   $id   The conversation ID.
     * @param array $data The data to update.
     * @return bool True on success, false on failure.
     */
    public function update($id, array $data);

    /**
     * Delete a conversation.
     *
     * @param int $id The conversation ID.
     * @return bool True on success, false on failure.
     */
    public function delete($id);

    /**
     * Get conversations by status.
     *
     * @param string   $status    The conversation status (active|ended).
     * @param int      $limit     Maximum number of results.
     * @param int      $offset    Offset for pagination.
     * @param int|null $config_id Optional chatbot configuration ID filter.
     * @return array Array of conversation objects.
     */
    public function get_by_status($status, $limit = 50, $offset = 0, $config_id = null);

    /**
     * Count conversations by status.
     *
     * @param string   $status    The conversation status.
     * @param int|null $config_id Optional chatbot configuration ID filter.
     * @return int The count of matching conversations.
     */
    public function count_by_status($status, $config_id = null);

    /**
     * End a conversation (set status to 'ended').
     *
     * @param int $id The conversation ID.
     * @return bool True on success, false on failure.
     */
    public function end_conversation($id);

    /**
     * Get all conversations for a specific configuration.
     *
     * @param int $config_id The chatbot configuration ID.
     * @param int $limit     Maximum number of results.
     * @param int $offset    Offset for pagination.
     * @return array Array of conversation objects.
     */
    public function get_by_config($config_id, $limit = 50, $offset = 0);

    /**
     * Get conversations within a date range.
     *
     * @param string   $start_date Start date (Y-m-d format).
     * @param string   $end_date   End date (Y-m-d format).
     * @param int|null $config_id  Optional chatbot configuration ID filter.
     * @return array Array of conversation objects.
     */
    public function get_by_date_range($start_date, $end_date, $config_id = null);
}
