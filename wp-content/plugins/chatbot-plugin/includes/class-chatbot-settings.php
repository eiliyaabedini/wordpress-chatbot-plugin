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
        register_setting('chatbot_settings', 'chatbot_primary_color'); // Single color setting
        register_setting('chatbot_settings', 'chatbot_button_icon');
        register_setting('chatbot_settings', 'chatbot_button_icon_type');
        register_setting('chatbot_settings', 'chatbot_button_icon_url');
        
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
}

// Initialize the settings
function chatbot_settings_init() {
    return Chatbot_Settings::get_instance();
}
chatbot_settings_init();