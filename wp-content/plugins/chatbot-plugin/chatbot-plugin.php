<?php
/**
 * Plugin Name: Chatbot Plugin
 * Description: A WordPress plugin for integrating AI-powered chatbot functionality.
 * Version: 1.2.0
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: chatbot-plugin
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('CHATBOT_PLUGIN_VERSION', '1.2.0');
define('CHATBOT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CHATBOT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-handler.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-db.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-admin.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-openai.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-settings.php';

// Plugin activation
function activate_chatbot_plugin() {
    // Create necessary folders if they don't exist
    if (!file_exists(CHATBOT_PLUGIN_PATH . 'assets/css')) {
        wp_mkdir_p(CHATBOT_PLUGIN_PATH . 'assets/css');
    }
    
    if (!file_exists(CHATBOT_PLUGIN_PATH . 'assets/js')) {
        wp_mkdir_p(CHATBOT_PLUGIN_PATH . 'assets/js');
    }
    
    if (!file_exists(CHATBOT_PLUGIN_PATH . 'templates')) {
        wp_mkdir_p(CHATBOT_PLUGIN_PATH . 'templates');
    }
    
    // Create database tables for chat
    create_chatbot_database_tables();
    
    // Flush rewrite rules
    flush_rewrite_rules();
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
    
    // Include WordPress database upgrade functions
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    // Create the tables
    dbDelta($sql_conversations);
    dbDelta($sql_messages);
}
register_activation_hook(__FILE__, 'activate_chatbot_plugin');

/**
 * Update database tables when plugin is updated
 */
function update_chatbot_database_tables() {
    global $wpdb;
    
    // Check if the database needs updating
    $table_name = $wpdb->prefix . 'chatbot_conversations';
    $check_column = $wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'status'");
    
    if (empty($check_column)) {
        // Add new columns for archiving/status features
        $wpdb->query("ALTER TABLE `$table_name` 
                      ADD COLUMN `status` varchar(20) DEFAULT 'active' NOT NULL,
                      ADD COLUMN `ended_at` datetime DEFAULT NULL,
                      ADD COLUMN `archived_at` datetime DEFAULT NULL");
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
        
        // Add shortcode
        add_shortcode('chatbot', array($this, 'chatbot_shortcode'));
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
        
        // Define default greeting to ensure consistency across the plugin
        $default_greeting = 'Hello %s! How can I help you today?';
        
        wp_localize_script(
            'chatbot-plugin-script',
            'chatbotPluginVars',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('chatbot-plugin-nonce'),
                'chatGreeting' => get_option('chatbot_chat_greeting', $default_greeting)
            )
        );
    }
    
    public function chatbot_shortcode($atts) {
        $atts = shortcode_atts(
            array(
                'theme' => 'light',
            ),
            $atts,
            'chatbot'
        );
        
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