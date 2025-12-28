<?php
/**
 * Plugin Name: AIPass Chat
 * Plugin URI: https://aipass.one
 * Description: AI-powered chatbot for WordPress. Connect to 161+ AI models including GPT-4 and Gemini via AIPass.
 * Version: 1.6.0
 * Author: Eiliya Abedini
 * Author URI: https://iact.ir
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: aipass-chat
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('CHATBOT_PLUGIN_VERSION', '1.6.0');
define('CHATBOT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CHATBOT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHATBOT_DEFAULT_MODEL', 'gemini/gemini-2.5-flash-lite'); // Default AI model for all API calls

// Include required files
// IMPORTANT: Load AIPass classes BEFORE AI class
// so that AI class can detect AIPass during initialization
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-aipass.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-aipass-proxy.php';

// Load n8n gateway BEFORE AI class so it can use it for function calling
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-n8n-gateway.php';

require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-handler.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-db.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-admin.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-ai.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-settings.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-analytics.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-notifications.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-rate-limiter.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-data-retention.php';

// Load new messaging platform architecture
require_once CHATBOT_PLUGIN_PATH . 'includes/messaging/class-chatbot-messaging-platform.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/messaging/class-chatbot-messaging-manager.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/messaging/class-chatbot-platform-telegram.php';
require_once CHATBOT_PLUGIN_PATH . 'includes/messaging/class-chatbot-platform-whatsapp.php';

// Keep legacy Telegram class for backward compatibility
require_once CHATBOT_PLUGIN_PATH . 'includes/class-chatbot-telegram.php';

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

    // List of sensitive keys that should never be logged fully
    $sensitive_keys = array(
        'api_key', 'password', 'secret', 'token', 'auth', 'credential',
        'wpdb_error', 'wpdb_query', 'sql', 'body'
    );

    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            // Convert to array for consistent handling
            $data_array = (array)$data;

            // Sanitize sensitive data
            foreach ($data_array as $key => $value) {
                // Check if this key might contain sensitive information
                foreach ($sensitive_keys as $sensitive_key) {
                    if (stripos($key, $sensitive_key) !== false) {
                        if (is_string($value) && !empty($value)) {
                            // Mask the sensitive value - show only type and length
                            $data_array[$key] = '[REDACTED:' . gettype($value) . ':' . strlen($value) . 'chars]';
                        } elseif (is_array($value) || is_object($value)) {
                            // For arrays/objects just show their type and size
                            $data_array[$key] = '[REDACTED:' . gettype($value) . ':' . count((array)$value) . 'items]';
                        }
                        break;
                    }
                }
            }

            // Convert back to string representation
            $data_string = print_r($data_array, true);

            // Truncate if too long
            if (strlen($data_string) > 500) {
                $data_string = substr($data_string, 0, 500) . '... (truncated)';
            }

            error_log($log_prefix . $message . ' Data: ' . $data_string);
        } else {
            // For simple values, check if it might be sensitive
            $potentially_sensitive = false;
            if (is_string($data)) {
                // Check if the data might contain sensitive information based on the message
                $lower_message = strtolower($message);
                foreach ($sensitive_keys as $sensitive_key) {
                    if (stripos($lower_message, $sensitive_key) !== false) {
                        $potentially_sensitive = true;
                        break;
                    }
                }
            }

            if ($potentially_sensitive) {
                // Only log the type and length of potentially sensitive data
                error_log($log_prefix . $message . ' Data type: ' . gettype($data) . ', Length: ' . (is_string($data) ? strlen($data) : 'N/A'));
            } else {
                // Regular logging for non-sensitive data
                error_log($log_prefix . $message . ' Data: ' . $data);
            }
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
        telegram_chat_id bigint(20) DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY telegram_chat_id (telegram_chat_id)
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
        knowledge_sources text,
        telegram_bot_token varchar(100) DEFAULT NULL,
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
    
    // Check for status column using INFORMATION_SCHEMA for better security
    $check_status_column = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table_name,
            'status'
        )
    );
    if (empty($check_status_column)) {
        // Add new columns for archiving/status features
        // Because table/column names can't be parameterized directly in SQL,
        // we construct the query safely with known table name
        $alter_query = sprintf(
            "ALTER TABLE `%s`
             ADD COLUMN `status` varchar(20) DEFAULT 'active' NOT NULL,
             ADD COLUMN `ended_at` datetime DEFAULT NULL,
             ADD COLUMN `archived_at` datetime DEFAULT NULL",
            $wpdb->prefix . 'chatbot_conversations'
        );
        $wpdb->query($alter_query);
        
        chatbot_log('INFO', 'update_tables', 'Added status columns to conversations table');
    }
    
    // Check for chatbot_config_id column using INFORMATION_SCHEMA for better security
    $check_config_column = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table_name,
            'chatbot_config_id'
        )
    );
    if (empty($check_config_column)) {
        // Add new column for chatbot configuration
        // Because table/column names can't be parameterized directly in SQL,
        // we construct the query safely with known table name
        $alter_query = sprintf(
            "ALTER TABLE `%s`
             ADD COLUMN `chatbot_config_id` bigint(20) DEFAULT NULL,
             ADD COLUMN `chatbot_config_name` varchar(100) DEFAULT NULL",
            $wpdb->prefix . 'chatbot_conversations'
        );
        $wpdb->query($alter_query);
        
        chatbot_log('INFO', 'update_tables', 'Added chatbot configuration columns to conversations table');
    }
    
    // Check if the configurations table exists
    $table_configurations = $wpdb->prefix . 'chatbot_configurations';
    $table_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME,
            $table_configurations
        )
    );
    
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
        // Check for knowledge column using INFORMATION_SCHEMA for better security
        $check_knowledge_column = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table_configurations,
                'knowledge'
            )
        );
        $check_persona_column = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table_configurations,
                'persona'
            )
        );
        
        if (empty($check_knowledge_column) || empty($check_persona_column)) {
            chatbot_log('INFO', 'update_tables', 'Adding knowledge and persona columns to configurations table');
            
            // First, make sure both columns don't exist before adding
            if (empty($check_knowledge_column)) {
                // Using sprintf for consistent, clean code - table name is not user input
                $alter_query = sprintf(
                    "ALTER TABLE `%s` ADD COLUMN `knowledge` text DEFAULT NULL AFTER `system_prompt`",
                    $wpdb->prefix . 'chatbot_configurations'
                );
                $wpdb->query($alter_query);
            }

            if (empty($check_persona_column)) {
                // Using sprintf for consistent, clean code - table name is not user input
                $alter_query = sprintf(
                    "ALTER TABLE `%s` ADD COLUMN `persona` text DEFAULT NULL AFTER `knowledge`",
                    $wpdb->prefix . 'chatbot_configurations'
                );
                $wpdb->query($alter_query);
            }
            
            // Now migrate existing data from system_prompt to both fields
            $query = sprintf(
                "SELECT id, system_prompt FROM `%s`",
                $wpdb->prefix . 'chatbot_configurations'
            );
            $configurations = $wpdb->get_results($query);
            
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

        // Check for knowledge_sources column (for WordPress content as knowledge)
        $check_knowledge_sources_column = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table_configurations,
                'knowledge_sources'
            )
        );

        if (empty($check_knowledge_sources_column)) {
            chatbot_log('INFO', 'update_tables', 'Adding knowledge_sources column to configurations table');

            $alter_query = sprintf(
                "ALTER TABLE `%s` ADD COLUMN `knowledge_sources` text DEFAULT NULL AFTER `persona`",
                $wpdb->prefix . 'chatbot_configurations'
            );
            $wpdb->query($alter_query);

            chatbot_log('INFO', 'update_tables', 'Added knowledge_sources column to configurations table');
        }

        // Check for telegram_bot_token column (for Telegram bot integration)
        $check_telegram_token_column = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME,
                $table_configurations,
                'telegram_bot_token'
            )
        );

        if (empty($check_telegram_token_column)) {
            chatbot_log('INFO', 'update_tables', 'Adding telegram_bot_token column to configurations table');

            $alter_query = sprintf(
                "ALTER TABLE `%s` ADD COLUMN `telegram_bot_token` varchar(100) DEFAULT NULL AFTER `knowledge_sources`",
                $wpdb->prefix . 'chatbot_configurations'
            );
            $wpdb->query($alter_query);

            chatbot_log('INFO', 'update_tables', 'Added telegram_bot_token column to configurations table');
        }
    }

    // Check for telegram_chat_id column in conversations table
    $check_telegram_chat_id_column = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $wpdb->prefix . 'chatbot_conversations',
            'telegram_chat_id'
        )
    );

    if (empty($check_telegram_chat_id_column)) {
        chatbot_log('INFO', 'update_tables', 'Adding telegram_chat_id column to conversations table');

        $alter_query = sprintf(
            "ALTER TABLE `%s` ADD COLUMN `telegram_chat_id` bigint(20) DEFAULT NULL",
            $wpdb->prefix . 'chatbot_conversations'
        );
        $wpdb->query($alter_query);

        // Add index for telegram_chat_id
        $alter_query = sprintf(
            "ALTER TABLE `%s` ADD INDEX `telegram_chat_id` (`telegram_chat_id`)",
            $wpdb->prefix . 'chatbot_conversations'
        );
        $wpdb->query($alter_query);

        chatbot_log('INFO', 'update_tables', 'Added telegram_chat_id column to conversations table');
    }

    // Check for platform_type and platform_chat_id columns (for new messaging platform architecture)
    $check_platform_type_column = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $wpdb->prefix . 'chatbot_conversations',
            'platform_type'
        )
    );

    if (empty($check_platform_type_column)) {
        chatbot_log('INFO', 'update_tables', 'Adding platform_type and platform_chat_id columns to conversations table');

        $alter_query = sprintf(
            "ALTER TABLE `%s` ADD COLUMN `platform_type` varchar(20) DEFAULT 'web' AFTER `telegram_chat_id`",
            $wpdb->prefix . 'chatbot_conversations'
        );
        $wpdb->query($alter_query);

        $alter_query = sprintf(
            "ALTER TABLE `%s` ADD COLUMN `platform_chat_id` varchar(100) DEFAULT NULL AFTER `platform_type`",
            $wpdb->prefix . 'chatbot_conversations'
        );
        $wpdb->query($alter_query);

        // Add index for platform lookups
        $alter_query = sprintf(
            "ALTER TABLE `%s` ADD INDEX `platform_lookup` (`platform_type`, `platform_chat_id`, `chatbot_config_id`)",
            $wpdb->prefix . 'chatbot_conversations'
        );
        $wpdb->query($alter_query);

        chatbot_log('INFO', 'update_tables', 'Added platform columns to conversations table');
    }

    // Check for n8n_settings column in configurations table (for n8n integration per chatbot)
    $check_n8n_settings_column = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $wpdb->prefix . 'chatbot_configurations',
            'n8n_settings'
        )
    );

    if (empty($check_n8n_settings_column)) {
        chatbot_log('INFO', 'update_tables', 'Adding n8n_settings column to configurations table');

        $alter_query = sprintf(
            "ALTER TABLE `%s` ADD COLUMN `n8n_settings` text DEFAULT NULL AFTER `telegram_bot_token`",
            $wpdb->prefix . 'chatbot_configurations'
        );
        $wpdb->query($alter_query);

        chatbot_log('INFO', 'update_tables', 'Added n8n_settings column to configurations table');
    }
}
add_action('plugins_loaded', 'update_chatbot_database_tables');

/**
 * Initialize n8n gateway for AJAX handlers
 */
function chatbot_init_n8n_gateway() {
    // Initialize n8n gateway to register AJAX handlers
    Chatbot_N8N_Gateway::get_instance();
}
add_action('init', 'chatbot_init_n8n_gateway', 5);

/**
 * Register messaging platforms with the manager
 */
function chatbot_register_messaging_platforms() {
    $manager = Chatbot_Messaging_Manager::get_instance();

    // Register built-in platforms
    $manager->register_platform('telegram', 'Chatbot_Platform_Telegram');
    $manager->register_platform('whatsapp', 'Chatbot_Platform_WhatsApp');

    // Instantiate platforms immediately so their AJAX handlers are registered
    // This is needed because platforms are lazy-loaded by default
    $manager->get_platform('telegram');
    $manager->get_platform('whatsapp');

    // Allow other plugins/themes to register additional platforms
    do_action('chatbot_register_messaging_platforms', $manager);
}
add_action('init', 'chatbot_register_messaging_platforms', 5);

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
        load_plugin_textdomain('ai-chat-bot', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
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

        // Add marked.js for markdown rendering (lightweight, ~8KB gzipped)
        wp_enqueue_script(
            'marked-js',
            'https://cdn.jsdelivr.net/npm/marked/marked.min.js',
            array(),
            '15.0.4',
            true
        );

        wp_enqueue_script(
            'chatbot-plugin-script',
            CHATBOT_PLUGIN_URL . 'assets/js/chatbot.js',
            array('jquery', 'marked-js'),
            CHATBOT_PLUGIN_VERSION,
            true
        );
        
        // Define default values to ensure consistency across the plugin
        $default_greeting = 'Hello %s! How can I help you today?';
        $default_typing_indicator = 'AI Assistant is typing...';
        
        // Get max message length from rate limiter settings
        $max_message_length = 500; // Default value
        if (class_exists('Chatbot_Rate_Limiter')) {
            $rate_limiter = Chatbot_Rate_Limiter::get_instance();
            $max_message_length = get_option($rate_limiter->option_names['max_message_length'], $rate_limiter->default_limits['max_message_length']);
        }

        wp_localize_script(
            'chatbot-plugin-script',
            'chatbotPluginVars',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('chatbot-plugin-nonce'),
                'chatGreeting' => get_option('chatbot_chat_greeting', $default_greeting),
                'typingIndicatorText' => get_option('chatbot_typing_indicator_text', $default_typing_indicator),
                'maxMessageLength' => $max_message_length
            )
        );
    }
    
    public function chatbot_shortcode($atts) {
        $atts = shortcode_atts(
            array(
                'theme' => 'light',
                'name' => '',
                'mode' => 'floating',       // 'floating' (default) or 'inline'
                'height' => '600px',        // Height for inline mode
                'skip_welcome' => 'false',  // Skip welcome screen and start immediately
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