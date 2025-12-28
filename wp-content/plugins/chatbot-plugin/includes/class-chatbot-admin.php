<?php
/**
 * Chatbot Admin
 * 
 * Handles the admin interface for the chatbot plugin
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_Admin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Add custom admin styles for menu icon
        add_action('admin_head', array($this, 'add_admin_menu_styles'));

        // Register admin-specific scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Register AJAX handlers for admin
        add_action('wp_ajax_chatbot_admin_send_message', array($this, 'admin_send_message'));
        add_action('wp_ajax_chatbot_reset_rate_limits', array($this, 'ajax_reset_rate_limits'));
        add_action('wp_ajax_chatbot_test_rate_limits', array($this, 'ajax_test_rate_limits'));
        add_action('wp_ajax_chatbot_test_ai_response', array($this, 'ajax_test_ai_response'));
        add_action('wp_ajax_chatbot_search_content', array($this, 'ajax_search_content'));
        add_action('wp_ajax_chatbot_get_post_tokens', array($this, 'ajax_get_post_tokens'));

        // Handle admin actions
        add_action('admin_init', array($this, 'handle_admin_actions'));

        // Add admin-post handlers for form submissions
        add_action('admin_post_chatbot_add_configuration', array($this, 'process_add_configuration'));
        add_action('admin_post_chatbot_update_configuration', array($this, 'process_update_configuration'));

        // Add admin notices
        add_action('admin_notices', array($this, 'show_admin_notices'));
    }
    
    /**
     * Show admin notices
     */
    public function show_admin_notices() {
        // Only show on chatbot configuration pages when needed
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'chatbot-configurations') === false) {
            return;
        }
        
        // Currently no notices to show
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Main plugin page
        add_menu_page(
            __('AIPass Chat', 'aipass-chat'),
            'Pass',
            'manage_options',
            'chatbot-plugin',
            array($this, 'display_admin_page'),
            'dashicons-admin-generic', // Placeholder, replaced by CSS
            100
        );

        // First submenu - renamed to 'Overview'
        add_submenu_page(
            'chatbot-plugin',
            __('AIPass Overview', 'aipass-chat'),
            __('Overview', 'aipass-chat'),
            'manage_options',
            'chatbot-plugin',
            array($this, 'display_admin_page')
        );

        // Chatbots management submenu
        add_submenu_page(
            'chatbot-plugin',
            __('Chat Bots', 'aipass-chat'),
            __('Chat Bots', 'aipass-chat'),
            'manage_options',
            'chatbot-configurations',
            array($this, 'display_configurations_page')
        );

        // Conversations submenu
        add_submenu_page(
            'chatbot-plugin',
            __('Conversations', 'aipass-chat'),
            __('Conversations', 'aipass-chat'),
            'manage_options',
            'chatbot-conversations',
            array($this, 'display_conversations_page')
        );
    }

    /**
     * Add custom CSS for admin menu - AIPass branding
     */
    public function add_admin_menu_styles() {
        ?>
        <style>
            /* Make icon area a flex container for vertical centering */
            #adminmenu .toplevel_page_chatbot-plugin .wp-menu-image {
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }

            /* Replace dashicon with AI text box */
            #adminmenu .toplevel_page_chatbot-plugin .wp-menu-image.dashicons-before.dashicons-admin-generic:before {
                content: "AI" !important;
                font-family: Arial, sans-serif !important;
                font-size: 11px !important;
                font-weight: bold !important;
                background: #8A4FFF !important;
                color: #fff !important;
                padding: 3px 5px !important;
                border-radius: 4px 0 4px 4px !important;
                line-height: 1 !important;
                width: auto !important;
                height: auto !important;
                position: static !important;
            }

            /* Make Pass text bold */
            #adminmenu .toplevel_page_chatbot-plugin .wp-menu-name {
                font-weight: 600;
            }
        </style>
        <?php
    }

    /**
     * Display the main admin page
     */
    public function display_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('chatbot_plugin'); ?>
            
            <div class="dashboard-layout" style="padding: 0 10px;">
                <div class="card ai-chat-card" style="width: 100%; max-width: 1200px; margin: 0 auto;">
                    <h2><?php _e('AI Conversation Insights', 'chatbot-plugin'); ?></h2>
                    <p class="ai-overview-description"><?php _e('Get AI-powered insights about your chatbot conversations. Analyze patterns, user behavior, and discover opportunities for improvement.', 'chatbot-plugin'); ?></p>
                    
                    <div class="ai-chat-interface">
                        <div class="ai-chat-messages" style="height: 500px;">
                            <div id="ai-chat-messages-container">
                                <div class="ai-chat-welcome">
                                    <div class="ai-chat-welcome-icon">
                                        <span class="dashicons dashicons-chart-area"></span>
                                    </div>
                                    <h3><?php _e('AI Chat Analysis', 'chatbot-plugin'); ?></h3>
                                    <p><?php _e('Your AI assistant can analyze your chatbot conversations and provide concise, actionable insights with suggested follow-up questions.', 'chatbot-plugin'); ?></p>
                                    <button id="generate-ai-overview" class="button button-primary">
                                        <?php _e('Start Conversation Analysis', 'chatbot-plugin'); ?>
                                    </button>
                                </div>
                            </div>
                            <div class="ai-summary-loading" style="display: none;">
                                <span class="spinner is-active"></span>
                                <p><?php _e('Analyzing conversations...', 'chatbot-plugin'); ?></p>
                            </div>
                        </div>
                        <div class="ai-chat-input-area">
                            <input type="text" id="ai-chat-input" placeholder="<?php _e('Ask a question about your chat data...', 'chatbot-plugin'); ?>" disabled>
                            <button id="ai-chat-send" class="button button-primary" disabled>
                                <span class="dashicons dashicons-arrow-right-alt2"></span>
                            </button>
                        </div>
                        <p class="description" style="text-align: center; margin-top: 10px; font-style: italic;">
                            <?php _e('Type your own question or click on a suggested question button for quick insights.', 'chatbot-plugin'); ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <?php do_action('chatbot_after_overview_content'); ?>
            
            <!-- Direct Showdown.js script tag to ensure it's loaded before use -->
            <script src="https://cdnjs.cloudflare.com/ajax/libs/showdown/2.1.0/showdown.min.js"></script>
            
            <!-- Inject Showdown directly if needed -->
            <script type="text/javascript">
                // Log showdown version to console for debugging
                document.addEventListener('DOMContentLoaded', function() {
                    console.log('Inline Showdown script loaded');
                    window.showdownVersionCheck = function() {
                        if (typeof showdown !== 'undefined') {
                            console.log('Showdown is available, version:', showdown.version || 'unknown');
                        } else {
                            console.log('Showdown is NOT available');
                        }
                    };
                    
                    // Check showdown on load
                    window.showdownVersionCheck();
                    
                    // Run another check after a short delay
                    setTimeout(window.showdownVersionCheck, 1000);
                });
            </script>
        </div>
        <?php
    }
    
    /**
     * Display the chatbot configurations page
     */
    public function display_configurations_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check for success messages from admin-post redirects
        if (isset($_GET['success'])) {
            $success_type = sanitize_text_field($_GET['success']);

            // Whitelist approach for success messages
            $allowed_success_types = array('add', 'update');
            if (in_array($success_type, $allowed_success_types, true)) {
                $message = ($success_type === 'add')
                    ? __('Chatbot added successfully.', 'chatbot-plugin')
                    : __('Chatbot updated successfully.', 'chatbot-plugin');
                add_settings_error('chatbot_plugin', esc_attr('success-' . $success_type), esc_html($message), 'updated');
            }
        }

        // Check for error messages from admin-post redirects
        if (isset($_GET['error'])) {
            $error_type = sanitize_text_field($_GET['error']);

            // Whitelist approach for error messages
            $error_messages = array(
                'name_required' => __('Chatbot name is required.', 'chatbot-plugin'),
                'name_exists' => __('A chatbot with this name already exists.', 'chatbot-plugin'),
                'db_error' => __('Failed to add or update chatbot. Please try again.', 'chatbot-plugin'),
                'invalid_data' => __('Invalid configuration data.', 'chatbot-plugin')
            );

            if (isset($error_messages[$error_type])) {
                add_settings_error('chatbot_plugin', esc_attr('error-' . $error_type), esc_html($error_messages[$error_type]), 'error');
            }
        }
        
        // Handle delete action
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && check_admin_referer('chatbot_delete_configuration')) {
            $this->handle_delete_configuration(intval($_GET['id']));
        }
        
        // Check if we're editing a configuration
        $editing = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']);
        $edit_id = $editing ? intval($_GET['id']) : 0;
        
        // Include database class
        $db = Chatbot_DB::get_instance();
        
        // Get all configurations
        $configurations = $db->get_configurations();
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php echo $editing ? __('Edit Chatbot', 'chatbot-plugin') : __('Chat Bots', 'chatbot-plugin'); ?>
            </h1>
            
            <?php if (!$editing): ?>
                <a href="<?php echo admin_url('admin.php?page=chatbot-configurations&action=add'); ?>" class="page-title-action">
                    <?php _e('Add New', 'chatbot-plugin'); ?>
                </a>
            <?php endif; ?>
            
            <?php settings_errors('chatbot_plugin'); ?>
            
            <?php if ($editing || isset($_GET['action']) && $_GET['action'] === 'add'): ?>
                <?php 
                // Get the configuration if editing
                $config = $editing ? $db->get_configuration($edit_id) : null;
                
                if ($editing && !$config) {
                    echo '<div class="notice notice-error"><p>' . __('Configuration not found.', 'chatbot-plugin') . '</p></div>';
                    echo '<p><a href="' . admin_url('admin.php?page=chatbot-configurations') . '">' . __('&larr; Back to Chat Bots', 'chatbot-plugin') . '</a></p>';
                    echo '</div>'; // Close .wrap
                    return;
                }
                
                // Set defaults for new configuration
                $name = $editing ? $config->name : '';
                $system_prompt = $editing ? $config->system_prompt : '';
                ?>
                
                <p>
                    <a href="<?php echo admin_url('admin.php?page=chatbot-configurations'); ?>" class="button">
                        <?php _e('&larr; Back to Chat Bots', 'chatbot-plugin'); ?>
                    </a>
                </p>
                
                <form method="post" id="chatbot-config-form" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="page" value="chatbot-configurations">
                    <?php if ($editing): ?>
                        <input type="hidden" name="action" value="chatbot_update_configuration">
                        <input type="hidden" name="id" value="<?php echo esc_attr($edit_id); ?>">
                        <?php wp_nonce_field('chatbot_update_configuration_nonce'); ?>
                        <input type="hidden" name="configuration_id" value="<?php echo esc_attr($edit_id); ?>">
                    <?php else: ?>
                        <input type="hidden" name="action" value="chatbot_add_configuration">
                        <?php wp_nonce_field('chatbot_add_configuration_nonce'); ?>
                    <?php endif; ?>

                    <!-- Tabbed Interface -->
                    <style>
                        .chatbot-config-tabs {
                            display: flex;
                            gap: 0;
                            border-bottom: 1px solid #c3c4c7;
                            margin-bottom: 20px;
                            background: #f0f0f1;
                            padding: 0 10px;
                        }
                        .chatbot-config-tabs .nav-tab {
                            padding: 10px 20px;
                            cursor: pointer;
                            border: 1px solid transparent;
                            border-bottom: none;
                            margin-bottom: -1px;
                            background: transparent;
                            color: #50575e;
                            text-decoration: none;
                            font-size: 14px;
                            font-weight: 500;
                            transition: all 0.2s;
                        }
                        .chatbot-config-tabs .nav-tab:hover {
                            background: #fff;
                            color: #2271b1;
                        }
                        .chatbot-config-tabs .nav-tab.nav-tab-active {
                            background: #fff;
                            border-color: #c3c4c7;
                            border-bottom-color: #fff;
                            color: #1d2327;
                        }
                        .chatbot-config-tabs .nav-tab .dashicons {
                            font-size: 16px;
                            width: 16px;
                            height: 16px;
                            margin-right: 5px;
                            vertical-align: middle;
                        }
                        .chatbot-tab-content {
                            display: none;
                            background: #fff;
                            padding: 20px;
                            border: 1px solid #c3c4c7;
                            border-top: none;
                        }
                        .chatbot-tab-content.active {
                            display: block;
                        }
                        .chatbot-tab-content .form-table {
                            margin-top: 0;
                        }
                        .chatbot-tab-content .form-table th {
                            width: 200px;
                            padding-left: 0;
                        }
                        .chatbot-integration-status {
                            display: inline-block;
                            padding: 2px 8px;
                            border-radius: 3px;
                            font-size: 11px;
                            margin-left: 8px;
                            vertical-align: middle;
                        }
                        .chatbot-integration-status.connected {
                            background: #d4edda;
                            color: #155724;
                        }
                        .chatbot-integration-status.disconnected {
                            background: #f8f9fa;
                            color: #6c757d;
                        }
                        @media (max-width: 1200px) {
                            .integrations-grid {
                                grid-template-columns: 1fr !important;
                            }
                        }
                        .integration-card p.description {
                            margin-top: 15px;
                            padding-top: 15px;
                            border-top: 1px solid #eee;
                        }
                    </style>

                    <div class="chatbot-config-tabs">
                        <a href="#" class="nav-tab nav-tab-active" data-tab="general">
                            <span class="dashicons dashicons-admin-generic"></span><?php _e('General', 'chatbot-plugin'); ?>
                        </a>
                        <a href="#" class="nav-tab" data-tab="knowledge">
                            <span class="dashicons dashicons-database"></span><?php _e('Knowledge', 'chatbot-plugin'); ?>
                        </a>
                        <a href="#" class="nav-tab" data-tab="integrations">
                            <span class="dashicons dashicons-share"></span><?php _e('Integrations', 'chatbot-plugin'); ?>
                            <?php
                            // Show integration status badges
                            $telegram_connected = $editing && !empty($config->telegram_bot_token);
                            $whatsapp_connected = false;
                            if ($editing && class_exists('Chatbot_Messaging_Manager')) {
                                $manager = Chatbot_Messaging_Manager::get_instance();
                                $whatsapp = $manager->get_platform('whatsapp');
                                if ($whatsapp) {
                                    $whatsapp_connected = $whatsapp->is_connected($config->id);
                                }
                            }
                            $connected_count = ($telegram_connected ? 1 : 0) + ($whatsapp_connected ? 1 : 0);
                            if ($connected_count > 0): ?>
                                <span class="chatbot-integration-status connected"><?php echo $connected_count; ?> <?php _e('active', 'chatbot-plugin'); ?></span>
                            <?php endif; ?>
                        </a>
                    </div>

                    <!-- General Tab -->
                    <div id="tab-general" class="chatbot-tab-content active">
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row">
                                        <label for="chatbot_config_name"><?php _e('Name', 'chatbot-plugin'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" name="chatbot_config_name" id="chatbot_config_name" class="regular-text" value="<?php echo esc_attr($name); ?>" required>
                                        <p class="description"><?php _e('Enter a unique name for this chatbot. This will be used in the shortcode.', 'chatbot-plugin'); ?></p>
                                        <p class="description"><?php _e('Example shortcode:', 'chatbot-plugin'); ?> <code>[chatbot name="<?php echo esc_attr($name ?: 'product'); ?>"]</code></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="chatbot_persona"><?php _e('Persona', 'chatbot-plugin'); ?></label>
                                    </th>
                                    <td>
                                        <textarea name="chatbot_persona" id="chatbot_persona" class="large-text code" rows="7" data-original-value="<?php echo esc_attr($editing && isset($config->persona) ? $config->persona : 'You are a helpful, friendly, and professional assistant. Respond to user inquiries in a conversational tone while maintaining accuracy and being concise.'); ?>"><?php echo esc_textarea($editing && isset($config->persona) ? $config->persona : 'You are a helpful, friendly, and professional assistant. Respond to user inquiries in a conversational tone while maintaining accuracy and being concise.'); ?></textarea>
                                        <p class="description"><?php _e('Define the personality and tone for this chatbot. This controls how the AI responds to users.', 'chatbot-plugin'); ?></p>
                                        <button type="button" class="button" id="chatbot_improve_prompt"
                                            data-nonce-test="<?php echo wp_create_nonce('chatbot_test_ai_nonce'); ?>"
                                            data-nonce-improve="<?php echo wp_create_nonce('chatbot_improve_prompt_nonce'); ?>">
                                            <?php _e('Improve Persona with AI', 'chatbot-plugin'); ?>
                                        </button>
                                        <span id="chatbot_improve_prompt_status"></span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Knowledge Tab -->
                    <div id="tab-knowledge" class="chatbot-tab-content">
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row">
                                        <label for="chatbot_knowledge"><?php _e('Knowledge Base', 'chatbot-plugin'); ?></label>
                                    </th>
                                    <td>
                                        <textarea name="chatbot_knowledge" id="chatbot_knowledge" class="large-text code" rows="12" data-original-value="<?php echo esc_attr($editing && isset($config->knowledge) ? $config->knowledge : $system_prompt); ?>"><?php echo esc_textarea($editing && isset($config->knowledge) ? $config->knowledge : $system_prompt); ?></textarea>
                                        <p class="description"><?php _e('Define the domain-specific knowledge for this chatbot. This information is what the chatbot will use to answer questions.', 'chatbot-plugin'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="chatbot_knowledge_sources_select"><?php _e('WordPress Content', 'chatbot-plugin'); ?></label>
                                    </th>
                                    <td>
                                        <?php
                                        // Get current knowledge sources
                                        $knowledge_sources = $editing && isset($config->knowledge_sources) ? $config->knowledge_sources : '';
                                        $selected_ids = !empty($knowledge_sources) ? json_decode($knowledge_sources, true) : array();
                                        if (!is_array($selected_ids)) {
                                            $selected_ids = array();
                                        }

                                        // Get all published posts for the select
                                        $all_posts = get_posts(array(
                                            'post_type' => array_values(array_diff(get_post_types(array('public' => true), 'names'), array('attachment'))),
                                            'post_status' => 'publish',
                                            'posts_per_page' => -1,
                                            'orderby' => 'title',
                                            'order' => 'ASC'
                                        ));
                                        ?>
                                        <div class="chatbot-knowledge-sources-wrapper">
                                        <select id="chatbot_knowledge_sources_select" class="chatbot-select2" multiple="multiple" style="width: 100%; max-width: 600px;">
                                            <?php foreach ($all_posts as $post):
                                                $post_type_obj = get_post_type_object($post->post_type);
                                                $type_label = $post_type_obj ? $post_type_obj->labels->singular_name : 'Content';
                                                $content = wp_strip_all_tags(strip_shortcodes($post->post_content));
                                                $token_count = ceil(strlen($content) / 4);
                                                $selected = in_array($post->ID, $selected_ids) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo esc_attr($post->ID); ?>" <?php echo $selected; ?> data-type="<?php echo esc_attr($type_label); ?>" data-tokens="<?php echo esc_attr($token_count); ?>">
                                                    <?php echo esc_html($post->post_title); ?> (<?php echo esc_html($type_label); ?>) - ~<?php echo number_format_i18n($token_count); ?> tokens
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <div class="chatbot-knowledge-token-counter" style="margin-top: 15px;">
                                            <p style="margin: 0 0 8px 0; font-weight: 500;"><?php _e('Total System Prompt Size (Knowledge + Persona + WordPress Content):', 'chatbot-plugin'); ?></p>
                                            <div class="token-bar-container">
                                                <div class="token-bar" id="chatbot_token_bar" style="width: 0%;"></div>
                                            </div>
                                            <div class="token-info">
                                                <span id="chatbot_token_count">0</span> / <span id="chatbot_token_max">100,000</span> <?php _e('tokens', 'chatbot-plugin'); ?>
                                                <span id="chatbot_token_percentage">(0%)</span>
                                            </div>
                                        </div>

                                        <input type="hidden" name="chatbot_knowledge_sources" id="chatbot_knowledge_sources" value="<?php echo esc_attr($knowledge_sources); ?>">
                                    </div>
                                    <p class="description"><?php _e('Select WordPress posts, pages, or products to use as additional knowledge for the AI. The content will be extracted and the AI can cite source URLs when responding.', 'chatbot-plugin'); ?></p>
                                    <!-- Direct inline backup script -->
                                    <script type="text/javascript">
                                    (function() {
                                        console.log('Inline script for improve button loaded');
                                        document.addEventListener('DOMContentLoaded', function() {
                                            var improveButton = document.getElementById('chatbot_improve_prompt');
                                            if (improveButton) {
                                                console.log('Found improve button in inline script');
                                                improveButton.addEventListener('click', function() {
                                                    console.log('Improve button clicked in inline script');
                                                    var textarea = document.getElementById('chatbot_persona');
                                                    var status = document.getElementById('chatbot_improve_prompt_status');
                                                    
                                                    if (textarea && textarea.value.trim() !== '' && 
                                                        typeof jQuery !== 'undefined' && typeof ajaxurl !== 'undefined') {
                                                        
                                                        // Disable the button to prevent multiple clicks
                                                        improveButton.disabled = true;
                                                        status.innerHTML = '<span>Thinking ...</span>';
                                                        
                                                        // Use jQuery AJAX for simplicity in the inline script
                                                        jQuery.ajax({
                                                            url: ajaxurl,
                                                            type: 'POST',
                                                            data: {
                                                                action: 'chatbot_improve_prompt',
                                                                prompt: textarea.value,
                                                                nonce: improveButton.getAttribute('data-nonce-improve')
                                                            },
                                                            success: function(response) {
                                                                console.log('Improve response:', response);
                                                                if (response.success && response.data && response.data.improved_prompt) {
                                                                    if (response.data.improved_prompt.trim() === '') {
                                                                        status.innerHTML = '<span style="color: red;">Error: AI returned an empty response. Please try again.</span>';
                                                                    } else {
                                                                        textarea.value = response.data.improved_prompt;
                                                                        status.innerHTML = '<span style="color: green;">All Done, check it and if you need modify it!</span>';
                                                                    }
                                                                } else {
                                                                    status.innerHTML = '<span style="color: red;">Error: ' + 
                                                                        (response.data && response.data.message ? response.data.message : 'Unknown error') + 
                                                                        '</span>';
                                                                    console.error('API error details:', response);
                                                                }
                                                            },
                                                            error: function(xhr, status, error) {
                                                                console.error('AJAX error:', status, error);
                                                                status.innerHTML = '<span style="color: red;">Communication error: ' + error + '</span>';
                                                            },
                                                            complete: function() {
                                                                // Always re-enable the button when the request completes
                                                                improveButton.disabled = false;
                                                            }
                                                        });
                                                    }
                                                });
                                            }
                                        });
                                    })();
                                    </script>
                                    
                                    <!-- Hidden system prompt field for backward compatibility -->
                                    <input type="hidden" name="chatbot_system_prompt" id="chatbot_system_prompt" value="<?php echo esc_attr($system_prompt); ?>" />
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Integrations Tab -->
                    <div id="tab-integrations" class="chatbot-tab-content">
                        <div class="integrations-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <!-- Telegram Section -->
                            <div class="integration-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
                                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                                    <span style="background: #0088cc; color: #fff; padding: 8px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;">
                                        <span class="dashicons dashicons-format-status" style="font-size: 20px; width: 20px; height: 20px;"></span>
                                    </span>
                                    <?php _e('Telegram', 'chatbot-plugin'); ?>
                                </h3>
                                <?php
                                // Get current Telegram bot token
                                $telegram_bot_token = $editing && isset($config->telegram_bot_token) ? $config->telegram_bot_token : '';
                                $telegram_connected = !empty($telegram_bot_token);

                                // Get bot info if connected
                                $telegram_bot_info = null;
                                $telegram = Chatbot_Telegram::get_instance();
                                $is_localhost = $telegram->is_localhost();
                                if ($telegram_connected) {
                                    $telegram_bot_info = $telegram->validate_token($telegram_bot_token);
                                }
                                ?>

                                    <?php
                                    // Check if polling mode is enabled for this config
                                    $polling_mode = $editing ? get_option("chatbot_telegram_polling_{$config->id}", false) : false;
                                    ?>
                                    <div class="chatbot-telegram-wrapper" style="max-width: 600px;">
                                        <?php if ($is_localhost && !$telegram_connected): ?>
                                            <!-- Localhost info -->
                                            <div class="telegram-localhost-info" style="background: #e3f2fd; border: 1px solid #2196f3; padding: 12px 15px; border-radius: 4px; margin-bottom: 15px;">
                                                <strong style="color: #1565c0;">&#x1F4BB; Local Development Mode</strong>
                                                <p style="margin: 8px 0 0 0; color: #1565c0; font-size: 13px;">
                                                    <?php _e('Your site is running locally. Telegram will use polling mode instead of webhooks for testing.', 'chatbot-plugin'); ?>
                                                </p>
                                                <p style="margin: 8px 0 0 0; color: #1565c0; font-size: 13px;">
                                                    <?php _e('Use the "Poll Now" button to manually check for new messages after connecting.', 'chatbot-plugin'); ?>
                                                </p>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($telegram_connected && $telegram_bot_info): ?>
                                            <!-- Connected state -->
                                            <?php if ($polling_mode): ?>
                                                <!-- Polling mode (localhost) -->
                                                <div class="telegram-status connected" style="background: #e3f2fd; padding: 15px; border-radius: 4px; margin-bottom: 10px;">
                                                    <div style="display: flex; align-items: center; gap: 10px;">
                                                        <span style="color: #1565c0; font-size: 20px;">&#x2713;</span>
                                                        <div>
                                                            <strong style="color: #1565c0;"><?php _e('Connected', 'chatbot-plugin'); ?></strong>
                                                            <span style="color: #666; margin-left: 10px;">@<?php echo esc_html($telegram_bot_info['username']); ?></span>
                                                            <span style="background: #fff3e0; color: #e65100; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 8px;"><?php _e('Polling Mode', 'chatbot-plugin'); ?></span>
                                                        </div>
                                                    </div>
                                                    <p style="margin: 10px 0 0 0; color: #555; font-size: 13px;">
                                                        <?php _e('Click "Poll Now" to check for new Telegram messages. This is for local testing only.', 'chatbot-plugin'); ?>
                                                    </p>
                                                </div>
                                            <?php else: ?>
                                                <!-- Webhook mode (production) -->
                                                <div class="telegram-status connected" style="background: #e8f5e9; padding: 15px; border-radius: 4px; margin-bottom: 10px;">
                                                    <div style="display: flex; align-items: center; gap: 10px;">
                                                        <span style="color: #2e7d32; font-size: 20px;">&#x2713;</span>
                                                        <div>
                                                            <strong style="color: #2e7d32;"><?php _e('Connected', 'chatbot-plugin'); ?></strong>
                                                            <span style="color: #666; margin-left: 10px;">@<?php echo esc_html($telegram_bot_info['username']); ?></span>
                                                            <span style="background: #e3f2fd; color: #1565c0; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 8px;"><?php _e('Webhook Active', 'chatbot-plugin'); ?></span>
                                                        </div>
                                                    </div>
                                                    <p style="margin: 10px 0 0 0; color: #555; font-size: 13px;">
                                                        <?php _e('Telegram will instantly send messages to your site. Users can message this bot on Telegram.', 'chatbot-plugin'); ?>
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                            <input type="hidden" name="chatbot_telegram_bot_token" id="chatbot_telegram_bot_token" value="<?php echo esc_attr($telegram_bot_token); ?>">
                                            <?php if ($polling_mode): ?>
                                            <button type="button" id="chatbot_telegram_poll" class="button button-secondary" data-config-id="<?php echo esc_attr($editing ? $config->id : 0); ?>" data-nonce="<?php echo wp_create_nonce('chatbot_telegram_poll'); ?>">
                                                <?php _e('Start Auto-Poll', 'chatbot-plugin'); ?>
                                            </button>
                                            <?php endif; ?>
                                            <button type="button" id="chatbot_telegram_disconnect" class="button" data-config-id="<?php echo esc_attr($editing ? $config->id : 0); ?>" data-nonce="<?php echo wp_create_nonce('chatbot_telegram_disconnect'); ?>" style="color: #d32f2f;">
                                                <?php _e('Disconnect Telegram', 'chatbot-plugin'); ?>
                                            </button>
                                            <span id="chatbot_telegram_poll_status" style="margin-left: 10px;"></span>
                                        <?php else: ?>
                                            <!-- Disconnected state -->
                                            <div class="telegram-input-group" style="margin-bottom: 10px;">
                                                <label for="chatbot_telegram_bot_token_input" style="display: block; margin-bottom: 5px;">
                                                    <?php _e('Bot Token', 'chatbot-plugin'); ?>
                                                </label>
                                                <input type="text" id="chatbot_telegram_bot_token_input" class="regular-text" placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz" style="width: 100%;">
                                                <input type="hidden" name="chatbot_telegram_bot_token" id="chatbot_telegram_bot_token" value="">
                                            </div>
                                            <?php if ($editing): ?>
                                            <button type="button" id="chatbot_telegram_connect" class="button button-primary" data-config-id="<?php echo esc_attr($config->id); ?>" data-nonce="<?php echo wp_create_nonce('chatbot_telegram_connect'); ?>">
                                                <?php _e('Connect Telegram Bot', 'chatbot-plugin'); ?>
                                            </button>
                                            <button type="button" id="chatbot_telegram_test" class="button" data-nonce="<?php echo wp_create_nonce('chatbot_telegram_test'); ?>">
                                                <?php _e('Test Token', 'chatbot-plugin'); ?>
                                            </button>
                                            <span id="chatbot_telegram_status" style="margin-left: 10px;"></span>
                                            <?php else: ?>
                                            <p class="description" style="color: #666;">
                                                <?php _e('Save the chatbot first, then you can connect Telegram.', 'chatbot-plugin'); ?>
                                            </p>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                <p class="description">
                                    <?php _e('Connect a Telegram bot to allow users to chat via Telegram.', 'chatbot-plugin'); ?>
                                    <a href="https://t.me/BotFather" target="_blank"><?php _e('Get your bot token from @BotFather', 'chatbot-plugin'); ?></a>
                                </p>

                                <!-- Telegram connection JavaScript -->
                                    <script type="text/javascript">
                                    (function($) {
                                        $(document).ready(function() {
                                            // Test token button
                                            $('#chatbot_telegram_test').on('click', function() {
                                                var token = $('#chatbot_telegram_bot_token_input').val();
                                                var status = $('#chatbot_telegram_status');

                                                if (!token) {
                                                    status.html('<span style="color: red;"><?php _e('Please enter a bot token', 'chatbot-plugin'); ?></span>');
                                                    return;
                                                }

                                                status.html('<span><?php _e('Testing...', 'chatbot-plugin'); ?></span>');

                                                $.ajax({
                                                    url: ajaxurl,
                                                    type: 'POST',
                                                    data: {
                                                        action: 'chatbot_telegram_test',
                                                        bot_token: token,
                                                        nonce: $(this).data('nonce')
                                                    },
                                                    success: function(response) {
                                                        if (response.success) {
                                                            status.html('<span style="color: green;"><?php _e('Valid!', 'chatbot-plugin'); ?> @' + response.data.bot_info.username + '</span>');
                                                        } else {
                                                            status.html('<span style="color: red;">' + (response.data.message || '<?php _e('Invalid token', 'chatbot-plugin'); ?>') + '</span>');
                                                        }
                                                    },
                                                    error: function() {
                                                        status.html('<span style="color: red;"><?php _e('Connection error', 'chatbot-plugin'); ?></span>');
                                                    }
                                                });
                                            });

                                            // Connect button
                                            $('#chatbot_telegram_connect').on('click', function() {
                                                var token = $('#chatbot_telegram_bot_token_input').val();
                                                var configId = $(this).data('config-id');
                                                var status = $('#chatbot_telegram_status');
                                                var button = $(this);

                                                if (!token) {
                                                    status.html('<span style="color: red;"><?php _e('Please enter a bot token', 'chatbot-plugin'); ?></span>');
                                                    return;
                                                }

                                                button.prop('disabled', true);
                                                status.html('<span><?php _e('Connecting...', 'chatbot-plugin'); ?></span>');

                                                $.ajax({
                                                    url: ajaxurl,
                                                    type: 'POST',
                                                    data: {
                                                        action: 'chatbot_telegram_connect',
                                                        config_id: configId,
                                                        bot_token: token,
                                                        nonce: $(this).data('nonce')
                                                    },
                                                    success: function(response) {
                                                        if (response.success) {
                                                            status.html('<span style="color: green;"><?php _e('Connected! Reloading...', 'chatbot-plugin'); ?></span>');
                                                            setTimeout(function() {
                                                                window.location.reload();
                                                            }, 1000);
                                                        } else {
                                                            status.html('<span style="color: red;">' + (response.data.message || '<?php _e('Connection failed', 'chatbot-plugin'); ?>') + '</span>');
                                                            button.prop('disabled', false);
                                                        }
                                                    },
                                                    error: function() {
                                                        status.html('<span style="color: red;"><?php _e('Connection error', 'chatbot-plugin'); ?></span>');
                                                        button.prop('disabled', false);
                                                    }
                                                });
                                            });

                                            // Disconnect button
                                            $('#chatbot_telegram_disconnect').on('click', function() {
                                                if (!confirm('<?php _e('Are you sure you want to disconnect this Telegram bot?', 'chatbot-plugin'); ?>')) {
                                                    return;
                                                }

                                                var configId = $(this).data('config-id');
                                                var button = $(this);

                                                button.prop('disabled', true).text('<?php _e('Disconnecting...', 'chatbot-plugin'); ?>');

                                                $.ajax({
                                                    url: ajaxurl,
                                                    type: 'POST',
                                                    data: {
                                                        action: 'chatbot_telegram_disconnect',
                                                        config_id: configId,
                                                        nonce: $(this).data('nonce')
                                                    },
                                                    success: function(response) {
                                                        if (response.success) {
                                                            window.location.reload();
                                                        } else {
                                                            alert(response.data.message || '<?php _e('Disconnect failed', 'chatbot-plugin'); ?>');
                                                            button.prop('disabled', false).text('<?php _e('Disconnect Telegram', 'chatbot-plugin'); ?>');
                                                        }
                                                    },
                                                    error: function() {
                                                        alert('<?php _e('Connection error', 'chatbot-plugin'); ?>');
                                                        button.prop('disabled', false).text('<?php _e('Disconnect Telegram', 'chatbot-plugin'); ?>');
                                                    }
                                                });
                                            });

                                            // Auto-polling for local development
                                            var pollingInterval = null;
                                            var isPolling = false;
                                            var totalProcessed = 0;

                                            function doPoll() {
                                                var configId = $('#chatbot_telegram_poll').data('config-id');
                                                var nonce = $('#chatbot_telegram_poll').data('nonce');
                                                var status = $('#chatbot_telegram_poll_status');

                                                $.ajax({
                                                    url: ajaxurl,
                                                    type: 'POST',
                                                    data: {
                                                        action: 'chatbot_telegram_poll',
                                                        config_id: configId,
                                                        nonce: nonce
                                                    },
                                                    success: function(response) {
                                                        if (response.success && response.data.messages_processed > 0) {
                                                            totalProcessed += response.data.messages_processed;
                                                            status.html('<span style="color: green;">&#x2714; ' + totalProcessed + ' <?php _e('message(s) processed', 'chatbot-plugin'); ?></span>');
                                                        }
                                                    },
                                                    error: function() {
                                                        // Silent fail, will retry on next poll
                                                    }
                                                });
                                            }

                                            // Start/Stop auto-polling button
                                            $('#chatbot_telegram_poll').on('click', function() {
                                                var button = $(this);
                                                var status = $('#chatbot_telegram_poll_status');

                                                if (isPolling) {
                                                    // Stop polling
                                                    clearInterval(pollingInterval);
                                                    pollingInterval = null;
                                                    isPolling = false;
                                                    button.text('<?php _e('Start Auto-Poll', 'chatbot-plugin'); ?>').removeClass('button-primary').addClass('button-secondary');
                                                    status.html('<span style="color: #666;"><?php _e('Polling stopped', 'chatbot-plugin'); ?></span>');
                                                } else {
                                                    // Start polling
                                                    isPolling = true;
                                                    totalProcessed = 0;
                                                    button.text('<?php _e('Stop Polling', 'chatbot-plugin'); ?>').removeClass('button-secondary').addClass('button-primary');
                                                    status.html('<span style="color: #1565c0;"><?php _e('Polling every 2 seconds...', 'chatbot-plugin'); ?></span>');

                                                    // Poll immediately, then every 2 seconds
                                                    doPoll();
                                                    pollingInterval = setInterval(doPoll, 2000);
                                                }
                                            });

                                        });
                                    })(jQuery);
                                    </script>
                            </div>
                            <!-- End Telegram Section -->

                            <!-- WhatsApp Section -->
                            <div class="integration-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
                                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                                    <span style="background: #25D366; color: #fff; padding: 8px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;">
                                        <span class="dashicons dashicons-smartphone" style="font-size: 20px; width: 20px; height: 20px;"></span>
                                    </span>
                                    <?php _e('WhatsApp', 'chatbot-plugin'); ?>
                                </h3>
                                <?php
                                // Get WhatsApp connection status
                                $whatsapp_connected = false;
                                $whatsapp_info = array();
                                if ($editing && class_exists('Chatbot_Messaging_Manager')) {
                                    $manager = Chatbot_Messaging_Manager::get_instance();
                                    $whatsapp = $manager->get_platform('whatsapp');
                                    if ($whatsapp) {
                                        $whatsapp_connected = $whatsapp->is_connected($config->id);
                                        if ($whatsapp_connected) {
                                            $whatsapp_info = $whatsapp->get_stored_credentials($config->id);
                                        }
                                    }
                                }
                                ?>
                                <div class="chatbot-whatsapp-wrapper">
                                        <?php if ($whatsapp_connected && !empty($whatsapp_info['phone_info'])): ?>
                                            <!-- Connected state -->
                                            <div class="whatsapp-status connected" style="background: #e8f5e9; padding: 15px; border-radius: 4px; margin-bottom: 10px;">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <span style="color: #2e7d32; font-size: 20px;">&#x2713;</span>
                                                    <div>
                                                        <strong style="color: #2e7d32;"><?php _e('Connected', 'chatbot-plugin'); ?></strong>
                                                        <span style="color: #666; margin-left: 10px;">
                                                            <?php echo esc_html($whatsapp_info['phone_info']['display_phone_number'] ?? ''); ?>
                                                            <?php if (!empty($whatsapp_info['phone_info']['verified_name'])): ?>
                                                                (<?php echo esc_html($whatsapp_info['phone_info']['verified_name']); ?>)
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <p style="margin: 10px 0 0 0; color: #555; font-size: 13px;">
                                                    <?php _e('WhatsApp Cloud API is connected. Users can message your WhatsApp Business number.', 'chatbot-plugin'); ?>
                                                </p>
                                            </div>
                                            <button type="button" id="chatbot_whatsapp_send_test" class="button button-primary" data-config-id="<?php echo esc_attr($config->id); ?>" data-nonce="<?php echo wp_create_nonce('chatbot_whatsapp_send_test'); ?>">
                                                <?php _e('Send Test Message', 'chatbot-plugin'); ?>
                                            </button>
                                            <button type="button" id="chatbot_whatsapp_disconnect" class="button" data-config-id="<?php echo esc_attr($config->id); ?>" data-nonce="<?php echo wp_create_nonce('chatbot_whatsapp_disconnect'); ?>" style="color: #d32f2f; margin-left: 5px;">
                                                <?php _e('Disconnect', 'chatbot-plugin'); ?>
                                            </button>
                                            <span id="chatbot_whatsapp_status" style="margin-left: 10px;"></span>

                                            <!-- Test Message Form (hidden by default) -->
                                            <div id="whatsapp_test_form" style="display: none; margin-top: 15px; background: #f0f6fc; border: 1px solid #c5d9ed; padding: 15px; border-radius: 4px;">
                                                <h4 style="margin: 0 0 10px 0; color: #1d2327;"><?php _e('Send Test Message', 'chatbot-plugin'); ?></h4>
                                                <div style="margin-bottom: 10px;">
                                                    <label for="whatsapp_test_number" style="display: block; margin-bottom: 5px; font-weight: 500;"><?php _e('Recipient Phone Number', 'chatbot-plugin'); ?></label>
                                                    <input type="text" id="whatsapp_test_number" class="regular-text" placeholder="+1234567890" style="width: 100%; max-width: 300px;">
                                                    <p class="description" style="margin-top: 5px;"><?php _e('Include country code (e.g., +1 for US). Must be a number that has messaged your WhatsApp Business first.', 'chatbot-plugin'); ?></p>
                                                </div>
                                                <div style="margin-bottom: 10px;">
                                                    <label for="whatsapp_test_message" style="display: block; margin-bottom: 5px; font-weight: 500;"><?php _e('Message', 'chatbot-plugin'); ?></label>
                                                    <input type="text" id="whatsapp_test_message" class="regular-text" value="Hello! This is a test message from your WordPress chatbot." style="width: 100%;">
                                                </div>
                                                <button type="button" id="chatbot_whatsapp_send_test_submit" class="button button-primary">
                                                    <?php _e('Send Message', 'chatbot-plugin'); ?>
                                                </button>
                                                <button type="button" id="chatbot_whatsapp_send_test_cancel" class="button">
                                                    <?php _e('Cancel', 'chatbot-plugin'); ?>
                                                </button>
                                                <span id="whatsapp_test_status" style="margin-left: 10px;"></span>
                                            </div>
                                        <?php elseif ($editing): ?>
                                            <!-- Disconnected state - show connection form -->
                                            <div style="margin-bottom: 15px;">
                                                <button type="button" id="chatbot_whatsapp_help_toggle" class="button button-small" style="display: flex; align-items: center; gap: 5px;">
                                                    <span class="dashicons dashicons-editor-help" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                                    <?php _e('How to get credentials', 'chatbot-plugin'); ?>
                                                    <span class="dashicons dashicons-arrow-down-alt2" id="whatsapp_help_arrow" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                                </button>
                                                <div id="chatbot_whatsapp_help_content" style="display: none; background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 4px; padding: 15px; margin-top: 10px;">
                                                    <h4 style="margin: 0 0 12px 0; color: #1d2327;"><?php _e('Where to get your credentials', 'chatbot-plugin'); ?></h4>
                                                    <ol style="margin: 0 0 15px 20px; padding: 0; color: #50575e;">
                                                        <li style="margin-bottom: 8px;"><?php _e('Go to', 'chatbot-plugin'); ?> <a href="https://developers.facebook.com/" target="_blank">Meta for Developers</a></li>
                                                        <li style="margin-bottom: 8px;"><?php _e('Create or select your App → Add WhatsApp product', 'chatbot-plugin'); ?></li>
                                                        <li style="margin-bottom: 8px;"><?php _e('In <strong>App Settings → Basic</strong>:', 'chatbot-plugin'); ?>
                                                            <ul style="margin: 8px 0 0 20px; list-style-type: disc;">
                                                                <li><strong><?php _e('App ID', 'chatbot-plugin'); ?></strong>: <?php _e('Shown at the top of the page', 'chatbot-plugin'); ?></li>
                                                                <li><strong><?php _e('App Secret', 'chatbot-plugin'); ?></strong>: <?php _e('Click "Show" to reveal', 'chatbot-plugin'); ?></li>
                                                            </ul>
                                                        </li>
                                                        <li style="margin-bottom: 8px;"><?php _e('In <strong>WhatsApp → API Setup</strong>:', 'chatbot-plugin'); ?>
                                                            <ul style="margin: 8px 0 0 20px; list-style-type: disc;">
                                                                <li><strong><?php _e('Business Account ID', 'chatbot-plugin'); ?></strong>: <?php _e('Shown at the top (also called WABA ID)', 'chatbot-plugin'); ?></li>
                                                                <li><strong><?php _e('Phone Number ID', 'chatbot-plugin'); ?></strong>: <?php _e('Shown under "From" phone number', 'chatbot-plugin'); ?></li>
                                                                <li><strong><?php _e('Access Token', 'chatbot-plugin'); ?></strong>: <?php _e('Click "Generate" for a temporary token or use System User token', 'chatbot-plugin'); ?></li>
                                                            </ul>
                                                        </li>
                                                    </ol>
                                                    <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 10px 12px; margin-top: 12px;">
                                                        <strong style="color: #155724;"><?php _e('Automatic Webhook Setup:', 'chatbot-plugin'); ?></strong>
                                                        <p style="margin: 8px 0 0 0; font-size: 13px; color: #155724;"><?php _e('The plugin will automatically configure webhooks via the Graph API. No manual webhook setup required!', 'chatbot-plugin'); ?></p>
                                                    </div>
                                                    <div style="background: #fff8e5; border-left: 4px solid #ffb900; padding: 10px 12px; margin-top: 12px;">
                                                        <strong style="color: #6d5200;"><?php _e('For production use:', 'chatbot-plugin'); ?></strong>
                                                        <ul style="margin: 8px 0 0 20px; padding: 0; color: #6d5200; list-style-type: disc; font-size: 13px;">
                                                            <li><?php _e('A verified Meta Business Account', 'chatbot-plugin'); ?></li>
                                                            <li><?php _e('A registered WhatsApp Business phone number', 'chatbot-plugin'); ?></li>
                                                            <li><?php _e('Your WordPress site must be accessible via HTTPS', 'chatbot-plugin'); ?></li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php
                                            // Only show tunnel URL field for local/dev environments
                                            $site_url = site_url();
                                            $is_local_dev = (
                                                strpos($site_url, 'localhost') !== false ||
                                                strpos($site_url, '127.0.0.1') !== false ||
                                                strpos($site_url, '.local') !== false ||
                                                strpos($site_url, '.ddev.site') !== false ||
                                                strpos($site_url, '.test') !== false ||
                                                strpos($site_url, '.dev') !== false ||
                                                strpos($site_url, 'http://') === 0
                                            );
                                            if ($is_local_dev):
                                            ?>
                                            <!-- Public Webhook URL (Optional) - Only shown for local dev -->
                                            <div class="whatsapp-input-group" style="margin-bottom: 10px; padding: 10px; background: #fff3cd; border-radius: 5px; border: 1px dashed #ffc107;">
                                                <label for="chatbot_whatsapp_webhook_url_input" style="display: block; margin-bottom: 5px;">
                                                    <strong><?php _e('Public Webhook URL (Required for Local Dev)', 'chatbot-plugin'); ?></strong>
                                                </label>
                                                <input type="text" id="chatbot_whatsapp_webhook_url_input" class="regular-text" placeholder="https://your-tunnel-url.trycloudflare.com" style="width: 100%;">
                                                <p class="description" style="margin-top: 3px; font-size: 12px;"><?php _e('Your site appears to be local. Enter a tunnel URL (ngrok, cloudflared) so Meta can reach your webhook.', 'chatbot-plugin'); ?></p>
                                            </div>
                                            <?php else: ?>
                                            <!-- Hidden input for production - will be empty and use site URL automatically -->
                                            <input type="hidden" id="chatbot_whatsapp_webhook_url_input" value="">
                                            <?php endif; ?>
                                            <!-- App ID -->
                                            <div class="whatsapp-input-group" style="margin-bottom: 10px;">
                                                <label for="chatbot_whatsapp_app_id_input" style="display: block; margin-bottom: 5px;">
                                                    <strong><?php _e('App ID', 'chatbot-plugin'); ?></strong>
                                                </label>
                                                <input type="text" id="chatbot_whatsapp_app_id_input" class="regular-text" placeholder="123456789012345" style="width: 100%;">
                                                <p class="description" style="margin-top: 3px; font-size: 12px;"><?php _e('From App Settings → Basic', 'chatbot-plugin'); ?></p>
                                            </div>
                                            <!-- App Secret -->
                                            <div class="whatsapp-input-group" style="margin-bottom: 10px;">
                                                <label for="chatbot_whatsapp_app_secret_input" style="display: block; margin-bottom: 5px;">
                                                    <strong><?php _e('App Secret', 'chatbot-plugin'); ?></strong>
                                                </label>
                                                <input type="password" id="chatbot_whatsapp_app_secret_input" class="regular-text" placeholder="abc123def456..." style="width: 100%;">
                                                <button type="button" class="button button-small" onclick="var f=document.getElementById('chatbot_whatsapp_app_secret_input');f.type=f.type==='password'?'text':'password';" style="margin-top: 5px;">
                                                    <?php _e('Show/Hide', 'chatbot-plugin'); ?>
                                                </button>
                                                <p class="description" style="margin-top: 3px; font-size: 12px;"><?php _e('From App Settings → Basic (click "Show")', 'chatbot-plugin'); ?></p>
                                            </div>
                                            <!-- Business Account ID -->
                                            <div class="whatsapp-input-group" style="margin-bottom: 10px;">
                                                <label for="chatbot_whatsapp_waba_id_input" style="display: block; margin-bottom: 5px;">
                                                    <strong><?php _e('Business Account ID (WABA ID)', 'chatbot-plugin'); ?></strong>
                                                </label>
                                                <input type="text" id="chatbot_whatsapp_waba_id_input" class="regular-text" placeholder="123456789012345" style="width: 100%;">
                                                <p class="description" style="margin-top: 3px; font-size: 12px;"><?php _e('From WhatsApp → API Setup (shown at top)', 'chatbot-plugin'); ?></p>
                                            </div>
                                            <!-- Phone Number ID -->
                                            <div class="whatsapp-input-group" style="margin-bottom: 10px;">
                                                <label for="chatbot_whatsapp_phone_id_input" style="display: block; margin-bottom: 5px;">
                                                    <strong><?php _e('Phone Number ID', 'chatbot-plugin'); ?></strong>
                                                </label>
                                                <input type="text" id="chatbot_whatsapp_phone_id_input" class="regular-text" placeholder="123456789012345" style="width: 100%;">
                                                <p class="description" style="margin-top: 3px; font-size: 12px;"><?php _e('From WhatsApp → API Setup (under "From" number)', 'chatbot-plugin'); ?></p>
                                            </div>
                                            <!-- Access Token -->
                                            <div class="whatsapp-input-group" style="margin-bottom: 10px;">
                                                <label for="chatbot_whatsapp_token_input" style="display: block; margin-bottom: 5px;">
                                                    <strong><?php _e('Access Token', 'chatbot-plugin'); ?></strong>
                                                </label>
                                                <input type="password" id="chatbot_whatsapp_token_input" class="regular-text" placeholder="EAAxxxxxxx..." style="width: 100%;">
                                                <button type="button" class="button button-small" onclick="var f=document.getElementById('chatbot_whatsapp_token_input');f.type=f.type==='password'?'text':'password';" style="margin-top: 5px;">
                                                    <?php _e('Show/Hide', 'chatbot-plugin'); ?>
                                                </button>
                                                <p class="description" style="margin-top: 3px; font-size: 12px;"><?php _e('Temporary (24h) or permanent System User token', 'chatbot-plugin'); ?></p>
                                            </div>
                                            <button type="button" id="chatbot_whatsapp_connect" class="button button-primary" data-config-id="<?php echo esc_attr($config->id); ?>" data-nonce="<?php echo wp_create_nonce('chatbot_whatsapp_connect'); ?>">
                                                <?php _e('Connect WhatsApp', 'chatbot-plugin'); ?>
                                            </button>
                                            <button type="button" id="chatbot_whatsapp_test" class="button" data-nonce="<?php echo wp_create_nonce('chatbot_whatsapp_test'); ?>">
                                                <?php _e('Test Credentials', 'chatbot-plugin'); ?>
                                            </button>
                                            <span id="chatbot_whatsapp_status" style="margin-left: 10px;"></span>
                                        <?php else: ?>
                                            <p class="description" style="color: #666;">
                                                <?php _e('Save the chatbot first, then you can connect WhatsApp.', 'chatbot-plugin'); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <p class="description" style="margin-top: 10px;">
                                        <?php _e('Connect WhatsApp Cloud API to allow users to chat via WhatsApp. Requires', 'chatbot-plugin'); ?>
                                        <a href="https://business.facebook.com/" target="_blank"><?php _e('Meta Business Account', 'chatbot-plugin'); ?></a>.
                                    </p>

                                    <!-- WhatsApp connection JavaScript -->
                                    <script type="text/javascript">
                                    (function($) {
                                        $(document).ready(function() {
                                            // Show/hide help content
                                            $('#chatbot_whatsapp_help_toggle').on('click', function() {
                                                var content = $('#chatbot_whatsapp_help_content');
                                                var arrow = $('#whatsapp_help_arrow');
                                                content.slideToggle(200, function() {
                                                    if (content.is(':visible')) {
                                                        arrow.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                                                    } else {
                                                        arrow.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                                                    }
                                                });
                                            });

                                            // Show/hide test message form
                                            $('#chatbot_whatsapp_send_test').on('click', function() {
                                                $('#whatsapp_test_form').slideToggle();
                                            });

                                            $('#chatbot_whatsapp_send_test_cancel').on('click', function() {
                                                $('#whatsapp_test_form').slideUp();
                                                $('#whatsapp_test_status').html('');
                                            });

                                            // Send test message
                                            $('#chatbot_whatsapp_send_test_submit').on('click', function() {
                                                var phoneNumber = $('#whatsapp_test_number').val().replace(/\s+/g, '').replace(/^\+/, '');
                                                var message = $('#whatsapp_test_message').val();
                                                var configId = $('#chatbot_whatsapp_send_test').data('config-id');
                                                var nonce = $('#chatbot_whatsapp_send_test').data('nonce');
                                                var status = $('#whatsapp_test_status');
                                                var button = $(this);

                                                if (!phoneNumber) {
                                                    status.html('<span style="color: red;"><?php _e('Please enter a phone number', 'chatbot-plugin'); ?></span>');
                                                    return;
                                                }

                                                if (!message) {
                                                    status.html('<span style="color: red;"><?php _e('Please enter a message', 'chatbot-plugin'); ?></span>');
                                                    return;
                                                }

                                                button.prop('disabled', true);
                                                status.html('<span><?php _e('Sending...', 'chatbot-plugin'); ?></span>');

                                                $.ajax({
                                                    url: ajaxurl,
                                                    type: 'POST',
                                                    data: {
                                                        action: 'chatbot_whatsapp_send_test',
                                                        config_id: configId,
                                                        phone_number: phoneNumber,
                                                        message: message,
                                                        nonce: nonce
                                                    },
                                                    success: function(response) {
                                                        if (response.success) {
                                                            status.html('<span style="color: green;"><?php _e('Message sent successfully!', 'chatbot-plugin'); ?></span>');
                                                        } else {
                                                            status.html('<span style="color: red;">' + (response.data.message || '<?php _e('Failed to send message', 'chatbot-plugin'); ?>') + '</span>');
                                                        }
                                                        button.prop('disabled', false);
                                                    },
                                                    error: function() {
                                                        status.html('<span style="color: red;"><?php _e('Connection error', 'chatbot-plugin'); ?></span>');
                                                        button.prop('disabled', false);
                                                    }
                                                });
                                            });

                                            // Test credentials button
                                            $('#chatbot_whatsapp_test').on('click', function() {
                                                var phoneId = $('#chatbot_whatsapp_phone_id_input').val();
                                                var token = $('#chatbot_whatsapp_token_input').val();
                                                var status = $('#chatbot_whatsapp_status');

                                                if (!phoneId || !token) {
                                                    status.html('<span style="color: red;"><?php _e('Please enter Phone Number ID and Access Token', 'chatbot-plugin'); ?></span>');
                                                    return;
                                                }

                                                status.html('<span><?php _e('Testing...', 'chatbot-plugin'); ?></span>');

                                                $.ajax({
                                                    url: ajaxurl,
                                                    type: 'POST',
                                                    data: {
                                                        action: 'chatbot_platform_test',
                                                        platform: 'whatsapp',
                                                        credentials: {
                                                            phone_number_id: phoneId,
                                                            access_token: token
                                                        },
                                                        nonce: $(this).data('nonce')
                                                    },
                                                    success: function(response) {
                                                        if (response.success) {
                                                            var info = response.data.info;
                                                            status.html('<span style="color: green;"><?php _e('Valid!', 'chatbot-plugin'); ?> ' + (info.display_phone_number || '') + '</span>');
                                                        } else {
                                                            status.html('<span style="color: red;">' + (response.data.message || '<?php _e('Invalid credentials', 'chatbot-plugin'); ?>') + '</span>');
                                                        }
                                                    },
                                                    error: function() {
                                                        status.html('<span style="color: red;"><?php _e('Connection error', 'chatbot-plugin'); ?></span>');
                                                    }
                                                });
                                            });

                                            // Connect button
                                            $('#chatbot_whatsapp_connect').on('click', function() {
                                                var webhookBaseUrl = $('#chatbot_whatsapp_webhook_url_input').val();
                                                var appId = $('#chatbot_whatsapp_app_id_input').val();
                                                var appSecret = $('#chatbot_whatsapp_app_secret_input').val();
                                                var wabaId = $('#chatbot_whatsapp_waba_id_input').val();
                                                var phoneId = $('#chatbot_whatsapp_phone_id_input').val();
                                                var token = $('#chatbot_whatsapp_token_input').val();
                                                var configId = $(this).data('config-id');
                                                var status = $('#chatbot_whatsapp_status');
                                                var button = $(this);

                                                // Validate all required fields
                                                if (!appId || !appSecret) {
                                                    status.html('<span style="color: red;"><?php _e('Please enter App ID and App Secret', 'chatbot-plugin'); ?></span>');
                                                    return;
                                                }
                                                if (!wabaId) {
                                                    status.html('<span style="color: red;"><?php _e('Please enter Business Account ID (WABA ID)', 'chatbot-plugin'); ?></span>');
                                                    return;
                                                }
                                                if (!phoneId || !token) {
                                                    status.html('<span style="color: red;"><?php _e('Please enter Phone Number ID and Access Token', 'chatbot-plugin'); ?></span>');
                                                    return;
                                                }

                                                button.prop('disabled', true);
                                                status.html('<span><?php _e('Connecting and configuring webhooks...', 'chatbot-plugin'); ?></span>');

                                                $.ajax({
                                                    url: ajaxurl,
                                                    type: 'POST',
                                                    data: {
                                                        action: 'chatbot_platform_connect',
                                                        platform: 'whatsapp',
                                                        config_id: configId,
                                                        credentials: {
                                                            webhook_base_url: webhookBaseUrl,
                                                            app_id: appId,
                                                            app_secret: appSecret,
                                                            business_account_id: wabaId,
                                                            phone_number_id: phoneId,
                                                            access_token: token
                                                        },
                                                        nonce: $(this).data('nonce')
                                                    },
                                                    success: function(response) {
                                                        if (response.success) {
                                                            var msg = response.data.message || '<?php _e('Connected!', 'chatbot-plugin'); ?>';
                                                            var color = response.data.webhook_configured ? 'green' : 'orange';
                                                            status.html('<span style="color: ' + color + ';">' + msg + '</span>');

                                                            // Show warning alert if webhooks not configured
                                                            if (response.data.webhook_warning) {
                                                                alert('<?php _e('WhatsApp Connected!', 'chatbot-plugin'); ?>\n\n' +
                                                                    '<?php _e('Warning:', 'chatbot-plugin'); ?> ' + response.data.webhook_warning + '\n\n' +
                                                                    '<?php _e('Webhook URL:', 'chatbot-plugin'); ?> ' + response.data.webhook_url + '\n' +
                                                                    '<?php _e('Verify Token:', 'chatbot-plugin'); ?> ' + response.data.verify_token);
                                                            }

                                                            setTimeout(function() {
                                                                window.location.reload();
                                                            }, 1500);
                                                        } else {
                                                            status.html('<span style="color: red;">' + (response.data.message || '<?php _e('Connection failed', 'chatbot-plugin'); ?>') + '</span>');
                                                            button.prop('disabled', false);
                                                        }
                                                    },
                                                    error: function() {
                                                        status.html('<span style="color: red;"><?php _e('Connection error', 'chatbot-plugin'); ?></span>');
                                                        button.prop('disabled', false);
                                                    }
                                                });
                                            });

                                            // Disconnect button
                                            $('#chatbot_whatsapp_disconnect').on('click', function() {
                                                if (!confirm('<?php _e('Are you sure you want to disconnect WhatsApp?', 'chatbot-plugin'); ?>')) {
                                                    return;
                                                }

                                                var configId = $(this).data('config-id');
                                                var button = $(this);

                                                button.prop('disabled', true).text('<?php _e('Disconnecting...', 'chatbot-plugin'); ?>');

                                                $.ajax({
                                                    url: ajaxurl,
                                                    type: 'POST',
                                                    data: {
                                                        action: 'chatbot_platform_disconnect',
                                                        platform: 'whatsapp',
                                                        config_id: configId,
                                                        nonce: $(this).data('nonce')
                                                    },
                                                    success: function(response) {
                                                        if (response.success) {
                                                            window.location.reload();
                                                        } else {
                                                            alert(response.data.message || '<?php _e('Disconnect failed', 'chatbot-plugin'); ?>');
                                                            button.prop('disabled', false).text('<?php _e('Disconnect WhatsApp', 'chatbot-plugin'); ?>');
                                                        }
                                                    },
                                                    error: function() {
                                                        alert('<?php _e('Connection error', 'chatbot-plugin'); ?>');
                                                        button.prop('disabled', false).text('<?php _e('Disconnect WhatsApp', 'chatbot-plugin'); ?>');
                                                    }
                                                });
                                            });
                                        });
                                    })(jQuery);
                                    </script>
                            </div>
                            <!-- End WhatsApp Section -->
                        </div>
                        <!-- End integrations-grid -->

                        <!-- n8n Workflow Automation Section (full width) -->
                        <div class="integration-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin-top: 20px;">
                            <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                                <span style="background: #ff6d5a; color: #fff; padding: 8px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;">
                                    <span class="dashicons dashicons-admin-generic" style="font-size: 20px; width: 20px; height: 20px;"></span>
                                </span>
                                <?php _e('n8n Workflow Automation', 'chatbot-plugin'); ?>
                            </h3>
                            <?php
                            // Get current n8n settings for this chatbot
                            $n8n_settings_json = $editing && isset($config->n8n_settings) ? $config->n8n_settings : '';
                            $n8n_settings = !empty($n8n_settings_json) ? json_decode($n8n_settings_json, true) : array();
                            $n8n_enabled = isset($n8n_settings['enabled']) ? (bool) $n8n_settings['enabled'] : false;
                            $n8n_webhook_url = isset($n8n_settings['webhook_url']) ? $n8n_settings['webhook_url'] : '';
                            $n8n_webhook_secret = isset($n8n_settings['webhook_secret']) ? $n8n_settings['webhook_secret'] : '';
                            $n8n_timeout = isset($n8n_settings['timeout']) ? (int) $n8n_settings['timeout'] : 300;
                            $n8n_actions = isset($n8n_settings['actions']) ? $n8n_settings['actions'] : array();
                            ?>

                            <p style="color: #666; margin-bottom: 15px;">
                                <?php _e('Connect to n8n or Zapier to enable AI-powered actions. The AI can execute actions like scheduling meetings, sending emails, or updating your CRM.', 'chatbot-plugin'); ?>
                            </p>

                            <div style="background: #e7f3ff; border-left: 4px solid #2196F3; padding: 12px; margin-bottom: 15px;">
                                <strong><?php _e('How it works:', 'chatbot-plugin'); ?></strong>
                                <span style="color: #555;"><?php _e('Define actions below. The AI decides when to call them during conversations, and n8n handles the integrations.', 'chatbot-plugin'); ?></span>
                            </div>

                            <!-- n8n Enable Checkbox -->
                            <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 15px;">
                                <input type="checkbox" name="chatbot_n8n_enabled" id="chatbot_n8n_enabled" value="1" <?php checked($n8n_enabled, true); ?>>
                                <strong><?php _e('Enable n8n Integration', 'chatbot-plugin'); ?></strong>
                            </label>

                            <!-- n8n Settings (shown only when enabled) -->
                            <div id="chatbot_n8n_config_container" style="<?php echo $n8n_enabled ? '' : 'display: none;'; ?>">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                                <div>
                                    <label for="chatbot_n8n_webhook_url" style="display: block; margin-bottom: 5px; font-weight: 500;">
                                        <?php _e('Webhook URL', 'chatbot-plugin'); ?>
                                    </label>
                                    <input type="url" name="chatbot_n8n_webhook_url" id="chatbot_n8n_webhook_url" class="regular-text" style="width: 100%;" value="<?php echo esc_attr($n8n_webhook_url); ?>" placeholder="https://your-n8n.com/webhook/...">
                                    <p class="description"><?php _e('The webhook URL from your n8n workflow.', 'chatbot-plugin'); ?></p>
                                </div>
                                <div>
                                    <label for="chatbot_n8n_webhook_secret" style="display: block; margin-bottom: 5px; font-weight: 500; margin-top: 35px;">
                                        <?php _e('Webhook Secret (Optional)', 'chatbot-plugin'); ?>
                                    </label>
                                    <input type="password" name="chatbot_n8n_webhook_secret" id="chatbot_n8n_webhook_secret" class="regular-text" style="width: 100%;" value="<?php echo esc_attr($n8n_webhook_secret); ?>" placeholder="<?php _e('Optional security secret', 'chatbot-plugin'); ?>">
                                    <p class="description"><?php _e('If set, requests include HMAC signature for verification.', 'chatbot-plugin'); ?></p>
                                </div>
                            </div>

                            <!-- Custom Headers -->
                            <?php
                            $n8n_headers = isset($n8n_settings['headers']) ? $n8n_settings['headers'] : array();
                            ?>
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                                    <?php _e('Custom Headers (Optional)', 'chatbot-plugin'); ?>
                                </label>
                                <p class="description" style="margin-bottom: 10px;"><?php _e('Add custom headers to send with webhook requests (e.g., Authorization, API keys).', 'chatbot-plugin'); ?></p>

                                <input type="hidden" name="chatbot_n8n_headers" id="chatbot_n8n_headers" value="<?php echo esc_attr(wp_json_encode($n8n_headers)); ?>">

                                <div id="chatbot_n8n_headers_list" style="margin-bottom: 10px;">
                                    <!-- Headers rendered by JavaScript -->
                                </div>

                                <button type="button" id="chatbot_n8n_add_header" class="button button-small">
                                    <?php _e('+ Add Header', 'chatbot-plugin'); ?>
                                </button>
                            </div>

                            <!-- n8n Actions -->
                            <h4 style="margin: 20px 0 10px 0; border-top: 1px solid #ddd; padding-top: 20px;">
                                <?php _e('Configured Actions', 'chatbot-plugin'); ?>
                            </h4>
                            <p class="description" style="margin-bottom: 15px;">
                                <?php _e('Each action becomes a "tool" that the AI can call when appropriate during conversations.', 'chatbot-plugin'); ?>
                            </p>

                            <!-- Hidden input to store actions JSON -->
                            <input type="hidden" name="chatbot_n8n_actions" id="chatbot_n8n_actions" value="<?php echo esc_attr(wp_json_encode($n8n_actions)); ?>">

                            <div id="chatbot_n8n_actions_list" style="margin-bottom: 15px;">
                                <!-- Actions rendered by JavaScript -->
                            </div>

                            <button type="button" id="chatbot_n8n_add_action" class="button button-primary">
                                <?php _e('+ Add Action', 'chatbot-plugin'); ?>
                            </button>

                            <!-- Action Editor Modal -->
                            <div id="chatbot_n8n_action_modal" style="display: none; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin-top: 15px;">
                                <h4 id="chatbot_n8n_modal_title" style="margin-top: 0;"><?php _e('Add New Action', 'chatbot-plugin'); ?></h4>

                                <div style="margin-bottom: 15px;">
                                    <label for="chatbot_n8n_action_name" style="display: block; margin-bottom: 5px; font-weight: 500;">
                                        <?php _e('Action Name', 'chatbot-plugin'); ?>
                                    </label>
                                    <input type="text" id="chatbot_n8n_action_name" class="regular-text" placeholder="schedule_meeting">
                                    <p class="description"><?php _e('Unique identifier (lowercase, underscores allowed).', 'chatbot-plugin'); ?></p>
                                </div>

                                <div style="margin-bottom: 15px;">
                                    <label for="chatbot_n8n_action_description" style="display: block; margin-bottom: 5px; font-weight: 500;">
                                        <?php _e('Description', 'chatbot-plugin'); ?>
                                    </label>
                                    <textarea id="chatbot_n8n_action_description" class="large-text" rows="2" placeholder="<?php _e('Schedule a meeting with a customer. Use this when a user wants to book an appointment.', 'chatbot-plugin'); ?>"></textarea>
                                    <p class="description"><?php _e('The AI uses this to decide when to call this action.', 'chatbot-plugin'); ?></p>
                                </div>

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                                        <?php _e('Parameters', 'chatbot-plugin'); ?>
                                    </label>
                                    <div id="chatbot_n8n_action_params">
                                        <!-- Parameters added by JavaScript -->
                                    </div>
                                    <button type="button" id="chatbot_n8n_add_param" class="button button-small">
                                        <?php _e('+ Add Parameter', 'chatbot-plugin'); ?>
                                    </button>
                                </div>

                                <p>
                                    <button type="button" id="chatbot_n8n_save_action" class="button button-primary"><?php _e('Save Action', 'chatbot-plugin'); ?></button>
                                    <button type="button" id="chatbot_n8n_cancel_action" class="button"><?php _e('Cancel', 'chatbot-plugin'); ?></button>
                                </p>
                            </div>

                            <!-- Test Action Modal -->
                            <div id="chatbot_n8n_test_modal" style="display: none; background: #f0f7ff; border: 1px solid #2271b1; border-radius: 4px; padding: 20px; margin-top: 15px;">
                                <h4 style="margin-top: 0; color: #2271b1;">
                                    <?php _e('Test Action:', 'chatbot-plugin'); ?> <span id="chatbot_n8n_test_action_name"></span>
                                </h4>
                                <p class="description" style="margin-bottom: 15px;"><?php _e('Enter values for each parameter to test this action.', 'chatbot-plugin'); ?></p>

                                <div id="chatbot_n8n_test_params" style="margin-bottom: 15px;">
                                    <!-- Test parameter inputs rendered by JavaScript -->
                                </div>

                                <div id="chatbot_n8n_test_modal_result" style="display: none; margin-bottom: 15px; padding: 10px; border-radius: 4px;">
                                    <!-- Test result shown here -->
                                </div>

                                <p>
                                    <button type="button" id="chatbot_n8n_run_test" class="button button-primary"><?php _e('Run Test', 'chatbot-plugin'); ?></button>
                                    <button type="button" id="chatbot_n8n_cancel_test" class="button"><?php _e('Close', 'chatbot-plugin'); ?></button>
                                </p>
                            </div>

                            <!-- Timeout and Save/Test Buttons -->
                            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; display: flex; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <label for="chatbot_n8n_timeout" style="font-weight: 500;">
                                    <?php _e('Timeout (seconds):', 'chatbot-plugin'); ?>
                                </label>
                                <input type="number" name="chatbot_n8n_timeout" id="chatbot_n8n_timeout" class="small-text" value="<?php echo esc_attr($n8n_timeout); ?>" min="5" max="300">

                                <?php if ($editing): ?>
                                <button type="button" id="chatbot_n8n_test_connection" class="button button-secondary" style="margin-left: 10px;" data-nonce="<?php echo wp_create_nonce('chatbot_n8n_test'); ?>">
                                    <?php _e('Test Connection', 'chatbot-plugin'); ?>
                                </button>
                                <button type="button" id="chatbot_n8n_save_settings" class="button button-primary" data-chatbot-id="<?php echo esc_attr($config->id); ?>" data-nonce="<?php echo wp_create_nonce('chatbot_n8n_save'); ?>">
                                    <?php _e('Save Webhook Settings', 'chatbot-plugin'); ?>
                                </button>
                                <span id="chatbot_n8n_test_result"></span>
                                <?php endif; ?>
                            </div>
                            </div><!-- End chatbot_n8n_config_container -->

                            <!-- n8n JavaScript -->
                            <script type="text/javascript">
                            (function($) {
                                $(document).ready(function() {
                                    var currentActions = <?php echo wp_json_encode($n8n_actions); ?>;
                                    var currentHeaders = <?php echo wp_json_encode($n8n_headers); ?>;
                                    var editingIndex = -1;

                                    // Toggle n8n config visibility based on checkbox
                                    function toggleN8nConfig() {
                                        if ($('#chatbot_n8n_enabled').is(':checked')) {
                                            $('#chatbot_n8n_config_container').slideDown(200);
                                        } else {
                                            $('#chatbot_n8n_config_container').slideUp(200);
                                        }
                                    }

                                    // Bind checkbox change event
                                    $('#chatbot_n8n_enabled').on('change', toggleN8nConfig);

                                    function escapeHtml(text) {
                                        var div = document.createElement('div');
                                        div.textContent = text || '';
                                        return div.innerHTML;
                                    }

                                    // ========== Headers Management ==========
                                    function renderHeaders() {
                                        var $list = $('#chatbot_n8n_headers_list');
                                        $list.empty();

                                        if (!currentHeaders || currentHeaders.length === 0) {
                                            $list.html('<p style="color: #888; font-style: italic; font-size: 12px;"><?php _e('No custom headers configured.', 'chatbot-plugin'); ?></p>');
                                            return;
                                        }

                                        currentHeaders.forEach(function(header, index) {
                                            var $row = $('<div style="display: flex; gap: 10px; align-items: center; margin-bottom: 8px; padding: 8px; background: #fff; border: 1px solid #e0e0e0; border-radius: 3px;">' +
                                                '<input type="text" class="n8n-header-name" placeholder="Header-Name" value="' + escapeHtml(header.name || '') + '" style="flex: 1;">' +
                                                '<input type="text" class="n8n-header-value" placeholder="Header Value" value="' + escapeHtml(header.value || '') + '" style="flex: 2;">' +
                                                '<button type="button" class="button button-small n8n-remove-header" data-index="' + index + '">&times;</button>' +
                                            '</div>');
                                            $list.append($row);
                                        });

                                        // Update hidden input
                                        $('#chatbot_n8n_headers').val(JSON.stringify(currentHeaders));
                                    }

                                    function updateHeadersFromUI() {
                                        currentHeaders = [];
                                        $('#chatbot_n8n_headers_list > div').each(function() {
                                            var $row = $(this);
                                            var name = $row.find('.n8n-header-name').val().trim();
                                            var value = $row.find('.n8n-header-value').val().trim();
                                            if (name) {
                                                currentHeaders.push({ name: name, value: value });
                                            }
                                        });
                                        $('#chatbot_n8n_headers').val(JSON.stringify(currentHeaders));
                                    }

                                    $('#chatbot_n8n_add_header').on('click', function() {
                                        currentHeaders = currentHeaders || [];
                                        currentHeaders.push({ name: '', value: '' });
                                        renderHeaders();
                                    });

                                    $(document).on('click', '.n8n-remove-header', function() {
                                        var index = $(this).data('index');
                                        currentHeaders.splice(index, 1);
                                        renderHeaders();
                                    });

                                    $(document).on('change blur', '.n8n-header-name, .n8n-header-value', function() {
                                        updateHeadersFromUI();
                                    });

                                    // Initial render of headers
                                    renderHeaders();

                                    // ========== Actions Management ==========
                                    function renderActions() {
                                        var $list = $('#chatbot_n8n_actions_list');
                                        $list.empty();

                                        if (!currentActions || currentActions.length === 0) {
                                            $list.html('<p style="color: #666; font-style: italic;"><?php _e('No actions configured yet.', 'chatbot-plugin'); ?></p>');
                                            return;
                                        }

                                        currentActions.forEach(function(action, index) {
                                            var paramCount = action.parameters ? action.parameters.length : 0;
                                            var paramText = paramCount === 1 ? '1 parameter' : paramCount + ' parameters';

                                            var $item = $('<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin-bottom: 8px;">' +
                                                '<div style="display: flex; justify-content: space-between; align-items: center;">' +
                                                    '<div>' +
                                                        '<strong style="color: #1d2327;">' + escapeHtml(action.name) + '</strong>' +
                                                        '<span style="color: #888; margin-left: 10px; font-size: 12px;">' + paramText + '</span>' +
                                                    '</div>' +
                                                    '<div>' +
                                                        '<button type="button" class="button button-small n8n-test-action" data-index="' + index + '" style="color: #2271b1;"><?php _e('Test', 'chatbot-plugin'); ?></button> ' +
                                                        '<button type="button" class="button button-small n8n-edit-action" data-index="' + index + '"><?php _e('Edit', 'chatbot-plugin'); ?></button> ' +
                                                        '<button type="button" class="button button-small n8n-delete-action" data-index="' + index + '" style="color: #d32f2f;"><?php _e('Delete', 'chatbot-plugin'); ?></button>' +
                                                    '</div>' +
                                                '</div>' +
                                                '<p style="margin: 8px 0 0 0; color: #666; font-size: 13px;">' + escapeHtml(action.description) + '</p>' +
                                                '<div class="n8n-action-test-result" data-index="' + index + '" style="display: none; margin-top: 10px; padding: 10px; border-radius: 4px; font-size: 12px;"></div>' +
                                            '</div>');

                                            $list.append($item);
                                        });
                                    }

                                    function addParamRow(param) {
                                        param = param || { name: '', type: 'string', description: '', required: false };
                                        var $row = $('<div class="n8n-param-row" style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px; padding: 8px; background: #fff; border: 1px solid #e0e0e0; border-radius: 3px;">' +
                                            '<input type="text" class="n8n-param-name" placeholder="<?php _e('Name', 'chatbot-plugin'); ?>" value="' + escapeHtml(param.name) + '" style="flex: 1;">' +
                                            '<select class="n8n-param-type" style="flex: 0 0 100px;">' +
                                                '<option value="string"' + (param.type === 'string' ? ' selected' : '') + '>String</option>' +
                                                '<option value="number"' + (param.type === 'number' ? ' selected' : '') + '>Number</option>' +
                                                '<option value="boolean"' + (param.type === 'boolean' ? ' selected' : '') + '>Boolean</option>' +
                                            '</select>' +
                                            '<input type="text" class="n8n-param-desc" placeholder="<?php _e('Description', 'chatbot-plugin'); ?>" value="' + escapeHtml(param.description) + '" style="flex: 2;">' +
                                            '<label style="flex: 0 0 auto; white-space: nowrap;"><input type="checkbox" class="n8n-param-required"' + (param.required ? ' checked' : '') + '> <?php _e('Req', 'chatbot-plugin'); ?></label>' +
                                            '<button type="button" class="button button-small n8n-remove-param" style="flex: 0 0 auto;">&times;</button>' +
                                        '</div>');
                                        $('#chatbot_n8n_action_params').append($row);
                                    }

                                    function showModal(action, index) {
                                        editingIndex = index;
                                        action = action || { name: '', description: '', parameters: [] };

                                        $('#chatbot_n8n_modal_title').text(index >= 0 ? '<?php _e('Edit Action', 'chatbot-plugin'); ?>' : '<?php _e('Add New Action', 'chatbot-plugin'); ?>');
                                        $('#chatbot_n8n_action_name').val(action.name || '');
                                        $('#chatbot_n8n_action_description').val(action.description || '');

                                        $('#chatbot_n8n_action_params').empty();
                                        if (action.parameters && action.parameters.length > 0) {
                                            action.parameters.forEach(function(param) { addParamRow(param); });
                                        }

                                        $('#chatbot_n8n_action_modal').show();
                                    }

                                    function hideModal() {
                                        editingIndex = -1;
                                        $('#chatbot_n8n_action_modal').hide();
                                    }

                                    function getActionFromForm() {
                                        var params = [];
                                        $('#chatbot_n8n_action_params .n8n-param-row').each(function() {
                                            var $row = $(this);
                                            var name = $row.find('.n8n-param-name').val().trim();
                                            if (name) {
                                                params.push({
                                                    name: name,
                                                    type: $row.find('.n8n-param-type').val(),
                                                    description: $row.find('.n8n-param-desc').val().trim(),
                                                    required: $row.find('.n8n-param-required').is(':checked')
                                                });
                                            }
                                        });

                                        return {
                                            name: $('#chatbot_n8n_action_name').val().trim().toLowerCase().replace(/[^a-z0-9_]/g, '_'),
                                            description: $('#chatbot_n8n_action_description').val().trim(),
                                            parameters: params
                                        };
                                    }

                                    function updateHiddenInput() {
                                        $('#chatbot_n8n_actions').val(JSON.stringify(currentActions));
                                    }

                                    // Event handlers
                                    $('#chatbot_n8n_add_action').on('click', function() { showModal(null, -1); });
                                    $('#chatbot_n8n_add_param').on('click', function() { addParamRow(); });
                                    $(document).on('click', '.n8n-remove-param', function() { $(this).closest('.n8n-param-row').remove(); });
                                    $('#chatbot_n8n_cancel_action').on('click', function() { hideModal(); });

                                    $('#chatbot_n8n_save_action').on('click', function() {
                                        var action = getActionFromForm();
                                        if (!action.name) { alert('<?php _e('Please enter an action name.', 'chatbot-plugin'); ?>'); return; }
                                        if (!action.description) { alert('<?php _e('Please enter a description.', 'chatbot-plugin'); ?>'); return; }

                                        if (editingIndex >= 0) {
                                            currentActions[editingIndex] = action;
                                        } else {
                                            currentActions.push(action);
                                        }

                                        hideModal();
                                        renderActions();
                                        updateHiddenInput();
                                    });

                                    $(document).on('click', '.n8n-edit-action', function() {
                                        var index = $(this).data('index');
                                        showModal(currentActions[index], index);
                                    });

                                    $(document).on('click', '.n8n-delete-action', function() {
                                        if (confirm('<?php _e('Delete this action?', 'chatbot-plugin'); ?>')) {
                                            currentActions.splice($(this).data('index'), 1);
                                            renderActions();
                                            updateHiddenInput();
                                        }
                                    });

                                    // ========== Test Action Modal ==========
                                    var testingActionIndex = -1;

                                    function showTestModal(action, index) {
                                        testingActionIndex = index;
                                        $('#chatbot_n8n_test_action_name').text(action.name);
                                        $('#chatbot_n8n_test_modal_result').hide().html('');

                                        // Build parameter input fields
                                        var $params = $('#chatbot_n8n_test_params');
                                        $params.empty();

                                        if (!action.parameters || action.parameters.length === 0) {
                                            $params.html('<p style="color: #666; font-style: italic;"><?php _e('This action has no parameters.', 'chatbot-plugin'); ?></p>');
                                        } else {
                                            action.parameters.forEach(function(param) {
                                                var defaultValue = '';
                                                var inputType = 'text';

                                                switch(param.type) {
                                                    case 'number':
                                                    case 'integer':
                                                        inputType = 'number';
                                                        defaultValue = '';
                                                        break;
                                                    case 'boolean':
                                                        inputType = 'checkbox';
                                                        break;
                                                    default:
                                                        defaultValue = '';
                                                }

                                                var requiredLabel = param.required ? ' <span style="color: #d32f2f;">*</span>' : '';
                                                var $row = $('<div style="margin-bottom: 10px;">' +
                                                    '<label style="display: block; margin-bottom: 3px; font-weight: 500;">' +
                                                        escapeHtml(param.name) + requiredLabel +
                                                        '<span style="font-weight: normal; color: #888; margin-left: 8px;">(' + escapeHtml(param.type) + ')</span>' +
                                                    '</label>' +
                                                    (param.description ? '<p class="description" style="margin: 0 0 5px 0;">' + escapeHtml(param.description) + '</p>' : '') +
                                                    (inputType === 'checkbox' ?
                                                        '<label><input type="checkbox" class="n8n-test-param" data-name="' + escapeHtml(param.name) + '" data-type="boolean"> <?php _e('True', 'chatbot-plugin'); ?></label>' :
                                                        '<input type="' + inputType + '" class="regular-text n8n-test-param" data-name="' + escapeHtml(param.name) + '" data-type="' + escapeHtml(param.type) + '" value="' + escapeHtml(defaultValue) + '" style="width: 100%;" placeholder="<?php _e('Enter value...', 'chatbot-plugin'); ?>">'
                                                    ) +
                                                '</div>');
                                                $params.append($row);
                                            });
                                        }

                                        $('#chatbot_n8n_action_modal').hide();
                                        $('#chatbot_n8n_test_modal').show();
                                    }

                                    function hideTestModal() {
                                        $('#chatbot_n8n_test_modal').hide();
                                        testingActionIndex = -1;
                                    }

                                    // Open test modal when clicking Test button
                                    $(document).on('click', '.n8n-test-action', function() {
                                        var index = $(this).data('index');
                                        var url = $('#chatbot_n8n_webhook_url').val();

                                        if (!url) {
                                            alert('<?php _e('Enter a webhook URL first.', 'chatbot-plugin'); ?>');
                                            return;
                                        }

                                        showTestModal(currentActions[index], index);
                                    });

                                    // Close test modal
                                    $('#chatbot_n8n_cancel_test').on('click', hideTestModal);

                                    // Run the test
                                    $('#chatbot_n8n_run_test').on('click', function() {
                                        var $btn = $(this);
                                        var $result = $('#chatbot_n8n_test_modal_result');
                                        var action = currentActions[testingActionIndex];
                                        var url = $('#chatbot_n8n_webhook_url').val();
                                        var secret = $('#chatbot_n8n_webhook_secret').val();

                                        // Collect parameter values
                                        var testParams = {};
                                        $('.n8n-test-param').each(function() {
                                            var $input = $(this);
                                            var name = $input.data('name');
                                            var type = $input.data('type');
                                            var value;

                                            if (type === 'boolean') {
                                                value = $input.is(':checked');
                                            } else if (type === 'number' || type === 'integer') {
                                                value = $input.val() !== '' ? parseFloat($input.val()) : null;
                                            } else {
                                                value = $input.val();
                                            }

                                            if (value !== null && value !== '') {
                                                testParams[name] = value;
                                            }
                                        });

                                        $btn.prop('disabled', true);
                                        $result.show().css('background', '#f0f0f0').html('<span style="color: #666;"><?php _e('Testing action...', 'chatbot-plugin'); ?></span>');

                                        $.ajax({
                                            url: ajaxurl,
                                            type: 'POST',
                                            data: {
                                                action: 'chatbot_n8n_test_action',
                                                nonce: '<?php echo wp_create_nonce('chatbot_n8n_test'); ?>',
                                                webhook_url: url,
                                                webhook_secret: secret,
                                                headers: $('#chatbot_n8n_headers').val(),
                                                action_name: action.name,
                                                action_params: JSON.stringify(testParams)
                                            },
                                            success: function(response) {
                                                if (response.success) {
                                                    var result = response.data.result;
                                                    var resultStr = JSON.stringify(result, null, 2);
                                                    var isDefaultResponse = result && result.message === 'Workflow was started';

                                                    var resultHtml = '';
                                                    if (isDefaultResponse) {
                                                        resultHtml = '<strong style="color: #ed6c02;">&#x26A0; <?php _e('Webhook received (async mode)', 'chatbot-plugin'); ?></strong>';
                                                        resultHtml += '<p style="margin: 8px 0; color: #666; font-size: 12px;"><?php _e('n8n is set to respond immediately. To see actual results, configure your Webhook node:', 'chatbot-plugin'); ?><br>';
                                                        resultHtml += '&bull; <?php _e('Set "Respond" to "When Last Node Finishes", OR', 'chatbot-plugin'); ?><br>';
                                                        resultHtml += '&bull; <?php _e('Add a "Respond to Webhook" node at the end of your workflow', 'chatbot-plugin'); ?></p>';
                                                        $result.css('background', '#fff3e0');
                                                    } else {
                                                        resultHtml = '<strong style="color: #2e7d32;">&#x2713; <?php _e('Success!', 'chatbot-plugin'); ?></strong>';
                                                        $result.css('background', '#e8f5e9');
                                                    }

                                                    resultHtml += '<div style="margin-top: 8px;"><strong style="font-size: 11px; color: #666;"><?php _e('Sent Parameters:', 'chatbot-plugin'); ?></strong></div>';
                                                    resultHtml += '<pre style="margin: 4px 0 8px 0; padding: 8px; background: #e3f2fd; border-radius: 3px; overflow-x: auto; white-space: pre-wrap; word-break: break-word; font-size: 11px;">' + escapeHtml(JSON.stringify(testParams, null, 2)) + '</pre>';

                                                    resultHtml += '<div><strong style="font-size: 11px; color: #666;"><?php _e('Response:', 'chatbot-plugin'); ?></strong></div>';
                                                    resultHtml += '<pre style="margin: 4px 0 0 0; padding: 8px; background: #f5f5f5; border-radius: 3px; overflow-x: auto; white-space: pre-wrap; word-break: break-word; font-size: 11px;">' + escapeHtml(resultStr) + '</pre>';

                                                    $result.html(resultHtml);
                                                } else {
                                                    $result.css('background', '#fef0f0').html('<span style="color: #d32f2f;">&#x2717; ' + escapeHtml(response.data ? response.data.message : '<?php _e('Failed', 'chatbot-plugin'); ?>') + '</span>');
                                                }
                                            },
                                            error: function() {
                                                $result.css('background', '#fef0f0').html('<span style="color: #d32f2f;">&#x2717; <?php _e('Connection error', 'chatbot-plugin'); ?></span>');
                                            },
                                            complete: function() { $btn.prop('disabled', false); }
                                        });
                                    });

                                    // Test connection
                                    $('#chatbot_n8n_test_connection').on('click', function() {
                                        var $btn = $(this);
                                        var $result = $('#chatbot_n8n_test_result');
                                        var url = $('#chatbot_n8n_webhook_url').val();
                                        var secret = $('#chatbot_n8n_webhook_secret').val();

                                        if (!url) { $result.html('<span style="color: red;"><?php _e('Enter a webhook URL first.', 'chatbot-plugin'); ?></span>'); return; }

                                        $btn.prop('disabled', true);
                                        $result.html('<span style="color: #666;"><?php _e('Testing...', 'chatbot-plugin'); ?></span>');

                                        $.ajax({
                                            url: ajaxurl,
                                            type: 'POST',
                                            data: {
                                                action: 'chatbot_n8n_test_connection',
                                                nonce: $btn.data('nonce'),
                                                webhook_url: url,
                                                webhook_secret: secret,
                                                headers: $('#chatbot_n8n_headers').val()
                                            },
                                            success: function(response) {
                                                if (response.success) {
                                                    $result.html('<span style="color: green;">&#x2713; ' + response.data.message + '</span>');
                                                } else {
                                                    $result.html('<span style="color: red;">&#x2717; ' + (response.data ? response.data.message : '<?php _e('Failed', 'chatbot-plugin'); ?>') + '</span>');
                                                }
                                            },
                                            error: function() {
                                                $result.html('<span style="color: red;">&#x2717; <?php _e('Connection error', 'chatbot-plugin'); ?></span>');
                                            },
                                            complete: function() { $btn.prop('disabled', false); }
                                        });
                                    });

                                    // Save webhook settings without full form submit
                                    $('#chatbot_n8n_save_settings').on('click', function() {
                                        var $btn = $(this);
                                        var $result = $('#chatbot_n8n_test_result');
                                        var chatbotId = $btn.data('chatbot-id');

                                        // Collect current n8n settings
                                        var n8nSettings = {
                                            enabled: $('#chatbot_n8n_enabled').is(':checked'),
                                            webhook_url: $('#chatbot_n8n_webhook_url').val(),
                                            webhook_secret: $('#chatbot_n8n_webhook_secret').val(),
                                            timeout: parseInt($('#chatbot_n8n_timeout').val()) || 300,
                                            headers: JSON.parse($('#chatbot_n8n_headers').val() || '[]'),
                                            actions: currentActions
                                        };

                                        $btn.prop('disabled', true);
                                        $result.html('<span style="color: #666;"><?php _e('Saving...', 'chatbot-plugin'); ?></span>');

                                        $.ajax({
                                            url: ajaxurl,
                                            type: 'POST',
                                            data: {
                                                action: 'chatbot_n8n_save_settings',
                                                nonce: $btn.data('nonce'),
                                                chatbot_id: chatbotId,
                                                n8n_settings: JSON.stringify(n8nSettings)
                                            },
                                            success: function(response) {
                                                if (response.success) {
                                                    $result.html('<span style="color: green;">&#x2713; ' + response.data.message + '</span>');
                                                    // Clear message after 3 seconds
                                                    setTimeout(function() { $result.html(''); }, 3000);
                                                } else {
                                                    $result.html('<span style="color: red;">&#x2717; ' + (response.data ? response.data.message : '<?php _e('Failed', 'chatbot-plugin'); ?>') + '</span>');
                                                }
                                            },
                                            error: function() {
                                                $result.html('<span style="color: red;">&#x2717; <?php _e('Save error', 'chatbot-plugin'); ?></span>');
                                            },
                                            complete: function() { $btn.prop('disabled', false); }
                                        });
                                    });

                                    // Initial render
                                    renderActions();
                                });
                            })(jQuery);
                            </script>

                            <p class="description" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                                <a href="https://n8n.io/" target="_blank"><?php _e('Learn more about n8n', 'chatbot-plugin'); ?></a> |
                                <a href="https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.webhook/" target="_blank"><?php _e('Webhook Documentation', 'chatbot-plugin'); ?></a>
                            </p>
                        </div>
                        <!-- End n8n Section -->
                    </div>
                    <!-- End Integrations Tab -->

                    <!-- Tab switching JavaScript -->
                    <script type="text/javascript">
                    (function($) {
                        $(document).ready(function() {
                            // Tab switching
                            $('.chatbot-config-tabs .nav-tab').on('click', function(e) {
                                e.preventDefault();
                                var tabId = $(this).data('tab');

                                // Update active tab
                                $('.chatbot-config-tabs .nav-tab').removeClass('nav-tab-active');
                                $(this).addClass('nav-tab-active');

                                // Show corresponding content
                                $('.chatbot-tab-content').removeClass('active');
                                $('#tab-' + tabId).addClass('active');

                                // Store active tab in session storage
                                sessionStorage.setItem('chatbot_active_tab', tabId);
                            });

                            // Restore active tab from session storage
                            var activeTab = sessionStorage.getItem('chatbot_active_tab');
                            if (activeTab) {
                                $('.chatbot-config-tabs .nav-tab[data-tab="' + activeTab + '"]').click();
                            }
                        });
                    })(jQuery);
                    </script>

                    <p class="submit" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #c3c4c7;">
                        <?php if ($editing): ?>
                            <input type="submit" class="button button-primary" value="<?php esc_attr_e('Update Chatbot', 'chatbot-plugin'); ?>">
                        <?php else: ?>
                            <input type="submit" class="button button-primary" value="<?php esc_attr_e('Add Chatbot', 'chatbot-plugin'); ?>">
                            <a href="<?php echo admin_url('admin.php?page=chatbot-configurations&action=add'); ?>" class="button">
                                <?php _e('Reset Form', 'chatbot-plugin'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </form>
                
                <!-- JavaScript functionality moved to chatbot-admin.js for easier debugging -->
                <!-- Previous inline script replaced with data attributes in the button above -->
                <!-- This provides cleaner separation of concerns and better debugging -->
                
            <?php else: ?>
                <!-- Display list of configurations -->
                <?php if (empty($configurations)): ?>
                    <div class="card">
                        <h2><?php _e('No chatbots found', 'chatbot-plugin'); ?></h2>
                        <p><?php _e('Create your first chatbot by clicking the "Add New" button above.', 'chatbot-plugin'); ?></p>
                    </div>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col"><?php _e('Name', 'chatbot-plugin'); ?></th>
                                <th scope="col"><?php _e('Shortcode', 'chatbot-plugin'); ?></th>
                                <th scope="col"><?php _e('Conversations', 'chatbot-plugin'); ?></th>
                                <th scope="col"><?php _e('Actions', 'chatbot-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($configurations as $config): ?>
                                <tr>
                                    <td><?php echo esc_html($config->name); ?></td>
                                    <td><code>[chatbot name="<?php echo esc_attr($config->name); ?>"]</code></td>
                                    <td>
                                        <?php 
                                        $conversation_count = $db->get_conversation_count_by_chatbot($config->id); 
                                        ?>
                                        <a href="<?php echo admin_url('admin.php?page=chatbot-conversations' . ($config->id ? '&chatbot=' . $config->id : '')); ?>">
                                            <?php echo number_format_i18n($conversation_count); ?> 
                                            <?php echo _n('conversation', 'conversations', $conversation_count, 'chatbot-plugin'); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=chatbot-configurations&action=edit&id=' . $config->id); ?>" class="button button-small">
                                            <?php _e('Edit', 'chatbot-plugin'); ?>
                                        </a>
                                        
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=chatbot-configurations&action=delete&id=' . $config->id), 'chatbot_delete_configuration'); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this chatbot? This action cannot be undone.', 'chatbot-plugin'); ?>');">
                                            <?php _e('Delete', 'chatbot-plugin'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <div class="card" style="margin-top: 20px;">
                    <h2><?php _e('Using Chatbots on Your Site', 'chatbot-plugin'); ?></h2>
                    <p><?php _e('To add a chatbot to your site, use the shortcode format shown in the table above.', 'chatbot-plugin'); ?></p>
                    <p><?php _e('For example:', 'chatbot-plugin'); ?></p>
                    <ul style="list-style-type: disc; margin-left: 20px;">
                        <li><code>[chatbot name="Default"]</code> - <?php _e('Use a specific chatbot by name', 'chatbot-plugin'); ?></li>
                        <li><code>[chatbot]</code> - <?php _e('Uses the chatbot named "Default" if it exists', 'chatbot-plugin'); ?></li>
                        <li><code>[chatbot theme="dark"]</code> - <?php _e('Apply a dark theme to the chatbot', 'chatbot-plugin'); ?></li>
                        <li><code>[chatbot mode="inline"]</code> - <?php _e('Display chatbot inline on the page (default is floating)', 'chatbot-plugin'); ?></li>
                        <li><code>[chatbot height="550px"]</code> - <?php _e('Set a custom height for the chatbot', 'chatbot-plugin'); ?></li>
                        <li><code>[chatbot skip_welcome="true"]</code> - <?php _e('Skip the welcome message when chatbot loads', 'chatbot-plugin'); ?></li>
                    </ul>
                    <p style="margin-top: 15px;"><strong><?php _e('Combined example:', 'chatbot-plugin'); ?></strong></p>
                    <code>[chatbot mode="inline" height="550px" skip_welcome="true"]</code>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Display the conversations page
     */
    public function display_conversations_page() {
        // Check if we're viewing a single conversation
        $conversation_id = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : 0;
        
        if ($conversation_id > 0) {
            $this->display_single_conversation($conversation_id);
        } else {
            $this->display_all_conversations();
        }
    }
    
    /**
     * Display a list of recent conversations
     * 
     * @param int $limit Number of conversations to display
     */
    private function display_recent_conversations($limit = 5) {
        $db = Chatbot_DB::get_instance();
        $conversations = $db->get_conversations($limit);
        
        if (empty($conversations)) {
            echo '<p>' . __('No conversations found.', 'chatbot-plugin') . '</p>';
            return;
        }
        
        $this->render_conversations_table($conversations, false);
    }
    
    /**
     * Display all conversations with pagination and filters
     */
    private function display_all_conversations() {
        $db = Chatbot_DB::get_instance();
        
        // Handle bulk actions
        $this->process_bulk_actions();
        
        // Get current filters
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        
        // Check for both chatbot and chatbot_filter parameters (for backward compatibility)
        $chatbot_filter = null;
        
        // First check for chatbot_filter parameter
        if (isset($_GET['chatbot_filter'])) {
            // Only use it if it has a valid value (not empty)
            if (!empty($_GET['chatbot_filter'])) {
                $chatbot_filter = intval($_GET['chatbot_filter']);
                error_log('Chatbot: DEBUG - display_all_conversations - Using chatbot_filter parameter: ' . $chatbot_filter);
            } else {
                error_log('Chatbot: DEBUG - display_all_conversations - chatbot_filter parameter exists but is empty, ignoring it');
            }
        } 
        // If no chatbot_filter parameter or it was empty, check for the older 'chatbot' parameter
        elseif (isset($_GET['chatbot'])) {
            // Only use it if it has a valid value (not empty)
            if (!empty($_GET['chatbot'])) {
                $chatbot_filter = intval($_GET['chatbot']);
                error_log('Chatbot: DEBUG - display_all_conversations - Using chatbot parameter (backward compatibility): ' . $chatbot_filter);
            } else {
                error_log('Chatbot: DEBUG - display_all_conversations - chatbot parameter exists but is empty, ignoring it');
            }
        } else {
            // No filter parameters provided
            error_log('Chatbot: DEBUG - display_all_conversations - No chatbot filter parameters found');
        }
        
        // Get all chatbot configurations for the filter dropdown
        $chatbot_configs = $db->get_configurations();
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get conversations with filters
        $conversations = $db->get_conversations_by_status($status_filter, $per_page, $offset, $chatbot_filter);
        $total_conversations = $db->get_conversation_count_by_status($status_filter, $chatbot_filter);
        $total_pages = ceil($total_conversations / $per_page);
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Conversations', 'chatbot-plugin'); ?></h1>
            
            <?php settings_errors('chatbot_plugin'); ?>
            
            <!-- Status filter -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get" id="chatbot-filter-form">
                        <input type="hidden" name="page" value="chatbot-conversations">
                        <select name="status">
                            <option value="all" <?php selected($status_filter, 'all'); ?>><?php _e('All Conversations', 'chatbot-plugin'); ?></option>
                            <option value="active" <?php selected($status_filter, 'active'); ?>><?php _e('Active', 'chatbot-plugin'); ?></option>
                            <option value="ended" <?php selected($status_filter, 'ended'); ?>><?php _e('Ended', 'chatbot-plugin'); ?></option>
                            <option value="archived" <?php selected($status_filter, 'archived'); ?>><?php _e('Archived', 'chatbot-plugin'); ?></option>
                        </select>
                        
                        <!-- Add chatbot filter dropdown -->
                        <select name="chatbot_filter" id="chatbot_filter">
                            <option value=""><?php _e('All Chatbots', 'chatbot-plugin'); ?></option>
                            <?php foreach ($chatbot_configs as $config): ?>
                                <option value="<?php echo esc_attr($config->id); ?>" <?php selected($chatbot_filter, $config->id); ?>>
                                    <?php echo esc_html($config->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'chatbot-plugin'); ?>">
                    </form>
                    
                    <script type="text/javascript">
                        jQuery(document).ready(function($) {
                            $('form').on('submit', function(e) {
                                // If "All Chatbots" is selected (empty value), remove the parameter from the form
                                if ($('#chatbot_filter').val() === '') {
                                    $('#chatbot_filter').removeAttr('name');
                                }
                                // Keep the chatbot_filter name when submitting (don't rename to chatbot)
                            });
                        });
                    </script>
                </div>
            </div>
            
            <?php if (empty($conversations)): ?>
                <p><?php _e('No conversations found.', 'chatbot-plugin'); ?></p>
            <?php else: ?>
                <form method="post">
                    <?php wp_nonce_field('chatbot_bulk_action', 'chatbot_bulk_nonce'); ?>
                    
                    <div class="tablenav top">
                        <div class="alignleft actions bulkactions">
                            <label for="bulk-action-selector-top" class="screen-reader-text"><?php _e('Select bulk action', 'chatbot-plugin'); ?></label>
                            <select name="bulk_action" id="bulk-action-selector-top">
                                <option value="-1"><?php _e('Bulk Actions', 'chatbot-plugin'); ?></option>
                                <option value="archive"><?php _e('Archive', 'chatbot-plugin'); ?></option>
                                <option value="activate"><?php _e('Mark as Active', 'chatbot-plugin'); ?></option>
                                <option value="delete"><?php _e('Delete', 'chatbot-plugin'); ?></option>
                            </select>
                            <input type="submit" class="button action" value="<?php esc_attr_e('Apply', 'chatbot-plugin'); ?>">
                        </div>
                    </div>
                    
                    <?php $this->render_conversations_table($conversations, true); ?>
                </form>
                
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php printf(
                                    _n('%s conversation', '%s conversations', $total_conversations, 'chatbot-plugin'),
                                    number_format_i18n($total_conversations)
                                ); ?>
                            </span>
                            
                            <span class="pagination-links">
                                <?php
                                echo paginate_links(array(
                                    'base' => add_query_arg('paged', '%#%'),
                                    'format' => '',
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                    'total' => $total_pages,
                                    'current' => $current_page
                                ));
                                ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Display a single conversation with a chat interface
     * 
     * @param int $conversation_id The conversation ID
     */
    private function display_single_conversation($conversation_id) {
        $db = Chatbot_DB::get_instance();
        $conversation = $db->get_conversation($conversation_id);
        
        if (!$conversation) {
            wp_die(__('Conversation not found.', 'chatbot-plugin'));
        }
        
        $messages = $db->get_messages($conversation_id);
        ?>
        <div class="wrap">
            <h1>
                <?php printf(
                    __('Conversation with %s', 'chatbot-plugin'),
                    esc_html($conversation->visitor_name)
                ); ?>
                
                <span class="chatbot-admin-status-badge chatbot-admin-status-<?php echo esc_attr($conversation->status); ?>">
                    <?php echo esc_html(ucfirst($conversation->status)); ?>
                </span>
                
                <?php if (!empty($conversation->chatbot_config_name)): ?>
                <span class="chatbot-admin-type-badge">
                    <?php echo esc_html($conversation->chatbot_config_name); ?>
                </span>
                <?php endif; ?>

                <?php
                // Show platform badge for Telegram/WhatsApp conversations
                $platform = '';
                if (!empty($conversation->platform_type)) {
                    $platform = $conversation->platform_type;
                } elseif (!empty($conversation->telegram_chat_id)) {
                    $platform = 'telegram';
                }
                if ($platform): ?>
                <span class="chatbot-admin-platform-badge chatbot-admin-platform-<?php echo esc_attr($platform); ?>">
                    <?php
                    switch ($platform) {
                        case 'telegram':
                            echo '📱 Telegram';
                            break;
                        case 'whatsapp':
                            echo '💬 WhatsApp';
                            break;
                        default:
                            echo esc_html(ucfirst($platform));
                    }
                    ?>
                </span>
                <?php endif; ?>
            </h1>
            
            <p>
                <a href="<?php echo admin_url('admin.php?page=chatbot-conversations'); ?>" class="button">
                    &larr; <?php _e('Back to All Conversations', 'chatbot-plugin'); ?>
                </a>
                
                <?php if ($conversation->status === 'active'): ?>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=chatbot-conversations&action=archive&conversation_id=' . $conversation->id), 'chatbot_archive_conversation'); ?>" class="button">
                        <?php _e('Archive Conversation', 'chatbot-plugin'); ?>
                    </a>
                <?php elseif ($conversation->status === 'archived' || $conversation->status === 'ended'): ?>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=chatbot-conversations&action=activate&conversation_id=' . $conversation->id), 'chatbot_activate_conversation'); ?>" class="button">
                        <?php _e('Reactivate Conversation', 'chatbot-plugin'); ?>
                    </a>
                <?php endif; ?>
                
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=chatbot-conversations&action=delete&conversation_id=' . $conversation->id), 'chatbot_delete_conversation'); ?>" class="button button-link-delete" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this conversation? This action cannot be undone.', 'chatbot-plugin'); ?>');">
                    <?php _e('Delete Conversation', 'chatbot-plugin'); ?>
                </a>
            </p>
            
            <div class="card" style="max-width: 800px;">
                <div class="chatbot-admin-chat">
                    <div class="chatbot-admin-messages">
                        <?php if (empty($messages)): ?>
                            <p class="chatbot-admin-no-messages"><?php _e('No messages in this conversation yet.', 'chatbot-plugin'); ?></p>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <div class="chatbot-admin-message chatbot-admin-message-<?php echo esc_attr($message->sender_type); ?>">
                                    <div class="chatbot-admin-message-meta">
                                        <span class="chatbot-admin-message-sender">
                                            <?php 
                                            switch ($message->sender_type) {
                                                case 'user':
                                                    echo esc_html($conversation->visitor_name);
                                                    break;
                                                case 'admin':
                                                    _e('Admin', 'chatbot-plugin');
                                                    break;
                                                case 'ai':
                                                    _e('AI Assistant', 'chatbot-plugin');
                                                    break;
                                                case 'system':
                                                    _e('System', 'chatbot-plugin');
                                                    break;
                                                case 'function':
                                                    _e('🔧 Function Call', 'chatbot-plugin');
                                                    break;
                                                default:
                                                    echo esc_html(ucfirst($message->sender_type));
                                            }
                                            ?>
                                        </span>
                                        <span class="chatbot-admin-message-time">
                                            <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($message->timestamp))); ?>
                                        </span>
                                    </div>
                                    <div class="chatbot-admin-message-content">
                                        <?php echo wp_kses(nl2br(esc_html($message->message)), array('br' => array())); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($conversation->status === 'active'): ?>
                    <div class="chatbot-admin-reply">
                        <textarea id="chatbot-admin-reply-text" class="widefat" rows="3" placeholder="<?php esc_attr_e('Type your reply here...', 'chatbot-plugin'); ?>"></textarea>
                        <div class="chatbot-admin-reply-actions">
                            <button id="chatbot-admin-send" class="button button-primary" data-conversation-id="<?php echo esc_attr($conversation_id); ?>">
                                <?php _e('Send', 'chatbot-plugin'); ?>
                            </button>
                            <span id="chatbot-admin-status"></span>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="chatbot-admin-reply-inactive">
                        <p><?php _e('This conversation is no longer active. You cannot send messages to it.', 'chatbot-plugin'); ?></p>
                        <?php if ($conversation->status === 'ended' || $conversation->status === 'archived'): ?>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=chatbot-conversations&action=activate&conversation_id=' . $conversation->id), 'chatbot_activate_conversation'); ?>" class="button">
                                <?php _e('Reactivate Conversation', 'chatbot-plugin'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render a table of conversations
     * 
     * @param array $conversations Array of conversation objects
     * @param bool $show_checkboxes Whether to show checkboxes for bulk actions
     */
    private function render_conversations_table($conversations, $show_checkboxes = true) {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <?php if ($show_checkboxes): ?>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-1">
                    </td>
                    <?php endif; ?>
                    <th><?php _e('Visitor', 'chatbot-plugin'); ?></th>
                    <th><?php _e('Chatbot', 'chatbot-plugin'); ?></th>
                    <th><?php _e('Started', 'chatbot-plugin'); ?></th>
                    <th><?php _e('Last Message', 'chatbot-plugin'); ?></th>
                    <th><?php _e('Messages', 'chatbot-plugin'); ?></th>
                    <th><?php _e('Status', 'chatbot-plugin'); ?></th>
                    <th><?php _e('Actions', 'chatbot-plugin'); ?></th>
                </tr>
            </thead>
            
            <tbody>
                <?php foreach ($conversations as $conversation): ?>
                    <tr>
                        <?php if ($show_checkboxes): ?>
                        <th scope="row" class="check-column">
                            <input type="checkbox" name="conversation_ids[]" value="<?php echo esc_attr($conversation->id); ?>">
                        </th>
                        <?php endif; ?>
                        <td>
                            <?php echo esc_html($conversation->visitor_name); ?>
                            <?php
                            // Show platform icon for Telegram/WhatsApp
                            $platform = '';
                            if (!empty($conversation->platform_type)) {
                                $platform = $conversation->platform_type;
                            } elseif (!empty($conversation->telegram_chat_id)) {
                                $platform = 'telegram';
                            }
                            if ($platform === 'telegram') {
                                echo ' <span class="chatbot-admin-platform-badge chatbot-admin-platform-telegram" title="Telegram">📱</span>';
                            } elseif ($platform === 'whatsapp') {
                                echo ' <span class="chatbot-admin-platform-badge chatbot-admin-platform-whatsapp" title="WhatsApp">💬</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            if (!empty($conversation->chatbot_config_name)) {
                                echo '<span class="chatbot-name-badge">' . esc_html($conversation->chatbot_config_name) . '</span>';
                            } else {
                                echo '<span class="chatbot-name-badge chatbot-name-default">Default</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($conversation->created_at)); ?>
                        </td>
                        <td>
                            <?php if (isset($conversation->last_message)): ?>
                                <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($conversation->last_message)); ?>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td><?php echo isset($conversation->message_count) ? intval($conversation->message_count) : 0; ?></td>
                        <td>
                            <?php 
                                $status_class = '';
                                $status_text = '';
                                
                                // Get status from the database or use is_active as fallback
                                $status = isset($conversation->status) ? $conversation->status : ($conversation->is_active ? 'active' : 'inactive');
                                
                                switch ($status) {
                                    case 'active':
                                        $status_class = 'chatbot-admin-status-active';
                                        $status_text = __('Active', 'chatbot-plugin');
                                        break;
                                    case 'ended':
                                        $status_class = 'chatbot-admin-status-ended';
                                        $status_text = __('Ended', 'chatbot-plugin');
                                        break;
                                    case 'archived':
                                        $status_class = 'chatbot-admin-status-archived';
                                        $status_text = __('Archived', 'chatbot-plugin');
                                        break;
                                    default:
                                        $status_class = '';
                                        $status_text = isset($conversation->status) ? $conversation->status : ($conversation->is_active ? __('Active', 'chatbot-plugin') : __('Inactive', 'chatbot-plugin'));
                                }
                            ?>
                            <span class="<?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_text); ?></span>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=chatbot-conversations&conversation_id=' . $conversation->id); ?>" class="button button-small">
                                <?php _e('View', 'chatbot-plugin'); ?>
                            </a>
                            
                            <?php if (isset($conversation->status) && $conversation->status === 'active'): ?>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=chatbot-conversations&action=archive&conversation_id=' . $conversation->id), 'chatbot_archive_conversation'); ?>" class="button button-small">
                                    <?php _e('Archive', 'chatbot-plugin'); ?>
                                </a>
                            <?php elseif (isset($conversation->status) && ($conversation->status === 'archived' || $conversation->status === 'ended')): ?>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=chatbot-conversations&action=activate&conversation_id=' . $conversation->id), 'chatbot_activate_conversation'); ?>" class="button button-small">
                                    <?php _e('Activate', 'chatbot-plugin'); ?>
                                </a>
                            <?php endif; ?>
                            
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=chatbot-conversations&action=delete&conversation_id=' . $conversation->id), 'chatbot_delete_conversation'); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this conversation? This action cannot be undone.', 'chatbot-plugin'); ?>');">
                                <?php _e('Delete', 'chatbot-plugin'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Handle admin actions like archive, activate, delete
     */
    public function handle_admin_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'chatbot-conversations') {
            return;
        }
        
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        $conversation_id = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : 0;
        
        if (empty($action) || empty($conversation_id)) {
            return;
        }
        
        $db = Chatbot_DB::get_instance();
        $redirect_url = admin_url('admin.php?page=chatbot-conversations');
        
        switch ($action) {
            case 'archive':
                // Check nonce
                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'chatbot_archive_conversation')) {
                    wp_die(__('Security check failed.', 'chatbot-plugin'));
                }
                
                // Archive the conversation
                if ($db->set_conversation_status($conversation_id, 'archived')) {
                    add_settings_error('chatbot_plugin', 'archive-success', __('Conversation archived successfully.', 'chatbot-plugin'), 'updated');
                } else {
                    add_settings_error('chatbot_plugin', 'archive-error', __('Failed to archive conversation.', 'chatbot-plugin'), 'error');
                }
                break;
                
            case 'activate':
                // Check nonce
                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'chatbot_activate_conversation')) {
                    wp_die(__('Security check failed.', 'chatbot-plugin'));
                }
                
                // Activate the conversation
                if ($db->set_conversation_status($conversation_id, 'active')) {
                    add_settings_error('chatbot_plugin', 'activate-success', __('Conversation activated successfully.', 'chatbot-plugin'), 'updated');
                } else {
                    add_settings_error('chatbot_plugin', 'activate-error', __('Failed to activate conversation.', 'chatbot-plugin'), 'error');
                }
                break;
                
            case 'delete':
                // Check nonce
                if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'chatbot_delete_conversation')) {
                    wp_die(__('Security check failed.', 'chatbot-plugin'));
                }
                
                // Delete the conversation
                if ($db->delete_conversation($conversation_id)) {
                    add_settings_error('chatbot_plugin', 'delete-success', __('Conversation deleted successfully.', 'chatbot-plugin'), 'updated');
                } else {
                    add_settings_error('chatbot_plugin', 'delete-error', __('Failed to delete conversation.', 'chatbot-plugin'), 'error');
                }
                break;
        }
        
        // Redirect back after action
        if (isset($_GET['conversation_id']) && $action !== 'delete') {
            $redirect_url = admin_url('admin.php?page=chatbot-conversations&conversation_id=' . $conversation_id);
        }
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Process bulk actions
     */
    private function process_bulk_actions() {
        // Check if a bulk action is being triggered
        if (!isset($_POST['bulk_action']) || $_POST['bulk_action'] == -1) {
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['chatbot_bulk_nonce']) || !wp_verify_nonce($_POST['chatbot_bulk_nonce'], 'chatbot_bulk_action')) {
            wp_die(__('Security check failed.', 'chatbot-plugin'));
        }
        
        $conversation_ids = isset($_POST['conversation_ids']) ? array_map('intval', $_POST['conversation_ids']) : array();
        
        if (empty($conversation_ids)) {
            add_settings_error('chatbot_plugin', 'no-items', __('No conversations were selected.', 'chatbot-plugin'), 'error');
            return;
        }
        
        $action = sanitize_text_field($_POST['bulk_action']);
        $db = Chatbot_DB::get_instance();
        $processed = 0;
        
        foreach ($conversation_ids as $id) {
            switch ($action) {
                case 'archive':
                    if ($db->set_conversation_status($id, 'archived')) {
                        $processed++;
                    }
                    break;
                    
                case 'activate':
                    if ($db->set_conversation_status($id, 'active')) {
                        $processed++;
                    }
                    break;
                    
                case 'delete':
                    if ($db->delete_conversation($id)) {
                        $processed++;
                    }
                    break;
            }
        }
        
        $message = '';
        
        switch ($action) {
            case 'archive':
                $message = sprintf(_n('%s conversation archived.', '%s conversations archived.', $processed, 'chatbot-plugin'), number_format_i18n($processed));
                break;
                
            case 'activate':
                $message = sprintf(_n('%s conversation activated.', '%s conversations activated.', $processed, 'chatbot-plugin'), number_format_i18n($processed));
                break;
                
            case 'delete':
                $message = sprintf(_n('%s conversation deleted.', '%s conversations deleted.', $processed, 'chatbot-plugin'), number_format_i18n($processed));
                break;
        }
        
        add_settings_error('chatbot_plugin', 'bulk-action', $message, 'updated');
    }
    
    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook The current admin page
     */
    public function enqueue_admin_scripts($hook) {
        // Debug: Log the current hook for troubleshooting
        error_log('Chatbot Plugin Admin Hook: ' . $hook);

        // Load on chatbot plugin pages or settings page
        if (
            strpos($hook, 'chatbot-plugin') === false &&
            strpos($hook, 'chatbot-conversations') === false &&
            strpos($hook, 'chatbot-configurations') === false &&
            strpos($hook, 'page_chatbot-settings') === false &&
            $hook !== 'toplevel_page_chatbot-plugin'
        ) {
            return;
        }

        wp_enqueue_style(
            'chatbot-admin-style',
            CHATBOT_PLUGIN_URL . 'assets/css/chatbot-admin.css',
            array(),
            CHATBOT_PLUGIN_VERSION
        );

        // Explicitly enqueue WordPress media scripts and styles
        wp_enqueue_media();

        // Enqueue jQuery
        wp_enqueue_script('jquery');

        // Ensure thickbox is loaded for modals
        wp_enqueue_script('thickbox');
        wp_enqueue_style('thickbox');

        // Enqueue Select2 for enhanced dropdowns
        // Check if selectWoo (WooCommerce's Select2) is available, otherwise use CDN
        if (wp_script_is('selectWoo', 'registered')) {
            wp_enqueue_script('selectWoo');
            wp_enqueue_style('select2');
        } else {
            // Fallback to CDN Select2
            wp_enqueue_style(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
                array(),
                '4.1.0'
            );
            wp_enqueue_script(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
                array('jquery'),
                '4.1.0',
                true
            );
        }

        // Add DOMPurify for HTML sanitization
        wp_enqueue_script(
            'dompurify',
            'https://cdnjs.cloudflare.com/ajax/libs/dompurify/2.4.0/purify.min.js',
            array(),
            '2.4.0',
            true
        );

        // Add custom admin script
        wp_enqueue_script(
            'chatbot-admin-script',
            CHATBOT_PLUGIN_URL . 'assets/js/chatbot-admin.js',
            array('jquery', 'media-upload', 'thickbox'),
            CHATBOT_PLUGIN_VERSION,
            true
        );

        // Enqueue the filters script only on the conversations page
        if (isset($_GET['page']) && $_GET['page'] === 'chatbot-conversations') {
            error_log('Chatbot: DEBUG - enqueue_admin_scripts - Enqueuing chatbot-admin-filters.js on conversations page');
            wp_enqueue_script(
                'chatbot-admin-filters',
                CHATBOT_PLUGIN_URL . 'assets/js/chatbot-admin-filters.js',
                array('jquery', 'chatbot-admin-script'),
                CHATBOT_PLUGIN_VERSION,
                true
            );
        }

        wp_localize_script(
            'chatbot-admin-script',
            'chatbotAdminVars',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('chatbot-admin-nonce'),
                'frontendNonce' => wp_create_nonce('chatbot-plugin-nonce'), // For frontend actions like get_messages
                'sendingText' => __('Sending...', 'chatbot-plugin'),
                'sentText' => __('Message sent', 'chatbot-plugin'),
                'errorText' => __('Error sending message', 'chatbot-plugin'),
                'confirmDeleteText' => __('Are you sure you want to delete this conversation? This action cannot be undone.', 'chatbot-plugin'),
                'confirmArchiveText' => __('Are you sure you want to archive this conversation?', 'chatbot-plugin'),
                'confirmActivateText' => __('Are you sure you want to reactivate this conversation?', 'chatbot-plugin')
            )
        );
    }
    
    /**
     * AJAX handler for resetting rate limits
     */
    public function ajax_reset_rate_limits() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_reset_rate_limits')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }

        // Check if rate limiter class exists
        if (!class_exists('Chatbot_Rate_Limiter')) {
            wp_send_json_error(array('message' => 'Rate limiter not available.'));
            return;
        }

        // Reset all rate limits
        $rate_limiter = Chatbot_Rate_Limiter::get_instance();
        $result = $rate_limiter->reset_all_rate_limits();

        if ($result) {
            wp_send_json_success(array('message' => 'All rate limits have been reset successfully.'));
        } else {
            wp_send_json_error(array('message' => 'Failed to reset rate limits.'));
        }
    }

    /**
     * AJAX handler for testing rate limits
     */
    public function ajax_test_rate_limits() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_test_rate_limits')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }

        // Check if rate limiter class exists
        if (!class_exists('Chatbot_Rate_Limiter')) {
            wp_send_json_error(array('message' => 'Rate limiter not available.'));
            return;
        }

        // Get rate limiter instance
        $rate_limiter = Chatbot_Rate_Limiter::get_instance();

        // Run a test sequence
        $results = array();
        $test_user_id = 'test_user_' . uniqid();

        // Reset limits first
        $rate_limiter->reset_all_rate_limits();
        $results[] = "- Rate limits reset successfully";

        // Get current rate limit settings
        $limits = array();
        foreach ($rate_limiter->option_names as $key => $option_name) {
            $limits[$key] = get_option($option_name, $rate_limiter->default_limits[$key]);
        }

        $results[] = "- Current rate limit settings:";
        foreach ($limits as $key => $value) {
            $results[] = "  * $key: $value";
        }

        // Test 1: First message should succeed
        $check_result = $rate_limiter->can_send_message($test_user_id);
        $results[] = "- Test 1: First message check: " . ($check_result['allowed'] ? "PASS" : "FAIL - " . $check_result['reason']);

        // Increment counter
        $rate_limiter->increment_rate_counters($test_user_id);
        $results[] = "- Incremented counters for first message";

        // Test 2: Second message should also succeed
        $check_result = $rate_limiter->can_send_message($test_user_id);
        $results[] = "- Test 2: Second message check: " . ($check_result['allowed'] ? "PASS" : "FAIL - " . $check_result['reason']);

        // Simulate sending messages up to per-minute limit
        $per_minute_limit = $limits['messages_per_minute'];
        $results[] = "- Simulating sending " . ($per_minute_limit - 1) . " more messages";

        for ($i = 0; $i < $per_minute_limit - 1; $i++) {
            $rate_limiter->increment_rate_counters($test_user_id);
        }

        // Test 3: After reaching limit, should fail
        $check_result = $rate_limiter->can_send_message($test_user_id);
        $results[] = "- Test 3: After sending " . $per_minute_limit . " messages: " .
                   (!$check_result['allowed'] ? "PASS - Blocked as expected: " . $check_result['reason'] : "FAIL - Should be blocked");

        // Test 4: Different user should still be able to send
        $different_user_id = 'different_user_' . uniqid();
        $check_result = $rate_limiter->can_send_message($different_user_id);
        $results[] = "- Test 4: Different user test: " .
                   ($check_result['allowed'] ? "PASS - Different user not affected" : "FAIL - " . $check_result['reason']);

        // Clean up by resetting rate limits again
        $rate_limiter->reset_all_rate_limits();
        $results[] = "- Cleanup: Rate limits reset";

        // Format results as a string
        $results_text = implode("\n", $results);

        wp_send_json_success(array(
            'message' => 'Rate limit testing completed',
            'results' => $results_text
        ));
    }

    /**
     * AJAX handler for testing AI response
     */
    public function ajax_test_ai_response() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_test_ai_response')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }

        // Get test message
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';

        if (empty($message)) {
            wp_send_json_error(array('message' => 'No test message provided.'));
            return;
        }

        // Check if AI class exists
        if (!class_exists('Chatbot_AI')) {
            wp_send_json_error(array('message' => 'AI integration not available.'));
            return;
        }

        // Create test conversation in the database
        $db = Chatbot_DB::get_instance();
        $conversation_id = $db->create_conversation('Test User', null, 'Test');

        if (!$conversation_id) {
            wp_send_json_error(array('message' => 'Failed to create test conversation.'));
            return;
        }

        // Add the test message to the conversation
        $db->add_message($conversation_id, 'user', $message);

        // Get AI instance
        $ai = Chatbot_AI::get_instance();

        // Check if AIPass is connected
        if (!$ai->is_configured()) {
            wp_send_json_error(array('message' => 'AIPass not connected. Please connect AIPass in the AI Integration tab.'));
            return;
        }

        // Generate a response
        $response = $ai->generate_response($conversation_id, $message);

        // Clean up test conversation
        $db->delete_conversation($conversation_id);

        // Send the response
        wp_send_json_success(array(
            'response' => nl2br(esc_html($response)),
            'raw_response' => $response
        ));
    }

    /**
     * AJAX handler for searching WordPress content for knowledge sources
     */
    public function ajax_search_content() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-admin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }

        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;

        $db = Chatbot_DB::get_instance();
        $results = $db->search_posts_for_knowledge($search, $limit);

        wp_send_json_success(array(
            'posts' => $results,
            'count' => count($results)
        ));
    }

    /**
     * AJAX handler for getting token counts for selected posts
     */
    public function ajax_get_post_tokens() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-admin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }

        $post_ids = isset($_POST['post_ids']) ? $_POST['post_ids'] : array();

        if (!is_array($post_ids)) {
            $post_ids = json_decode($post_ids, true);
        }

        if (empty($post_ids) || !is_array($post_ids)) {
            wp_send_json_success(array('total_tokens' => 0, 'posts' => array()));
            return;
        }

        $db = Chatbot_DB::get_instance();
        $total_tokens = 0;
        $post_data = array();

        foreach ($post_ids as $post_id) {
            $data = $db->extract_post_content(intval($post_id));
            if ($data) {
                $total_tokens += $data['token_count'];
                $post_data[] = array(
                    'id' => $data['id'],
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'token_count' => $data['token_count']
                );
            }
        }

        wp_send_json_success(array(
            'total_tokens' => $total_tokens,
            'posts' => $post_data,
            'max_tokens' => 100000
        ));
    }

    /**
     * AJAX handler for sending an admin message
     */
    public function admin_send_message() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-admin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }
        
        // Get parameters
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        
        if (empty($message) || empty($conversation_id)) {
            wp_send_json_error(array('message' => 'Missing required parameters.'));
        }
        
        // Check if conversation is active
        $db = Chatbot_DB::get_instance();
        $conversation = $db->get_conversation($conversation_id);
        
        if (!$conversation || $conversation->status !== 'active') {
            wp_send_json_error(array('message' => 'Cannot send message to inactive conversation.'));
        }
        
        // Add the message to the database
        $message_id = $db->add_message($conversation_id, 'admin', $message);

        if (!$message_id) {
            wp_send_json_error(array('message' => 'Error saving message.'));
        }

        // Check if this is a platform conversation (Telegram, WhatsApp, etc.) and send message to platform
        $platform_sent = false;
        $platform_error = null;

        if (!empty($conversation->platform_type) && !empty($conversation->platform_chat_id)) {
            // Get chatbot config for bot token
            $config = $db->get_configuration($conversation->chatbot_config_id);

            if ($conversation->platform_type === 'telegram' && $config && !empty($config->telegram_bot_token)) {
                // Send to Telegram
                $platform_sent = $this->send_telegram_message($config->telegram_bot_token, $conversation->platform_chat_id, $message);
                if (!$platform_sent) {
                    $platform_error = 'Failed to send message to Telegram';
                }
            } elseif ($conversation->platform_type === 'whatsapp' && class_exists('Chatbot_Platform_WhatsApp')) {
                // Send to WhatsApp
                $whatsapp = Chatbot_Platform_WhatsApp::get_instance();
                $platform_sent = $whatsapp->send_message($conversation->chatbot_config_id, $conversation->platform_chat_id, $message);
                if (!$platform_sent) {
                    $platform_error = 'Failed to send message to WhatsApp';
                }
            }
        } elseif (!empty($conversation->telegram_chat_id)) {
            // Legacy Telegram support
            $config = $db->get_configuration($conversation->chatbot_config_id);

            if ($config && !empty($config->telegram_bot_token)) {
                $platform_sent = $this->send_telegram_message($config->telegram_bot_token, $conversation->telegram_chat_id, $message);
                if (!$platform_sent) {
                    $platform_error = 'Failed to send message to Telegram';
                }
            }
        }

        // Get the updated message object
        $message_obj = (object) array(
            'id' => $message_id,
            'conversation_id' => $conversation_id,
            'sender_type' => 'admin',
            'message' => $message,
            'timestamp' => current_time('mysql')
        );

        $response_data = array(
            'message_id' => $message_id,
            'message' => $message_obj,
            'formatted_time' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($message_obj->timestamp))
        );

        // Add platform info to response
        if (!empty($conversation->platform_type) || !empty($conversation->telegram_chat_id)) {
            $response_data['platform_sent'] = $platform_sent;
            if ($platform_error) {
                $response_data['platform_error'] = $platform_error;
            }
        }

        wp_send_json_success($response_data);
    }

    /**
     * Send a message to Telegram
     *
     * @param string $bot_token The Telegram bot token
     * @param string $chat_id The Telegram chat ID
     * @param string $message The message to send
     * @return bool Whether the message was sent successfully
     */
    private function send_telegram_message($bot_token, $chat_id, $message) {
        $api_url = "https://api.telegram.org/bot{$bot_token}/sendMessage";

        $response = wp_remote_post($api_url, array(
            'body' => array(
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => 'HTML'
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            chatbot_log('ERROR', 'admin_send_telegram', 'Failed to send Telegram message', array(
                'error' => $response->get_error_message()
            ));
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['ok']) || !$body['ok']) {
            chatbot_log('ERROR', 'admin_send_telegram', 'Telegram API error', array(
                'response' => $body
            ));
            return false;
        }

        chatbot_log('INFO', 'admin_send_telegram', 'Message sent to Telegram', array(
            'chat_id' => $chat_id
        ));

        return true;
    }
    
    /**
     * Handle adding a new chatbot configuration
     */
    private function handle_add_configuration() {
        $name = isset($_POST['chatbot_config_name']) ? sanitize_text_field($_POST['chatbot_config_name']) : '';
        $system_prompt = isset($_POST['chatbot_system_prompt']) ? sanitize_textarea_field($_POST['chatbot_system_prompt']) : '';
        
        if (empty($name)) {
            add_settings_error('chatbot_plugin', 'name-required', __('Chatbot name is required.', 'chatbot-plugin'), 'error');
            return;
        }
        
        // Log the attempt to add a configuration
        chatbot_log('INFO', 'handle_add_configuration', 'Attempting to add new chatbot configuration', array(
            'name' => $name,
            'system_prompt_length' => strlen($system_prompt)
        ));
        
        $db = Chatbot_DB::get_instance();
        
        // Check if name already exists
        if ($db->configuration_name_exists($name)) {
            add_settings_error('chatbot_plugin', 'name-exists', __('A chatbot with this name already exists.', 'chatbot-plugin'), 'error');
            return;
        }
        
        // Validate inputs further
        if (empty($system_prompt)) {
            // Set a default system prompt if none provided
            $system_prompt = "You are a helpful AI assistant embedded on a WordPress website. Your goal is to provide accurate, helpful responses to user questions. Be concise but thorough, and always maintain a professional and friendly tone.";
            chatbot_log('INFO', 'handle_add_configuration', 'Using default system prompt as none was provided');
        }
        
        // Try to add the configuration
        try {
            $result = $db->add_configuration($name, $system_prompt);
            
            if ($result) {
                // Successfully added configuration
                chatbot_log('INFO', 'handle_add_configuration', 'Successfully added chatbot configuration', array('id' => $result));
                add_settings_error('chatbot_plugin', 'add-success', __('Chatbot added successfully.', 'chatbot-plugin'), 'updated');
                
                // Add JavaScript redirect instead of PHP redirect to avoid header issues
                echo '<script type="text/javascript">
                    window.location.href = "' . admin_url('admin.php?page=chatbot-configurations') . '";
                </script>';
                return;
            } else {
                // Failed to add configuration
                global $wpdb;
                chatbot_log('ERROR', 'handle_add_configuration', 'Failed to add chatbot configuration', array(
                    'db_error' => $wpdb->last_error,
                    'db_last_query' => $wpdb->last_query
                ));
                add_settings_error('chatbot_plugin', 'add-error', sprintf(
                    __('Failed to add chatbot: %s', 'chatbot-plugin'), 
                    $wpdb->last_error ? $wpdb->last_error : 'Unknown database error'
                ), 'error');
            }
        } catch (Exception $e) {
            // Catch any exceptions
            chatbot_log('ERROR', 'handle_add_configuration', 'Exception thrown while adding chatbot configuration', array(
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            add_settings_error('chatbot_plugin', 'add-error', sprintf(
                __('Error adding chatbot: %s', 'chatbot-plugin'), 
                $e->getMessage()
            ), 'error');
        }
    }
    
    /**
     * Handle updating a chatbot configuration
     */
    private function handle_update_configuration() {
        $id = isset($_POST['configuration_id']) ? intval($_POST['configuration_id']) : 0;
        $name = isset($_POST['chatbot_config_name']) ? sanitize_text_field($_POST['chatbot_config_name']) : '';
        $system_prompt = isset($_POST['chatbot_system_prompt']) ? sanitize_textarea_field($_POST['chatbot_system_prompt']) : '';
        
        if (empty($id) || empty($name)) {
            add_settings_error('chatbot_plugin', 'update-error', __('Invalid configuration data.', 'chatbot-plugin'), 'error');
            return;
        }
        
        $db = Chatbot_DB::get_instance();
        
        // Check if name already exists for a different configuration
        if ($db->configuration_name_exists($name, $id)) {
            add_settings_error('chatbot_plugin', 'name-exists', __('A chatbot with this name already exists.', 'chatbot-plugin'), 'error');
            return;
        }
        
        // Update the configuration
        $result = $db->update_configuration($id, $name, $system_prompt);
        
        if ($result) {
            add_settings_error('chatbot_plugin', 'update-success', __('Chatbot updated successfully.', 'chatbot-plugin'), 'updated');
        } else {
            add_settings_error('chatbot_plugin', 'update-error', __('Failed to update chatbot.', 'chatbot-plugin'), 'error');
        }
    }
    
    /**
     * Handle deleting a chatbot configuration
     */
    private function handle_delete_configuration($id) {
        if (empty($id)) {
            add_settings_error('chatbot_plugin', 'delete-error', __('Invalid configuration ID.', 'chatbot-plugin'), 'error');
            return;
        }

        $db = Chatbot_DB::get_instance();

        // Get configuration before deleting to check for Telegram integration
        $config = $db->get_configuration($id);
        if ($config && !empty($config->telegram_bot_token)) {
            // Unregister Telegram webhook before deleting configuration
            $telegram = Chatbot_Telegram::get_instance();
            $telegram->unregister_webhook($id, $config->telegram_bot_token);
            // Delete the secret token option
            delete_option('chatbot_telegram_secret_' . $id);
            chatbot_log('INFO', 'handle_delete_configuration', 'Unregistered Telegram webhook for config', array('config_id' => $id));
        }

        $result = $db->delete_configuration($id);

        if ($result) {
            add_settings_error('chatbot_plugin', 'delete-success', __('Chatbot deleted successfully.', 'chatbot-plugin'), 'updated');
        } else {
            add_settings_error('chatbot_plugin', 'delete-error', __('Failed to delete chatbot.', 'chatbot-plugin'), 'error');
        }
    }
    
    /**
     * Process adding a new chatbot configuration via admin-post
     */
    public function process_add_configuration() {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'chatbot_add_configuration_nonce')) {
            wp_die(__('Security check failed.', 'chatbot-plugin'));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to add configurations.', 'chatbot-plugin'));
        }
        
        // Get form data
        $name = isset($_POST['chatbot_config_name']) ? sanitize_text_field($_POST['chatbot_config_name']) : '';
        $system_prompt = isset($_POST['chatbot_system_prompt']) ? sanitize_textarea_field($_POST['chatbot_system_prompt']) : '';
        $knowledge = isset($_POST['chatbot_knowledge']) ? sanitize_textarea_field($_POST['chatbot_knowledge']) : '';
        $persona = isset($_POST['chatbot_persona']) ? sanitize_textarea_field($_POST['chatbot_persona']) : '';
        $knowledge_sources = isset($_POST['chatbot_knowledge_sources']) ? sanitize_text_field($_POST['chatbot_knowledge_sources']) : '';
        $telegram_bot_token = isset($_POST['chatbot_telegram_bot_token']) ? sanitize_text_field($_POST['chatbot_telegram_bot_token']) : '';

        // Validate knowledge_sources is valid JSON if provided
        if (!empty($knowledge_sources)) {
            $decoded = json_decode($knowledge_sources, true);
            if (!is_array($decoded)) {
                $knowledge_sources = ''; // Invalid JSON, reset to empty
            }
        }

        // Validate inputs
        if (empty($name)) {
            $redirect_url = add_query_arg(
                array(
                    'page' => 'chatbot-configurations',
                    'action' => 'add',
                    'error' => 'name_required'
                ),
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect_url);
            exit;
        }

        $db = Chatbot_DB::get_instance();

        // Check if name already exists
        if ($db->configuration_name_exists($name)) {
            $redirect_url = add_query_arg(
                array(
                    'page' => 'chatbot-configurations',
                    'action' => 'add',
                    'error' => 'name_exists'
                ),
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect_url);
            exit;
        }

        // Set default knowledge if empty
        if (empty($knowledge)) {
            $knowledge = "This is a WordPress website. WordPress is a popular content management system used " .
                         "to create websites, blogs, and online stores. The website may contain blog posts, " .
                         "pages, products, or other content types common to WordPress sites.";
        }

        // Set default persona if empty
        if (empty($persona)) {
            $persona = "You are a helpful, friendly, and professional assistant. You should respond in a " .
                       "conversational tone while maintaining accuracy and being concise. Aim to be " .
                       "informative but not overly technical unless specifically asked for technical details. " .
                       "Be patient and considerate in your responses. If you don't know something, admit it " .
                       "rather than making up information.";
        }

        // For backwards compatibility, update system prompt if it's empty or if we're using the new fields
        if (empty($system_prompt) || (!empty($knowledge) && !empty($persona))) {
            // Construct a system prompt that combines knowledge and persona
            $system_prompt = $this->build_system_prompt($knowledge, $persona);
        }

        // Log what we're attempting to add
        chatbot_log('DEBUG', 'process_add_configuration', 'Adding configuration with separate fields', array(
            'name' => $name,
            'knowledge_length' => strlen($knowledge),
            'persona_length' => strlen($persona),
            'system_prompt_length' => strlen($system_prompt),
            'knowledge_sources' => $knowledge_sources,
            'telegram_bot_token' => !empty($telegram_bot_token) ? 'set' : 'empty'
        ));

        // Add the configuration
        $result = $db->add_configuration($name, $system_prompt, $knowledge, $persona, $knowledge_sources, $telegram_bot_token);
        
        if ($result) {
            // Success
            $redirect_url = add_query_arg(
                array(
                    'page' => 'chatbot-configurations',
                    'success' => 'add'
                ),
                admin_url('admin.php')
            );
        } else {
            // Failure
            $redirect_url = add_query_arg(
                array(
                    'page' => 'chatbot-configurations',
                    'action' => 'add',
                    'error' => 'db_error'
                ),
                admin_url('admin.php')
            );
        }
        
        wp_safe_redirect($redirect_url);
        exit;
    }
    
    /**
     * Build a system prompt that combines knowledge and persona
     * 
     * @param string $knowledge Knowledge content
     * @param string $persona Persona content
     * @return string Combined system prompt
     */
    private function build_system_prompt($knowledge, $persona) {
        // First, add the persona
        $system_prompt = $persona;
        
        // Add a separator if both persona and knowledge are provided
        if (!empty($persona) && !empty($knowledge)) {
            $system_prompt .= "\n\n### KNOWLEDGE BASE ###\n\n";
        }
        
        // Add the knowledge if it exists
        if (!empty($knowledge)) {
            $system_prompt .= $knowledge;
        }
        
        // Always add instruction to consult knowledge base when responding
        $system_prompt .= "\n\nWhen responding to user questions, always consult the knowledge base provided above to ensure accurate information.";
        
        return $system_prompt;
    }
    
    /**
     * Process updating a chatbot configuration via admin-post
     */
    public function process_update_configuration() {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'chatbot_update_configuration_nonce')) {
            wp_die(__('Security check failed.', 'chatbot-plugin'));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to update configurations.', 'chatbot-plugin'));
        }
        
        // Get form data
        $id = isset($_POST['configuration_id']) ? intval($_POST['configuration_id']) : 0;
        $name = isset($_POST['chatbot_config_name']) ? sanitize_text_field($_POST['chatbot_config_name']) : '';
        $system_prompt = isset($_POST['chatbot_system_prompt']) ? sanitize_textarea_field($_POST['chatbot_system_prompt']) : '';
        $knowledge = isset($_POST['chatbot_knowledge']) ? sanitize_textarea_field($_POST['chatbot_knowledge']) : '';
        $persona = isset($_POST['chatbot_persona']) ? sanitize_textarea_field($_POST['chatbot_persona']) : '';
        $knowledge_sources = isset($_POST['chatbot_knowledge_sources']) ? sanitize_text_field($_POST['chatbot_knowledge_sources']) : '';
        $telegram_bot_token = isset($_POST['chatbot_telegram_bot_token']) ? sanitize_text_field($_POST['chatbot_telegram_bot_token']) : '';

        // Build n8n settings JSON
        $n8n_enabled = isset($_POST['chatbot_n8n_enabled']) && $_POST['chatbot_n8n_enabled'] === '1';
        $n8n_webhook_url = isset($_POST['chatbot_n8n_webhook_url']) ? esc_url_raw($_POST['chatbot_n8n_webhook_url']) : '';
        $n8n_webhook_secret = isset($_POST['chatbot_n8n_webhook_secret']) ? sanitize_text_field($_POST['chatbot_n8n_webhook_secret']) : '';
        $n8n_timeout = isset($_POST['chatbot_n8n_timeout']) ? absint($_POST['chatbot_n8n_timeout']) : 300;
        $n8n_actions_raw = isset($_POST['chatbot_n8n_actions']) ? wp_unslash($_POST['chatbot_n8n_actions']) : '[]';

        // Sanitize n8n actions
        $n8n_actions = array();
        $decoded_actions = json_decode($n8n_actions_raw, true);
        if (is_array($decoded_actions)) {
            foreach ($decoded_actions as $action) {
                $sanitized_action = array(
                    'name' => isset($action['name']) ? sanitize_key($action['name']) : '',
                    'description' => isset($action['description']) ? sanitize_text_field($action['description']) : '',
                    'parameters' => array()
                );
                if (isset($action['parameters']) && is_array($action['parameters'])) {
                    foreach ($action['parameters'] as $param) {
                        $sanitized_action['parameters'][] = array(
                            'name' => isset($param['name']) ? sanitize_key($param['name']) : '',
                            'type' => isset($param['type']) ? sanitize_key($param['type']) : 'string',
                            'description' => isset($param['description']) ? sanitize_text_field($param['description']) : '',
                            'required' => isset($param['required']) ? (bool) $param['required'] : false
                        );
                    }
                }
                if (!empty($sanitized_action['name'])) {
                    $n8n_actions[] = $sanitized_action;
                }
            }
        }

        // Sanitize n8n headers
        $n8n_headers_raw = isset($_POST['chatbot_n8n_headers']) ? wp_unslash($_POST['chatbot_n8n_headers']) : '[]';
        $n8n_headers = array();
        $decoded_headers = json_decode($n8n_headers_raw, true);
        if (is_array($decoded_headers)) {
            foreach ($decoded_headers as $header) {
                $header_name = isset($header['name']) ? sanitize_text_field($header['name']) : '';
                $header_value = isset($header['value']) ? sanitize_text_field($header['value']) : '';
                if (!empty($header_name)) {
                    $n8n_headers[] = array(
                        'name' => $header_name,
                        'value' => $header_value
                    );
                }
            }
        }

        $n8n_settings = wp_json_encode(array(
            'enabled' => $n8n_enabled,
            'webhook_url' => $n8n_webhook_url,
            'webhook_secret' => $n8n_webhook_secret,
            'timeout' => $n8n_timeout,
            'headers' => $n8n_headers,
            'actions' => $n8n_actions
        ));

        // Validate knowledge_sources is valid JSON if provided
        if (!empty($knowledge_sources)) {
            $decoded = json_decode($knowledge_sources, true);
            if (!is_array($decoded)) {
                $knowledge_sources = ''; // Invalid JSON, reset to empty
            }
        }

        // Validate inputs
        if (empty($id) || empty($name)) {
            $redirect_url = add_query_arg(
                array(
                    'page' => 'chatbot-configurations',
                    'action' => 'edit',
                    'id' => $id,
                    'error' => 'invalid_data'
                ),
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect_url);
            exit;
        }

        $db = Chatbot_DB::get_instance();

        // Check if name already exists for a different configuration
        if ($db->configuration_name_exists($name, $id)) {
            $redirect_url = add_query_arg(
                array(
                    'page' => 'chatbot-configurations',
                    'action' => 'edit',
                    'id' => $id,
                    'error' => 'name_exists'
                ),
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect_url);
            exit;
        }

        // For backwards compatibility, update system prompt if it's empty or if we're using the new fields
        if (empty($system_prompt) || (!empty($knowledge) && !empty($persona))) {
            // Construct a system prompt that combines knowledge and persona
            $system_prompt = $this->build_system_prompt($knowledge, $persona);
        }

        // Log what we're attempting to update
        chatbot_log('DEBUG', 'process_update_configuration', 'Updating configuration with separate fields', array(
            'id' => $id,
            'name' => $name,
            'knowledge_length' => strlen($knowledge),
            'persona_length' => strlen($persona),
            'system_prompt_length' => strlen($system_prompt),
            'knowledge_sources' => $knowledge_sources,
            'telegram_bot_token' => !empty($telegram_bot_token) ? 'set' : 'empty',
            'n8n_enabled' => $n8n_enabled ? 'yes' : 'no',
            'n8n_action_count' => count($n8n_actions)
        ));

        // Update the configuration
        $result = $db->update_configuration($id, $name, $system_prompt, $knowledge, $persona, $knowledge_sources, $telegram_bot_token, $n8n_settings);
        
        if ($result) {
            // Success
            $redirect_url = add_query_arg(
                array(
                    'page' => 'chatbot-configurations',
                    'success' => 'update'
                ),
                admin_url('admin.php')
            );
        } else {
            // Failure
            $redirect_url = add_query_arg(
                array(
                    'page' => 'chatbot-configurations',
                    'action' => 'edit',
                    'id' => $id,
                    'error' => 'db_error'
                ),
                admin_url('admin.php')
            );
        }
        
        wp_safe_redirect($redirect_url);
        exit;
    }
}

// Initialize the admin
function chatbot_admin_init() {
    return Chatbot_Admin::get_instance();
}
chatbot_admin_init();