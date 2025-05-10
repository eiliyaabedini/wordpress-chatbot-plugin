<?php
/**
 * Chatbot Data Retention Handler
 * 
 * Handles data retention policies and cleanup operations for GDPR compliance
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_Data_Retention {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register the hooks
        add_action('admin_init', array($this, 'register_settings'));

        // Register the cron job for automatic cleanup
        add_action('init', array($this, 'register_cron'));

        // Add the hook for the cron job to call
        add_action('chatbot_data_retention_cleanup', array($this, 'scheduled_cleanup'));

        // Handle manual cleanup requests
        add_action('admin_post_chatbot_manual_cleanup', array($this, 'handle_manual_cleanup'));

        // Add the cleanup action to the chatbot settings page
        add_action('admin_notices', array($this, 'display_cleanup_notices'));

        // Register AJAX handlers for data retention operations
        add_action('wp_ajax_chatbot_ajax_cleanup', array($this, 'ajax_cleanup_handler'));
    }
    
    /**
     * Register data retention settings
     */
    public function register_settings() {
        // Register data retention settings
        register_setting('chatbot_data_retention', 'chatbot_enable_data_retention', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ));
        
        register_setting('chatbot_data_retention', 'chatbot_data_retention_period', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 90,
        ));
        
        register_setting('chatbot_data_retention', 'chatbot_skip_export_before_deletion', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ));
    }
    
    /**
     * Register the cron job for automatic cleanup
     */
    public function register_cron() {
        // Check if automatic cleanup is enabled
        if (get_option('chatbot_enable_data_retention', false)) {
            // Schedule the event if not already scheduled
            if (!wp_next_scheduled('chatbot_data_retention_cleanup')) {
                // Schedule it to run daily at midnight
                wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'chatbot_data_retention_cleanup');
                
                error_log('Chatbot: INFO - Data retention cron job scheduled');
            }
        } else {
            // Unschedule the event if automatic cleanup is disabled
            $timestamp = wp_next_scheduled('chatbot_data_retention_cleanup');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'chatbot_data_retention_cleanup');
                
                error_log('Chatbot: INFO - Data retention cron job unscheduled');
            }
        }
    }
    
    /**
     * Run the scheduled cleanup task
     */
    public function scheduled_cleanup() {
        // Check if automatic cleanup is enabled
        if (!get_option('chatbot_enable_data_retention', false)) {
            error_log('Chatbot: INFO - Scheduled data cleanup skipped (automatic cleanup disabled)');
            return;
        }
        
        // Get the retention period in days
        $days = intval(get_option('chatbot_data_retention_period', 90));
        
        // Run the cleanup
        $result = $this->delete_old_conversations($days);
        
        error_log('Chatbot: INFO - Scheduled data cleanup completed. Deleted conversations: ' . $result['deleted_count']);
        
        return $result;
    }
    
    /**
     * Delete conversations older than a specified number of days
     * 
     * @param int $days The number of days to keep conversations for
     * @param bool $export Whether to export the conversations before deletion
     * @return array Result of the operation with count and status
     */
    public function delete_old_conversations($days = 90, $export = false) {
        global $wpdb;
        
        // Validate days parameter
        $days = max(1, intval($days));
        
        error_log('Chatbot: INFO - Starting deletion of conversations older than ' . $days . ' days');
        
        // Calculate the cutoff date
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        
        // Export conversations if requested
        if ($export) {
            $this->export_conversations_before_date($cutoff_date);
        }
        
        // Get the DB handler
        $db = Chatbot_DB::get_instance();
        if (!$db) {
            error_log('Chatbot: ERROR - Data retention cleanup failed: DB handler not available');
            return array(
                'success' => false,
                'deleted_count' => 0,
                'error' => 'Database handler not available',
            );
        }
        
        // Get the conversation IDs to delete
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $conversation_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $conversations_table WHERE created_at < %s",
            $cutoff_date
        ));
        
        if (empty($conversation_ids)) {
            error_log('Chatbot: INFO - No conversations found older than ' . $cutoff_date);
            return array(
                'success' => true,
                'deleted_count' => 0,
                'message' => 'No conversations found to delete',
            );
        }
        
        // Delete each conversation
        $deleted_count = 0;
        $error_count = 0;
        
        foreach ($conversation_ids as $id) {
            $result = $db->delete_conversation($id);
            if ($result) {
                $deleted_count++;
            } else {
                $error_count++;
                error_log('Chatbot: ERROR - Failed to delete conversation ID: ' . $id);
            }
        }
        
        error_log('Chatbot: INFO - Data retention cleanup completed. Deleted: ' . $deleted_count . ', Errors: ' . $error_count);
        
        return array(
            'success' => true,
            'deleted_count' => $deleted_count,
            'error_count' => $error_count,
            'message' => sprintf('Successfully deleted %d conversations. Failed to delete %d.', $deleted_count, $error_count),
        );
    }
    
    /**
     * Delete all conversations (used for complete cleanup)
     * 
     * @param bool $export Whether to export the conversations before deletion
     * @return array Result of the operation with count and status
     */
    public function delete_all_conversations($export = false) {
        global $wpdb;
        
        error_log('Chatbot: INFO - Starting deletion of ALL conversations');
        
        // Export all conversations if requested
        if ($export) {
            $this->export_all_conversations();
        }
        
        // Get the DB handler
        $db = Chatbot_DB::get_instance();
        if (!$db) {
            error_log('Chatbot: ERROR - Complete data cleanup failed: DB handler not available');
            return array(
                'success' => false,
                'deleted_count' => 0,
                'error' => 'Database handler not available',
            );
        }
        
        // Get all conversation IDs
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $conversation_ids = $wpdb->get_col("SELECT id FROM $conversations_table");
        
        if (empty($conversation_ids)) {
            error_log('Chatbot: INFO - No conversations found to delete');
            return array(
                'success' => true,
                'deleted_count' => 0,
                'message' => 'No conversations found to delete',
            );
        }
        
        // Delete each conversation
        $deleted_count = 0;
        $error_count = 0;
        
        foreach ($conversation_ids as $id) {
            $result = $db->delete_conversation($id);
            if ($result) {
                $deleted_count++;
            } else {
                $error_count++;
                error_log('Chatbot: ERROR - Failed to delete conversation ID: ' . $id);
            }
        }
        
        error_log('Chatbot: INFO - Complete data cleanup completed. Deleted: ' . $deleted_count . ', Errors: ' . $error_count);
        
        return array(
            'success' => true,
            'deleted_count' => $deleted_count,
            'error_count' => $error_count,
            'message' => sprintf('Successfully deleted %d conversations. Failed to delete %d.', $deleted_count, $error_count),
        );
    }
    
    /**
     * Export conversations before a specific date (for GDPR compliance)
     * 
     * @param string $date The cutoff date in MySQL format
     * @return string|false The path to the export file or false on failure
     */
    private function export_conversations_before_date($date) {
        global $wpdb;
        
        // Get the conversations and messages data
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $messages_table = $wpdb->prefix . 'chatbot_messages';
        
        $conversations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $conversations_table WHERE created_at < %s",
            $date
        ));
        
        if (empty($conversations)) {
            return false;
        }
        
        // Prepare the export data
        $export_data = array(
            'conversations' => array(),
            'export_date' => current_time('mysql'),
            'plugin_version' => defined('CHATBOT_VERSION') ? CHATBOT_VERSION : 'unknown',
        );
        
        foreach ($conversations as $conversation) {
            // Get all messages for this conversation
            $messages = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $messages_table WHERE conversation_id = %d",
                $conversation->id
            ));
            
            // Add to export data
            $export_data['conversations'][] = array(
                'conversation' => $conversation,
                'messages' => $messages,
            );
        }
        
        // Create the export file
        $export_dir = WP_CONTENT_DIR . '/chatbot-exports/';
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
            
            // Add index.php to prevent directory listing
            file_put_contents($export_dir . 'index.php', '<?php // Silence is golden');
            
            // Add .htaccess to prevent direct access
            file_put_contents($export_dir . '.htaccess', 'Deny from all');
        }
        
        // Generate filename with timestamp
        $filename = 'chatbot-export-' . date('Y-m-d-His') . '.json';
        $filepath = $export_dir . $filename;
        
        // Write the export data to the file
        $result = file_put_contents($filepath, json_encode($export_data, JSON_PRETTY_PRINT));
        
        if ($result === false) {
            error_log('Chatbot: ERROR - Failed to write export file: ' . $filepath);
            return false;
        }
        
        error_log('Chatbot: INFO - Exported ' . count($export_data['conversations']) . ' conversations to ' . $filepath);
        
        return $filepath;
    }
    
    /**
     * Export all conversations (for GDPR compliance)
     * 
     * @return string|false The path to the export file or false on failure
     */
    private function export_all_conversations() {
        global $wpdb;
        
        // Get the conversations and messages data
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $messages_table = $wpdb->prefix . 'chatbot_messages';
        
        $conversations = $wpdb->get_results("SELECT * FROM $conversations_table");
        
        if (empty($conversations)) {
            return false;
        }
        
        // Prepare the export data
        $export_data = array(
            'conversations' => array(),
            'export_date' => current_time('mysql'),
            'plugin_version' => defined('CHATBOT_VERSION') ? CHATBOT_VERSION : 'unknown',
        );
        
        foreach ($conversations as $conversation) {
            // Get all messages for this conversation
            $messages = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $messages_table WHERE conversation_id = %d",
                $conversation->id
            ));
            
            // Add to export data
            $export_data['conversations'][] = array(
                'conversation' => $conversation,
                'messages' => $messages,
            );
        }
        
        // Create the export file
        $export_dir = WP_CONTENT_DIR . '/chatbot-exports/';
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
            
            // Add index.php to prevent directory listing
            file_put_contents($export_dir . 'index.php', '<?php // Silence is golden');
            
            // Add .htaccess to prevent direct access
            file_put_contents($export_dir . '.htaccess', 'Deny from all');
        }
        
        // Generate filename with timestamp
        $filename = 'chatbot-export-all-' . date('Y-m-d-His') . '.json';
        $filepath = $export_dir . $filename;
        
        // Write the export data to the file
        $result = file_put_contents($filepath, json_encode($export_data, JSON_PRETTY_PRINT));
        
        if ($result === false) {
            error_log('Chatbot: ERROR - Failed to write export file: ' . $filepath);
            return false;
        }
        
        error_log('Chatbot: INFO - Exported all ' . count($export_data['conversations']) . ' conversations to ' . $filepath);
        
        return $filepath;
    }
    
    /**
     * Handle manual cleanup requests from the admin interface
     */
    public function handle_manual_cleanup() {
        // Verify nonce and user capabilities
        if (!isset($_POST['chatbot_cleanup_nonce']) || 
            !wp_verify_nonce($_POST['chatbot_cleanup_nonce'], 'chatbot_manual_cleanup') ||
            !current_user_can('manage_options')) {
            
            wp_die('Security check failed', 'Security Error', array('response' => 403));
        }
        
        $cleanup_period = isset($_POST['cleanup_period']) ? sanitize_text_field($_POST['cleanup_period']) : 'custom';
        $custom_period = isset($_POST['custom_period']) ? intval($_POST['custom_period']) : 90;
        
        // Whether to export before deletion
        $export = !get_option('chatbot_skip_export_before_deletion', true);
        
        // Process based on selected option
        if ($cleanup_period === 'all') {
            // Delete all conversations
            $result = $this->delete_all_conversations($export);
            
            // Set notice for success or failure
            if ($result['success']) {
                set_transient('chatbot_cleanup_message', sprintf(
                    __('Successfully deleted %d conversations.', 'chatbot-plugin'),
                    $result['deleted_count']
                ), 60);
            } else {
                set_transient('chatbot_cleanup_error', $result['error'], 60);
            }
        } else {
            // Delete conversations older than the specified period
            $result = $this->delete_old_conversations($custom_period, $export);
            
            // Set notice for success or failure
            if ($result['success']) {
                set_transient('chatbot_cleanup_message', sprintf(
                    __('Successfully deleted %d conversations older than %d days.', 'chatbot-plugin'),
                    $result['deleted_count'], $custom_period
                ), 60);
            } else {
                set_transient('chatbot_cleanup_error', $result['error'], 60);
            }
        }
        
        // Redirect back to the security page
        wp_redirect(admin_url('admin.php?page=chatbot-settings&tab=security'));
        exit;
    }
    
    /**
     * Display cleanup notices on the settings page
     */
    public function display_cleanup_notices() {
        // Only show on chatbot settings page
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'chatbot_page_chatbot-settings') {
            return;
        }

        // Check for success message
        $message = get_transient('chatbot_cleanup_message');
        if ($message) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
            delete_transient('chatbot_cleanup_message');
        }

        // Check for error message
        $error = get_transient('chatbot_cleanup_error');
        if ($error) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
            delete_transient('chatbot_cleanup_error');
        }
    }

    /**
     * AJAX handler for data retention operations
     * Handles asynchronous cleanup requests from the admin UI
     */
    public function ajax_cleanup_handler() {
        // Check nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_cleanup_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You do not have permission to perform this action'));
        }

        // Get the cleanup action
        $action_type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';

        // Get export preference
        $export = isset($_POST['export']) ? (bool)$_POST['export'] : false;

        // Log the request (sanitized)
        error_log('Chatbot: INFO - AJAX cleanup request received. Type: ' . $action_type . ', Export: ' . ($export ? 'yes' : 'no'));

        $result = array();

        switch ($action_type) {
            case 'all':
                // Delete all conversations
                $result = $this->delete_all_conversations($export);
                break;

            case 'older_than':
                // Get the number of days to keep
                $days = isset($_POST['days']) ? intval($_POST['days']) : 90;

                // Delete conversations older than the specified days
                $result = $this->delete_old_conversations($days, $export);
                break;

            default:
                wp_send_json_error(array('message' => 'Invalid action type'));
                break;
        }

        // Send the result
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}

// Initialize the data retention handler
function chatbot_data_retention_init() {
    return Chatbot_Data_Retention::get_instance();
}
chatbot_data_retention_init();