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
        // Register AIPass settings for general settings form
        register_setting('chatbot_settings', 'chatbot_aipass_enabled', array(
            'type' => 'boolean',
            'default' => true,
        ));
        register_setting('chatbot_settings', 'chatbot_aipass_access_token');
        register_setting('chatbot_settings', 'chatbot_aipass_refresh_token');
        register_setting('chatbot_settings', 'chatbot_aipass_token_expiry');

        // Register general settings
        register_setting('chatbot_settings', 'chatbot_welcome_message');
        register_setting('chatbot_settings', 'chatbot_chat_greeting');
        register_setting('chatbot_settings', 'chatbot_primary_color'); // Single color setting
        register_setting('chatbot_settings', 'chatbot_button_icon');
        register_setting('chatbot_settings', 'chatbot_button_icon_type');
        register_setting('chatbot_settings', 'chatbot_button_icon_url');
        register_setting('chatbot_settings', 'chatbot_typing_indicator_text');

        // Register rate limit settings
        register_setting('chatbot_settings', 'chatbot_rate_limit_per_minute', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 5,
        ));
        register_setting('chatbot_settings', 'chatbot_rate_limit_per_hour', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 20,
        ));
        register_setting('chatbot_settings', 'chatbot_rate_limit_per_day', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 50,
        ));
        register_setting('chatbot_settings', 'chatbot_rate_limit_global_per_minute', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 30,
        ));
        register_setting('chatbot_settings', 'chatbot_rate_limit_global_per_hour', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 200,
        ));

        // Register notification settings
        register_setting('chatbot_notification_settings', 'chatbot_notification_email');
        register_setting('chatbot_notification_settings', 'chatbot_email_notify_events', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_notify_events')
        ));
        register_setting('chatbot_notification_settings', 'chatbot_telegram_api_key');
        register_setting('chatbot_notification_settings', 'chatbot_telegram_chat_id');
        register_setting('chatbot_notification_settings', 'chatbot_telegram_notify_events', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_notify_events')
        ));
        
        // Add AIPass connection section (first, at the top)
        add_settings_section(
            'chatbot_aipass_general_section',
            __('AI Connection', 'chatbot-plugin'),
            array($this, 'render_aipass_general_section'),
            'chatbot_settings'
        );

        // Add AIPass connection field
        add_settings_field(
            'chatbot_aipass_connection_general',
            __('AIPass', 'chatbot-plugin'),
            array($this, 'render_aipass_connection_field'),
            'chatbot_settings',
            'chatbot_aipass_general_section'
        );

        // Add general settings section
        add_settings_section(
            'chatbot_general_settings',
            __('General Settings', 'chatbot-plugin'),
            array($this, 'render_general_section'),
            'chatbot_settings'
        );

        // Add rate limiting section
        add_settings_section(
            'chatbot_rate_limit_section',
            __('Rate Limiting', 'chatbot-plugin'),
            array($this, 'render_rate_limit_section'),
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
            'chatbot_typing_indicator_text',
            __('Typing Indicator Text', 'chatbot-plugin'),
            array($this, 'render_typing_indicator_text_field'),
            'chatbot_settings',
            'chatbot_general_settings'
        );
        
        add_settings_field(
            'chatbot_primary_color',
            __('Primary Color', 'chatbot-plugin'),
            array($this, 'render_primary_color_field'),
            'chatbot_settings',
            'chatbot_general_settings'
        );
        
        add_settings_field(
            'chatbot_button_icon_type',
            __('Button Icon Type', 'chatbot-plugin'),
            array($this, 'render_button_icon_type_field'),
            'chatbot_settings',
            'chatbot_general_settings'
        );
        
        add_settings_field(
            'chatbot_button_icon',
            __('Custom Button Icon', 'chatbot-plugin'),
            array($this, 'render_button_icon_field'),
            'chatbot_settings',
            'chatbot_general_settings'
        );

        // Add rate limit fields
        add_settings_field(
            'chatbot_rate_limit_per_minute',
            __('Messages per minute (per user)', 'chatbot-plugin'),
            array($this, 'render_number_field'),
            'chatbot_settings',
            'chatbot_rate_limit_section',
            array(
                'label_for' => 'chatbot_rate_limit_per_minute',
                'min' => 1,
                'max' => 60,
                'description' => __('Maximum number of messages a user can send per minute.', 'chatbot-plugin')
            )
        );

        add_settings_field(
            'chatbot_rate_limit_per_hour',
            __('Messages per hour (per user)', 'chatbot-plugin'),
            array($this, 'render_number_field'),
            'chatbot_settings',
            'chatbot_rate_limit_section',
            array(
                'label_for' => 'chatbot_rate_limit_per_hour',
                'min' => 5,
                'max' => 500,
                'description' => __('Maximum number of messages a user can send per hour.', 'chatbot-plugin')
            )
        );

        add_settings_field(
            'chatbot_rate_limit_per_day',
            __('Messages per day (per user)', 'chatbot-plugin'),
            array($this, 'render_number_field'),
            'chatbot_settings',
            'chatbot_rate_limit_section',
            array(
                'label_for' => 'chatbot_rate_limit_per_day',
                'min' => 10,
                'max' => 1000,
                'description' => __('Maximum number of messages a user can send per day.', 'chatbot-plugin')
            )
        );

        add_settings_field(
            'chatbot_rate_limit_global_per_minute',
            __('Global messages per minute', 'chatbot-plugin'),
            array($this, 'render_number_field'),
            'chatbot_settings',
            'chatbot_rate_limit_section',
            array(
                'label_for' => 'chatbot_rate_limit_global_per_minute',
                'min' => 5,
                'max' => 1000,
                'description' => __('Maximum number of messages from all users per minute.', 'chatbot-plugin')
            )
        );

        add_settings_field(
            'chatbot_rate_limit_global_per_hour',
            __('Global messages per hour', 'chatbot-plugin'),
            array($this, 'render_number_field'),
            'chatbot_settings',
            'chatbot_rate_limit_section',
            array(
                'label_for' => 'chatbot_rate_limit_global_per_hour',
                'min' => 20,
                'max' => 5000,
                'description' => __('Maximum number of messages from all users per hour.', 'chatbot-plugin')
            )
        );
        
        // Add notification settings section
        add_settings_section(
            'chatbot_notification_settings_section',
            __('Notification Settings', 'chatbot-plugin'),
            array($this, 'render_notification_section'),
            'chatbot_notification_settings'
        );
        
        // Add email notification fields
        add_settings_field(
            'chatbot_notification_email',
            __('Notification Email', 'chatbot-plugin'),
            array($this, 'render_notification_email_field'),
            'chatbot_notification_settings',
            'chatbot_notification_settings_section'
        );
        
        add_settings_field(
            'chatbot_email_notify_events',
            __('Email Notifications', 'chatbot-plugin'),
            array($this, 'render_email_notify_events_field'),
            'chatbot_notification_settings',
            'chatbot_notification_settings_section'
        );
        
        // Add Telegram notification fields
        add_settings_field(
            'chatbot_telegram_settings',
            __('Telegram Notifications', 'chatbot-plugin'),
            array($this, 'render_telegram_settings_field'),
            'chatbot_notification_settings',
            'chatbot_notification_settings_section'
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

        // Debug logging for settings page access
        error_log('Chatbot: Displaying settings page, active tab: ' . $active_tab);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=chatbot-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General', 'chatbot-plugin'); ?>
                </a>
                <a href="?page=chatbot-settings&tab=openai" class="nav-tab <?php echo $active_tab === 'openai' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('AI Integration', 'chatbot-plugin'); ?>
                </a>
                <a href="?page=chatbot-settings&tab=notifications" class="nav-tab <?php echo $active_tab === 'notifications' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Notifications', 'chatbot-plugin'); ?>
                </a>
                <a href="?page=chatbot-settings&tab=security" class="nav-tab <?php echo $active_tab === 'security' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Security', 'chatbot-plugin'); ?>
                </a>
                <a href="?page=chatbot-settings&tab=data-retention" class="nav-tab <?php echo $active_tab === 'data-retention' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Data Retention', 'chatbot-plugin'); ?>
                </a>
            </h2>
            
            <?php
            // We still need the sync functionality without showing the debug panel
            // Security: Verify nonce to prevent CSRF attacks
            if ($active_tab === 'openai' && isset($_GET['action']) && $_GET['action'] === 'sync_settings') {
                // Verify nonce for CSRF protection
                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'chatbot_sync_settings')) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Security check failed. Please try again.', 'chatbot-plugin') . '</p></div>';
                } else {
                    // Try to force sync settings
                    if (class_exists('Chatbot_OpenAI')) {
                        $openai = Chatbot_OpenAI::get_instance();
                        if (method_exists($openai, 'sync_settings_between_groups')) {
                            // Silently sync settings
                            $openai->sync_settings_between_groups();

                            // Force refresh to see changes
                            echo '<script>
                                setTimeout(function() {
                                    window.location.href = "' . esc_url(admin_url('admin.php?page=chatbot-settings&tab=openai')) . '";
                                }, 500);
                            </script>';
                        }
                    }
                }
            }
            
            // Admin-only debugging can be shown with a special URL parameter
            if ($active_tab === 'openai' && current_user_can('manage_options') && isset($_GET['debug']) && $_GET['debug'] === 'openai'): 
            ?>
                <!-- Hidden diagnostic panel, only visible with ?debug=openai parameter -->
                <div class="card" style="margin-bottom: 20px; background-color: #f8f8f8; border-left: 4px solid blue; padding: 10px 15px;">
                    <h3>AI Settings Diagnostic</h3>
                    <?php
                    // Direct database query to find AI integration options
                    global $wpdb;
                    $openai_options = $wpdb->get_results(
                        "SELECT option_name, option_value FROM {$wpdb->options}
                        WHERE option_name LIKE 'chatbot_openai_%' OR option_name LIKE 'chatbot_aipass_%'"
                    );
                    
                    if (empty($openai_options)) {
                        echo '<p>No AI integration settings found in database.</p>';
                    } else {
                        echo '<p>Current AI integration settings found in database:</p>';
                        echo '<ul>';
                        foreach ($openai_options as $option) {
                            $display_value = $option->option_name === 'chatbot_openai_api_key' 
                                ? (empty($option->option_value) ? 'Empty' : 'Set (hidden for security)')
                                : esc_html($option->option_value);
                            
                            echo '<li><strong>' . esc_html($option->option_name) . ':</strong> ' . $display_value . '</li>';
                        }
                        echo '</ul>';
                    }
                    
                    // Add an action to manually force a refresh
                    echo '<p><a href="' . admin_url('admin.php?page=chatbot-settings&tab=openai&action=sync_settings&debug=openai') . '" class="button button-small">Force Settings Sync</a></p>';
                    ?>
                </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php
                if ($active_tab === 'general') {
                    settings_fields('chatbot_settings');
                    do_settings_sections('chatbot_settings');
                } elseif ($active_tab === 'notifications') {
                    // Debug log for Notifications tab rendering
                    error_log('Chatbot: Rendering Notifications settings tab');

                    settings_fields('chatbot_notification_settings');
                    do_settings_sections('chatbot_notification_settings');
                } elseif ($active_tab === 'security') {
                    // Security tab content
                    $this->render_security_tab();
                } elseif ($active_tab === 'data-retention') {
                    // Data Retention tab content
                    $this->render_data_retention_tab();
                } elseif ($active_tab === 'openai') {
                    // Debug log for AI Integration tab rendering
                    error_log('Chatbot: Rendering AI Integration settings tab');

                    // Debug current AI integration settings values
                    $api_key = get_option('chatbot_openai_api_key', '');
                    $model = get_option('chatbot_openai_model', 'gpt-3.5-turbo');
                    $max_tokens = get_option('chatbot_openai_max_tokens', 150);
                    $temperature = get_option('chatbot_openai_temperature', 0.7);

                    error_log('Chatbot: OpenAI API Key exists: ' . (!empty($api_key) ? 'Yes' : 'No'));
                    error_log('Chatbot: OpenAI Model: ' . $model);
                    error_log('Chatbot: OpenAI Max Tokens: ' . $max_tokens);
                    error_log('Chatbot: OpenAI Temperature: ' . $temperature);
                    
                    // Add diagnostic JavaScript to check registered settings
                    ?>
                    <script type="text/javascript">
                    console.group('Chatbot Debug: AI Integration Settings');
                    console.log('Tab active: AI Integration');
                    console.log('Settings group used: chatbot_openai_settings');

                    // Log all available wp options for debugging
                    jQuery(document).ready(function($) {
                        // Helper function to inspect DOM elements
                        function inspectFields() {
                            console.log('API Key field exists:', $('#chatbot_openai_api_key').length > 0);
                            console.log('Model field exists:', $('#chatbot_openai_model').length > 0);
                            console.log('Max tokens field exists:', $('#chatbot_openai_max_tokens').length > 0);
                            console.log('Temperature field exists:', $('#chatbot_openai_temperature').length > 0);
                            console.log('System prompt field exists:', $('#chatbot_openai_system_prompt').length > 0);

                            // Check for any visible fields at all
                            console.log('Input fields in form:', $('form input, form textarea, form select').length);

                            // Check form action
                            console.log('Form action:', $('form').attr('action'));
                        }

                        // Inspect DOM after short delay to ensure it's rendered
                        setTimeout(inspectFields, 500);
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'chatbot_debug_get_options',
                                nonce: '<?php echo wp_create_nonce('chatbot_debug_nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success && response.data) {
                                    console.log('AI integration settings from database:', response.data);
                                }
                            }
                        });
                    });
                    console.groupEnd();
                    </script>
                    <?php
                    
                    settings_fields('chatbot_openai_settings');
                    do_settings_sections('chatbot_openai_settings');
                }
                
                submit_button();
                ?>
            </form>
            
            <?php if ($active_tab === 'openai'): ?>
                <?php
                // Check if AIPass is enabled
                $aipass_enabled = get_option('chatbot_aipass_enabled', true);
                $aipass_connected = false;
                if (class_exists('Chatbot_AIPass')) {
                    $aipass_connected = Chatbot_AIPass::get_instance()->is_connected();
                }
                ?>

                <?php if ($aipass_enabled && $aipass_connected): ?>
                    <!-- AIPass Documentation Card -->
                    <div id="aipass-doc-card" class="card" style="max-width: 800px; margin-top: 20px; border-left: 4px solid #2196F3;">
                        <h3 style="color: #2196F3;"><?php _e('AIPass Documentation', 'chatbot-plugin'); ?></h3>
                        <p><?php _e('You are using AIPass for AI-powered features. AIPass provides seamless access to 161+ AI models without managing API keys.', 'chatbot-plugin'); ?></p>
                        <p>
                            <?php _e('Resources:', 'chatbot-plugin'); ?>
                            <ul style="margin: 10px 0; padding-left: 20px;">
                                <li><a href="https://aipass.one/" target="_blank" rel="noopener"><?php _e('AIPass Homepage', 'chatbot-plugin'); ?></a> - <?php _e('Learn about features and pricing', 'chatbot-plugin'); ?></li>
                                <li><a href="https://aipass.one/dashboard" target="_blank" rel="noopener"><?php _e('AIPass Dashboard', 'chatbot-plugin'); ?></a> - <?php _e('View your usage and balance', 'chatbot-plugin'); ?></li>
                                <li><a href="https://aipass.one/docs" target="_blank" rel="noopener"><?php _e('API Documentation', 'chatbot-plugin'); ?></a> - <?php _e('Developer resources', 'chatbot-plugin'); ?></li>
                            </ul>
                        </p>
                    </div>
                <?php else: ?>
                    <!-- OpenAI Documentation Card -->
                    <div id="openai-doc-card" class="card" style="max-width: 800px; margin-top: 20px; border-left: 4px solid #10a37f;">
                        <h3 style="color: #10a37f;"><?php _e('OpenAI API Documentation', 'chatbot-plugin'); ?></h3>
                        <p><?php _e('To use OpenAI integration, you need an API key from OpenAI. Visit <a href="https://platform.openai.com/signup" target="_blank">OpenAI</a> to create an account and get your API key.', 'chatbot-plugin'); ?></p>
                        <p><?php _e('For more detailed information about the OpenAI API and how it\'s used in this plugin, please refer to our <a href="' . esc_url(plugin_dir_url(dirname(__FILE__)) . 'docs/openai-api-documentation.md') . '" target="_blank">documentation</a>.', 'chatbot-plugin'); ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($active_tab === 'notifications'): ?>
                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h3><?php _e('Notification Documentation', 'chatbot-plugin'); ?></h3>
                    <p><?php _e('Configure email and Telegram notifications to keep you informed about new chat conversations and receive daily summaries.', 'chatbot-plugin'); ?></p>
                    <p><?php _e('For Telegram notifications, you need to create a Telegram bot and obtain the API key. Visit <a href="https://core.telegram.org/bots#how-do-i-create-a-bot" target="_blank">Telegram Bot Documentation</a> for more information.', 'chatbot-plugin'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render AIPass general section description
     */
    public function render_aipass_general_section() {
        echo '<p>' . __('Connect your chatbot to AI services. AIPass is the recommended way to power your chatbot with AI.', 'chatbot-plugin') . '</p>';
    }

    /**
     * Render AIPass connection field for General Settings tab
     * Shows only the connection status - toggle switch is in AI Integration tab
     */
    public function render_aipass_connection_field() {
        // Check if AIPass class exists
        if (!class_exists('Chatbot_AIPass')) {
            echo '<p class="description">' . __('AIPass integration is not available.', 'chatbot-plugin') . '</p>';
            return;
        }

        $aipass = Chatbot_AIPass::get_instance();
        $is_connected = $aipass->is_connected();

        echo '<div class="chatbot-aipass-general-container" style="padding: 15px; background: #f9f9f9; border-radius: 5px; border: 1px solid #ddd;">';

        // Hidden inputs to preserve token and enabled values when form is saved
        $aipass_enabled = get_option('chatbot_aipass_enabled', true);
        $access_token = get_option('chatbot_aipass_access_token', '');
        $refresh_token = get_option('chatbot_aipass_refresh_token', '');
        $token_expiry = get_option('chatbot_aipass_token_expiry', 0);
        echo '<input type="hidden" name="chatbot_aipass_enabled" value="' . ($aipass_enabled ? '1' : '0') . '" />';
        echo '<input type="hidden" name="chatbot_aipass_access_token" value="' . esc_attr($access_token) . '" />';
        echo '<input type="hidden" name="chatbot_aipass_refresh_token" value="' . esc_attr($refresh_token) . '" />';
        echo '<input type="hidden" name="chatbot_aipass_token_expiry" value="' . esc_attr($token_expiry) . '" />';

        if ($is_connected) {
            // Connected state
            echo '<div class="aipass-status-general" style="display: flex; align-items: center; flex-wrap: wrap; gap: 10px;">';

            // AIPass logo - clickable to dashboard
            echo '<a href="https://aipass.one/panel/dashboard.html" target="_blank" rel="noopener" style="text-decoration: none;" title="' . esc_attr__('Open AIPass Dashboard', 'chatbot-plugin') . '">';
            echo '<div style="display: inline-flex; align-items: center; background: white; padding: 5px 10px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); cursor: pointer; transition: box-shadow 0.2s;" onmouseover="this.style.boxShadow=\'0 4px 8px rgba(0,0,0,0.15)\'" onmouseout="this.style.boxShadow=\'0 2px 4px rgba(0,0,0,0.1)\'">';
            echo '<div style="background: #8A4FFF; color: white; font-weight: bold; padding: 5px 7px; border-radius: 5px 0 5px 5px; margin-right: 5px;">AI</div>';
            echo '<div style="color: #8A4FFF; font-weight: bold;">Pass</div>';
            echo '<div style="margin-left: 10px; background: #4CAF50; color: white; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: bold;">CONNECTED</div>';
            echo '</div>';
            echo '</a>';

            // Balance info - clickable to dashboard
            echo '<a href="https://aipass.one/panel/dashboard.html" target="_blank" rel="noopener" id="aipass-balance-info-general" style="padding: 5px 10px; background: #f0f0f0; border-radius: 4px; text-decoration: none; color: inherit;" title="' . esc_attr__('View balance in AIPass Dashboard', 'chatbot-plugin') . '">';
            echo '<span style="font-style: italic; color: #666; font-size: 13px;">' . __('Loading balance...', 'chatbot-plugin') . '</span>';
            echo '</a>';

            echo '<button type="button" id="chatbot-aipass-disconnect-general" class="button button-secondary">' . __('Disconnect', 'chatbot-plugin') . '</button>';
            echo '</div>';
        } else {
            // Not connected state
            echo '<div class="aipass-status-general">';
            echo '<button type="button" id="chatbot-aipass-connect-general" class="button button-primary" style="display: inline-flex; align-items: center; padding: 5px 15px;">';
            echo '<span style="display: inline-flex; align-items: center; background: white; padding: 3px 8px; border-radius: 5px; margin-right: 10px;">';
            echo '<span style="background: #8A4FFF; color: white; font-weight: bold; padding: 3px 5px; border-radius: 4px 0 4px 4px; margin-right: 3px; font-size: 12px;">AI</span>';
            echo '<span style="color: #8A4FFF; font-weight: bold; font-size: 12px;">Pass</span>';
            echo '</span>';
            echo '<span>' . __('Connect with AIPass', 'chatbot-plugin') . '</span>';
            echo '</button>';
            echo '</div>';
        }

        echo '<p class="description" style="margin-top: 12px;">' . __('AIPass provides access to 161+ AI models including GPT-4 and Gemini - no API key needed!', 'chatbot-plugin') . '</p>';
        echo '<p class="description">' . sprintf(
            __('For advanced AI settings, visit the %s tab.', 'chatbot-plugin'),
            '<a href="' . admin_url('admin.php?page=chatbot-settings&tab=openai') . '">' . __('AI Integration', 'chatbot-plugin') . '</a>'
        ) . '</p>';

        echo '</div>';

        // Balance loading is handled via inline script (aipass-integration.js handles connect/disconnect)
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Load balance info on page load (if connected)
            <?php if ($is_connected): ?>
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'chatbot_aipass_get_balance',
                    nonce: '<?php echo wp_create_nonce('chatbot_aipass_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success && response.data && response.data.balance) {
                        var balance = response.data.balance;
                        var remaining = parseFloat(balance.remainingBudget || 0).toFixed(2);
                        $('#aipass-balance-info-general').html(
                            '<span style="font-size: 13px;">Balance: <strong style="color: #8A4FFF;">$' + remaining + '</strong></span>'
                        );
                    } else {
                        $('#aipass-balance-info-general').html('<span style="color: #666; font-size: 13px;">Balance unavailable</span>');
                    }
                },
                error: function() {
                    $('#aipass-balance-info-general').html('<span style="color: #d32f2f; font-size: 13px;">Error loading balance</span>');
                }
            });
            <?php endif; ?>
            // Note: Connect and Disconnect button handlers are in aipass-integration.js
        });
        </script>
        <?php
    }

    /**
     * Render general settings section
     */
    public function render_general_section() {
        echo '<p>' . __('Configure general settings for the chatbot.', 'chatbot-plugin') . '</p>';
    }

    /**
     * Render rate limit section
     */
    public function render_rate_limit_section() {
        echo '<p>' . __('Configure rate limits to prevent abuse and control API costs.', 'chatbot-plugin') . '</p>';

        // Add reset button if rate limiter class exists
        if (class_exists('Chatbot_Rate_Limiter')) {
            echo '<div style="margin: 10px 0 20px;">';
            echo '<a href="' . admin_url('admin.php?page=chatbot-settings&tab=security') . '" class="button button-small">';
            echo __('Go to Security Tab for Rate Limit Reset', 'chatbot-plugin');
            echo '</a>';
            echo '<p class="description" style="margin-top: 5px;">' . __('Use the Security tab to reset all rate limit counters if needed.', 'chatbot-plugin') . '</p>';
            echo '</div>';
        }
    }

    /**
     * Render number field
     */
    public function render_number_field($args) {
        $option_name = $args['label_for'];
        $min = isset($args['min']) ? $args['min'] : 0;
        $max = isset($args['max']) ? $args['max'] : 1000;
        $value = get_option($option_name);

        echo '<input type="number" id="' . esc_attr($option_name) . '" name="' . esc_attr($option_name) . '" value="' . esc_attr($value) . '" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" class="small-text" />';

        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
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
     * Render primary color field
     */
    public function render_primary_color_field() {
        $default_color = '#4a6cf7'; // Default blue
        $primary_color = get_option('chatbot_primary_color', $default_color);
        
        // If empty or invalid, use default
        if (empty($primary_color) || !preg_match('/^#[a-f0-9]{6}$/i', $primary_color)) {
            $primary_color = $default_color;
        }
        
        echo '<div class="color-picker-wrapper">';
        echo '<input type="color" name="chatbot_primary_color" id="chatbot_primary_color" value="' . esc_attr($primary_color) . '" />';
        echo '<input type="text" id="chatbot_primary_color_text" value="' . esc_attr($primary_color) . '" class="chatbot-color-text" />';
        echo '<button type="button" class="button button-secondary" id="chatbot_reset_color">' . __('Reset to Default', 'chatbot-plugin') . '</button>';
        echo '</div>';
        
        echo '<div class="color-preview" style="margin-top: 10px;">';
        echo '<div style="display: inline-block; width: 50px; height: 50px; border-radius: 50%; background-color: ' . esc_attr($primary_color) . '; border: 1px solid #ddd;"></div>';
        echo '<span style="margin-left: 10px; vertical-align: middle;">' . __('Preview', 'chatbot-plugin') . '</span>';
        echo '</div>';
        
        echo '<p class="description">' . __('The primary color used throughout the chatbot (button, header, links, etc).', 'chatbot-plugin') . '</p>';
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Sync color input with text input
            $('#chatbot_primary_color').on('input', function() {
                $('#chatbot_primary_color_text').val($(this).val());
                $('.color-preview div').css('background-color', $(this).val());
            });
            
            // Sync text input with color input (if valid)
            $('#chatbot_primary_color_text').on('input', function() {
                var color = $(this).val();
                if (/^#[0-9A-F]{6}$/i.test(color)) {
                    $('#chatbot_primary_color').val(color);
                    $('.color-preview div').css('background-color', color);
                }
            });
            
            // Reset to default
            $('#chatbot_reset_color').on('click', function() {
                var defaultColor = '<?php echo $default_color; ?>';
                $('#chatbot_primary_color').val(defaultColor);
                $('#chatbot_primary_color_text').val(defaultColor);
                $('.color-preview div').css('background-color', defaultColor);
            });
        });
        </script>
        
        <style>
        .color-picker-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .chatbot-color-text {
            width: 80px;
        }
        </style>
        <?php
    }
    
    /**
     * Render button icon type field
     */
    public function render_button_icon_type_field() {
        $button_icon_type = get_option('chatbot_button_icon_type', 'default');
        
        echo '<select name="chatbot_button_icon_type" id="chatbot_button_icon_type">';
        echo '<option value="default" ' . selected($button_icon_type, 'default', false) . '>' . __('Default Chat Icon', 'chatbot-plugin') . '</option>';
        echo '<option value="custom" ' . selected($button_icon_type, 'custom', false) . '>' . __('Custom SVG Icon', 'chatbot-plugin') . '</option>';
        echo '<option value="upload" ' . selected($button_icon_type, 'upload', false) . '>' . __('Uploaded Image', 'chatbot-plugin') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('Select the type of icon to use for the chat button.', 'chatbot-plugin') . '</p>';
        
        // Add JavaScript to show/hide the appropriate field based on selection
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            function toggleIconFields() {
                var iconType = $('#chatbot_button_icon_type').val();
                if (iconType === 'custom') {
                    $('#chatbot_button_icon_wrapper').show();
                    $('#chatbot_button_icon_upload_wrapper').hide();
                } else if (iconType === 'upload') {
                    $('#chatbot_button_icon_wrapper').hide();
                    $('#chatbot_button_icon_upload_wrapper').show();
                } else {
                    $('#chatbot_button_icon_wrapper').hide();
                    $('#chatbot_button_icon_upload_wrapper').hide();
                }
            }
            
            $('#chatbot_button_icon_type').on('change', toggleIconFields);
            toggleIconFields();
        });
        </script>
        <?php
    }
    
    /**
     * Render typing indicator text field
     */
    public function render_typing_indicator_text_field() {
        $default_text = 'AI Assistant is typing...';
        $typing_indicator_text = get_option('chatbot_typing_indicator_text', $default_text);
        
        echo '<input type="text" name="chatbot_typing_indicator_text" id="chatbot_typing_indicator_text" class="regular-text" value="' . esc_attr($typing_indicator_text) . '" />';
        echo '<p class="description">' . __('The text to display when the AI is generating a response.', 'chatbot-plugin') . '</p>';
        echo '<p class="description"><strong>' . __('Default:', 'chatbot-plugin') . '</strong> ' . esc_html($default_text) . '</p>';
    }
    
    /**
     * Render button icon field
     */
    public function render_button_icon_field() {
        $button_icon = get_option('chatbot_button_icon', '');
        $button_icon_url = get_option('chatbot_button_icon_url', '');
        
        // Custom SVG icon input
        echo '<div id="chatbot_button_icon_wrapper">';
        echo '<textarea name="chatbot_button_icon" id="chatbot_button_icon" class="large-text code" rows="5">' . esc_textarea($button_icon) . '</textarea>';
        
        // Add sample SVG icons that can be used
        $sample_icons = array(
            'chat' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>',
            'message-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>',
            'message-square' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>'
        );
        
        echo '<div class="svg-samples" style="margin-top: 10px; display: flex; gap: 15px;">';
        foreach ($sample_icons as $name => $svg) {
            echo '<div class="svg-sample" style="cursor: pointer; border: 1px solid #ddd; border-radius: 5px; padding: 10px; background: white; display: flex; flex-direction: column; align-items: center; width: 80px;">';
            echo '<div style="color: #4a6cf7;">' . $svg . '</div>';
            echo '<span style="font-size: 11px; margin-top: 5px;">' . ucfirst($name) . '</span>';
            echo '</div>';
        }
        echo '</div>';
        
        echo '<p class="description">' . __('Enter custom SVG code for the chat button icon.', 'chatbot-plugin') . '</p>';
        echo '<p class="description">' . __('Click on a sample icon above or paste your own SVG code.', 'chatbot-plugin') . '</p>';
        
        // Add script to use sample icons
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.svg-sample').on('click', function() {
                var svgCode = $(this).find('div').html();
                $('#chatbot_button_icon').val(svgCode);
            });
        });
        </script>
        <?php
        echo '</div>';
        
        // Image upload input - simplified since we're handling in admin.js
        echo '<div id="chatbot_button_icon_upload_wrapper">';
        echo '<div class="image-upload-container">';
        echo '<input type="text" name="chatbot_button_icon_url" id="chatbot_button_icon_url" class="regular-text" value="' . esc_attr($button_icon_url) . '" />';
        echo '<button type="button" class="button button-secondary upload-button" id="chatbot_button_icon_upload_button">' . __('Upload Image', 'chatbot-plugin') . '</button>';
        echo '</div>';
        
        // Image preview
        echo '<div class="image-preview-container" style="margin-top: 10px;">';
        if (!empty($button_icon_url)) {
            echo '<img src="' . esc_url($button_icon_url) . '" alt="Button Icon Preview" style="max-width: 50px; max-height: 50px; border: 1px solid #ddd; border-radius: 5px; padding: 5px; background: white;" id="chatbot_button_icon_preview" />';
        } else {
            echo '<div id="chatbot_button_icon_preview_placeholder" style="width: 50px; height: 50px; border: 1px dashed #ddd; border-radius: 5px; display: flex; align-items: center; justify-content: center;">';
            echo '<span style="color: #999;">No image</span>';
            echo '</div>';
            echo '<img src="" alt="Button Icon Preview" style="max-width: 50px; max-height: 50px; border: 1px solid #ddd; border-radius: 5px; padding: 5px; background: white; display: none;" id="chatbot_button_icon_preview" />';
        }
        echo '</div>';
        
        echo '<p class="description">' . __('Upload an image to use as the chat button icon. Recommended size: 24x24 pixels.', 'chatbot-plugin') . '</p>';
        
        // The JavaScript for this functionality is now in admin.js
        echo '</div>';
    }
    
    /**
     * Sanitize notification events array
     */
    public function sanitize_notify_events($events) {
        if (!is_array($events)) {
            return array();
        }
        
        $valid_events = array('new_conversation', 'daily_summary');
        return array_filter($events, function($event) use ($valid_events) {
            return in_array($event, $valid_events);
        });
    }

    /**
     * Render notification section
     */
    public function render_notification_section() {
        echo '<p>' . __('Configure notification settings for the chatbot. You can receive notifications via email or Telegram when new conversations start or receive daily summaries.', 'chatbot-plugin') . '</p>';
    }

    /**
     * Render notification email field
     */
    public function render_notification_email_field() {
        $admin_email = get_option('admin_email');
        $notification_email = get_option('chatbot_notification_email', $admin_email);
        
        echo '<input type="email" name="chatbot_notification_email" id="chatbot_notification_email" class="regular-text" value="' . esc_attr($notification_email) . '" />';
        echo '<p class="description">' . __('Email address where notifications will be sent. Defaults to admin email.', 'chatbot-plugin') . '</p>';
    }

    /**
     * Render email notification events field
     */
    public function render_email_notify_events_field() {
        $email_notify_events = get_option('chatbot_email_notify_events', array());
        
        $events = array(
            'new_conversation' => __('New Conversations', 'chatbot-plugin'),
            'daily_summary' => __('Daily Summary', 'chatbot-plugin')
        );
        
        echo '<fieldset>';
        foreach ($events as $event_key => $event_label) {
            $checked = in_array($event_key, $email_notify_events) ? 'checked' : '';
            echo '<label for="chatbot_email_notify_events_' . esc_attr($event_key) . '">';
            echo '<input type="checkbox" name="chatbot_email_notify_events[]" id="chatbot_email_notify_events_' . esc_attr($event_key) . '" value="' . esc_attr($event_key) . '" ' . $checked . ' />';
            echo esc_html($event_label);
            echo '</label><br>';
        }
        echo '</fieldset>';
        echo '<p class="description">' . __('Select which events should trigger email notifications.', 'chatbot-plugin') . '</p>';
    }

    /**
     * Render Telegram settings field
     */
    public function render_telegram_settings_field() {
        $telegram_api_key = get_option('chatbot_telegram_api_key', '');
        $telegram_chat_id = get_option('chatbot_telegram_chat_id', '');
        $telegram_notify_events = get_option('chatbot_telegram_notify_events', array());
        
        // API Key
        echo '<div class="telegram-settings">';
        echo '<label for="chatbot_telegram_api_key">' . __('Bot API Key', 'chatbot-plugin') . '</label><br>';
        echo '<input type="text" name="chatbot_telegram_api_key" id="chatbot_telegram_api_key" class="regular-text" value="' . esc_attr($telegram_api_key) . '" />';
        echo '<p class="description">' . __('Enter your Telegram Bot API Key. Create a bot through BotFather to get this key.', 'chatbot-plugin') . '</p>';
        echo '</div>';
        
        // Chat ID
        echo '<div class="telegram-settings" style="margin-top: 15px;">';
        echo '<label for="chatbot_telegram_chat_id">' . __('Chat ID', 'chatbot-plugin') . '</label><br>';
        echo '<input type="text" name="chatbot_telegram_chat_id" id="chatbot_telegram_chat_id" class="regular-text" value="' . esc_attr($telegram_chat_id) . '" />';
        echo '<p class="description">' . __('Enter the Chat ID where notifications should be sent. This can be your personal chat ID or a group chat ID.', 'chatbot-plugin') . '</p>';
        echo '</div>';
        
        // Notification Events
        echo '<div class="telegram-settings" style="margin-top: 15px;">';
        echo '<label>' . __('Notification Events', 'chatbot-plugin') . '</label><br>';
        
        $events = array(
            'new_conversation' => __('New Conversations', 'chatbot-plugin'),
            'daily_summary' => __('Daily Summary', 'chatbot-plugin')
        );
        
        echo '<fieldset>';
        foreach ($events as $event_key => $event_label) {
            $checked = in_array($event_key, $telegram_notify_events) ? 'checked' : '';
            echo '<label for="chatbot_telegram_notify_events_' . esc_attr($event_key) . '">';
            echo '<input type="checkbox" name="chatbot_telegram_notify_events[]" id="chatbot_telegram_notify_events_' . esc_attr($event_key) . '" value="' . esc_attr($event_key) . '" ' . $checked . ' />';
            echo esc_html($event_label);
            echo '</label><br>';
        }
        echo '</fieldset>';
        echo '<p class="description">' . __('Select which events should trigger Telegram notifications.', 'chatbot-plugin') . '</p>';
        echo '</div>';
        
        // Test Button - We'll add functionality for this in the JavaScript
        if (!empty($telegram_api_key) && !empty($telegram_chat_id)) {
            echo '<div class="telegram-settings" style="margin-top: 15px;">';
            echo '<button type="button" id="chatbot_test_telegram" class="button">' . __('Test Telegram Connection', 'chatbot-plugin') . '</button>';
            echo '<span id="telegram_test_result" style="margin-left: 10px;"></span>';
            echo '</div>';
            
            // Add JavaScript for the test button
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#chatbot_test_telegram').on('click', function() {
                    var button = $(this);
                    var resultSpan = $('#telegram_test_result');
                    
                    // Disable button and show loading
                    button.prop('disabled', true);
                    resultSpan.text('<?php _e("Testing...", "chatbot-plugin"); ?>');
                    
                    // Send AJAX request to test Telegram connection
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'chatbot_test_telegram',
                            api_key: $('#chatbot_telegram_api_key').val(),
                            chat_id: $('#chatbot_telegram_chat_id').val(),
                            nonce: '<?php echo wp_create_nonce("chatbot_test_telegram"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                resultSpan.html('<span style="color: green;"><?php _e("Success! Test message sent.", "chatbot-plugin"); ?></span>');
                            } else {
                                resultSpan.html('<span style="color: red;"><?php _e("Error: ", "chatbot-plugin"); ?>' + response.data + '</span>');
                            }
                        },
                        error: function() {
                            resultSpan.html('<span style="color: red;"><?php _e("Error: Could not complete the test.", "chatbot-plugin"); ?></span>');
                        },
                        complete: function() {
                            button.prop('disabled', false);
                        }
                    });
                });
            });
            </script>
            <?php
        }
    }

    /**
     * Render data retention tab content
     */
    public function render_data_retention_tab() {
        // Add CSS for data retention tab
        echo '<style>
        .data-retention-card {
            background-color: #f9f9f9;
            border: 1px solid #e5e5e5;
            border-radius: 5px;
            padding: 20px;
            max-width: 100%;
            margin-bottom: 20px;
        }
        .data-retention-card h2 {
            margin-top: 0;
            color: #23282d;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .cleanup-results-box {
            background: #f8f8f8;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 4px;
        }
        .cleanup-actions {
            margin-top: 15px;
        }
        #cleanup-status {
            margin-left: 10px;
            display: inline-block;
        }
        .chatbot-manual-cleanup-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        </style>';

        ?>
        <div class="data-retention-card">
            <h2><?php _e('Data Retention Policy', 'chatbot-plugin'); ?></h2>
            <p><?php _e('Configure how long chat conversations are stored before being automatically deleted. This helps with GDPR compliance and data minimization.', 'chatbot-plugin'); ?></p>

            <form method="post" action="options.php" class="chatbot-settings-form">
                <?php settings_fields('chatbot_data_retention'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Enable Automatic Cleanup', 'chatbot-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="chatbot_enable_data_retention" value="1" <?php checked(get_option('chatbot_enable_data_retention', '0'), '1'); ?> />
                                <?php _e('Automatically delete old conversations', 'chatbot-plugin'); ?>
                            </label>
                            <p class="description"><?php _e('When enabled, conversations older than the specified period will be permanently deleted.', 'chatbot-plugin'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php _e('Retention Period', 'chatbot-plugin'); ?></th>
                        <td>
                            <select name="chatbot_data_retention_period">
                                <option value="5" <?php selected(get_option('chatbot_data_retention_period', '90'), '5'); ?>><?php _e('5 days', 'chatbot-plugin'); ?></option>
                                <option value="7" <?php selected(get_option('chatbot_data_retention_period', '90'), '7'); ?>><?php _e('1 week', 'chatbot-plugin'); ?></option>
                                <option value="14" <?php selected(get_option('chatbot_data_retention_period', '90'), '14'); ?>><?php _e('2 weeks', 'chatbot-plugin'); ?></option>
                                <option value="30" <?php selected(get_option('chatbot_data_retention_period', '90'), '30'); ?>><?php _e('30 days', 'chatbot-plugin'); ?></option>
                                <option value="60" <?php selected(get_option('chatbot_data_retention_period', '90'), '60'); ?>><?php _e('60 days', 'chatbot-plugin'); ?></option>
                                <option value="90" <?php selected(get_option('chatbot_data_retention_period', '90'), '90'); ?>><?php _e('90 days', 'chatbot-plugin'); ?></option>
                                <option value="180" <?php selected(get_option('chatbot_data_retention_period', '90'), '180'); ?>><?php _e('180 days', 'chatbot-plugin'); ?></option>
                                <option value="365" <?php selected(get_option('chatbot_data_retention_period', '90'), '365'); ?>><?php _e('1 year', 'chatbot-plugin'); ?></option>
                            </select>
                            <p class="description"><?php _e('Conversations older than this will be permanently deleted.', 'chatbot-plugin'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php _e('Skip Export Before Deletion', 'chatbot-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="chatbot_skip_export_before_deletion" value="1" <?php checked(get_option('chatbot_skip_export_before_deletion', '1'), '1'); ?> />
                                <?php _e('Delete without exporting', 'chatbot-plugin'); ?>
                            </label>
                            <p class="description"><?php _e('When checked, conversations will be deleted without creating an export. Uncheck to automatically save an export before deletion.', 'chatbot-plugin'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Retention Settings', 'chatbot-plugin')); ?>
            </form>
        </div>

        <div class="data-retention-card">
            <h2><?php _e('Manual Data Cleanup', 'chatbot-plugin'); ?></h2>
            <p><?php _e('You can also manually clean up old conversations right now.', 'chatbot-plugin'); ?></p>

            <div id="chatbot-data-cleanup-container">
                <div class="cleanup-options">
                    <p>
                        <label>
                            <input type="radio" name="cleanup_period" value="older_than" checked>
                            <?php _e('Delete conversations older than:', 'chatbot-plugin'); ?>
                        </label>
                        <select id="cleanup-days">
                            <option value="5"><?php _e('5 days', 'chatbot-plugin'); ?></option>
                            <option value="7"><?php _e('1 week', 'chatbot-plugin'); ?></option>
                            <option value="14"><?php _e('2 weeks', 'chatbot-plugin'); ?></option>
                            <option value="30"><?php _e('30 days', 'chatbot-plugin'); ?></option>
                            <option value="60"><?php _e('60 days', 'chatbot-plugin'); ?></option>
                            <option value="90" selected><?php _e('90 days', 'chatbot-plugin'); ?></option>
                            <option value="180"><?php _e('180 days', 'chatbot-plugin'); ?></option>
                            <option value="365"><?php _e('1 year', 'chatbot-plugin'); ?></option>
                        </select>
                    </p>

                    <p>
                        <label>
                            <input type="radio" name="cleanup_period" value="all">
                            <?php _e('Delete ALL conversations (use with caution)', 'chatbot-plugin'); ?>
                        </label>
                    </p>

                    <p>
                        <label>
                            <input type="checkbox" id="cleanup-export" <?php echo get_option('chatbot_skip_export_before_deletion', true) ? '' : 'checked'; ?>>
                            <?php _e('Create export backup before deletion', 'chatbot-plugin'); ?>
                        </label>
                    </p>

                    <div class="cleanup-actions">
                        <button type="button" id="chatbot-run-cleanup" class="button button-secondary"><?php esc_html_e('Run Cleanup Now', 'chatbot-plugin'); ?></button>
                        <span id="cleanup-status"></span>
                    </div>
                </div>

                <!-- Results container -->
                <div id="cleanup-results" style="margin-top: 15px; display: none;">
                    <h4><?php _e('Cleanup Results', 'chatbot-plugin'); ?></h4>
                    <div id="cleanup-results-content" class="cleanup-results-box"></div>
                </div>
            </div>

            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Handle the cleanup button click
                $('#chatbot-run-cleanup').on('click', function() {
                    var button = $(this);
                    var status = $('#cleanup-status');
                    var resultsContainer = $('#cleanup-results');
                    var resultsContent = $('#cleanup-results-content');

                    // Get the selected option
                    var actionType = $('input[name="cleanup_period"]:checked').val();
                    var days = $('#cleanup-days').val();
                    var export_data = $('#cleanup-export').is(':checked');

                    // Make sure the user confirms the action
                    var confirmMessage = actionType === 'all' ?
                        '<?php esc_attr_e('Are you sure you want to delete ALL conversations? This action cannot be undone.', 'chatbot-plugin'); ?>' :
                        '<?php esc_attr_e('Are you sure you want to delete conversations older than', 'chatbot-plugin'); ?> ' + days + ' <?php esc_attr_e('days? This action cannot be undone.', 'chatbot-plugin'); ?>';

                    if (!confirm(confirmMessage)) {
                        return false;
                    }

                    // Disable the button and show loading message
                    button.prop('disabled', true);
                    status.html('<span style="color:blue;"><em><?php esc_html_e('Cleaning up data...', 'chatbot-plugin'); ?></em></span>');
                    resultsContainer.hide();

                    // Run the cleanup via AJAX
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'chatbot_ajax_cleanup',
                            action_type: actionType,
                            days: days,
                            export: export_data ? 1 : 0,
                            nonce: '<?php echo wp_create_nonce('chatbot_cleanup_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                status.html('<span style="color:green;"><?php esc_html_e('Cleanup completed successfully!', 'chatbot-plugin'); ?></span>');

                                // Show results
                                var resultHTML = '<p><strong><?php esc_html_e('Conversations deleted:', 'chatbot-plugin'); ?></strong> ' + response.data.deleted_count + '</p>';

                                if (response.data.error_count > 0) {
                                    resultHTML += '<p><strong><?php esc_html_e('Failed to delete:', 'chatbot-plugin'); ?></strong> ' + response.data.error_count + '</p>';
                                }

                                if (export_data && response.data.export_path) {
                                    resultHTML += '<p><strong><?php esc_html_e('Export created:', 'chatbot-plugin'); ?></strong> ' + response.data.export_path + '</p>';
                                }

                                resultsContent.html(resultHTML);
                                resultsContainer.show();
                            } else {
                                status.html('<span style="color:red;"><?php esc_html_e('Error:', 'chatbot-plugin'); ?> ' + (response.data ? response.data.message : '<?php esc_html_e('Unknown error', 'chatbot-plugin'); ?>') + '</span>');
                            }
                        },
                        error: function() {
                            status.html('<span style="color:red;"><?php esc_html_e('Error: Server communication failed', 'chatbot-plugin'); ?></span>');
                        },
                        complete: function() {
                            button.prop('disabled', false);
                        }
                    });
                });
            });
            </script>
        </div>

        <div class="data-retention-card">
            <h2><?php _e('Data Retention Guidelines', 'chatbot-plugin'); ?></h2>
            <p><?php _e('Here are some best practices for data retention in compliance with GDPR and other privacy regulations:', 'chatbot-plugin'); ?></p>

            <ul style="list-style-type: disc; margin-left: 20px;">
                <li><?php _e('Only store personal data for as long as necessary for the purposes for which it was collected', 'chatbot-plugin'); ?></li>
                <li><?php _e('Regularly review and delete data that is no longer needed', 'chatbot-plugin'); ?></li>
                <li><?php _e('Be transparent with users about how long their data will be stored', 'chatbot-plugin'); ?></li>
                <li><?php _e('Consider creating a data retention policy document for your website', 'chatbot-plugin'); ?></li>
                <li><?php _e('Ensure you have a legal basis for retaining any data you keep', 'chatbot-plugin'); ?></li>
                <li><?php _e('Provide mechanisms for users to request deletion of their data', 'chatbot-plugin'); ?></li>
            </ul>

            <p><?php _e('For more information on GDPR compliance, visit the <a href="https://gdpr.eu/" target="_blank">official GDPR website</a>.', 'chatbot-plugin'); ?></p>
        </div>
        <?php
    }

    /**
     * Render security tab content
     */
    public function render_security_tab() {
        // Add CSS for security tab
        echo '<style>
        .rate-limiting-card {
            background-color: #f9f9f9;
            border: 1px solid #e5e5e5;
            border-radius: 5px;
            padding: 20px;
            max-width: 100%;
        }
        .rate-limiting-card h2,
        .security-card h2 {
            margin-top: 0;
            color: #23282d;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .rate-limiting-card table {
            border-collapse: collapse;
            margin: 20px 0;
        }
        .rate-limiting-card table th {
            background-color: #f1f1f1;
            font-weight: 600;
        }
        .rate-limiting-card table td,
        .rate-limiting-card table th {
            padding: 10px;
            border: 1px solid #e1e1e1;
        }
        .security-card {
            background-color: #f9f9f9;
            border: 1px solid #e5e5e5;
            border-radius: 5px;
            padding: 20px;
            margin-top: 30px;
        }
        </style>';

        // Add a reference to the Data Retention tab
        ?>
        <div class="security-card" style="margin-top: 0;">
            <h2><?php _e('Data Management & GDPR', 'chatbot-plugin'); ?></h2>
            <p><?php _e('For data retention settings and GDPR compliance options, please visit our dedicated Data Retention tab.', 'chatbot-plugin'); ?></p>

            <p><a href="?page=chatbot-settings&tab=data-retention" class="button button-primary">
                <?php _e('Go to Data Retention Settings', 'chatbot-plugin'); ?>
            </a></p>
        </div>
        <?php

        // Check for rate limiter reset action
        if (isset($_POST['action']) && $_POST['action'] === 'reset_rate_limits' &&
            isset($_POST['chatbot_security_nonce']) &&
            wp_verify_nonce($_POST['chatbot_security_nonce'], 'chatbot_reset_rate_limits')) {

            if (class_exists('Chatbot_Rate_Limiter')) {
                $rate_limiter = Chatbot_Rate_Limiter::get_instance();
                $reset_result = $rate_limiter->reset_all_rate_limits();

                if ($reset_result) {
                    echo '<div class="notice notice-success is-dismissible"><p>' . __('All rate limits have been reset successfully.', 'chatbot-plugin') . '</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>' . __('Failed to reset rate limits.', 'chatbot-plugin') . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . __('Rate limiter not available.', 'chatbot-plugin') . '</p></div>';
            }
        }

        ?>
        <div class="rate-limiting-card">
            <h2><?php _e('Rate Limiting', 'chatbot-plugin'); ?></h2>
            <p><?php _e('Rate limiting protects your chatbot from abuse and helps manage API costs by limiting how many messages users can send in a given time period.', 'chatbot-plugin'); ?></p>

            <?php if (class_exists('Chatbot_Rate_Limiter')): ?>
                <?php $rate_limiter = Chatbot_Rate_Limiter::get_instance(); ?>

                <h3><?php _e('Current Rate Limit Settings', 'chatbot-plugin'); ?></h3>
                <table class="widefat" style="max-width: 800px;">
                    <thead>
                        <tr>
                            <th><?php _e('Setting', 'chatbot-plugin'); ?></th>
                            <th><?php _e('Value', 'chatbot-plugin'); ?></th>
                            <th><?php _e('Description', 'chatbot-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Display current rate limit settings
                        $rate_settings = array(
                            'messages_per_minute' => array(
                                'label' => __('Messages per minute (per user)', 'chatbot-plugin'),
                                'description' => __('Maximum number of messages a user can send per minute', 'chatbot-plugin')
                            ),
                            'messages_per_hour' => array(
                                'label' => __('Messages per hour (per user)', 'chatbot-plugin'),
                                'description' => __('Maximum number of messages a user can send per hour', 'chatbot-plugin')
                            ),
                            'messages_per_day' => array(
                                'label' => __('Messages per day (per user)', 'chatbot-plugin'),
                                'description' => __('Maximum number of messages a user can send per day', 'chatbot-plugin')
                            ),
                            'global_per_minute' => array(
                                'label' => __('Global messages per minute', 'chatbot-plugin'),
                                'description' => __('Maximum number of messages from all users per minute', 'chatbot-plugin')
                            ),
                            'global_per_hour' => array(
                                'label' => __('Global messages per hour', 'chatbot-plugin'),
                                'description' => __('Maximum number of messages from all users per hour', 'chatbot-plugin')
                            )
                        );

                        // Loop through settings and display current values
                        foreach ($rate_settings as $key => $setting) {
                            $option_name = 'chatbot_rate_limit_' . str_replace('messages_', '', $key);
                            $default_value = $rate_limiter->default_limits[$key] ?? '';
                            $current_value = get_option($option_name, $default_value);

                            echo '<tr>';
                            echo '<td><strong>' . esc_html($setting['label']) . '</strong></td>';
                            echo '<td>' . esc_html($current_value) . '</td>';
                            echo '<td>' . esc_html($setting['description']) . '</td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>

                <p><?php _e('You can adjust these settings in the Rate Limiting section of the General settings tab.', 'chatbot-plugin'); ?>
                <a href="<?php echo admin_url('admin.php?page=chatbot-settings&tab=general'); ?>" class="button button-small"><?php _e('Go to Rate Limit Settings', 'chatbot-plugin'); ?></a>
                </p>

                <h3><?php _e('Reset All Rate Limits', 'chatbot-plugin'); ?></h3>
                <p><?php _e('If you need to reset all rate limit counters (for example, after testing or if users are experiencing issues), you can do so using the button below.', 'chatbot-plugin'); ?></p>
                <p><strong><?php _e('Warning:', 'chatbot-plugin'); ?></strong> <?php _e('This will reset all rate limit counters for all users. Use with caution.', 'chatbot-plugin'); ?></p>

                <!-- Use AJAX for rate limit reset instead of form submission -->
                <button id="chatbot-reset-rate-limits" class="button button-primary"><?php esc_html_e('Reset All Rate Limits', 'chatbot-plugin'); ?></button>
                <span id="reset-result-message" style="margin-left: 10px; display: inline-block;"></span>

                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('#chatbot-reset-rate-limits').on('click', function() {
                        if (confirm('<?php esc_attr_e('Are you sure you want to reset all rate limits? This action cannot be undone.', 'chatbot-plugin'); ?>')) {
                            var button = $(this);
                            var resultMessage = $('#reset-result-message');

                            // Disable button and show loading message
                            button.prop('disabled', true);
                            resultMessage.html('<em><?php esc_html_e('Resetting rate limits...', 'chatbot-plugin'); ?></em>');

                            // Make AJAX request to reset rate limits
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'chatbot_reset_rate_limits',
                                    nonce: '<?php echo wp_create_nonce('chatbot_reset_rate_limits'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        resultMessage.html('<span style="color: green;"><?php esc_html_e('All rate limits have been reset successfully!', 'chatbot-plugin'); ?></span>');
                                    } else {
                                        resultMessage.html('<span style="color: red;"><?php esc_html_e('Failed to reset rate limits.', 'chatbot-plugin'); ?></span>');
                                    }
                                },
                                error: function() {
                                    resultMessage.html('<span style="color: red;"><?php esc_html_e('An error occurred while resetting rate limits.', 'chatbot-plugin'); ?></span>');
                                },
                                complete: function() {
                                    // Re-enable button
                                    button.prop('disabled', false);
                                }
                            });
                        }
                    });
                });
                </script>

                <!-- Add API Key Verification Section -->
                <h3><?php _e('Verify OpenAI API Key', 'chatbot-plugin'); ?></h3>
                <p><?php _e('Verify that your OpenAI API key is correctly configured and working properly.', 'chatbot-plugin'); ?></p>

                <div class="api-key-verification-tool">
                    <button id="chatbot-verify-api-key" class="button button-primary"><?php esc_html_e('Verify API Key', 'chatbot-plugin'); ?></button>
                    <span id="api-key-result-message" style="margin-left: 10px; display: inline-block;"></span>

                    <div id="api-key-results-container" style="margin-top: 10px; display: none;">
                        <h4><?php _e('Verification Results:', 'chatbot-plugin'); ?></h4>
                        <pre id="api-key-results" style="background: #f5f5f5; padding: 10px; max-height: 200px; overflow: auto;"></pre>
                    </div>

                    <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        $('#chatbot-verify-api-key').on('click', function() {
                            var $button = $(this);
                            var $message = $('#api-key-result-message');
                            var $resultsContainer = $('#api-key-results-container');
                            var $results = $('#api-key-results');

                            // Disable button during test
                            $button.prop('disabled', true);
                            $message.html('<span style="color: blue;">Testing API key...</span>');

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'chatbot_test_openai',
                                    nonce: '<?php echo wp_create_nonce('chatbot_test_openai_nonce'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        $message.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                                        $results.html('API key is correctly configured and working properly.\n\nModel: ' +
                                                    '<?php echo esc_js(get_option('chatbot_openai_model', 'gpt-3.5-turbo')); ?>\n' +
                                                    'Connection status: success');
                                    } else {
                                        $message.html('<span style="color: red;">✗ Error</span>');
                                        $results.html('API key verification failed.\n\nError: ' + response.data.message);
                                    }
                                    $resultsContainer.show();
                                },
                                error: function() {
                                    $message.html('<span style="color: red;">✗ Connection error</span>');
                                    $results.html('Could not connect to the server to verify the API key.');
                                    $resultsContainer.show();
                                },
                                complete: function() {
                                    $button.prop('disabled', false);
                                }
                            });
                        });
                    });
                    </script>
                </div>

                <!-- Add Rate Limit Test Section -->
                <h3><?php _e('Test Rate Limiting', 'chatbot-plugin'); ?></h3>
                <p><?php _e('Use this tool to test that rate limiting is working correctly.', 'chatbot-plugin'); ?></p>

                <div class="rate-limit-test-tool">
                    <button id="chatbot-test-rate-limit" class="button"><?php esc_html_e('Simulate Rate Limit Test', 'chatbot-plugin'); ?></button>
                    <span id="test-result-message" style="margin-left: 10px; display: inline-block;"></span>

                    <div id="test-results-container" style="margin-top: 10px; display: none;">
                        <h4><?php _e('Test Results:', 'chatbot-plugin'); ?></h4>
                        <pre id="test-results" style="background: #f5f5f5; padding: 10px; max-height: 200px; overflow: auto;"></pre>
                    </div>

                    <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        $('#chatbot-test-rate-limit').on('click', function() {
                            var button = $(this);
                            var resultMessage = $('#test-result-message');
                            var testResults = $('#test-results');
                            var testContainer = $('#test-results-container');

                            // Disable button and show loading message
                            button.prop('disabled', true);
                            resultMessage.html('<em><?php esc_html_e('Testing rate limits...', 'chatbot-plugin'); ?></em>');
                            testResults.empty();
                            testContainer.hide();

                            // Make AJAX request to test rate limits
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'chatbot_test_rate_limits',
                                    nonce: '<?php echo wp_create_nonce('chatbot_test_rate_limits'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        resultMessage.html('<span style="color: green;"><?php esc_html_e('Test completed successfully!', 'chatbot-plugin'); ?></span>');

                                        // Display test results
                                        testResults.html(response.data.results);
                                        testContainer.show();
                                    } else {
                                        resultMessage.html('<span style="color: red;"><?php esc_html_e('Test failed:', 'chatbot-plugin'); ?> ' + response.data.message + '</span>');
                                    }
                                },
                                error: function() {
                                    resultMessage.html('<span style="color: red;"><?php esc_html_e('An error occurred during the test.', 'chatbot-plugin'); ?></span>');
                                },
                                complete: function() {
                                    // Re-enable button
                                    button.prop('disabled', false);
                                }
                            });
                        });
                    });
                    </script>
                </div>

                <!-- "Test AI Response" section was removed from here since it's already available in the OpenAI tab -->
                <!-- This eliminates duplicate functionality and prevents errors -->
            <?php else: ?>
                <div class="notice notice-warning">
                    <p><?php _e('Rate limiting functionality is not available. Make sure the plugin is installed and activated correctly.', 'chatbot-plugin'); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="security-card" style="margin-top: 20px;">
            <h2><?php _e('Security Best Practices', 'chatbot-plugin'); ?></h2>
            <p><?php _e('Here are some recommendations to keep your chatbot secure:', 'chatbot-plugin'); ?></p>

            <ul style="list-style-type: disc; margin-left: 20px;">
                <li><?php _e('Regularly update the plugin to get the latest security improvements', 'chatbot-plugin'); ?></li>
                <li><?php _e('Keep your WordPress installation and all plugins up to date', 'chatbot-plugin'); ?></li>
                <li><?php _e('Use strong passwords for your WordPress admin account', 'chatbot-plugin'); ?></li>
                <li><?php _e('Implement WordPress security plugins for additional protection', 'chatbot-plugin'); ?></li>
                <li><?php _e('Monitor your chatbot logs for any suspicious activity', 'chatbot-plugin'); ?></li>
                <li><?php _e('Adjust rate limits based on your site traffic and API usage', 'chatbot-plugin'); ?></li>
                <li><?php _e('Set an appropriate data retention period to comply with privacy regulations', 'chatbot-plugin'); ?></li>
            </ul>
        </div>
        <?php
    }
} // End of Chatbot_Settings class

// Initialize the settings
function chatbot_settings_init() {
    return Chatbot_Settings::get_instance();
}
chatbot_settings_init();