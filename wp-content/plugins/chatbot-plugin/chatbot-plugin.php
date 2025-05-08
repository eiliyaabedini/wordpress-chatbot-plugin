<?php
/**
 * Plugin Name: Chatbot Plugin
 * Description: A WordPress plugin for integrating AI-powered chatbot functionality.
 * Version: 1.2.1
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: chatbot-plugin
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('CHATBOT_PLUGIN_VERSION', '1.2.1');
define('CHATBOT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CHATBOT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-handler.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-db.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-admin.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-openai.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-settings.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-analytics.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-notifications.php';

/**
 * Helper function for standardized logging
 * 
 * @param string $level Log level (INFO, DEBUG, ERROR)
 * @param string $context Context information (function name, class, etc.)
 * @param string $message Log message
 * @param mixed $data Optional data to include in log
 */
function chatbot_log($level, $context, $message, $data = null) {
    $log_prefix = 'Chatbot: ' . $level . ' - ' . $context . ' - ';
    
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            // For arrays and objects, convert to string representation
            $data_string = print_r($data, true);
            // Truncate if too long
            if (strlen($data_string) > 500) {
                $data_string = substr($data_string, 0, 500) . '... (truncated)';
            }
            error_log($log_prefix . $message . ' Data: ' . $data_string);
        } else {
            // For simple values
            error_log($log_prefix . $message . ' Data: ' . $data);
        }
    } else {
        error_log($log_prefix . $message);
    }
}

// Plugin activation
function activate_chatbot_plugin() {
    chatbot_log('INFO', 'activation', 'Plugin activation started');
    
    // Create necessary folders if they don't exist
    if (!file_exists(CHATBOT_PLUGIN_PATH . 'assets/css')) {
        wp_mkdir_p(CHATBOT_PLUGIN_PATH . 'assets/css');
        chatbot_log('INFO', 'activation', 'Created assets/css directory');
    }
    
    if (!file_exists(CHATBOT_PLUGIN_PATH . 'assets/js')) {
        wp_mkdir_p(CHATBOT_PLUGIN_PATH . 'assets/js');
        chatbot_log('INFO', 'activation', 'Created assets/js directory');
    }
    
    if (!file_exists(CHATBOT_PLUGIN_PATH . 'templates')) {
        wp_mkdir_p(CHATBOT_PLUGIN_PATH . 'templates');
        chatbot_log('INFO', 'activation', 'Created templates directory');
    }
    
    // Create database tables for chat
    create_chatbot_database_tables();
    
    // Create analytics tables
    Chatbot_Analytics::create_tables();
    
    // Add a default configuration if none exists
    add_default_chatbot_configuration();
    
    // Flush rewrite rules
    flush_rewrite_rules();
    
    chatbot_log('INFO', 'activation', 'Plugin activation completed successfully');
}

/**
 * Add a default chatbot configuration if none exists
 */
function add_default_chatbot_configuration() {
    require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-db.php';
    $db = Chatbot_DB::get_instance();
    
    // Check if any configurations exist
    $configurations = $db->get_configurations();
    
    if (empty($configurations)) {
        // Create a default configuration
        $default_name = 'Default';
        
        // System prompt is kept for backward compatibility
        $default_prompt = "You are a helpful AI assistant embedded on a WordPress website. " . 
                          "Your goal is to provide accurate, helpful responses to user questions. " .
                          "Be concise but thorough, and always maintain a professional and friendly tone. " .
                          "If you don't know the answer to a question, acknowledge that rather than making up information.";
        
        // Define default knowledge
        $default_knowledge = "This is a WordPress website. WordPress is a popular content management system used " .
                             "to create websites, blogs, and online stores. The website may contain blog posts, " .
                             "pages, products, or other content types common to WordPress sites.";
        
        // Define default persona
        $default_persona = "You are a helpful, friendly, and professional assistant. You should respond in a " .
                           "conversational tone while maintaining accuracy and being concise. Aim to be " . 
                           "informative but not overly technical unless specifically asked for technical details. " .
                           "Be patient and considerate in your responses. If you don't know something, admit it " .
                           "rather than making up information.";
        
        $db->add_configuration($default_name, $default_prompt, $default_knowledge, $default_persona);
    }
}

/**
 * Create the database tables needed for the chatbot
 */
function create_chatbot_database_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Table for conversations
    $table_conversations = $wpdb->prefix . 'chatbot_conversations';
    
    $sql_conversations = "CREATE TABLE $table_conversations (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        visitor_name varchar(100) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        status varchar(20) DEFAULT 'active' NOT NULL,
        is_active tinyint(1) DEFAULT 1 NOT NULL,
        ended_at datetime DEFAULT NULL,
        archived_at datetime DEFAULT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    
    // Table for messages
    $table_messages = $wpdb->prefix . 'chatbot_messages';
    
    $sql_messages = "CREATE TABLE $table_messages (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        conversation_id bigint(20) NOT NULL,
        sender_type varchar(10) NOT NULL,
        message text NOT NULL,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        KEY conversation_id (conversation_id)
    ) $charset_collate;";
    
    // Table for chatbot configurations
    $table_configurations = $wpdb->prefix . 'chatbot_configurations';
    
    $sql_configurations = "CREATE TABLE $table_configurations (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        system_prompt text NOT NULL,
        knowledge text,
        persona text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY name (name)
    ) $charset_collate;";
    
    // Include WordPress database upgrade functions
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    // Create the tables
    dbDelta($sql_conversations);
    dbDelta($sql_messages);
    dbDelta($sql_configurations);
}
register_activation_hook(__FILE__, 'activate_chatbot_plugin');

/**
 * Update database tables when plugin is updated
 */
function update_chatbot_database_tables() {
    global $wpdb;
    
    // Check if the conversations table needs updating
    $table_name = $wpdb->prefix . 'chatbot_conversations';
    
    // Check for status column
    $check_status_column = $wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'status'");
    if (empty($check_status_column)) {
        // Add new columns for archiving/status features
        $wpdb->query("ALTER TABLE `$table_name` 
                      ADD COLUMN `status` varchar(20) DEFAULT 'active' NOT NULL,
                      ADD COLUMN `ended_at` datetime DEFAULT NULL,
                      ADD COLUMN `archived_at` datetime DEFAULT NULL");
        
        chatbot_log('INFO', 'update_tables', 'Added status columns to conversations table');
    }
    
    // Check for chatbot_config_id column
    $check_config_column = $wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'chatbot_config_id'");
    if (empty($check_config_column)) {
        // Add new column for chatbot configuration
        $wpdb->query("ALTER TABLE `$table_name` 
                      ADD COLUMN `chatbot_config_id` bigint(20) DEFAULT NULL,
                      ADD COLUMN `chatbot_config_name` varchar(100) DEFAULT NULL");
        
        chatbot_log('INFO', 'update_tables', 'Added chatbot configuration columns to conversations table');
    }
    
    // Check if the configurations table exists
    $table_configurations = $wpdb->prefix . 'chatbot_configurations';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_configurations'") === $table_configurations;
    
    if (!$table_exists) {
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql_configurations = "CREATE TABLE $table_configurations (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            system_prompt text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY name (name)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_configurations);
    }
    
    // Check for knowledge and persona columns in configurations table
    if ($table_exists) {
        // Check for knowledge column
        $check_knowledge_column = $wpdb->get_results("SHOW COLUMNS FROM `$table_configurations` LIKE 'knowledge'");
        $check_persona_column = $wpdb->get_results("SHOW COLUMNS FROM `$table_configurations` LIKE 'persona'");
        
        if (empty($check_knowledge_column) || empty($check_persona_column)) {
            chatbot_log('INFO', 'update_tables', 'Adding knowledge and persona columns to configurations table');
            
            // First, make sure both columns don't exist before adding
            if (empty($check_knowledge_column)) {
                $wpdb->query("ALTER TABLE `$table_configurations` ADD COLUMN `knowledge` text DEFAULT NULL AFTER `system_prompt`");
            }
            
            if (empty($check_persona_column)) {
                $wpdb->query("ALTER TABLE `$table_configurations` ADD COLUMN `persona` text DEFAULT NULL AFTER `knowledge`");
            }
            
            // Now migrate existing data from system_prompt to both fields
            $configurations = $wpdb->get_results("SELECT id, system_prompt FROM `$table_configurations`");
            
            foreach ($configurations as $config) {
                if (!empty($config->system_prompt)) {
                    // For existing configs, copy system_prompt to both fields
                    $wpdb->update(
                        $table_configurations,
                        array(
                            'knowledge' => $config->system_prompt,
                            'persona' => "You are a helpful, friendly, and professional assistant. Respond to user inquiries in a conversational tone while maintaining accuracy and being concise."
                        ),
                        array('id' => $config->id),
                        array('%s', '%s'),
                        array('%d')
                    );
                    
                    chatbot_log('INFO', 'update_tables', "Migrated system_prompt to knowledge and persona fields for config id: {$config->id}");
                }
            }
        }
    }
}
add_action('plugins_loaded', 'update_chatbot_database_tables');

// Plugin deactivation
function deactivate_chatbot_plugin() {
    // Deactivation code here
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'deactivate_chatbot_plugin');

// Main plugin class
class Chatbot_Plugin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Initialize the plugin
        add_action('plugins_loaded', array($this, 'init'));
    }
    
    public function init() {
        // Load text domain for internationalization
        load_plugin_textdomain('chatbot-plugin', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add viewport meta tag for proper mobile scaling
        add_action('wp_head', array($this, 'add_viewport_meta'));
        
        // Add shortcode
        add_shortcode('chatbot', array($this, 'chatbot_shortcode'));
    }
    
    /**
     * Add viewport meta tag for mobile devices
     * This ensures proper scaling on mobile devices
     */
    public function add_viewport_meta() {
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">';
        chatbot_log('INFO', 'add_viewport_meta', 'Added viewport meta tag for mobile optimization');
    }
    
    public function enqueue_scripts() {
        wp_enqueue_style(
            'chatbot-plugin-style',
            CHATBOT_PLUGIN_URL . 'assets/css/chatbot.css',
            array(),
            CHATBOT_PLUGIN_VERSION
        );
        
        wp_enqueue_script(
            'chatbot-plugin-script',
            CHATBOT_PLUGIN_URL . 'assets/js/chatbot.js',
            array('jquery'),
            CHATBOT_PLUGIN_VERSION,
            true
        );
        
        // Define default values to ensure consistency across the plugin
        $default_greeting = 'Hello %s! How can I help you today?';
        $default_typing_indicator = 'AI Assistant is typing...';
        
        wp_localize_script(
            'chatbot-plugin-script',
            'chatbotPluginVars',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('chatbot-plugin-nonce'),
                'chatGreeting' => get_option('chatbot_chat_greeting', $default_greeting),
                'typingIndicatorText' => get_option('chatbot_typing_indicator_text', $default_typing_indicator)
            )
        );
    }
    
    public function chatbot_shortcode($atts) {
        $atts = shortcode_atts(
            array(
                'theme' => 'light',
                'name' => '',
            ),
            $atts,
            'chatbot'
        );
        
        $db = Chatbot_DB::get_instance();
        
        // If a specific chatbot name is requested, load its configuration
        if (!empty($atts['name'])) {
            $chatbot_config = $db->get_configuration_by_name($atts['name']);
            
            // If the requested chatbot doesn't exist, fallback to default
            if (!$chatbot_config) {
                // Log for debugging
                error_log('Chatbot configuration not found: ' . $atts['name']);
                
                // Try to get the default configuration
                $chatbot_config = $db->get_configuration_by_name('Default');
            }
        } else {
            // If no name specified, use the default configuration
            $chatbot_config = $db->get_configuration_by_name('Default');
        }
        
        // If we have a valid configuration, add it to the attributes
        if ($chatbot_config) {
            $atts['config'] = $chatbot_config;
        } else {
            // If no configuration found at all, log this issue
            error_log('No chatbot configurations found, including default.');
        }
        
        ob_start();
        include CHATBOT_PLUGIN_PATH . 'templates/chatbot-template.php';
        return ob_get_clean();
    }
}

// Initialize the plugin
function chatbot_plugin_init() {
    return Chatbot_Plugin::get_instance();
}
chatbot_plugin_init();