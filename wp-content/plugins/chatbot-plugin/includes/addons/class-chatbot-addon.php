<?php
/**
 * Chatbot Native Addon Base Class
 *
 * All WordPress-native chatbot addons must extend this class.
 *
 * @package Chatbot_Plugin
 * @since 1.9.0
 */

if (!defined('WPINC')) {
    die;
}

abstract class Chatbot_Addon {

    /**
     * Unique addon ID/slug.
     * @var string
     */
    protected $id;

    /**
     * Addon display name.
     * @var string
     */
    protected $name;

    /**
     * Addon description.
     * @var string
     */
    protected $description;

    /**
     * Addon icon (dashicon class or HTML).
     * @var string
     */
    protected $icon = 'dashicons-admin-plugins';

    /**
     * Current configuration settings for this addon (per-chatbot config).
     * @var array
     */
    protected $settings = array();

    /**
     * Constructor.
     */
    public function __construct() {
        // Initialization inside subclass if needed
    }

    /**
     * Get unique addon ID.
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get display name.
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * Get description.
     */
    public function get_description() {
        return $this->description;
    }

    /**
     * Get icon.
     */
    public function get_icon() {
        return $this->icon;
    }

    /**
     * Set configuration settings for this addon.
     */
    public function set_settings(array $settings) {
        $this->settings = $settings;
    }

    /**
     * Get configuration settings.
     */
    public function get_settings() {
        return $this->settings;
    }

    /**
     * Get tool/function definitions to expose to the AI model.
     * Matches OpenAI / Gemini function calling format.
     *
     * @return array Array of function schemas
     */
    abstract public function get_tool_definitions();

    /**
     * Execute a function call requested by the AI.
     *
     * @param string $tool_name The name of the function to run.
     * @param array $args The arguments supplied by the AI.
     * @param array $context Dynamic context (conversation_id, visitor_name, etc.)
     * @return array|WP_Error Result to return to the AI, or WP_Error on failure.
     */
    abstract public function execute_tool($tool_name, array $args, array $context = array());

    /**
     * Render settings fields in the WordPress admin panel.
     * These fields will be displayed when the addon is enabled.
     * Output HTML directly.
     *
     * @param int $chatbot_id The current chatbot configuration ID.
     */
    public function render_settings_fields($chatbot_id) {
        // Optional override in subclass
    }

    /**
     * Sanitize and validate settings posted from the admin form.
     *
     * @param array $input Raw POST inputs.
     * @return array Sanitized settings.
     */
    public function sanitize_settings(array $input) {
        return $input; // Optional override in subclass
    }

    /**
     * Get custom admin tabs for this addon when active.
     * Overriden by addons that want to register tabs in the Addons page.
     *
     * @return array Array of tab_id => tab_label
     */
    public function get_admin_tabs() {
        return array();
    }

    /**
     * Render the content for a custom admin tab.
     *
     * @param string $tab The tab ID.
     */
    public function render_admin_tab($tab) {
        // Optional override in subclass
    }
}

