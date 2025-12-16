<?php
/**
 * Chatbot Analytics Handler
 * 
 * Handles analytics functions for the chatbot plugin
 * - Tracks user interactions
 * - Calculates API usage costs
 * - Generates AI-powered reports and insights
 * - Provides admin dashboard with analytics data
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_Analytics {
    
    private static $instance = null;
    
    /**
     * Get singleton instance
     * 
     * @return Chatbot_Analytics The singleton instance
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
        // Register AJAX handlers for analytics
        add_action('wp_ajax_chatbot_get_analytics', array($this, 'get_analytics'));
        add_action('wp_ajax_chatbot_get_cost_data', array($this, 'get_cost_data'));
        add_action('wp_ajax_chatbot_get_ai_insights', array($this, 'get_ai_insights'));
        add_action('wp_ajax_chatbot_get_analytics_summary', array($this, 'get_analytics_summary'));
        add_action('wp_ajax_chatbot_generate_conversation_summary', array($this, 'generate_conversation_summary'));
        add_action('wp_ajax_chatbot_analytics_follow_up', array($this, 'handle_follow_up_question'));
        
        // Register frontend tracking AJAX endpoint
        add_action('wp_ajax_chatbot_track_event', array($this, 'track_event'));
        add_action('wp_ajax_nopriv_chatbot_track_event', array($this, 'track_event'));
        
        // Add analytics to the sidebar of overview page
        add_action('chatbot_analytics_sidebar', array($this, 'render_analytics_summary'));
        
        // Enqueue admin scripts and styles for analytics
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Add tracking script to frontend
        add_action('wp_footer', array($this, 'add_tracking_script'));
        
        // Schedule daily AI insights generation
        if (!wp_next_scheduled('chatbot_generate_daily_insights')) {
            wp_schedule_event(time(), 'daily', 'chatbot_generate_daily_insights');
        }
        add_action('chatbot_generate_daily_insights', array($this, 'schedule_insights_generation'));
        
        // Register OpenAI API usage tracking hooks
        add_action('chatbot_openai_api_request_complete', array($this, 'track_api_usage'), 10, 3);
    }
    
    /**
     * Create database tables for analytics
     * 
     * @global wpdb $wpdb WordPress database access object
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Table for tracking events
        $table_events = $wpdb->prefix . 'chatbot_analytics_events';
        $sql_events = "CREATE TABLE $table_events (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            event_data longtext DEFAULT NULL,
            user_id bigint(20) DEFAULT NULL,
            session_id varchar(128) DEFAULT NULL,
            conversation_id bigint(20) DEFAULT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            user_agent text DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            page_url varchar(255) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY conversation_id (conversation_id),
            KEY timestamp (timestamp)
        ) $charset_collate;";
        
        // Table for API usage and costs
        $table_api_usage = $wpdb->prefix . 'chatbot_analytics_api_usage';
        $sql_api_usage = "CREATE TABLE $table_api_usage (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) NOT NULL,
            message_id bigint(20) DEFAULT NULL,
            model varchar(50) NOT NULL,
            prompt_tokens int NOT NULL DEFAULT 0,
            completion_tokens int NOT NULL DEFAULT 0,
            total_tokens int NOT NULL DEFAULT 0,
            cost decimal(10,6) NOT NULL DEFAULT 0,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY conversation_id (conversation_id),
            KEY message_id (message_id)
        ) $charset_collate;";
        
        // Table for AI-generated insights
        $table_insights = $wpdb->prefix . 'chatbot_analytics_insights';
        $sql_insights = "CREATE TABLE $table_insights (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            insight_type varchar(50) NOT NULL,
            insight_title varchar(255) NOT NULL,
            insight_content text NOT NULL,
            priority varchar(20) DEFAULT 'medium' NOT NULL,
            is_alert tinyint(1) DEFAULT 0 NOT NULL,
            is_read tinyint(1) DEFAULT 0 NOT NULL,
            generated_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY insight_type (insight_type),
            KEY priority (priority),
            KEY is_alert (is_alert)
        ) $charset_collate;";
        
        // Table for daily metrics
        $table_metrics = $wpdb->prefix . 'chatbot_analytics_metrics';
        $sql_metrics = "CREATE TABLE $table_metrics (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            metric_date date NOT NULL,
            conversations_count int NOT NULL DEFAULT 0,
            messages_count int NOT NULL DEFAULT 0,
            user_messages_count int NOT NULL DEFAULT 0,
            ai_messages_count int NOT NULL DEFAULT 0,
            unique_users int NOT NULL DEFAULT 0,
            avg_conversation_length decimal(10,2) NOT NULL DEFAULT 0,
            total_tokens int NOT NULL DEFAULT 0,
            total_cost decimal(10,6) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY metric_date (metric_date)
        ) $charset_collate;";
        
        // Include WordPress database upgrade functions
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Create the tables
        dbDelta($sql_events);
        dbDelta($sql_api_usage);
        dbDelta($sql_insights);
        dbDelta($sql_metrics);
        
        // Log the table creation
        chatbot_log('INFO', 'analytics_create_tables', 'Analytics tables created successfully');
    }
    
    /**
     * Track an event in the analytics system
     * Security: Rate limited and event type validated for unauthenticated users
     */
    public function track_event() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-plugin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }

        // Get parameters
        $event_type = isset($_POST['event_type']) ? sanitize_text_field($_POST['event_type']) : '';
        $event_data = isset($_POST['event_data']) ? $_POST['event_data'] : array();
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : null;
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : null;

        if (empty($event_type)) {
            wp_send_json_error(array('message' => 'Missing event type.'));
        }

        // Security: Whitelist allowed event types to prevent data pollution
        $allowed_event_types = array(
            'chatbot_opened',
            'chatbot_closed',
            'message_sent',
            'message_received',
            'conversation_started',
            'conversation_ended',
            'feedback_submitted',
            'error_occurred'
        );

        if (!in_array($event_type, $allowed_event_types, true)) {
            chatbot_log('WARN', 'track_event', 'Blocked invalid event type', array('event_type' => $event_type));
            wp_send_json_error(array('message' => 'Invalid event type.'));
        }

        // Security: Rate limiting for unauthenticated users (max 60 events per minute per IP)
        if (!is_user_logged_in()) {
            $client_ip = $this->get_client_ip();
            $rate_limit_key = 'chatbot_rate_' . md5($client_ip);
            $rate_limit = get_transient($rate_limit_key);

            if ($rate_limit === false) {
                // First request - set counter
                set_transient($rate_limit_key, 1, 60); // 60 second window
            } elseif ($rate_limit >= 60) {
                // Rate limit exceeded
                chatbot_log('WARN', 'track_event', 'Rate limit exceeded', array('ip' => $client_ip));
                wp_send_json_error(array('message' => 'Rate limit exceeded. Please try again later.'));
            } else {
                // Increment counter
                set_transient($rate_limit_key, $rate_limit + 1, 60);
            }
        }
        
        // Sanitize event data
        if (is_array($event_data)) {
            array_walk_recursive($event_data, function(&$value) {
                if (is_string($value)) {
                    $value = sanitize_text_field($value);
                }
            });
        } else {
            $event_data = sanitize_text_field($event_data);
        }
        
        // Insert the event into database
        global $wpdb;
        $table = $wpdb->prefix . 'chatbot_analytics_events';
        
        $user_id = get_current_user_id();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $ip_address = $this->get_client_ip();
        $page_url = isset($_POST['page_url']) ? esc_url_raw($_POST['page_url']) : '';
        
        $result = $wpdb->insert(
            $table,
            array(
                'event_type' => $event_type,
                'event_data' => is_array($event_data) ? json_encode($event_data) : $event_data,
                'user_id' => $user_id ? $user_id : null,
                'session_id' => $session_id,
                'conversation_id' => $conversation_id,
                'user_agent' => $user_agent,
                'ip_address' => $ip_address,
                'page_url' => $page_url
            ),
            array(
                '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s'
            )
        );
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to record event.'));
        }
        
        wp_send_json_success(array('message' => 'Event recorded successfully.'));
    }
    
    /**
     * Get client IP address
     * 
     * @return string The client IP address
     */
    private function get_client_ip() {
        // Check for shared internet/ISP IP
        if (!empty($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        
        // Check for IPs passing through proxies
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Extract the first IP in the list which is the client's IP
            $ip_list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            foreach ($ip_list as $ip) {
                if (filter_var(trim($ip), FILTER_VALIDATE_IP)) {
                    return trim($ip);
                }
            }
        }
        
        // If on command line, return localhost
        if (php_sapi_name() == 'cli') {
            return '127.0.0.1';
        }
        
        // Use the regular remote address as a fallback
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Track OpenAI API usage for cost calculation
     * 
     * @param string $model The OpenAI model used
     * @param array $response The API response
     * @param int $conversation_id The conversation ID
     */
    /**
     * Generate a summary of all conversations using AI
     */
    public function generate_conversation_summary() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-admin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }
        
        // Check if OpenAI integration is available
        if (!class_exists('Chatbot_OpenAI') || !Chatbot_OpenAI::get_instance()->is_configured()) {
            wp_send_json_error(array('message' => 'OpenAI API is not configured. Please set up your API key in the settings.'));
            return;
        }
        
        // Get all conversations with their messages
        global $wpdb;
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $messages_table = $wpdb->prefix . 'chatbot_messages';
        
        // Get conversation data (limited to last 30 for performance)
        $conversations = $wpdb->get_results("
            SELECT c.id, c.visitor_name, c.created_at, c.status
            FROM $conversations_table c
            ORDER BY c.created_at DESC
            LIMIT 30
        ");
        
        if (empty($conversations)) {
            wp_send_json_error(array('message' => 'No conversations found to analyze.'));
            return;
        }
        
        // Prepare conversation data for analysis
        $conversation_data = array();
        foreach ($conversations as $conv) {
            // Get messages for this conversation
            $messages = $wpdb->get_results($wpdb->prepare("
                SELECT sender_type, message, timestamp
                FROM $messages_table
                WHERE conversation_id = %d
                ORDER BY timestamp ASC
            ", $conv->id));
            
            // Skip conversations with no messages
            if (empty($messages)) {
                continue;
            }
            
            // Format the conversation and messages
            $formatted_messages = array();
            foreach ($messages as $msg) {
                // Skip system messages and only include user/ai exchanges
                if ($msg->sender_type === 'system') {
                    continue;
                }
                
                $formatted_messages[] = array(
                    'role' => $msg->sender_type,
                    'content' => $msg->message,
                    'time' => $msg->timestamp
                );
            }
            
            // Add to conversation data if there are messages
            if (!empty($formatted_messages)) {
                $conversation_data[] = array(
                    'id' => $conv->id,
                    'visitor' => $conv->visitor_name,
                    'status' => $conv->status,
                    'date' => $conv->created_at,
                    'messages' => $formatted_messages
                );
            }
        }
        
        // If no valid conversations found
        if (empty($conversation_data)) {
            wp_send_json_error(array('message' => 'No valid conversation content found to analyze.'));
            return;
        }
        
        // Store the conversation data in the session for follow-up questions
        if (!isset($_SESSION)) {
            session_start();
        }
        $_SESSION['chatbot_analytics_conversation_data'] = $conversation_data;
        
        // Get the OpenAI instance
        $openai = Chatbot_OpenAI::get_instance();
        
        // Prepare enhanced system prompt for the analysis - more concise with suggested questions
        $system_prompt = "You are an analytics expert reviewing chat conversations from a website's chatbot. Your task is to analyze the provided conversation data and create a CONCISE but insightful data-driven summary that highlights only the most important key patterns, common questions, user needs, and actionable insights.

As an analytics expert, you should:
1. Apply statistical thinking to identify only the most significant patterns and trends
2. Keep your analysis extremely concise - just 2-3 lines per section
3. Identify only the top opportunities for business growth and conversion
4. Recommend only the most impactful specific improvements 
5. VERY IMPORTANT: For each section, include 1-2 suggested follow-up questions that the admin can ask to learn more
6. IMPORTANT: Include 1-2 data visualizations with Chart.js to help illustrate key insights

Your analysis should be extremely concise and well-organized with clear sections. Format your response with clear headings and bullet points. For each section, provide just 2-3 lines of key insights followed by suggested questions. At the end, include a section called 'SUGGESTED QUESTIONS' with 4-5 clickable question buttons formatted with HTML that can be clicked to ask those specific follow-up questions.

For the chart visualizations, we've enhanced our interface with the ability to render charts from code blocks. You can create charts in two ways:

METHOD 1: Simple chart syntax (recommended for basic charts):
```chart:bar|Chart Title|Label 1,Label 2,Label 3|10,20,30```
```chart:line|Conversations Over Time|Day 1,Day 2,Day 3,Day 4|5,12,8,15```
```chart:pie|Question Topics|Product,Support,Pricing,Other|45,30,15,10```
```chart:doughnut|User Types|New,Returning,Regular|65,25,10```

METHOD 2: Full Chart.js configuration (for advanced customization):
```chart:
{
  \"type\": \"bar\",
  \"data\": {
    \"labels\": [\"Label 1\", \"Label 2\", \"Label 3\"],
    \"datasets\": [{
      \"label\": \"Dataset Label\",
      \"data\": [10, 20, 30],
      \"backgroundColor\": [
        \"rgba(75, 192, 192, 0.5)\",
        \"rgba(54, 162, 235, 0.5)\",
        \"rgba(255, 206, 86, 0.5)\"
      ],
      \"borderColor\": [
        \"rgba(75, 192, 192, 1)\",
        \"rgba(54, 162, 235, 1)\",
        \"rgba(255, 206, 86, 1)\"
      ],
      \"borderWidth\": 1
    }]
  },
  \"options\": {
    \"responsive\": true,
    \"plugins\": {
      \"title\": {
        \"display\": true,
        \"text\": \"Chart Title\"
      },
      \"legend\": {
        \"position\": \"top\"
      }
    }
  }
}
```

Choose appropriate chart types based on the data:
- Bar charts: For comparisons across categories (message counts by user)
- Line charts: For trends over time (conversation volume over time)
- Pie/Doughnut charts: For composition (types of questions asked)
- Horizontal bar: For ranking data (most common topics)

Always make sure charts have clear titles, labeled axes, and use appropriate colors.";
        
        // Prepare user prompt with conversation data and detailed request - more concise with suggested questions
        $user_prompt = "Please analyze the following chatbot conversations and provide a VERY CONCISE data-driven summary:\n\n";
        $user_prompt .= "CONVERSATION DATA: " . json_encode($conversation_data, JSON_PRETTY_PRINT) . "\n\n";
        $user_prompt .= "In your analysis, please include these sections, but keep each section to just 2-3 key bullet points with the most important insights:

1. CONVERSATION OVERVIEW
   - Key stats like total conversations, messages, timeframe, and average length
   - Include 1-2 suggested follow-up questions about this section

2. USER BEHAVIOR PATTERNS
   - Only the most common topics/questions and user needs
   - Include 1-2 suggested follow-up questions about this section

3. CONTENT EFFECTIVENESS
   - Only the most important strengths and weaknesses of the chatbot responses
   - Include 1-2 suggested follow-up questions about this section

4. ACTIONABLE RECOMMENDATIONS
   - Only the 2-3 most important recommendations that would have the biggest impact
   - Include 1-2 suggested follow-up questions about this section

5. BUSINESS OPPORTUNITIES
   - Only the top 2-3 business opportunities identified
   - Include 1-2 suggested follow-up questions about this section

6. DATA VISUALIZATION
   - Include 1-2 meaningful charts that illustrate your key findings
   - Use our new simplified chart syntax for basic charts (e.g., ```chart:bar|Chart Title|Label 1,Label 2,Label 3|10,20,30```)
   - For more complex visualizations, use the full Chart.js configuration
   - Choose chart types that best represent the data (bar for comparison, line for trends, pie for composition)

7. SUGGESTED QUESTIONS
   - Create 4-5 HTML buttons with the most valuable follow-up questions
   - Format them like this: <button class='ai-chat-question-btn' onclick='sendPredefinedQuestion(this.textContent)'>Your question here?</button>

Keep your entire response under 750 words. Format with clear headings and simple bullet points. Use concise language and be specific. The HTML buttons should work when clicked to send the question text to the chatbot.";
        
        try {
            // IMPORTANT: Use the proper OpenAI integration layer which automatically
            // detects and uses AIPass when available, or falls back to direct OpenAI API
            chatbot_log('INFO', 'generate_conversation_summary', 'Using OpenAI integration layer (supports both AIPass and direct API)');

            // Prepare messages for the AI
            $messages = array(
                array(
                    'role' => 'system',
                    'content' => $system_prompt,
                ),
                array(
                    'role' => 'user',
                    'content' => $user_prompt,
                ),
            );

            // Check if using AIPass or direct API
            $using_aipass = $openai->is_using_aipass();
            chatbot_log('INFO', 'generate_conversation_summary', 'Integration mode: ' . ($using_aipass ? 'AIPass' : 'Direct OpenAI API'));

            // Generate the summary using the integration layer
            // This will automatically use AIPass if configured, otherwise direct OpenAI API
            if ($using_aipass && class_exists('Chatbot_AIPass')) {
                // Use AIPass integration
                $aipass = Chatbot_AIPass::get_instance();
                // Hardcoded: Use Gemini 2.5 Pro for AI Insights (most capable for analysis)
                $model = 'gemini/gemini-2.5-pro';

                $result = $aipass->generate_completion(
                    $messages,
                    $model,
                    4000, // Higher token limit for analytics
                    0.7
                );

                if (!$result['success']) {
                    chatbot_log('ERROR', 'generate_conversation_summary', 'AIPass API Error: ' . $result['error']);
                    wp_send_json_error(array('message' => 'Failed to generate summary: ' . $result['error']));
                    return;
                }

                $response = $result['content'];
            } else {
                // Use direct OpenAI API
                $api_url = 'https://api.openai.com/v1/chat/completions';
                $model = get_option('chatbot_openai_model', 'gpt-4.1-mini');

                // Create request body
                $request_body = array(
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => 4000,
                    'temperature' => 0.7
                );

                // Make API request
                $api_key = get_option('chatbot_openai_api_key', '');
                $response_data = wp_remote_post($api_url, array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $api_key,
                        'Content-Type' => 'application/json',
                    ),
                    'body' => json_encode($request_body),
                    'timeout' => 60,
                    'data_format' => 'body',
                ));

                if (is_wp_error($response_data)) {
                    chatbot_log('ERROR', 'generate_conversation_summary', 'API Error: ' . $response_data->get_error_message());
                    wp_send_json_error(array('message' => 'Failed to generate summary: ' . $response_data->get_error_message()));
                    return;
                }

                $response_code = wp_remote_retrieve_response_code($response_data);
                $response_body = json_decode(wp_remote_retrieve_body($response_data), true);

                if ($response_code !== 200) {
                    chatbot_log('ERROR', 'generate_conversation_summary', 'API Error: ' . json_encode($response_body));
                    wp_send_json_error(array('message' => 'Failed to generate summary: API returned status code ' . $response_code));
                    return;
                }

                if (!isset($response_body['choices'][0]['message']['content'])) {
                    chatbot_log('ERROR', 'generate_conversation_summary', 'Unexpected API response format');
                    wp_send_json_error(array('message' => 'Failed to generate summary: Unexpected API response format'));
                    return;
                }

                $response = $response_body['choices'][0]['message']['content'];
            }

            if (empty($response)) {
                wp_send_json_error(array('message' => 'Failed to generate summary. AI returned an empty response.'));
                return;
            }

            wp_send_json_success(array(
                'summary' => $response,
                'conversation_data' => $conversation_data
            ));

        } catch (Exception $e) {
            chatbot_log('ERROR', 'generate_conversation_summary', 'Error generating summary: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error generating summary: ' . $e->getMessage()));
        }
    }
    
    /**
     * Get analytics summary via AJAX
     */
    /**
     * Handle follow-up questions about conversation data
     */
    public function handle_follow_up_question() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-admin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }
        
        // Check if OpenAI integration is available
        if (!class_exists('Chatbot_OpenAI') || !Chatbot_OpenAI::get_instance()->is_configured()) {
            wp_send_json_error(array('message' => 'OpenAI API is not configured. Please set up your API key in the settings.'));
            return;
        }
        
        // Get the question and chat history
        $question = isset($_POST['question']) ? sanitize_text_field($_POST['question']) : '';
        $chat_history_json = isset($_POST['chat_history']) ? $_POST['chat_history'] : '';
        
        if (empty($question)) {
            wp_send_json_error(array('message' => 'No question provided.'));
            return;
        }
        
        // Get conversation data from session
        if (!isset($_SESSION)) {
            session_start();
        }
        
        $conversation_data = isset($_SESSION['chatbot_analytics_conversation_data']) 
            ? $_SESSION['chatbot_analytics_conversation_data'] 
            : array();
            
        if (empty($conversation_data)) {
            wp_send_json_error(array('message' => 'No conversation data available. Please generate a summary first.'));
            return;
        }
        
        // Parse chat history
        $chat_history = json_decode($chat_history_json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $chat_history = array();
        }
        
        // Get the OpenAI instance
        $openai = Chatbot_OpenAI::get_instance();
        
        // Prepare system prompt with enhanced instructions for more concise responses
        $system_prompt = "You are an analytics expert reviewing chat conversations from a website's chatbot. You have access to the conversation data and can answer specific questions about patterns, trends, user behavior, and insights from this data.

Your expertise areas include:
- Identifying common user questions and issues
- Spotting trends in conversation topics and sentiment
- Recognizing potential business opportunities
- Suggesting improvements to chatbot responses
- Analyzing conversation flow and user satisfaction
- Calculating conversion rates and engagement metrics
- Creating powerful data visualizations with Chart.js

When answering questions:
1. Be extremely concise - give direct answers in 2-3 sentences when possible
2. For simple questions, provide just facts without elaboration
3. Only include the most relevant data points
4. Use bullet points for lists rather than paragraphs
5. Focus on actionable insights over general observations
6. Skip unnecessary preambles and conclusions
7. IMPORTANT: Include relevant data visualizations when they would enhance your answer
8. End your response with 2-3 relevant follow-up question suggestions formatted as HTML buttons:
   <button class='ai-chat-question-btn' onclick='sendPredefinedQuestion(this.textContent)'>Suggested follow-up question?</button>

We've enhanced our interface with the ability to render charts from code blocks. You can create charts in two ways:

METHOD 1: Simple chart syntax (recommended for basic charts):
```chart:bar|Chart Title|Label 1,Label 2,Label 3|10,20,30```
```chart:line|Conversations Over Time|Day 1,Day 2,Day 3,Day 4|5,12,8,15```
```chart:pie|Question Topics|Product,Support,Pricing,Other|45,30,15,10```
```chart:doughnut|User Types|New,Returning,Regular|65,25,10```

METHOD 2: Full Chart.js configuration (for advanced customization):
```chart:
{
  \"type\": \"bar\",
  \"data\": {
    \"labels\": [\"Label 1\", \"Label 2\", \"Label 3\"],
    \"datasets\": [{
      \"label\": \"Dataset Label\",
      \"data\": [10, 20, 30],
      \"backgroundColor\": [
        \"rgba(75, 192, 192, 0.5)\",
        \"rgba(54, 162, 235, 0.5)\",
        \"rgba(255, 206, 86, 0.5)\"
      ],
      \"borderColor\": [
        \"rgba(75, 192, 192, 1)\",
        \"rgba(54, 162, 235, 1)\",
        \"rgba(255, 206, 86, 1)\"
      ],
      \"borderWidth\": 1
    }]
  },
  \"options\": {
    \"responsive\": true,
    \"plugins\": {
      \"title\": {
        \"display\": true,
        \"text\": \"Chart Title\"
      },
      \"legend\": {
        \"position\": \"top\"
      }
    },
    \"scales\": {
      \"y\": {
        \"beginAtZero\": true,
        \"title\": {
          \"display\": true,
          \"text\": \"Y-Axis Label\"
        }
      },
      \"x\": {
        \"title\": {
          \"display\": true,
          \"text\": \"X-Axis Label\"
        }
      }
    }
  }
}
```

For meaningful visualizations, choose appropriate chart types based on the data:
- Bar charts: For comparisons across categories (message counts by user)
- Line charts: For trends over time (conversation volume over time)
- Pie/Doughnut charts: For composition (types of questions asked)
- Horizontal bar: For ranking data (most common topics)
- Stacked bar/column: For comparing segments across categories
- Radar: For comparing multiple variables for different categories
- Scatter: For showing correlations between two variables

Enhance your charts with:
- Clear, descriptive titles (always include)
- Labeled axes with appropriate units
- Legends when using multiple data series
- Consistent color schemes that convey meaning
- Appropriate scale (linear vs logarithmic) based on data distribution

You should maintain the same analytical context throughout the conversation, referencing previous questions and building upon your earlier analysis. Remember, shorter responses are better - users prefer brief, direct answers over lengthy explanations.";
        
        // Prepare user prompt
        $user_prompt = "CONVERSATION DATA SUMMARY:\n";
        
        // Add condensed conversation data metadata
        $user_prompt .= "- Total conversations analyzed: " . count($conversation_data) . "\n";
        
        // Extract visitor names and dates for context
        $visitors = array();
        $date_range = array('start' => null, 'end' => null);
        $total_messages = 0;
        
        foreach ($conversation_data as $conv) {
            // Add visitor names
            if (!empty($conv['visitor']) && !in_array($conv['visitor'], $visitors)) {
                $visitors[] = $conv['visitor'];
            }
            
            // Track date range
            $date = strtotime($conv['date']);
            if ($date) {
                if ($date_range['start'] === null || $date < $date_range['start']) {
                    $date_range['start'] = $date;
                }
                if ($date_range['end'] === null || $date > $date_range['end']) {
                    $date_range['end'] = $date;
                }
            }
            
            // Count messages
            if (!empty($conv['messages']) && is_array($conv['messages'])) {
                $total_messages += count($conv['messages']);
            }
        }
        
        // Add metadata to prompt
        if (!empty($visitors)) {
            $user_prompt .= "- Visitors: " . implode(", ", array_slice($visitors, 0, 5));
            if (count($visitors) > 5) {
                $user_prompt .= " and " . (count($visitors) - 5) . " others";
            }
            $user_prompt .= "\n";
        }
        
        if ($date_range['start'] && $date_range['end']) {
            $user_prompt .= "- Date range: " . date('Y-m-d', $date_range['start']) . " to " . date('Y-m-d', $date_range['end']) . "\n";
        }
        
        $user_prompt .= "- Total messages: " . $total_messages . "\n\n";
        
        // Provide access to the full conversation data for reference
        $user_prompt .= "FULL CONVERSATION DATA: " . json_encode($conversation_data, JSON_PRETTY_PRINT) . "\n\n";
        
        // Add chat history context in a more structured way
        if (!empty($chat_history)) {
            $user_prompt .= "CONVERSATION CONTEXT:\n";
            
            foreach ($chat_history as $index => $message) {
                $role = $message['role'];
                $content = $message['content'];
                
                // Add only the previous messages, not the current question
                if ($role == 'user' && $content == $question) {
                    continue;
                }
                
                // Format the history in a clear way
                if ($role == 'user') {
                    $user_prompt .= "Question (" . ($index+1) . "): " . $content . "\n\n";
                } else if ($role == 'assistant') {
                    $user_prompt .= "Answer: " . $content . "\n\n";
                }
            }
        }
        
        // Add the current question with clear demarcation
        $user_prompt .= "CURRENT QUESTION: " . $question . "\n\n";
        $user_prompt .= "Please provide a detailed analysis based on the conversation data to answer this question directly and thoroughly.";
        
        try {
            // Log the request (for debugging)
            chatbot_log('INFO', 'handle_follow_up_question', 'Processing follow-up question', array(
                'question' => $question,
                'chat_history_length' => count($chat_history),
                'conversation_data_count' => count($conversation_data)
            ));

            // IMPORTANT: Use the proper OpenAI integration layer which automatically
            // detects and uses AIPass when available, or falls back to direct OpenAI API

            // Prepare messages
            $messages = array(
                array(
                    'role' => 'system',
                    'content' => $system_prompt,
                ),
                array(
                    'role' => 'user',
                    'content' => $user_prompt,
                ),
            );

            // Check if using AIPass or direct API
            $using_aipass = $openai->is_using_aipass();
            chatbot_log('INFO', 'handle_follow_up_question', 'Integration mode: ' . ($using_aipass ? 'AIPass' : 'Direct OpenAI API'));

            // Generate the response using the integration layer
            if ($using_aipass && class_exists('Chatbot_AIPass')) {
                // Use AIPass integration
                $aipass = Chatbot_AIPass::get_instance();
                // Hardcoded: Use Gemini 2.5 Pro for AI Insights follow-up questions (most capable for analysis)
                $model = 'gemini/gemini-2.5-pro';

                $result = $aipass->generate_completion(
                    $messages,
                    $model,
                    4000, // Higher token limit for analytics
                    0.7
                );

                if (!$result['success']) {
                    throw new Exception('AIPass API Error: ' . $result['error']);
                }

                $response = $result['content'];
            } else {
                // Use direct OpenAI API
                $api_url = 'https://api.openai.com/v1/chat/completions';
                $model = get_option('chatbot_openai_model', 'gpt-4.1-mini');

                // Create request body
                $request_body = array(
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => 4000,
                    'temperature' => 0.7
                );

                // Make API request
                $api_key = get_option('chatbot_openai_api_key', '');
                $response_data = wp_remote_post($api_url, array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $api_key,
                        'Content-Type' => 'application/json',
                    ),
                    'body' => json_encode($request_body),
                    'timeout' => 60,
                    'data_format' => 'body',
                ));

                if (is_wp_error($response_data)) {
                    throw new Exception('API Error: ' . $response_data->get_error_message());
                }

                $response_code = wp_remote_retrieve_response_code($response_data);
                $response_body = json_decode(wp_remote_retrieve_body($response_data), true);

                if ($response_code !== 200) {
                    throw new Exception('API Error: ' . json_encode($response_body));
                }

                if (isset($response_body['choices'][0]['message']['content'])) {
                    $response = $response_body['choices'][0]['message']['content'];
                } else {
                    throw new Exception('Unexpected API response format.');
                }
            }

            if (!$response) {
                wp_send_json_error(array('message' => 'Failed to generate response. AI returned an empty response.'));
                return;
            }

            wp_send_json_success(array(
                'response' => $response
            ));

        } catch (Exception $e) {
            chatbot_log('ERROR', 'handle_follow_up_question', 'Error generating response', array(
                'error' => $e->getMessage()
            ));
            wp_send_json_error(array('message' => 'Error generating response: ' . $e->getMessage()));
        }
    }
    
    /**
     * Get analytics summary via AJAX
     */
    public function get_analytics_summary() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-admin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }
        
        // Buffer the output of the render_analytics_overview method
        ob_start();
        $this->render_analytics_overview();
        $html = ob_get_clean();
        
        // Return the HTML
        wp_send_json_success(array(
            'html' => $html
        ));
    }
    
    /**
     * Track OpenAI API usage for cost calculation
     * 
     * @param string $model The OpenAI model used
     * @param array $response The API response
     * @param int $conversation_id The conversation ID
     */
    public function track_api_usage($model, $response, $conversation_id) {
        if (empty($model) || empty($response) || empty($conversation_id)) {
            chatbot_log('ERROR', 'track_api_usage', 'Missing required parameters', 
                array('model' => $model, 'conversation_id' => $conversation_id));
            return;
        }
        
        // Extract token usage from the response
        $usage = isset($response['usage']) ? $response['usage'] : null;
        
        if (!$usage) {
            chatbot_log('ERROR', 'track_api_usage', 'No usage data in API response', $response);
            return;
        }
        
        $prompt_tokens = isset($usage['prompt_tokens']) ? intval($usage['prompt_tokens']) : 0;
        $completion_tokens = isset($usage['completion_tokens']) ? intval($usage['completion_tokens']) : 0;
        $total_tokens = isset($usage['total_tokens']) ? intval($usage['total_tokens']) : 0;
        
        // Calculate cost based on model and token usage
        $cost = $this->calculate_token_cost($model, $prompt_tokens, $completion_tokens);
        
        // Get the message ID
        $message_id = null;
        if (isset($response['id'])) {
            // Check if there's a message record with this ID in our database
            global $wpdb;
            $message_table = $wpdb->prefix . 'chatbot_messages';
            $latest_message = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $message_table WHERE conversation_id = %d ORDER BY id DESC LIMIT 1",
                $conversation_id
            ));
            
            if ($latest_message) {
                $message_id = $latest_message;
            }
        }
        
        // Insert the usage data into the database
        global $wpdb;
        $table = $wpdb->prefix . 'chatbot_analytics_api_usage';
        
        $result = $wpdb->insert(
            $table,
            array(
                'conversation_id' => $conversation_id,
                'message_id' => $message_id,
                'model' => $model,
                'prompt_tokens' => $prompt_tokens,
                'completion_tokens' => $completion_tokens,
                'total_tokens' => $total_tokens,
                'cost' => $cost
            ),
            array(
                '%d', '%d', '%s', '%d', '%d', '%d', '%f'
            )
        );
        
        if ($result === false) {
            chatbot_log('ERROR', 'track_api_usage', 'Failed to insert API usage data', $wpdb->last_error);
        } else {
            chatbot_log('INFO', 'track_api_usage', 'API usage tracked successfully', 
                array('conversation_id' => $conversation_id, 'tokens' => $total_tokens, 'cost' => $cost));
            
            // Update daily metrics with this new cost
            $this->update_daily_metrics();
        }
    }
    
    /**
     * Calculate token cost based on model and token counts
     * 
     * @param string $model The OpenAI model used
     * @param int $prompt_tokens Number of prompt tokens
     * @param int $completion_tokens Number of completion tokens
     * @return float The calculated cost
     */
    private function calculate_token_cost($model, $prompt_tokens, $completion_tokens) {
        // Default pricing (as of 2024, subject to change)
        $pricing = array(
            'gpt-4-1106-preview' => array('prompt' => 0.01, 'completion' => 0.03), // $0.01 per 1K prompt tokens, $0.03 per 1K completion tokens
            'gpt-4-32k' => array('prompt' => 0.06, 'completion' => 0.12),
            'gpt-4' => array('prompt' => 0.03, 'completion' => 0.06),
            'gpt-3.5-turbo-1106' => array('prompt' => 0.0010, 'completion' => 0.0020),
            'gpt-3.5-turbo' => array('prompt' => 0.0010, 'completion' => 0.0020),
            'gpt-3.5-turbo-16k' => array('prompt' => 0.0015, 'completion' => 0.0020),
            // Fallback pricing for unknown models
            'default' => array('prompt' => 0.002, 'completion' => 0.002)
        );
        
        // Get the pricing for the specified model, or use default if not found
        $model_pricing = isset($pricing[$model]) ? $pricing[$model] : $pricing['default'];
        
        // Calculate cost: tokens / 1000 * rate
        $prompt_cost = ($prompt_tokens / 1000) * $model_pricing['prompt'];
        $completion_cost = ($completion_tokens / 1000) * $model_pricing['completion'];
        
        // Return total cost
        return $prompt_cost + $completion_cost;
    }
    
    /**
     * Update daily metrics for the current day
     */
    private function update_daily_metrics() {
        global $wpdb;
        
        $today = date('Y-m-d');
        $metrics_table = $wpdb->prefix . 'chatbot_analytics_metrics';
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $messages_table = $wpdb->prefix . 'chatbot_messages';
        $api_usage_table = $wpdb->prefix . 'chatbot_analytics_api_usage';
        $events_table = $wpdb->prefix . 'chatbot_analytics_events';
        
        // Check if we already have a record for today
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $metrics_table WHERE metric_date = %s",
            $today
        ));
        
        // Get today's metrics
        $metrics = array(
            'conversations_count' => (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $conversations_table WHERE DATE(created_at) = %s", $today)
            ),
            'messages_count' => (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $messages_table WHERE DATE(timestamp) = %s", $today)
            ),
            'user_messages_count' => (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $messages_table WHERE DATE(timestamp) = %s AND sender_type = %s", $today, 'user')
            ),
            'ai_messages_count' => (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $messages_table WHERE DATE(timestamp) = %s AND sender_type = %s", $today, 'ai')
            ),
            'unique_users' => (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $events_table WHERE DATE(timestamp) = %s", $today)
            ),
            'total_tokens' => (int) $wpdb->get_var(
                $wpdb->prepare("SELECT SUM(total_tokens) FROM $api_usage_table WHERE DATE(timestamp) = %s", $today)
            ),
            'total_cost' => (float) $wpdb->get_var(
                $wpdb->prepare("SELECT SUM(cost) FROM $api_usage_table WHERE DATE(timestamp) = %s", $today)
            )
        );
        
        // Calculate average conversation length (messages per conversation)
        if ($metrics['conversations_count'] > 0) {
            $metrics['avg_conversation_length'] = $metrics['messages_count'] / $metrics['conversations_count'];
        } else {
            $metrics['avg_conversation_length'] = 0;
        }
        
        // Insert or update the metrics
        if ($existing) {
            // Update existing record
            $wpdb->update(
                $metrics_table,
                $metrics,
                array('metric_date' => $today),
                array('%d', '%d', '%d', '%d', '%d', '%f', '%d', '%f'),
                array('%s')
            );
        } else {
            // Insert new record
            $metrics['metric_date'] = $today;
            $wpdb->insert(
                $metrics_table,
                $metrics,
                array('%s', '%d', '%d', '%d', '%d', '%d', '%f', '%d', '%f')
            );
        }
    }
    
    /**
     * Get analytics data for the admin dashboard
     */
    public function get_analytics() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-admin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }
        
        // Get requested time range
        $range = isset($_POST['range']) ? sanitize_text_field($_POST['range']) : '7days';
        
        // Calculate date range
        $end_date = date('Y-m-d');
        
        switch ($range) {
            case 'today':
                $start_date = $end_date;
                break;
            case '7days':
                $start_date = date('Y-m-d', strtotime('-6 days'));
                break;
            case '30days':
                $start_date = date('Y-m-d', strtotime('-29 days'));
                break;
            case 'month':
                $start_date = date('Y-m-01');
                break;
            case 'year':
                $start_date = date('Y-01-01');
                break;
            default:
                $start_date = date('Y-m-d', strtotime('-6 days'));
        }
        
        global $wpdb;
        $metrics_table = $wpdb->prefix . 'chatbot_analytics_metrics';
        
        // Get metrics for the date range
        $metrics = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $metrics_table 
             WHERE metric_date BETWEEN %s AND %s 
             ORDER BY metric_date ASC",
            $start_date, $end_date
        ));
        
        // Get total conversations
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $total_conversations = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $conversations_table"
        );
        
        // Get total API cost for the period
        $api_usage_table = $wpdb->prefix . 'chatbot_analytics_api_usage';
        $total_cost = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(cost) FROM $api_usage_table 
             WHERE DATE(timestamp) BETWEEN %s AND %s",
            $start_date, $end_date
        ));
        
        // Get total tokens used
        $total_tokens = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(total_tokens) FROM $api_usage_table 
             WHERE DATE(timestamp) BETWEEN %s AND %s",
            $start_date, $end_date
        ));
        
        // Get recent insights
        $insights_table = $wpdb->prefix . 'chatbot_analytics_insights';
        $recent_insights = $wpdb->get_results(
            "SELECT * FROM $insights_table 
             ORDER BY generated_at DESC, priority ASC 
             LIMIT 5"
        );
        
        // Prepare chart data
        $chart_data = array(
            'labels' => array(),
            'conversations' => array(),
            'messages' => array(),
            'costs' => array()
        );
        
        foreach ($metrics as $metric) {
            $chart_data['labels'][] = date('M d', strtotime($metric->metric_date));
            $chart_data['conversations'][] = $metric->conversations_count;
            $chart_data['messages'][] = $metric->messages_count;
            $chart_data['costs'][] = $metric->total_cost;
        }
        
        // Return the analytics data
        wp_send_json_success(array(
            'metrics' => $metrics,
            'total_conversations' => $total_conversations,
            'total_cost' => $total_cost,
            'total_tokens' => $total_tokens,
            'chart_data' => $chart_data,
            'recent_insights' => $recent_insights,
            'date_range' => array(
                'start' => $start_date,
                'end' => $end_date
            )
        ));
    }
    
    /**
     * Get detailed cost data for the admin dashboard
     */
    public function get_cost_data() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-admin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }
        
        // Get requested time range
        $range = isset($_POST['range']) ? sanitize_text_field($_POST['range']) : '30days';
        
        // Calculate date range
        $end_date = date('Y-m-d');
        
        switch ($range) {
            case 'today':
                $start_date = $end_date;
                break;
            case '7days':
                $start_date = date('Y-m-d', strtotime('-6 days'));
                break;
            case '30days':
                $start_date = date('Y-m-d', strtotime('-29 days'));
                break;
            case 'month':
                $start_date = date('Y-m-01');
                break;
            case 'year':
                $start_date = date('Y-01-01');
                break;
            default:
                $start_date = date('Y-m-d', strtotime('-29 days'));
        }
        
        global $wpdb;
        $api_usage_table = $wpdb->prefix . 'chatbot_analytics_api_usage';
        
        // Get cost breakdown by model
        $model_costs = $wpdb->get_results($wpdb->prepare(
            "SELECT model, 
                   SUM(prompt_tokens) as prompt_tokens,
                   SUM(completion_tokens) as completion_tokens,
                   SUM(total_tokens) as total_tokens,
                   SUM(cost) as total_cost
             FROM $api_usage_table 
             WHERE DATE(timestamp) BETWEEN %s AND %s
             GROUP BY model
             ORDER BY total_cost DESC",
            $start_date, $end_date
        ));
        
        // Get daily cost data for the chart
        $daily_costs = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(timestamp) as date, 
                    SUM(cost) as daily_cost,
                    SUM(total_tokens) as daily_tokens
             FROM $api_usage_table 
             WHERE DATE(timestamp) BETWEEN %s AND %s
             GROUP BY DATE(timestamp)
             ORDER BY date ASC",
            $start_date, $end_date
        ));
        
        // Calculate projected cost for the current month
        $month_start = date('Y-m-01');
        $days_in_month = date('t');
        $days_passed = min(date('d'), $days_in_month);
        
        $month_cost = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(cost) FROM $api_usage_table 
             WHERE DATE(timestamp) BETWEEN %s AND %s",
            $month_start, $end_date
        ));
        
        $projected_cost = 0;
        if ($days_passed > 0) {
            $daily_avg = $month_cost / $days_passed;
            $projected_cost = $daily_avg * $days_in_month;
        }
        
        // Prepare chart data
        $chart_data = array(
            'labels' => array(),
            'costs' => array(),
            'tokens' => array()
        );
        
        foreach ($daily_costs as $cost) {
            $chart_data['labels'][] = date('M d', strtotime($cost->date));
            $chart_data['costs'][] = (float) $cost->daily_cost;
            $chart_data['tokens'][] = (int) $cost->daily_tokens;
        }
        
        // Return the cost data
        wp_send_json_success(array(
            'model_costs' => $model_costs,
            'daily_costs' => $daily_costs,
            'chart_data' => $chart_data,
            'month_cost' => $month_cost,
            'projected_cost' => $projected_cost,
            'date_range' => array(
                'start' => $start_date,
                'end' => $end_date
            )
        ));
    }
    
    /**
     * Get AI-generated insights data
     */
    public function get_ai_insights() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-admin-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }
        
        // Get filter parameters
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'all';
        $priority = isset($_POST['priority']) ? sanitize_text_field($_POST['priority']) : 'all';
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 20;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        
        global $wpdb;
        $insights_table = $wpdb->prefix . 'chatbot_analytics_insights';
        
        // Build the query
        $where_clauses = array();
        $query_params = array();
        
        if ($type !== 'all') {
            $where_clauses[] = 'insight_type = %s';
            $query_params[] = $type;
        }
        
        if ($priority !== 'all') {
            $where_clauses[] = 'priority = %s';
            $query_params[] = $priority;
        }
        
        $where = '';
        if (!empty($where_clauses)) {
            $where = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        // Add pagination params
        $query_params[] = $limit;
        $query_params[] = $offset;
        
        // Get insights
        $query = "
            SELECT * FROM $insights_table
            $where
            ORDER BY generated_at DESC, priority ASC
            LIMIT %d OFFSET %d
        ";
        
        $insights = $wpdb->get_results($wpdb->prepare($query, $query_params));
        
        // Get total count for pagination
        $count_query = "SELECT COUNT(*) FROM $insights_table $where";
        $total_count = 0;
        
        if (empty($query_params)) {
            $total_count = $wpdb->get_var($count_query);
        } else {
            // Remove limit and offset params for count query
            $count_params = array_slice($query_params, 0, -2);
            $total_count = $wpdb->get_var($wpdb->prepare($count_query, $count_params));
        }
        
        // Return the insights data
        wp_send_json_success(array(
            'insights' => $insights,
            'total' => $total_count,
            'page' => floor($offset / $limit) + 1,
            'pages' => ceil($total_count / $limit)
        ));
    }
    
    /**
     * Schedule the generation of AI insights
     */
    public function schedule_insights_generation() {
        // Queue a non-blocking request to generate insights
        $this->generate_ai_insights();
    }
    
    /**
     * Generate AI-powered insights from conversation data
     */
    public function generate_ai_insights() {
        global $wpdb;
        
        // Check if OpenAI integration is available
        if (!class_exists('Chatbot_OpenAI') || !Chatbot_OpenAI::get_instance()->is_configured()) {
            chatbot_log('ERROR', 'generate_ai_insights', 'OpenAI integration not available or not configured');
            return;
        }
        
        // Get recent conversation data
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $messages_table = $wpdb->prefix . 'chatbot_messages';
        
        // Get conversations from the last 7 days
        $start_date = date('Y-m-d', strtotime('-7 days'));
        $conversations = $wpdb->get_results($wpdb->prepare("
            SELECT c.id, c.visitor_name, c.created_at, c.status,
                   COUNT(m.id) as message_count
            FROM $conversations_table c
            LEFT JOIN $messages_table m ON c.id = m.conversation_id
            WHERE DATE(c.created_at) >= %s
            GROUP BY c.id
            ORDER BY c.created_at DESC
            LIMIT 100
        ", $start_date));
        
        if (empty($conversations)) {
            chatbot_log('INFO', 'generate_ai_insights', 'No recent conversations found for insights generation');
            return;
        }
        
        // Get common questions and topics
        $user_messages = $wpdb->get_results($wpdb->prepare("
            SELECT m.message
            FROM $messages_table m
            JOIN $conversations_table c ON m.conversation_id = c.id
            WHERE m.sender_type = 'user'
            AND DATE(m.timestamp) >= %s
            ORDER BY m.timestamp DESC
            LIMIT 500
        ", $start_date));
        
        // Get API usage data
        $api_usage_table = $wpdb->prefix . 'chatbot_analytics_api_usage';
        $api_usage = $wpdb->get_row($wpdb->prepare("
            SELECT 
                SUM(total_tokens) as total_tokens,
                SUM(cost) as total_cost,
                AVG(cost) as avg_cost_per_call
            FROM $api_usage_table
            WHERE DATE(timestamp) >= %s
        ", $start_date));
        
        // Prepare the data for OpenAI to analyze
        $conversation_data = array();
        foreach ($conversations as $conv) {
            $conversation_data[] = array(
                'id' => $conv->id,
                'visitor' => $conv->visitor_name,
                'messages' => $conv->message_count,
                'status' => $conv->status,
                'date' => $conv->created_at
            );
        }
        
        $message_content = array();
        foreach ($user_messages as $msg) {
            $message_content[] = $msg->message;
        }
        
        // Create the prompt for OpenAI
        $system_prompt = "You are an analytics expert helping a website owner understand their chatbot usage data. Analyze the provided data and generate 3-5 actionable insights. Focus on patterns, potential leads, areas for improvement, and noteworthy trends.";
        
        $user_prompt = "Here is recent chatbot data. Please analyze it and provide 3-5 key insights that would be valuable for the website owner:\n\n";
        $user_prompt .= "CONVERSATION SUMMARY:\n" . json_encode($conversation_data) . "\n\n";
        $user_prompt .= "RECENT USER MESSAGES (SAMPLE):\n" . json_encode(array_slice($message_content, 0, 100)) . "\n\n";
        $user_prompt .= "API USAGE:\n" . json_encode($api_usage) . "\n\n";
        
        $user_prompt .= "For each insight, provide:
1. A concise title (max 10 words)
2. A detailed explanation (2-3 sentences)
3. Priority level (high, medium, low)
4. Is this a potential lead alert? (true/false)
5. Insight type (usage_pattern, potential_lead, improvement_suggestion, trend, cost_optimization)

Format each insight as JSON objects in an array. Example:
[
  {
    \"title\": \"Increase in Product Questions\",
    \"content\": \"There's been a 40% increase in users asking about product pricing. Consider adding pricing information more prominently on the website.\",
    \"priority\": \"high\",
    \"is_alert\": false,
    \"type\": \"trend\"
  }
]";
        
        // Get the OpenAI instance
        $openai = Chatbot_OpenAI::get_instance();
        
        // Generate insights using OpenAI
        try {
            $response = $openai->get_completion($system_prompt, $user_prompt);
            
            if (!$response) {
                chatbot_log('ERROR', 'generate_ai_insights', 'Failed to get response from OpenAI');
                return;
            }
            
            // Parse the JSON response
            $content = trim($response);
            
            // Extract JSON array from the response (in case there's extra text)
            if (preg_match('/\[\s*{.*}\s*\]/s', $content, $matches)) {
                $content = $matches[0];
            }
            
            $insights = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($insights)) {
                chatbot_log('ERROR', 'generate_ai_insights', 'Invalid JSON response from OpenAI', $content);
                return;
            }
            
            // Insert the insights into the database
            $insights_table = $wpdb->prefix . 'chatbot_analytics_insights';
            
            foreach ($insights as $insight) {
                if (empty($insight['title']) || empty($insight['content'])) {
                    continue;
                }
                
                $wpdb->insert(
                    $insights_table,
                    array(
                        'insight_type' => isset($insight['type']) ? sanitize_text_field($insight['type']) : 'general',
                        'insight_title' => sanitize_text_field($insight['title']),
                        'insight_content' => sanitize_textarea_field($insight['content']),
                        'priority' => isset($insight['priority']) ? sanitize_text_field($insight['priority']) : 'medium',
                        'is_alert' => isset($insight['is_alert']) && $insight['is_alert'] ? 1 : 0,
                        'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days'))
                    ),
                    array('%s', '%s', '%s', '%s', '%d', '%s')
                );
            }
            
            chatbot_log('INFO', 'generate_ai_insights', 'Generated ' . count($insights) . ' AI insights successfully');
            
        } catch (Exception $e) {
            chatbot_log('ERROR', 'generate_ai_insights', 'Exception in OpenAI API call', $e->getMessage());
        }
    }
    
    /**
     * Add analytics summary to the overview page
     */
    public function render_analytics_summary() {
        ?>
        <div class="card analytics-sidebar-card">
            <h2><?php _e('Analytics Overview', 'chatbot-plugin'); ?></h2>
            
            <div class="chatbot-analytics-summary">
                <?php $this->render_analytics_overview(); ?>
            </div>
            
            <p>
                <button id="refresh-analytics-summary" class="button button-secondary">
                    <?php _e('Refresh Data', 'chatbot-plugin'); ?>
                </button>
            </p>
        </div>
        <?php
    }
    
    /**
     * Enqueue admin scripts and styles for analytics
     * 
     * @param string $hook Current admin page
     */
    /**
     * Render the analytics overview for the dashboard
     */
    public function render_analytics_overview() {
        // Get analytics data for the last 7 days
        global $wpdb;
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime('-6 days'));
        
        $metrics_table = $wpdb->prefix . 'chatbot_analytics_metrics';
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        $api_usage_table = $wpdb->prefix . 'chatbot_analytics_api_usage';
        $insights_table = $wpdb->prefix . 'chatbot_analytics_insights';
        
        // Get conversations table
        $conversations_table = $wpdb->prefix . 'chatbot_conversations';
        
        // Check if there are any conversations
        $conversation_count = $wpdb->get_var("SELECT COUNT(*) FROM $conversations_table");
        
        if ($conversation_count == 0) {
            echo '<p>' . __('No conversations found. Analytics will populate once chat sessions begin.', 'chatbot-plugin') . '</p>';
            return;
        }
        
        // Get total conversations
        $total_conversations = (int) $wpdb->get_var("SELECT COUNT(*) FROM $conversations_table");
        
        // Get total messages
        $messages_table = $wpdb->prefix . 'chatbot_messages';
        $total_messages = (int) $wpdb->get_var("SELECT COUNT(*) FROM $messages_table");
        
        // Check if API usage table exists
        $api_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$api_usage_table'") === $api_usage_table;
        
        // Get total API cost for the period
        if ($api_table_exists) {
            $total_cost = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(cost) FROM $api_usage_table 
                 WHERE DATE(timestamp) BETWEEN %s AND %s",
                $start_date, $end_date
            ));
        } else {
            $total_cost = 0.00;
        }
        
        // Get recent insights if the table exists
        $insights_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$insights_table'") === $insights_table;
        
        if ($insights_table_exists) {
            $recent_insights = $wpdb->get_results(
                "SELECT * FROM $insights_table 
                 WHERE is_alert = 1
                 ORDER BY generated_at DESC, priority ASC 
                 LIMIT 3"
            );
        } else {
            $recent_insights = array();
        }
        
        // Display key metrics
        ?>
        <div class="chatbot-analytics-overview">
            <div class="chatbot-analytics-card">
                <h3><?php _e('Conversations', 'chatbot-plugin'); ?></h3>
                <div class="chatbot-analytics-card-content">
                    <div class="chatbot-analytics-metric">
                        <span class="chatbot-analytics-value"><?php echo $total_conversations; ?></span>
                        <span class="chatbot-analytics-label"><?php _e('Total', 'chatbot-plugin'); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="chatbot-analytics-card">
                <h3><?php _e('Messages', 'chatbot-plugin'); ?></h3>
                <div class="chatbot-analytics-card-content">
                    <div class="chatbot-analytics-metric">
                        <span class="chatbot-analytics-value"><?php echo $total_messages; ?></span>
                        <span class="chatbot-analytics-label"><?php _e('Total', 'chatbot-plugin'); ?></span>
                    </div>
                    <div class="chatbot-analytics-metric">
                        <span class="chatbot-analytics-value"><?php echo $total_conversations > 0 ? round($total_messages / $total_conversations, 1) : 0; ?></span>
                        <span class="chatbot-analytics-label"><?php _e('Avg/Conv', 'chatbot-plugin'); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="chatbot-analytics-card">
                <h3><?php _e('API Usage', 'chatbot-plugin'); ?></h3>
                <div class="chatbot-analytics-card-content">
                    <div class="chatbot-analytics-metric">
                        <span class="chatbot-analytics-value">$<?php echo number_format($total_cost, 2); ?></span>
                        <span class="chatbot-analytics-label"><?php _e('Last 7 Days', 'chatbot-plugin'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($recent_insights && count($recent_insights) > 0): ?>
        <div class="chatbot-analytics-insights">
            <h3><?php _e('Recent Insights', 'chatbot-plugin'); ?></h3>
            <?php foreach ($recent_insights as $insight): ?>
                <div class="chatbot-insight-card priority-<?php echo esc_attr($insight->priority); ?> <?php echo $insight->is_alert ? 'is-alert' : ''; ?>">
                    <div class="chatbot-insight-header">
                        <h4><?php echo esc_html($insight->insight_title); ?></h4>
                    </div>
                    <div class="chatbot-insight-content">
                        <p><?php echo esc_html($insight->insight_content); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Enqueue admin scripts and styles for analytics
     * 
     * @param string $hook Current admin page
     */
    public function enqueue_admin_scripts($hook) {
        // Load analytics scripts on main plugin page and analytics page
        if ($hook === 'toplevel_page_chatbot-plugin' || $hook === 'chat-bots_page_chatbot-analytics') {
            // Enqueue Chart.js
            wp_enqueue_script(
                'chart-js',
                'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
                array(),
                '3.9.1',
                true
            );
            
            // Enqueue Showdown.js for Markdown to HTML conversion (from CDN for reliability)
            wp_enqueue_script(
                'showdown-js',
                'https://cdnjs.cloudflare.com/ajax/libs/showdown/2.1.0/showdown.min.js',
                array(),
                '2.1.0',
                false // Load in header instead of footer
            );
            
            // Also enqueue our local version as a fallback
            wp_enqueue_script(
                'showdown-js-local',
                CHATBOT_PLUGIN_URL . 'assets/js/showdown.min.js',
                array(),
                '2.1.0',
                false // Load in header instead of footer
            );
            
            // Enqueue our admin JS
            wp_enqueue_script(
                'chatbot-analytics-admin',
                CHATBOT_PLUGIN_URL . 'assets/js/chatbot-analytics-admin.js',
                array('jquery', 'chart-js', 'showdown-js'),
                CHATBOT_PLUGIN_VERSION,
                true
            );
            
            // Enqueue analytics specific CSS
            wp_enqueue_style(
                'chatbot-analytics-admin-css',
                CHATBOT_PLUGIN_URL . 'assets/css/chatbot-admin-analytics.css',
                array(),
                CHATBOT_PLUGIN_VERSION
            );
            
            // Localize script with nonce, ajax URL, and plugin URL
            wp_localize_script(
                'chatbot-analytics-admin',
                'chatbotAnalyticsVars',
                array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('chatbot-admin-nonce'),
                    'pluginUrl' => CHATBOT_PLUGIN_URL
                )
            );
        }
    }
    
    /**
     * Add tracking script to the frontend
     */
    public function add_tracking_script() {
        // Only add tracking if there's a chatbot on the page
        if (!has_shortcode(get_the_content(), 'chatbot')) {
            return;
        }
        
        ?>
        <script type="text/javascript">
            (function($) {
                'use strict';
                
                // Generate a unique session ID if not already present
                if (!localStorage.getItem('chatbot_session_id')) {
                    localStorage.setItem('chatbot_session_id', 'session_' + Math.random().toString(36).substring(2, 15));
                }
                
                const sessionId = localStorage.getItem('chatbot_session_id');
                const conversationId = localStorage.getItem('chatbot_conversation_id');
                
                // Initialize event tracking
                function trackEvent(eventType, eventData = {}) {
                    // Add session and conversation IDs
                    eventData.session_id = sessionId;
                    
                    if (conversationId) {
                        eventData.conversation_id = conversationId;
                    }
                    
                    // Add page URL
                    eventData.page_url = window.location.href;
                    
                    // Send tracking data to server
                    $.ajax({
                        url: chatbotPluginVars.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'chatbot_track_event',
                            event_type: eventType,
                            event_data: eventData,
                            session_id: sessionId,
                            conversation_id: conversationId,
                            page_url: window.location.href,
                            nonce: chatbotPluginVars.nonce
                        },
                        success: function(response) {
                            // Event tracking successful
                        },
                        error: function() {
                            // Silent error handling for tracking
                        }
                    });
                }
                
                // Track chatbot initialization
                $(document).ready(function() {
                    // Track page view with chatbot
                    trackEvent('chatbot_page_view');
                    
                    const chatButton = $('#chatButton');
                    const chatbotContainer = $('.chatbot-container');
                    const chatbotStartBtn = $('.chatbot-start-btn');
                    const chatbotSendBtn = $('.chatbot-send-btn');
                    const chatbotEndBtn = $('.chatbot-end-btn');
                    const chatbotInput = $('.chatbot-input');
                    
                    // Track chatbot open/close
                    chatButton.on('click', function() {
                        if (chatbotContainer.hasClass('active')) {
                            trackEvent('chatbot_opened');
                        } else {
                            trackEvent('chatbot_closed');
                        }
                    });
                    
                    // Track conversation start
                    chatbotStartBtn.on('click', function() {
                        const name = $('.chatbot-name-input').val().trim();
                        if (name !== '') {
                            trackEvent('conversation_started', {
                                visitor_name: name
                            });
                        }
                    });
                    
                    // Track message sent
                    chatbotSendBtn.on('click', function() {
                        const message = chatbotInput.val().trim();
                        if (message !== '') {
                            trackEvent('message_sent', {
                                message_length: message.length,
                                message_preview: message.substring(0, 50)
                            });
                        }
                    });
                    
                    // Also track on Enter keypress
                    chatbotInput.on('keypress', function(e) {
                        if (e.which === 13) {
                            const message = chatbotInput.val().trim();
                            if (message !== '') {
                                trackEvent('message_sent', {
                                    message_length: message.length,
                                    message_preview: message.substring(0, 50)
                                });
                            }
                        }
                    });
                    
                    // Track conversation end
                    chatbotEndBtn.on('click', function() {
                        trackEvent('conversation_ended');
                    });
                    
                    // Track when user resumes a conversation
                    if (localStorage.getItem('chatbot_conversation_id')) {
                        trackEvent('conversation_resumed');
                    }
                    
                    // Track time spent on page
                    let startTime = new Date();
                    
                    $(window).on('beforeunload', function() {
                        const endTime = new Date();
                        const timeSpent = Math.round((endTime - startTime) / 1000); // time in seconds
                        
                        // Only track if spent more than 5 seconds
                        if (timeSpent > 5) {
                            trackEvent('page_exit', {
                                time_spent: timeSpent
                            });
                        }
                    });
                });
            })(jQuery);
        </script>
        <?php
    }
    
    /**
     * Render the analytics admin page
     */
    public function render_analytics_page() {
        ?>
        <div class="wrap chatbot-analytics-dashboard">
            <h1>Chatbot Analytics</h1>
            
            <div class="chatbot-analytics-period-selector">
                <label for="time-range">Time Period:</label>
                <select id="time-range">
                    <option value="today">Today</option>
                    <option value="7days" selected>Last 7 Days</option>
                    <option value="30days">Last 30 Days</option>
                    <option value="month">This Month</option>
                    <option value="year">This Year</option>
                </select>
                <button id="refresh-analytics" class="button">Refresh Data</button>
            </div>
            
            <div class="chatbot-analytics-loading">
                <span class="spinner is-active"></span>
                <p>Loading analytics data...</p>
            </div>
            
            <div class="chatbot-analytics-overview">
                <div class="chatbot-analytics-card">
                    <h3>Conversations</h3>
                    <div class="chatbot-analytics-card-content">
                        <div class="chatbot-analytics-metric">
                            <span class="chatbot-analytics-value" id="total-conversations">0</span>
                            <span class="chatbot-analytics-label">Total</span>
                        </div>
                        <div class="chatbot-analytics-metric">
                            <span class="chatbot-analytics-value" id="active-conversations">0</span>
                            <span class="chatbot-analytics-label">Active</span>
                        </div>
                    </div>
                </div>
                
                <div class="chatbot-analytics-card">
                    <h3>Messages</h3>
                    <div class="chatbot-analytics-card-content">
                        <div class="chatbot-analytics-metric">
                            <span class="chatbot-analytics-value" id="total-messages">0</span>
                            <span class="chatbot-analytics-label">Total</span>
                        </div>
                        <div class="chatbot-analytics-metric">
                            <span class="chatbot-analytics-value" id="avg-messages">0</span>
                            <span class="chatbot-analytics-label">Avg/Conv</span>
                        </div>
                    </div>
                </div>
                
                <div class="chatbot-analytics-card">
                    <h3>API Usage</h3>
                    <div class="chatbot-analytics-card-content">
                        <div class="chatbot-analytics-metric">
                            <span class="chatbot-analytics-value" id="total-tokens">0</span>
                            <span class="chatbot-analytics-label">Tokens</span>
                        </div>
                        <div class="chatbot-analytics-metric">
                            <span class="chatbot-analytics-value" id="total-cost">$0.00</span>
                            <span class="chatbot-analytics-label">Cost</span>
                        </div>
                    </div>
                </div>
                
                <div class="chatbot-analytics-card">
                    <h3>Monthly Projection</h3>
                    <div class="chatbot-analytics-card-content">
                        <div class="chatbot-analytics-metric">
                            <span class="chatbot-analytics-value" id="month-cost">$0.00</span>
                            <span class="chatbot-analytics-label">Current</span>
                        </div>
                        <div class="chatbot-analytics-metric">
                            <span class="chatbot-analytics-value" id="projected-cost">$0.00</span>
                            <span class="chatbot-analytics-label">Projected</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="chatbot-analytics-charts">
                <div class="chatbot-analytics-card full-width">
                    <h3>Conversation Activity</h3>
                    <div class="chatbot-chart-container">
                        <canvas id="conversation-chart"></canvas>
                    </div>
                </div>
                
                <div class="chatbot-analytics-card half-width">
                    <h3>API Usage</h3>
                    <div class="chatbot-chart-container">
                        <canvas id="api-usage-chart"></canvas>
                    </div>
                </div>
                
                <div class="chatbot-analytics-card half-width">
                    <h3>Message Distribution</h3>
                    <div class="chatbot-chart-container">
                        <canvas id="message-distribution-chart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="chatbot-analytics-tabs">
                <div class="chatbot-tabs-header">
                    <button class="chatbot-tab-button active" data-tab="insights">AI Insights</button>
                    <button class="chatbot-tab-button" data-tab="costs">Cost Analysis</button>
                    <button class="chatbot-tab-button" data-tab="alerts">Lead Alerts</button>
                </div>
                
                <div class="chatbot-tab-content" id="tab-insights">
                    <div class="chatbot-insights-header">
                        <h3>AI-Generated Insights</h3>
                        <button id="generate-insights" class="button">Generate New Insights</button>
                    </div>
                    
                    <div class="chatbot-insights-filters">
                        <select id="insight-type-filter">
                            <option value="all">All Types</option>
                            <option value="usage_pattern">Usage Patterns</option>
                            <option value="potential_lead">Potential Leads</option>
                            <option value="improvement_suggestion">Improvement Suggestions</option>
                            <option value="trend">Trends</option>
                            <option value="cost_optimization">Cost Optimization</option>
                        </select>
                        
                        <select id="insight-priority-filter">
                            <option value="all">All Priorities</option>
                            <option value="high">High Priority</option>
                            <option value="medium">Medium Priority</option>
                            <option value="low">Low Priority</option>
                        </select>
                    </div>
                    
                    <div class="chatbot-insights-container">
                        <div class="chatbot-insights-loading">
                            <span class="spinner is-active"></span>
                            <p>Loading insights...</p>
                        </div>
                        <div id="insights-list"></div>
                        <div class="chatbot-insights-pagination"></div>
                    </div>
                </div>
                
                <div class="chatbot-tab-content" id="tab-costs" style="display:none;">
                    <h3>Cost Analysis</h3>
                    
                    <div class="chatbot-costs-overview">
                        <div class="chatbot-analytics-card full-width">
                            <h3>Daily API Costs</h3>
                            <div class="chatbot-chart-container">
                                <canvas id="cost-chart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="chatbot-model-costs">
                        <h3>Cost by Model</h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Model</th>
                                    <th>Total Tokens</th>
                                    <th>Prompt Tokens</th>
                                    <th>Completion Tokens</th>
                                    <th>Total Cost</th>
                                </tr>
                            </thead>
                            <tbody id="model-costs-table">
                                <tr>
                                    <td colspan="5">Loading cost data...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="chatbot-tab-content" id="tab-alerts" style="display:none;">
                    <h3>Lead Alerts</h3>
                    
                    <div class="chatbot-alerts-filters">
                        <select id="alert-status-filter">
                            <option value="all">All Alerts</option>
                            <option value="unread">Unread</option>
                            <option value="read">Read</option>
                        </select>
                    </div>
                    
                    <div class="chatbot-alerts-container">
                        <div class="chatbot-alerts-loading">
                            <span class="spinner is-active"></span>
                            <p>Loading alerts...</p>
                        </div>
                        <div id="alerts-list"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
            // Analytics page initialization will be handled by chatbot-analytics-admin.js
        </script>
        <?php
    }
}

// Initialize the analytics handler
function chatbot_analytics_init() {
    return Chatbot_Analytics::get_instance();
}
chatbot_analytics_init();