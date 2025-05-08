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
        
        // Handle admin actions
        add_action('admin_init', array($this, 'handle_admin_actions'));
        
        // Add admin-post handlers for form submissions
        add_action('admin_post_chatbot_add_configuration', array($this, 'process_add_configuration'));
        add_action('admin_post_chatbot_update_configuration', array($this, 'process_update_configuration'));
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
            
            <div class="card">
                <h2><?php _e('Chatbot Plugin Settings', 'chatbot-plugin'); ?></h2>
                <p><?php _e('Thank you for using Chatbot Plugin! To add the chatbot to your site, use the shortcode below:', 'chatbot-plugin'); ?></p>
                <p><code>[chatbot]</code> <?php _e('or', 'chatbot-plugin'); ?> <code>[chatbot theme="dark"]</code></p>
                <p><?php _e('You can place this shortcode in any post, page, or widget area where shortcodes are supported.', 'chatbot-plugin'); ?></p>
            </div>
            
            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('Recent Conversations', 'chatbot-plugin'); ?></h2>
                <?php $this->display_recent_conversations(); ?>
                
                <p>
                    <a href="<?php echo admin_url('admin.php?page=chatbot-conversations'); ?>" class="button button-primary">
                        <?php _e('View All Conversations', 'chatbot-plugin'); ?>
                    </a>
                </p>
            </div>
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
            
            if ($success_type === 'add') {
                add_settings_error('chatbot_plugin', 'add-success', __('Chatbot added successfully.', 'chatbot-plugin'), 'updated');
            } elseif ($success_type === 'update') {
                add_settings_error('chatbot_plugin', 'update-success', __('Chatbot updated successfully.', 'chatbot-plugin'), 'updated');
            }
        }
        
        // Check for error messages from admin-post redirects
        if (isset($_GET['error'])) {
            $error_type = sanitize_text_field($_GET['error']);
            
            if ($error_type === 'name_required') {
                add_settings_error('chatbot_plugin', 'name-required', __('Chatbot name is required.', 'chatbot-plugin'), 'error');
            } elseif ($error_type === 'name_exists') {
                add_settings_error('chatbot_plugin', 'name-exists', __('A chatbot with this name already exists.', 'chatbot-plugin'), 'error');
            } elseif ($error_type === 'db_error') {
                add_settings_error('chatbot_plugin', 'db-error', __('Failed to add or update chatbot. Please try again.', 'chatbot-plugin'), 'error');
            } elseif ($error_type === 'invalid_data') {
                add_settings_error('chatbot_plugin', 'invalid-data', __('Invalid configuration data.', 'chatbot-plugin'), 'error');
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
                                    <label for="chatbot_system_prompt"><?php _e('Knowledge and Persona', 'chatbot-plugin'); ?></label>
                                </th>
                                <td>
                                    <textarea name="chatbot_system_prompt" id="chatbot_system_prompt" class="large-text code" rows="10" data-original-value="<?php echo esc_attr($system_prompt); ?>"><?php echo esc_textarea($system_prompt); ?></textarea>
                                    <p class="description"><?php _e('Define the knowledge and personality for this chatbot.', 'chatbot-plugin'); ?></p>
                                    <button type="button" class="button" id="chatbot_improve_prompt" 
                                        data-nonce-test="<?php echo wp_create_nonce('chatbot_test_openai_nonce'); ?>"
                                        data-nonce-improve="<?php echo wp_create_nonce('chatbot_improve_prompt_nonce'); ?>">
                                        <?php _e('Improve with AI', 'chatbot-plugin'); ?>
                                    </button>
                                    <span id="chatbot_improve_prompt_status"></span>
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
                                        <a href="<?php echo admin_url('admin.php?page=chatbot-conversations&chatbot=' . $config->id); ?>">
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
        $chatbot_filter = isset($_GET['chatbot']) ? intval($_GET['chatbot']) : null;
        
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
                    <form method="get">
                        <input type="hidden" name="page" value="chatbot-conversations">
                        <select name="status">
                            <option value="all" <?php selected($status_filter, 'all'); ?>><?php _e('All Conversations', 'chatbot-plugin'); ?></option>
                            <option value="active" <?php selected($status_filter, 'active'); ?>><?php _e('Active', 'chatbot-plugin'); ?></option>
                            <option value="ended" <?php selected($status_filter, 'ended'); ?>><?php _e('Ended', 'chatbot-plugin'); ?></option>
                            <option value="archived" <?php selected($status_filter, 'archived'); ?>><?php _e('Archived', 'chatbot-plugin'); ?></option>
                        </select>
                        
                        <!-- Add chatbot filter dropdown -->
                        <select name="chatbot">
                            <option value=""><?php _e('All Chatbots', 'chatbot-plugin'); ?></option>
                            <?php foreach ($chatbot_configs as $config): ?>
                                <option value="<?php echo esc_attr($config->id); ?>" <?php selected($chatbot_filter, $config->id); ?>>
                                    <?php echo esc_html($config->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'chatbot-plugin'); ?>">
                    </form>
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
                                            <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($message->timestamp)); ?>
                                        </span>
                                    </div>
                                    <div class="chatbot-admin-message-content">
                                        <?php echo nl2br(esc_html($message->message)); ?>
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
        
        // Add custom admin script
        wp_enqueue_script(
            'chatbot-admin-script',
            CHATBOT_PLUGIN_URL . 'assets/js/chatbot-admin.js',
            array('jquery', 'media-upload', 'thickbox'),
            CHATBOT_PLUGIN_VERSION,
            true
        );
        
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
        
        // Set default system prompt if empty
        if (empty($system_prompt)) {
            $system_prompt = "You are a helpful AI assistant embedded on a WordPress website. Your goal is to provide accurate, helpful responses to user questions. Be concise but thorough, and always maintain a professional and friendly tone.";
        }
        
        // Add the configuration
        $result = $db->add_configuration($name, $system_prompt);
        
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
        
        // Update the configuration
        $result = $db->update_configuration($id, $name, $system_prompt);
        
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