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

        // Register admin-specific scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Register AJAX handlers for admin
        add_action('wp_ajax_chatbot_admin_send_message', array($this, 'admin_send_message'));
        add_action('wp_ajax_chatbot_reset_rate_limits', array($this, 'ajax_reset_rate_limits'));
        add_action('wp_ajax_chatbot_test_rate_limits', array($this, 'ajax_test_rate_limits'));
        add_action('wp_ajax_chatbot_test_ai_response', array($this, 'ajax_test_ai_response'));

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
        // Main plugin page - Changed label to 'Chat Bots'
        add_menu_page(
            __('Chatbot Plugin', 'chatbot-plugin'),
            __('Chat Bots', 'chatbot-plugin'),
            'manage_options',
            'chatbot-plugin',
            array($this, 'display_admin_page'),
            'dashicons-format-chat',
            100
        );
        
        // First submenu - renamed to 'Overview'
        add_submenu_page(
            'chatbot-plugin',
            __('Chatbot Overview', 'chatbot-plugin'),
            __('Overview', 'chatbot-plugin'),
            'manage_options',
            'chatbot-plugin',
            array($this, 'display_admin_page')
        );
        
        // Chatbots management submenu
        add_submenu_page(
            'chatbot-plugin',
            __('Chat Bots', 'chatbot-plugin'),
            __('Chat Bots', 'chatbot-plugin'),
            'manage_options',
            'chatbot-configurations',
            array($this, 'display_configurations_page')
        );
        
        // Conversations submenu
        add_submenu_page(
            'chatbot-plugin',
            __('Conversations', 'chatbot-plugin'),
            __('Conversations', 'chatbot-plugin'),
            'manage_options',
            'chatbot-conversations',
            array($this, 'display_conversations_page')
        );
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
                    
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="chatbot_config_name"><?php _e('Name', 'chatbot-plugin'); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="chatbot_config_name" id="chatbot_config_name" class="regular-text" value="<?php echo esc_attr($name); ?>" required>
                                    <p class="description"><?php _e('Enter a unique name for this chatbot. This will be used in the shortcode.', 'chatbot-plugin'); ?></p>
                                    <p class="description"><?php _e('Example shortcode: [chatbot name="product"]', 'chatbot-plugin'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="chatbot_knowledge"><?php _e('Knowledge Base', 'chatbot-plugin'); ?></label>
                                </th>
                                <td>
                                    <textarea name="chatbot_knowledge" id="chatbot_knowledge" class="large-text code" rows="10" data-original-value="<?php echo esc_attr($editing && isset($config->knowledge) ? $config->knowledge : $system_prompt); ?>"><?php echo esc_textarea($editing && isset($config->knowledge) ? $config->knowledge : $system_prompt); ?></textarea>
                                    <p class="description"><?php _e('Define the domain-specific knowledge for this chatbot. This information is what the chatbot will use to answer questions.', 'chatbot-plugin'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="chatbot_persona"><?php _e('Persona', 'chatbot-plugin'); ?></label>
                                </th>
                                <td>
                                    <textarea name="chatbot_persona" id="chatbot_persona" class="large-text code" rows="7" data-original-value="<?php echo esc_attr($editing && isset($config->persona) ? $config->persona : 'You are a helpful, friendly, and professional assistant. Respond to user inquiries in a conversational tone while maintaining accuracy and being concise.'); ?>"><?php echo esc_textarea($editing && isset($config->persona) ? $config->persona : 'You are a helpful, friendly, and professional assistant. Respond to user inquiries in a conversational tone while maintaining accuracy and being concise.'); ?></textarea>
                                    <p class="description"><?php _e('Define the personality and tone for this chatbot. This controls how the AI responds to users.', 'chatbot-plugin'); ?></p>
                                    <p class="description"><?php _e('Important: The AI will read from the Knowledge Base when generating responses.', 'chatbot-plugin'); ?></p>
                                    <button type="button" class="button" id="chatbot_improve_prompt" 
                                        data-nonce-test="<?php echo wp_create_nonce('chatbot_test_openai_nonce'); ?>"
                                        data-nonce-improve="<?php echo wp_create_nonce('chatbot_improve_prompt_nonce'); ?>">
                                        <?php _e('Improve Persona with AI', 'chatbot-plugin'); ?>
                                    </button>
                                    <span id="chatbot_improve_prompt_status"></span>
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
                                                                        status.innerHTML = '<span style="color: red;">Error: OpenAI returned an empty response. Please try again.</span>';
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
                    
                    <p class="submit">
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
                    </ul>
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
                        <td><?php echo esc_html($conversation->visitor_name); ?></td>
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

        // Check if OpenAI class exists
        if (!class_exists('Chatbot_OpenAI')) {
            wp_send_json_error(array('message' => 'OpenAI integration not available.'));
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

        // Get OpenAI instance
        $openai = Chatbot_OpenAI::get_instance();

        // Check if API key is configured
        if (!$openai->is_configured()) {
            wp_send_json_error(array('message' => 'OpenAI API key not configured. Please add your API key in the OpenAI Integration tab.'));
            return;
        }

        // Generate a response
        $response = $openai->generate_response($conversation_id, $message);

        // Clean up test conversation
        $db->delete_conversation($conversation_id);

        // Send the response
        wp_send_json_success(array(
            'response' => nl2br(esc_html($response)),
            'raw_response' => $response
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
        
        // Get the updated message object
        $message_obj = (object) array(
            'id' => $message_id,
            'conversation_id' => $conversation_id,
            'sender_type' => 'admin',
            'message' => $message,
            'timestamp' => current_time('mysql')
        );
        
        wp_send_json_success(array(
            'message_id' => $message_id,
            'message' => $message_obj,
            'formatted_time' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($message_obj->timestamp))
        ));
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
            'system_prompt_length' => strlen($system_prompt)
        ));
        
        // Add the configuration
        $result = $db->add_configuration($name, $system_prompt, $knowledge, $persona);
        
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
            'system_prompt_length' => strlen($system_prompt)
        ));
        
        // Update the configuration
        $result = $db->update_configuration($id, $name, $system_prompt, $knowledge, $persona);
        
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