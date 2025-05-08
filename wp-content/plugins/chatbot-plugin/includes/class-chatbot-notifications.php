<?php
/**
 * Chatbot Notifications Handler
 * 
 * Handles notification functions for the chatbot plugin:
 * - Immediate admin notification on new chats
 * - Daily summary emails with AI analysis
 * - Telegram notifications for new chats
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_Notifications {
    
    private static $instance = null;
    
    /**
     * Get singleton instance
     * 
     * @return Chatbot_Notifications The singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register hooks for new conversation notifications
        add_action('chatbot_conversation_created', array($this, 'notify_new_conversation'), 10, 2);
        
        // Schedule daily email report
        if (!wp_next_scheduled('chatbot_daily_email_report')) {
            wp_schedule_event(strtotime('tomorrow 7:00am'), 'daily', 'chatbot_daily_email_report');
        }
        add_action('chatbot_daily_email_report', array($this, 'send_daily_email_report'));
        
        // Register AJAX handler for testing Telegram connection
        add_action('wp_ajax_chatbot_test_telegram', array($this, 'test_telegram_connection'));
    }
    
    /**
     * Notify admin of new conversations
     * 
     * @param int $conversation_id The conversation ID
     * @param array $conversation_data The conversation data array
     */
    public function notify_new_conversation($conversation_id, $conversation_data) {
        chatbot_log('INFO', 'notify_new_conversation', 'Processing notifications for new conversation', 
            array('conversation_id' => $conversation_id, 'visitor_name' => $conversation_data['visitor_name']));
        
        // Check if email notifications are enabled
        if ($this->is_email_notification_enabled('new_conversation')) {
            $this->send_new_conversation_email($conversation_id, $conversation_data['visitor_name']);
        }
        
        // Check if Telegram notifications are enabled
        if ($this->is_telegram_notification_enabled('new_conversation')) {
            $this->send_telegram_notification($conversation_id, $conversation_data['visitor_name']);
        }
    }
    
    /**
     * Check if email notifications are enabled for a specific event
     * 
     * @param string $event The notification event
     * @return bool Whether notifications are enabled
     */
    private function is_email_notification_enabled($event) {
        $notify_events = get_option('chatbot_email_notify_events', array());
        
        if (!is_array($notify_events)) {
            $notify_events = array();
        }
        
        return in_array($event, $notify_events);
    }
    
    /**
     * Check if Telegram notifications are enabled for a specific event
     * 
     * @param string $event The notification event
     * @return bool Whether notifications are enabled
     */
    private function is_telegram_notification_enabled($event) {
        // Check if Telegram bot API key and chat ID are set
        $bot_api_key = get_option('chatbot_telegram_api_key', '');
        $chat_id = get_option('chatbot_telegram_chat_id', '');
        
        if (empty($bot_api_key) || empty($chat_id)) {
            return false;
        }
        
        $notify_events = get_option('chatbot_telegram_notify_events', array());
        
        if (!is_array($notify_events)) {
            $notify_events = array();
        }
        
        return in_array($event, $notify_events);
    }
    
    /**
     * Send email notification for new conversation
     * 
     * @param int $conversation_id The conversation ID
     * @param string $visitor_name The visitor's name
     */
    private function send_new_conversation_email($conversation_id, $visitor_name) {
        // Get the admin email
        $admin_email = get_option('admin_email');
        $notification_email = get_option('chatbot_notification_email', $admin_email);
        
        if (empty($notification_email)) {
            $notification_email = $admin_email;
        }
        
        $site_name = get_bloginfo('name');
        $subject = sprintf(__('[%s] New Chat: %s', 'chatbot-plugin'), $site_name, $visitor_name);
        
        // Build email content
        $message = sprintf(__('A new chat conversation has been started by %s.', 'chatbot-plugin'), $visitor_name) . "\n\n";
        $message .= sprintf(__('View this conversation: %s', 'chatbot-plugin'), 
            admin_url('admin.php?page=chatbot-conversations&conversation_id=' . $conversation_id)) . "\n\n";
        $message .= sprintf(__('View all conversations: %s', 'chatbot-plugin'), 
            admin_url('admin.php?page=chatbot-conversations')) . "\n\n";
        $message .= __('This email was sent by the Chatbot Plugin.', 'chatbot-plugin');
        
        // Build HTML email
        $html_message = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e5e5e5; border-radius: 5px;">';
        $html_message .= '<h2 style="color: #4a6cf7;">'. sprintf(__('New Chat from %s', 'chatbot-plugin'), esc_html($visitor_name)) .'</h2>';
        $html_message .= '<p style="font-size: 16px;">'. sprintf(__('A new chat conversation has been started by %s.', 'chatbot-plugin'), esc_html($visitor_name)) .'</p>';
        $html_message .= '<p><a href="'. esc_url(admin_url('admin.php?page=chatbot-conversations&conversation_id=' . $conversation_id)) .'" style="display: inline-block; background-color: #4a6cf7; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; margin: 10px 0;">';
        $html_message .= __('View Conversation', 'chatbot-plugin');
        $html_message .= '</a></p>';
        $html_message .= '<p style="margin-top: 30px; padding-top: 10px; border-top: 1px solid #e5e5e5; font-size: 12px; color: #666;">';
        $html_message .= __('This notification was sent from', 'chatbot-plugin') . ' ' . esc_html($site_name);
        $html_message .= '</p></div>';
        
        // Set headers for HTML email
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>'
        );
        
        // Send email
        $sent = wp_mail($notification_email, $subject, $html_message, $headers);
        
        if ($sent) {
            chatbot_log('INFO', 'send_new_conversation_email', 'Email notification sent successfully', 
                array('to' => $notification_email, 'conversation_id' => $conversation_id));
        } else {
            chatbot_log('ERROR', 'send_new_conversation_email', 'Failed to send email notification', 
                array('to' => $notification_email, 'conversation_id' => $conversation_id));
        }
    }
    
    /**
     * Send Telegram notification for new conversation
     * 
     * @param int $conversation_id The conversation ID
     * @param string $visitor_name The visitor's name
     */
    private function send_telegram_notification($conversation_id, $visitor_name) {
        $bot_api_key = get_option('chatbot_telegram_api_key', '');
        $chat_id = get_option('chatbot_telegram_chat_id', '');
        
        if (empty($bot_api_key) || empty($chat_id)) {
            chatbot_log('ERROR', 'send_telegram_notification', 'Telegram API key or chat ID not set');
            return;
        }
        
        $site_name = get_bloginfo('name');
        
        // Build message
        $message = "🤖 *New Chat on $site_name* 🤖\n\n";
        $message .= "Visitor: $visitor_name\n";
        $message .= "Time: " . current_time('mysql') . "\n\n";
        
        // Add a link to the conversation
        $conversation_url = admin_url('admin.php?page=chatbot-conversations&conversation_id=' . $conversation_id);
        $message .= "[View Conversation]($conversation_url)";
        
        // Prepare the API URL
        $telegram_api_url = "https://api.telegram.org/bot$bot_api_key/sendMessage";
        
        // Prepare the request parameters
        $params = array(
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true
        );
        
        // Make the API request
        $response = wp_remote_post($telegram_api_url, array(
            'body' => $params,
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            chatbot_log('ERROR', 'send_telegram_notification', 'Failed to send Telegram notification', 
                array('error' => $response->get_error_message()));
        } else {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($response_body['ok']) && $response_body['ok'] === true) {
                chatbot_log('INFO', 'send_telegram_notification', 'Telegram notification sent successfully');
            } else {
                chatbot_log('ERROR', 'send_telegram_notification', 'Telegram API error', 
                    array('response' => $response_body));
            }
        }
    }
    
    /**
     * Send daily email report with conversation summary
     */
    public function send_daily_email_report() {
        // Check if daily email reports are enabled
        if (!$this->is_email_notification_enabled('daily_summary')) {
            chatbot_log('INFO', 'send_daily_email_report', 'Daily email reports are disabled');
            return;
        }
        
        chatbot_log('INFO', 'send_daily_email_report', 'Preparing daily email report');
        
        // Get the admin email
        $admin_email = get_option('admin_email');
        $notification_email = get_option('chatbot_notification_email', $admin_email);
        
        if (empty($notification_email)) {
            $notification_email = $admin_email;
        }
        
        $site_name = get_bloginfo('name');
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        $subject = sprintf(__('[%s] Daily Chat Summary: %s', 'chatbot-plugin'), $site_name, $yesterday);
        
        try {
            // Get summary data
            $summary_data = $this->generate_daily_summary($yesterday);
            
            if (empty($summary_data)) {
                chatbot_log('INFO', 'send_daily_email_report', 'No conversations to report for yesterday');
                return;
            }
            
            // Build HTML email
            $html_message = '<div style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; border: 1px solid #e5e5e5; border-radius: 5px;">';
            $html_message .= '<h2 style="color: #4a6cf7;">'. sprintf(__('Daily Chat Summary for %s', 'chatbot-plugin'), date('F j, Y', strtotime($yesterday))) .'</h2>';
            
            // Add summary metrics
            $html_message .= '<div style="background-color: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 20px;">';
            $html_message .= '<h3 style="margin-top: 0;">'. __('Overview', 'chatbot-plugin') .'</h3>';
            $html_message .= '<table style="width: 100%; border-collapse: collapse;">';
            $html_message .= '<tr><td style="padding: 5px 10px;">'. __('Total Conversations', 'chatbot-plugin') .':</td><td style="padding: 5px 10px; font-weight: bold;">'. $summary_data['total_conversations'] .'</td></tr>';
            $html_message .= '<tr><td style="padding: 5px 10px;">'. __('Total Messages', 'chatbot-plugin') .':</td><td style="padding: 5px 10px; font-weight: bold;">'. $summary_data['total_messages'] .'</td></tr>';
            $html_message .= '<tr><td style="padding: 5px 10px;">'. __('Avg. Messages per Conversation', 'chatbot-plugin') .':</td><td style="padding: 5px 10px; font-weight: bold;">'. number_format($summary_data['avg_messages'], 1) .'</td></tr>';
            
            if (isset($summary_data['total_tokens'])) {
                $html_message .= '<tr><td style="padding: 5px 10px;">'. __('Total Tokens Used', 'chatbot-plugin') .':</td><td style="padding: 5px 10px; font-weight: bold;">'. number_format($summary_data['total_tokens']) .'</td></tr>';
            }
            
            if (isset($summary_data['total_cost'])) {
                $html_message .= '<tr><td style="padding: 5px 10px;">'. __('Total Cost', 'chatbot-plugin') .':</td><td style="padding: 5px 10px; font-weight: bold;">$'. number_format($summary_data['total_cost'], 2) .'</td></tr>';
            }
            
            $html_message .= '</table></div>';
            
            // Add AI analysis if available
            if (!empty($summary_data['ai_analysis'])) {
                $html_message .= '<div style="margin-bottom: 20px;">';
                $html_message .= '<h3>'. __('AI Analysis', 'chatbot-plugin') .'</h3>';
                $html_message .= '<div style="line-height: 1.5;">' . wpautop($summary_data['ai_analysis']) . '</div>';
                $html_message .= '</div>';
            }
            
            // Add recent conversations
            if (!empty($summary_data['conversations'])) {
                $html_message .= '<div style="margin-bottom: 20px;">';
                $html_message .= '<h3>'. __('Recent Conversations', 'chatbot-plugin') .'</h3>';
                $html_message .= '<table style="width: 100%; border-collapse: collapse; border: 1px solid #e5e5e5;">';
                $html_message .= '<tr style="background-color: #f2f2f2;">';
                $html_message .= '<th style="padding: 8px; text-align: left; border-bottom: 1px solid #e5e5e5;">'. __('Visitor', 'chatbot-plugin') .'</th>';
                $html_message .= '<th style="padding: 8px; text-align: left; border-bottom: 1px solid #e5e5e5;">'. __('Time', 'chatbot-plugin') .'</th>';
                $html_message .= '<th style="padding: 8px; text-align: center; border-bottom: 1px solid #e5e5e5;">'. __('Messages', 'chatbot-plugin') .'</th>';
                $html_message .= '<th style="padding: 8px; text-align: center; border-bottom: 1px solid #e5e5e5;">'. __('Status', 'chatbot-plugin') .'</th>';
                $html_message .= '</tr>';
                
                foreach ($summary_data['conversations'] as $conv) {
                    $html_message .= '<tr>';
                    $html_message .= '<td style="padding: 8px; border-bottom: 1px solid #e5e5e5;"><a href="'. admin_url('admin.php?page=chatbot-conversations&conversation_id=' . $conv['id']) .'" style="color: #4a6cf7; text-decoration: none;">'. esc_html($conv['visitor_name']) .'</a></td>';
                    $html_message .= '<td style="padding: 8px; border-bottom: 1px solid #e5e5e5;">'. date('H:i', strtotime($conv['created_at'])) .'</td>';
                    $html_message .= '<td style="padding: 8px; text-align: center; border-bottom: 1px solid #e5e5e5;">'. $conv['message_count'] .'</td>';
                    $html_message .= '<td style="padding: 8px; text-align: center; border-bottom: 1px solid #e5e5e5;"><span style="display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; ';
                    
                    // Status styling
                    switch ($conv['status']) {
                        case 'active':
                            $html_message .= 'background-color: #e8f5e9; color: #2e7d32;';
                            break;
                        case 'ended':
                            $html_message .= 'background-color: #ffebee; color: #c62828;';
                            break;
                        case 'archived':
                            $html_message .= 'background-color: #e0e0e0; color: #616161;';
                            break;
                        default:
                            $html_message .= 'background-color: #e3f2fd; color: #1565c0;';
                    }
                    
                    $html_message .= '">'. ucfirst($conv['status']) .'</span></td>';
                    $html_message .= '</tr>';
                }
                
                $html_message .= '</table>';
                $html_message .= '</div>';
            }
            
            // Add view all link and footer
            $html_message .= '<p style="margin-top: 20px;">';
            $html_message .= '<a href="'. admin_url('admin.php?page=chatbot-conversations') .'" style="display: inline-block; background-color: #4a6cf7; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; margin-top: 10px;">';
            $html_message .= __('View All Conversations', 'chatbot-plugin');
            $html_message .= '</a></p>';
            
            $html_message .= '<p style="margin-top: 30px; padding-top: 10px; border-top: 1px solid #e5e5e5; font-size: 12px; color: #666;">';
            $html_message .= __('This daily summary was sent from', 'chatbot-plugin') . ' ' . esc_html($site_name);
            $html_message .= '</p></div>';
            
            // Set headers for HTML email
            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $site_name . ' <' . $admin_email . '>'
            );
            
            // Send email
            $sent = wp_mail($notification_email, $subject, $html_message, $headers);
            
            if ($sent) {
                chatbot_log('INFO', 'send_daily_email_report', 'Daily email report sent successfully', 
                    array('to' => $notification_email));
            } else {
                chatbot_log('ERROR', 'send_daily_email_report', 'Failed to send daily email report', 
                    array('to' => $notification_email));
            }
            
        } catch (Exception $e) {
            chatbot_log('ERROR', 'send_daily_email_report', 'Exception in daily email report generation', 
                array('error' => $e->getMessage()));
        }
    }
    
    /**
     * Generate daily summary data for reporting
     * 
     * @param string $date The date to generate summary for (Y-m-d)
     * @return array Summary data
     */
    private function generate_daily_summary($date) {
        global $wpdb;
        
        chatbot_log('INFO', 'generate_daily_summary', 'Generating summary for date', array('date' => $date));
        
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $messages_table = $wpdb->prefix . 'chatbot_messages';
        $api_usage_table = $wpdb->prefix . 'chatbot_analytics_api_usage';
        
        // Get start and end timestamps for the day
        $start_time = $date . ' 00:00:00';
        $end_time = $date . ' 23:59:59';
        
        // Get conversations for the day
        $conversations = $wpdb->get_results($wpdb->prepare("
            SELECT c.*, 
                (SELECT COUNT(*) FROM $messages_table WHERE conversation_id = c.id) as message_count
            FROM $conversations_table c
            WHERE c.created_at BETWEEN %s AND %s
            ORDER BY c.created_at DESC
        ", $start_time, $end_time), ARRAY_A);
        
        if (empty($conversations)) {
            return array();
        }
        
        // Get message counts
        $total_messages = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM $messages_table m
            JOIN $conversations_table c ON m.conversation_id = c.id
            WHERE c.created_at BETWEEN %s AND %s
        ", $start_time, $end_time));
        
        // Get API usage if available
        $api_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$api_usage_table'") === $api_usage_table;
        
        $total_tokens = 0;
        $total_cost = 0;
        
        if ($api_table_exists) {
            $total_tokens = (int) $wpdb->get_var($wpdb->prepare("
                SELECT SUM(total_tokens) 
                FROM $api_usage_table
                WHERE timestamp BETWEEN %s AND %s
            ", $start_time, $end_time));
            
            $total_cost = (float) $wpdb->get_var($wpdb->prepare("
                SELECT SUM(cost) 
                FROM $api_usage_table
                WHERE timestamp BETWEEN %s AND %s
            ", $start_time, $end_time));
        }
        
        // Calculate average messages per conversation
        $avg_messages = count($conversations) > 0 ? $total_messages / count($conversations) : 0;
        
        // Prepare summary data
        $summary_data = array(
            'date' => $date,
            'total_conversations' => count($conversations),
            'total_messages' => $total_messages,
            'avg_messages' => $avg_messages,
            'conversations' => $conversations
        );
        
        if ($api_table_exists) {
            $summary_data['total_tokens'] = $total_tokens;
            $summary_data['total_cost'] = $total_cost;
        }
        
        // Generate AI analysis if possible and configured
        if ($this->is_email_notification_enabled('ai_summary') && 
            class_exists('Chatbot_OpenAI') && 
            Chatbot_OpenAI::get_instance()->is_configured()) {
            
            $summary_data['ai_analysis'] = $this->generate_ai_summary($conversations, $summary_data);
        }
        
        return $summary_data;
    }
    
    /**
     * Generate AI-powered summary of conversations
     * 
     * @param array $conversations List of conversations
     * @param array $metrics Summary metrics
     * @return string AI-generated summary
     */
    private function generate_ai_summary($conversations, $metrics) {
        if (empty($conversations)) {
            return '';
        }
        
        chatbot_log('INFO', 'generate_ai_summary', 'Generating AI summary for conversations', 
            array('count' => count($conversations)));
        
        try {
            // Get all conversation content
            global $wpdb;
            $messages_table = $wpdb->prefix . 'chatbot_messages';
            
            $conversation_data = array();
            
            foreach ($conversations as $conv) {
                // Get messages for this conversation
                $messages = $wpdb->get_results($wpdb->prepare("
                    SELECT sender_type, message
                    FROM $messages_table
                    WHERE conversation_id = %d AND sender_type != 'system'
                    ORDER BY timestamp ASC
                ", $conv['id']), ARRAY_A);
                
                if (!empty($messages)) {
                    $conversation_data[] = array(
                        'id' => $conv['id'],
                        'visitor' => $conv['visitor_name'],
                        'messages' => $messages
                    );
                }
            }
            
            if (empty($conversation_data)) {
                return '';
            }
            
            // Check if OpenAI is available
            if (!class_exists('Chatbot_OpenAI') || !Chatbot_OpenAI::get_instance()->is_configured()) {
                return '';
            }
            
            $openai = Chatbot_OpenAI::get_instance();
            
            // Prepare system prompt
            $system_prompt = "You are an analytics expert reviewing chat conversations from a website's chatbot. Your task is to analyze the provided conversation data and create a CONCISE but insightful data-driven summary that highlights only the most important patterns, common questions, user needs, and actionable insights from the last day of chatbot activity.

Keep your analysis extremely concise - under 300 words total. Focus only on the most significant patterns and trends. Use bullet points where appropriate. Organize your analysis into these sections:

1. KEY PATTERNS: The 2-3 most common topics or questions users are asking about
2. USER NEEDS: The primary user needs or pain points revealed in the conversations
3. RECOMMENDATIONS: 1-2 specific, actionable improvements based on the data

Your analysis should be professional but conversational in tone, written as a helpful business intelligence report for a website administrator.";
            
            // Prepare user prompt
            $user_prompt = "Please analyze the following chat conversation data from yesterday and provide a concise, insightful summary:\n\n";
            
            // Add metrics information
            $user_prompt .= "METRICS SUMMARY:\n";
            $user_prompt .= "- Total conversations: " . $metrics['total_conversations'] . "\n";
            $user_prompt .= "- Total messages: " . $metrics['total_messages'] . "\n";
            $user_prompt .= "- Average messages per conversation: " . number_format($metrics['avg_messages'], 1) . "\n";
            
            if (isset($metrics['total_tokens'])) {
                $user_prompt .= "- Total tokens used: " . number_format($metrics['total_tokens']) . "\n";
            }
            
            if (isset($metrics['total_cost'])) {
                $user_prompt .= "- Total API cost: $" . number_format($metrics['total_cost'], 2) . "\n";
            }
            
            $user_prompt .= "\nCONVERSATION DATA:\n" . json_encode($conversation_data, JSON_PRETTY_PRINT) . "\n\n";
            
            $user_prompt .= "Based on this data, provide a concise, actionable summary following the format in your instructions.";
            
            // Generate summary using OpenAI
            $summary = $openai->get_completion($system_prompt, $user_prompt);
            
            if (empty($summary)) {
                chatbot_log('ERROR', 'generate_ai_summary', 'OpenAI returned empty summary');
                return '';
            }
            
            return $summary;
            
        } catch (Exception $e) {
            chatbot_log('ERROR', 'generate_ai_summary', 'Exception generating AI summary', 
                array('error' => $e->getMessage()));
            return '';
        }
    }
    
    /**
     * Test Telegram connection via AJAX
     */
    public function test_telegram_connection() {
        // Check for nonce and permissions
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_test_telegram') || !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized request');
            return;
        }
        
        // Get API key and chat ID
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $chat_id = isset($_POST['chat_id']) ? sanitize_text_field($_POST['chat_id']) : '';
        
        if (empty($api_key) || empty($chat_id)) {
            wp_send_json_error('Please provide both API key and chat ID');
            return;
        }
        
        // Send a test message
        $site_name = get_bloginfo('name');
        $message = sprintf("🧪 *Test Message from %s Chatbot*\n\nThis is a test message to confirm that your Telegram notifications are working correctly.", $site_name);
        
        // Prepare the API URL
        $telegram_api_url = "https://api.telegram.org/bot$api_key/sendMessage";
        
        // Prepare the request parameters
        $params = array(
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true
        );
        
        // Make the API request
        $response = wp_remote_post($telegram_api_url, array(
            'body' => $params,
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
            return;
        }
        
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($response_body['ok']) && $response_body['ok'] === true) {
            chatbot_log('INFO', 'test_telegram_connection', 'Telegram test message sent successfully');
            wp_send_json_success('Test message sent successfully');
        } else {
            $error_message = isset($response_body['description']) ? $response_body['description'] : 'Unknown error';
            chatbot_log('ERROR', 'test_telegram_connection', 'Telegram API error', array('response' => $response_body));
            wp_send_json_error($error_message);
        }
    }
}

// Initialize the notifications handler
function chatbot_notifications_init() {
    return Chatbot_Notifications::get_instance();
}
chatbot_notifications_init();