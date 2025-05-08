<?php
/**
 * Chatbot Settings
 * 
 * Handles the settings page for the chatbot plugin
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Add settings page
        add_action('admin_menu', array($this, 'add_settings_page'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        add_submenu_page(
            'chatbot-plugin',
            __('Settings', 'chatbot-plugin'),
            __('Settings', 'chatbot-plugin'),
            'manage_options',
            'chatbot-settings',
            array($this, 'display_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Register general settings
        register_setting('chatbot_settings', 'chatbot_welcome_message');
        register_setting('chatbot_settings', 'chatbot_chat_greeting');
        register_setting('chatbot_settings', 'chatbot_button_color');
        register_setting('chatbot_settings', 'chatbot_header_color');
        
        // Add general settings section
        add_settings_section(
            'chatbot_general_settings',
            __('General Settings', 'chatbot-plugin'),
            array($this, 'render_general_section'),
            'chatbot_settings'
        );
        
        // Add general settings fields
        add_settings_field(
            'chatbot_welcome_message',
            __('Welcome Message', 'chatbot-plugin'),
            array($this, 'render_welcome_message_field'),
            'chatbot_settings',
            'chatbot_general_settings'
        );
        
        add_settings_field(
            'chatbot_chat_greeting',
            __('Chat Greeting', 'chatbot-plugin'),
            array($this, 'render_chat_greeting_field'),
            'chatbot_settings',
            'chatbot_general_settings'
        );
        
        add_settings_field(
            'chatbot_button_color',
            __('Button Color', 'chatbot-plugin'),
            array($this, 'render_button_color_field'),
            'chatbot_settings',
            'chatbot_general_settings'
        );
        
        add_settings_field(
            'chatbot_header_color',
            __('Header Color', 'chatbot-plugin'),
            array($this, 'render_header_color_field'),
            'chatbot_settings',
            'chatbot_general_settings'
        );
        
        // We're not using this hook anymore
        // do_action('chatbot_settings_sections');
    }
    
    /**
     * Display the settings page
     */
    public function display_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get active tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=chatbot-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General', 'chatbot-plugin'); ?>
                </a>
                <a href="?page=chatbot-settings&tab=openai" class="nav-tab <?php echo $active_tab === 'openai' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('OpenAI Integration', 'chatbot-plugin'); ?>
                </a>
            </h2>
            
            <form method="post" action="options.php">
                <?php
                if ($active_tab === 'general') {
                    settings_fields('chatbot_settings');
                    do_settings_sections('chatbot_settings');
                } elseif ($active_tab === 'openai') {
                    settings_fields('chatbot_settings');
                    do_settings_sections('chatbot_openai_settings');
                    
                    // Add a 'Test Connection' button for OpenAI
                    echo '<p>';
                    echo '<button type="button" id="chatbot-test-openai" class="button button-secondary">';
                    echo __('Test OpenAI Connection', 'chatbot-plugin');
                    echo '</button>';
                    echo '<span id="chatbot-test-openai-result" style="margin-left: 10px;"></span>';
                    echo '</p>';
                    
                    // Add JavaScript for the test button
                    ?>
                    <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        $('#chatbot-test-openai').on('click', function() {
                            var $button = $(this);
                            var $result = $('#chatbot-test-openai-result');
                            
                            $button.prop('disabled', true);
                            $result.html('<?php _e('Testing connection...', 'chatbot-plugin'); ?>');
                            
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'chatbot_test_openai',
                                    nonce: '<?php echo wp_create_nonce('chatbot_test_openai_nonce'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        $result.html('<span style="color: green;">' + response.data.message + '</span>');
                                    } else {
                                        $result.html('<span style="color: red;">' + response.data.message + '</span>');
                                    }
                                },
                                error: function() {
                                    $result.html('<span style="color: red;"><?php _e('Connection error', 'chatbot-plugin'); ?></span>');
                                },
                                complete: function() {
                                    $button.prop('disabled', false);
                                }
                            });
                        });
                    });
                    </script>
                    <?php
                }
                
                submit_button();
                ?>
            </form>
            
            <?php if ($active_tab === 'openai'): ?>
                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h3><?php _e('OpenAI API Documentation', 'chatbot-plugin'); ?></h3>
                    <p><?php _e('To use OpenAI integration, you need an API key from OpenAI. Visit <a href="https://platform.openai.com/signup" target="_blank">OpenAI</a> to create an account and get your API key.', 'chatbot-plugin'); ?></p>
                    <p><?php _e('For more detailed information about the OpenAI API and how it\'s used in this plugin, please refer to our <a href="' . esc_url(plugin_dir_url(dirname(__FILE__)) . 'docs/openai-api-documentation.md') . '" target="_blank">documentation</a>.', 'chatbot-plugin'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render general settings section
     */
    public function render_general_section() {
        echo '<p>' . __('Configure general settings for the chatbot.', 'chatbot-plugin') . '</p>';
    }
    
    /**
     * Render welcome message field
     */
    public function render_welcome_message_field() {
        $welcome_message = get_option('chatbot_welcome_message', 'Welcome to our chat! Please enter your name to start chatting with us.');
        
        echo '<textarea name="chatbot_welcome_message" id="chatbot_welcome_message" class="large-text" rows="3">' . esc_textarea($welcome_message) . '</textarea>';
        echo '<p class="description">' . __('The welcome message displayed to visitors before they start chatting.', 'chatbot-plugin') . '</p>';
    }
    
    /**
     * Render chat greeting field
     */
    public function render_chat_greeting_field() {
        $default_greeting = 'Hello %s! How can I help you today?';
        $chat_greeting = get_option('chatbot_chat_greeting', $default_greeting);
        
        echo '<textarea name="chatbot_chat_greeting" id="chatbot_chat_greeting" class="large-text" rows="3">' . esc_textarea($chat_greeting) . '</textarea>';
        echo '<p class="description">' . __('The greeting message shown when a chat begins. Use %s where the visitor\'s name should appear.', 'chatbot-plugin') . '</p>';
        echo '<p class="description"><strong>' . __('Default:', 'chatbot-plugin') . '</strong> ' . esc_html($default_greeting) . '</p>';
    }
    
    /**
     * Render button color field
     */
    public function render_button_color_field() {
        $button_color = get_option('chatbot_button_color', '#4CAF50');
        
        echo '<input type="color" name="chatbot_button_color" id="chatbot_button_color" value="' . esc_attr($button_color) . '" />';
        echo '<p class="description">' . __('The color of the chat buttons.', 'chatbot-plugin') . '</p>';
    }
    
    /**
     * Render header color field
     */
    public function render_header_color_field() {
        $header_color = get_option('chatbot_header_color', '#2196F3');
        
        echo '<input type="color" name="chatbot_header_color" id="chatbot_header_color" value="' . esc_attr($header_color) . '" />';
        echo '<p class="description">' . __('The color of the chat header.', 'chatbot-plugin') . '</p>';
    }
}

// Initialize the settings
function chatbot_settings_init() {
    return Chatbot_Settings::get_instance();
}
chatbot_settings_init();