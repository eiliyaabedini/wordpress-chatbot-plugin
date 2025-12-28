<?php
/**
 * Configuration Repository Interface
 *
 * Defines the contract for chatbot configuration data access.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Interface Chatbot_Configuration_Repository
 *
 * Contract for managing chatbot configuration persistence.
 */
interface Chatbot_Configuration_Repository {

    /**
     * Create a new chatbot configuration.
     *
     * @param array $data Configuration data including:
     *                    - name: string (unique identifier)
     *                    - persona: string (AI personality description)
     *                    - knowledge: string (knowledge base content)
     *                    - knowledge_sources: string (JSON of WordPress content sources)
     *                    - n8n_settings: string (JSON of n8n integration settings)
     *                    - telegram_bot_token: string (optional)
     * @return int|null The new configuration ID or null on failure.
     */
    public function create(array $data);

    /**
     * Find a configuration by ID.
     *
     * @param int $id The configuration ID.
     * @return object|null The configuration object or null if not found.
     */
    public function find($id);

    /**
     * Find a configuration by name.
     *
     * @param string $name The configuration name.
     * @return object|null The configuration object or null if not found.
     */
    public function find_by_name($name);

    /**
     * Update a configuration.
     *
     * @param int   $id   The configuration ID.
     * @param array $data The data to update.
     * @return bool True on success, false on failure.
     */
    public function update($id, array $data);

    /**
     * Delete a configuration.
     *
     * @param int $id The configuration ID.
     * @return bool True on success, false on failure.
     */
    public function delete($id);

    /**
     * Get all configurations.
     *
     * @return array Array of configuration objects.
     */
    public function get_all();

    /**
     * Check if a configuration name exists.
     *
     * @param string $name       The configuration name to check.
     * @param int    $exclude_id Optional ID to exclude from check (for updates).
     * @return bool True if the name exists.
     */
    public function name_exists($name, $exclude_id = 0);

    /**
     * Get the default configuration.
     *
     * Returns the first configuration if multiple exist,
     * or creates a default one if none exist.
     *
     * @return object|null The default configuration or null.
     */
    public function get_default();

    /**
     * Count total configurations.
     *
     * @return int The configuration count.
     */
    public function count();

    /**
     * Get configurations with Telegram integration enabled.
     *
     * @return array Array of configuration objects with telegram_bot_token set.
     */
    public function get_with_telegram();

    /**
     * Get configurations with WhatsApp integration enabled.
     *
     * @return array Array of configuration objects with WhatsApp credentials.
     */
    public function get_with_whatsapp();

    /**
     * Get configurations with n8n integration enabled.
     *
     * @return array Array of configuration objects with n8n_settings.
     */
    public function get_with_n8n();
}
