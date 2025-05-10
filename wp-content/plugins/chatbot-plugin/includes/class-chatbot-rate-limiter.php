<?php
/**
 * Chatbot Rate Limiter
 * 
 * Handles rate limiting for the chatbot to prevent abuse and manage API costs
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_Rate_Limiter {
    
    private static $instance = null;
    
    // Default rate limits
    public $default_limits = array(
        'messages_per_minute' => 5,    // Messages per minute per user
        'messages_per_hour' => 20,     // Messages per hour per user
        'messages_per_day' => 50,      // Messages per day per user
        'global_per_minute' => 30,     // Global messages per minute (all users)
        'global_per_hour' => 200,      // Global messages per hour (all users)
        'max_message_length' => 500,   // Maximum message length in characters
    );
    
    // Option names for storing limits in WordPress options
    public $option_names = array(
        'messages_per_minute' => 'chatbot_rate_limit_per_minute',
        'messages_per_hour' => 'chatbot_rate_limit_per_hour',
        'messages_per_day' => 'chatbot_rate_limit_per_day',
        'global_per_minute' => 'chatbot_rate_limit_global_per_minute',
        'global_per_hour' => 'chatbot_rate_limit_global_per_hour',
        'max_message_length' => 'chatbot_max_message_length',
    );
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Initialize rate limit options with defaults if not set
        foreach ($this->default_limits as $key => $value) {
            if (get_option($this->option_names[$key]) === false) {
                add_option($this->option_names[$key], $value);
            }
        }
        
        // Register settings for rate limits
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Register settings for rate limits
     */
    public function register_settings() {
        // Register settings for rate limits
        register_setting('chatbot_settings', $this->option_names['messages_per_minute'], array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => $this->default_limits['messages_per_minute'],
        ));
        
        register_setting('chatbot_settings', $this->option_names['messages_per_hour'], array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => $this->default_limits['messages_per_hour'],
        ));
        
        register_setting('chatbot_settings', $this->option_names['messages_per_day'], array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => $this->default_limits['messages_per_day'],
        ));
        
        register_setting('chatbot_settings', $this->option_names['global_per_minute'], array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => $this->default_limits['global_per_minute'],
        ));
        
        register_setting('chatbot_settings', $this->option_names['global_per_hour'], array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => $this->default_limits['global_per_hour'],
        ));

        register_setting('chatbot_settings', $this->option_names['max_message_length'], array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => $this->default_limits['max_message_length'],
        ));
        
        // Add settings section for rate limits
        add_settings_section(
            'chatbot_rate_limit_section',
            __('Rate Limiting', 'chatbot-plugin'),
            array($this, 'render_settings_section'),
            'chatbot_settings'
        );
        
        // Add settings fields
        add_settings_field(
            'chatbot_rate_limit_per_minute',
            __('Messages per minute (per user)', 'chatbot-plugin'),
            array($this, 'render_number_field'),
            'chatbot_settings',
            'chatbot_rate_limit_section',
            array(
                'label_for' => $this->option_names['messages_per_minute'],
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
                'label_for' => $this->option_names['messages_per_hour'],
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
                'label_for' => $this->option_names['messages_per_day'],
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
                'label_for' => $this->option_names['global_per_minute'],
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
                'label_for' => $this->option_names['global_per_hour'],
                'min' => 20,
                'max' => 5000,
                'description' => __('Maximum number of messages from all users per hour.', 'chatbot-plugin')
            )
        );

        add_settings_field(
            'chatbot_max_message_length',
            __('Maximum message length', 'chatbot-plugin'),
            array($this, 'render_number_field'),
            'chatbot_settings',
            'chatbot_rate_limit_section',
            array(
                'label_for' => $this->option_names['max_message_length'],
                'min' => 100,
                'max' => 10000,
                'description' => __('Maximum length of a message in characters. Messages longer than this will be rejected.', 'chatbot-plugin')
            )
        );
    }
    
    /**
     * Render settings section
     */
    public function render_settings_section() {
        echo '<p>' . __('Configure rate limits to prevent abuse and control API costs.', 'chatbot-plugin') . '</p>';
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
     * Check if a message exceeds the maximum length limit
     *
     * @param string $message The message to check
     * @return array Result with status and error message if applicable
     */
    public function check_message_length($message) {
        // Get maximum message length from options
        $max_length = get_option($this->option_names['max_message_length'], $this->default_limits['max_message_length']);

        // Check if message exceeds maximum length
        if (strlen($message) > $max_length) {
            return array(
                'allowed' => false,
                'reason' => 'message_too_long',
                'message' => sprintf(
                    __('Your message is too long. Maximum allowed length is %d characters.', 'chatbot-plugin'),
                    $max_length
                ),
                'max_length' => $max_length,
                'current_length' => strlen($message)
            );
        }

        // Message length is within limits
        return array('allowed' => true);
    }

    /**
     * Check if a user can send a message based on rate limits
     *
     * @param string $user_identifier User identification (IP address or session ID)
     * @param string $message Optional message to check length
     * @return array Result with status and error message if applicable
     */
    public function can_send_message($user_identifier, $message = '') {
        // First check message length if message is provided
        if (!empty($message)) {
            $length_check = $this->check_message_length($message);
            if (!$length_check['allowed']) {
                return $length_check;
            }
        }

        // Get current rate limits from options
        $limits = array();
        foreach ($this->option_names as $key => $option_name) {
            $limits[$key] = get_option($option_name, $this->default_limits[$key]);
        }

        // Validate user identifier
        if (empty($user_identifier)) {
            $user_identifier = $this->get_user_identifier();
        }
        
        // Get transient keys for rate tracking
        $user_minute_key = 'chatbot_rate_' . md5($user_identifier . '_minute');
        $user_hour_key = 'chatbot_rate_' . md5($user_identifier . '_hour');
        $user_day_key = 'chatbot_rate_' . md5($user_identifier . '_day');
        $global_minute_key = 'chatbot_rate_global_minute';
        $global_hour_key = 'chatbot_rate_global_hour';
        
        // Get current counts from transients
        $user_minute_count = get_transient($user_minute_key) ?: 0;
        $user_hour_count = get_transient($user_hour_key) ?: 0;
        $user_day_count = get_transient($user_day_key) ?: 0;
        $global_minute_count = get_transient($global_minute_key) ?: 0;
        $global_hour_count = get_transient($global_hour_key) ?: 0;
        
        // Check rate limits
        if ($user_minute_count >= $limits['messages_per_minute']) {
            return array(
                'allowed' => false,
                'reason' => 'minute_limit',
                'message' => sprintf(
                    __('Rate limit exceeded. Please wait before sending more messages. You can send %d messages per minute.', 'chatbot-plugin'),
                    $limits['messages_per_minute']
                )
            );
        }
        
        if ($user_hour_count >= $limits['messages_per_hour']) {
            return array(
                'allowed' => false,
                'reason' => 'hour_limit',
                'message' => sprintf(
                    __('Rate limit exceeded. You have reached your hourly message limit of %d messages.', 'chatbot-plugin'),
                    $limits['messages_per_hour']
                )
            );
        }
        
        if ($user_day_count >= $limits['messages_per_day']) {
            return array(
                'allowed' => false,
                'reason' => 'day_limit',
                'message' => sprintf(
                    __('Rate limit exceeded. You have reached your daily message limit of %d messages.', 'chatbot-plugin'),
                    $limits['messages_per_day']
                )
            );
        }
        
        if ($global_minute_count >= $limits['global_per_minute']) {
            return array(
                'allowed' => false,
                'reason' => 'global_minute_limit',
                'message' => __('The system is currently experiencing high traffic. Please try again in a minute.', 'chatbot-plugin')
            );
        }
        
        if ($global_hour_count >= $limits['global_per_hour']) {
            return array(
                'allowed' => false,
                'reason' => 'global_hour_limit',
                'message' => __('The system has reached its hourly message limit. Please try again later.', 'chatbot-plugin')
            );
        }
        
        // All checks passed
        return array('allowed' => true);
    }
    
    /**
     * Increment rate counters for a user
     *
     * @param string $user_identifier User identification (IP address or session ID)
     */
    public function increment_rate_counters($user_identifier) {
        // Validate user identifier
        if (empty($user_identifier)) {
            $user_identifier = $this->get_user_identifier();
        }
        
        // Get transient keys for rate tracking
        $user_minute_key = 'chatbot_rate_' . md5($user_identifier . '_minute');
        $user_hour_key = 'chatbot_rate_' . md5($user_identifier . '_hour');
        $user_day_key = 'chatbot_rate_' . md5($user_identifier . '_day');
        $global_minute_key = 'chatbot_rate_global_minute';
        $global_hour_key = 'chatbot_rate_global_hour';
        
        // Get current counts from transients
        $user_minute_count = get_transient($user_minute_key) ?: 0;
        $user_hour_count = get_transient($user_hour_key) ?: 0;
        $user_day_count = get_transient($user_day_key) ?: 0;
        $global_minute_count = get_transient($global_minute_key) ?: 0;
        $global_hour_count = get_transient($global_hour_key) ?: 0;
        
        // Increment counts
        $user_minute_count++;
        $user_hour_count++;
        $user_day_count++;
        $global_minute_count++;
        $global_hour_count++;
        
        // Set transients with appropriate expirations
        set_transient($user_minute_key, $user_minute_count, 60);  // 1 minute
        set_transient($user_hour_key, $user_hour_count, 3600);    // 1 hour
        set_transient($user_day_key, $user_day_count, 86400);     // 1 day
        set_transient($global_minute_key, $global_minute_count, 60);  // 1 minute
        set_transient($global_hour_key, $global_hour_count, 3600);    // 1 hour
        
        // Log rate limiting information
        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'rate_limit', 'Rate limit counters incremented', array(
                'user_minute' => $user_minute_count,
                'user_hour' => $user_hour_count,
                'user_day' => $user_day_count,
                'global_minute' => $global_minute_count,
                'global_hour' => $global_hour_count
            ));
        }
    }
    
    /**
     * Get a unique identifier for the current user
     * Uses IP address plus session ID if available
     *
     * @return string User identifier
     */
    public function get_user_identifier() {
        // Start with IP address
        $identifier = $this->get_client_ip();
        
        // Add session ID if available
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['chatbot_session_id'])) {
            $identifier .= '_' . $_SESSION['chatbot_session_id'];
        } elseif (isset($_COOKIE['chatbot_session_id'])) {
            $identifier .= '_' . $_COOKIE['chatbot_session_id'];
        } elseif (isset($_POST['session_id'])) {
            $identifier .= '_' . sanitize_text_field($_POST['session_id']);
        }
        
        return $identifier;
    }
    
    /**
     * Get client IP address safely
     *
     * @return string Client IP address
     */
    private function get_client_ip() {
        // Check for proxy forwarded IP
        $ip = '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // HTTP_X_FORWARDED_FOR can contain multiple IPs separated by commas
            $ip_array = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ip_array[0]);
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        // Make sure it's a valid IP
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
        
        return $ip;
    }
    
    /**
     * Reset all rate limit counters for testing or emergencies
     */
    public function reset_all_rate_limits() {
        global $wpdb;
        
        // Only allow this for administrators
        if (!current_user_can('manage_options')) {
            return false;
        }
        
        // Delete all transients with the rate limit prefix
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $wpdb->options WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_chatbot_rate_') . '%'
            )
        );
        
        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'rate_limit', 'All rate limits reset by admin');
        }

        return true;
    }
}

// Initialize the rate limiter
function chatbot_rate_limiter_init() {
    return Chatbot_Rate_Limiter::get_instance();
}
chatbot_rate_limiter_init();