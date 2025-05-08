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
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Main plugin page
        add_menu_page(
            __('Chatbot Plugin', 'chatbot-plugin'),
            __('Chatbot', 'chatbot-plugin'),
            'manage_options',
            'chatbot-plugin',
            array($this, 'display_admin_page'),
            'dashicons-format-chat',
            100
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
        
        $this->render_conversations_table($conversations);
    }
    
    /**
     * Display all conversations with pagination
     */
    private function display_all_conversations() {
        $db = Chatbot_DB::get_instance();
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        $conversations = $db->get_conversations($per_page, $offset);
        $total_conversations = $db->get_conversation_count();
        $total_pages = ceil($total_conversations / $per_page);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Conversations', 'chatbot-plugin'); ?></h1>
            
            <?php if (empty($conversations)): ?>
                <p><?php _e('No conversations found.', 'chatbot-plugin'); ?></p>
            <?php else: ?>
                <?php $this->render_conversations_table($conversations); ?>
                
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
            </h1>
            
            <p>
                <a href="<?php echo admin_url('admin.php?page=chatbot-conversations'); ?>" class="button">
                    &larr; <?php _e('Back to All Conversations', 'chatbot-plugin'); ?>
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
                                            <?php if ($message->sender_type === 'user'): ?>
                                                <?php echo esc_html($conversation->visitor_name); ?>
                                            <?php else: ?>
                                                <?php _e('Admin', 'chatbot-plugin'); ?>
                                            <?php endif; ?>
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
                    
                    <div class="chatbot-admin-reply">
                        <textarea id="chatbot-admin-reply-text" class="widefat" rows="3" placeholder="<?php esc_attr_e('Type your reply here...', 'chatbot-plugin'); ?>"></textarea>
                        <div class="chatbot-admin-reply-actions">
                            <button id="chatbot-admin-send" class="button button-primary" data-conversation-id="<?php echo esc_attr($conversation_id); ?>">
                                <?php _e('Send', 'chatbot-plugin'); ?>
                            </button>
                            <span id="chatbot-admin-status"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render a table of conversations
     * 
     * @param array $conversations Array of conversation objects
     */
    private function render_conversations_table($conversations) {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Visitor', 'chatbot-plugin'); ?></th>
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
                        <td><?php echo esc_html($conversation->visitor_name); ?></td>
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
                            <?php if ($conversation->is_active): ?>
                                <span class="chatbot-admin-status-active"><?php _e('Active', 'chatbot-plugin'); ?></span>
                            <?php else: ?>
                                <span class="chatbot-admin-status-inactive"><?php _e('Inactive', 'chatbot-plugin'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=chatbot-conversations&conversation_id=' . $conversation->id); ?>" class="button button-small">
                                <?php _e('View', 'chatbot-plugin'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
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
                'errorText' => __('Error sending message', 'chatbot-plugin')
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
        
        // Add the message to the database
        $db = Chatbot_DB::get_instance();
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
}

// Initialize the admin
function chatbot_admin_init() {
    return Chatbot_Admin::get_instance();
}
chatbot_admin_init();