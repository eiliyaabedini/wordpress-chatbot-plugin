<?php
/**
 * Chatbot OpenAI Integration
 * 
 * Handles integration with OpenAI API for the chatbot
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_OpenAI {
    
    private static $instance = null;
    private $api_key = '';
    private $model = 'gpt-3.5-turbo';
    private $max_tokens = 150;
    private $temperature = 0.7;
    private $system_prompt = '';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Get API key from options
        $this->api_key = get_option('chatbot_openai_api_key', '');
        $this->model = get_option('chatbot_openai_model', 'gpt-3.5-turbo');
        $this->max_tokens = get_option('chatbot_openai_max_tokens', 150);
        $this->temperature = get_option('chatbot_openai_temperature', 0.7);
        $this->system_prompt = get_option('chatbot_openai_system_prompt', $this->get_default_system_prompt());
        
        // Add settings fields and section
        add_action('admin_init', array($this, 'register_settings'));
        
        // We're not using this hook anymore since it expects different parameters
        // add_action('chatbot_settings_sections', array($this, 'add_settings_section'));
        
        // AJAX handler for testing OpenAI connection
        add_action('wp_ajax_chatbot_test_openai', array($this, 'test_connection'));
    }
    
    /**
     * Validate the model and return a fallback if invalid
     * 
     * @param string $model The model to validate
     * @return string The validated model or fallback to gpt-3.5-turbo
     */
    private function validate_model($model) {
        $valid_models = array(
            'gpt-4.1-mini', 
            'gpt-4o', 
            'gpt-4o-mini', 
            'gpt-4-turbo',
            'gpt-4',
            'gpt-3.5-turbo'
        );
        
        if (!in_array($model, $valid_models)) {
            return 'gpt-3.5-turbo'; // Default fallback
        }
        
        return $model;
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Register settings
        register_setting('chatbot_settings', 'chatbot_openai_api_key');
        register_setting('chatbot_settings', 'chatbot_openai_model');
        register_setting('chatbot_settings', 'chatbot_openai_max_tokens', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 150,
        ));
        register_setting('chatbot_settings', 'chatbot_openai_temperature', array(
            'type' => 'number',
            'sanitize_callback' => array($this, 'sanitize_temperature'),
            'default' => 0.7,
        ));
        register_setting('chatbot_settings', 'chatbot_openai_system_prompt', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => $this->get_default_system_prompt(),
        ));
        
        // Add settings section for the OpenAI tab
        add_settings_section(
            'chatbot_openai_settings_section',
            __('OpenAI API Configuration', 'chatbot-plugin'),
            array($this, 'render_settings_section'),
            'chatbot_openai_settings' // This is the page we're using for the OpenAI tab
        );
        
        // Add settings fields to the OpenAI tab
        add_settings_field(
            'chatbot_openai_api_key',
            __('OpenAI API Key', 'chatbot-plugin'),
            array($this, 'render_api_key_field'),
            'chatbot_openai_settings', // The page
            'chatbot_openai_settings_section' // The section
        );
        
        add_settings_field(
            'chatbot_openai_model',
            __('Model', 'chatbot-plugin'),
            array($this, 'render_model_field'),
            'chatbot_openai_settings',
            'chatbot_openai_settings_section'
        );
        
        add_settings_field(
            'chatbot_openai_max_tokens',
            __('Max Tokens', 'chatbot-plugin'),
            array($this, 'render_max_tokens_field'),
            'chatbot_openai_settings',
            'chatbot_openai_settings_section'
        );
        
        add_settings_field(
            'chatbot_openai_temperature',
            __('Temperature', 'chatbot-plugin'),
            array($this, 'render_temperature_field'),
            'chatbot_openai_settings',
            'chatbot_openai_settings_section'
        );
        
        add_settings_field(
            'chatbot_openai_system_prompt',
            __('System Prompt', 'chatbot-plugin'),
            array($this, 'render_system_prompt_field'),
            'chatbot_openai_settings',
            'chatbot_openai_settings_section'
        );
    }
    
    /**
     * Add Settings Section to Admin Page
     * 
     * This hook is for adding a tab to the settings page, 
     * not for adding sections to existing tabs
     */
    public function add_settings_section() {
        // No need to add sections this way since we're using our own settings registration
        return;
    }
    
    /**
     * Render Settings Section
     */
    public function render_settings_section() {
        echo '<p>' . __('Configure OpenAI integration settings to enhance your chatbot with AI capabilities.', 'chatbot-plugin') . '</p>';
    }
    
    /**
     * Render API Key Field
     */
    public function render_api_key_field() {
        $api_key = $this->api_key;
        
        echo '<input type="password" name="chatbot_openai_api_key" id="chatbot_openai_api_key" class="regular-text" value="' . esc_attr($api_key) . '" placeholder="sk-..." />';
        echo '<button type="button" id="toggle-api-key" class="button button-secondary" style="margin-left: 8px;">' . __('Show/Hide', 'chatbot-plugin') . '</button>';
        echo '<p class="description">' . __('Your OpenAI API key. Keep this secure and never expose it to the public.', 'chatbot-plugin') . '</p>';
        
        if (!empty($api_key)) {
            echo '<p class="description">' . __('API key is set. For security, the key is not displayed.', 'chatbot-plugin') . '</p>';
        }
        
        // Add JavaScript to toggle password visibility
        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                $("#toggle-api-key").on("click", function() {
                    var apiKeyField = $("#chatbot_openai_api_key");
                    if (apiKeyField.attr("type") === "password") {
                        apiKeyField.attr("type", "text");
                    } else {
                        apiKeyField.attr("type", "password");
                    }
                });
            });
        </script>';
    }
    
    /**
     * Render Model Field
     */
    public function render_model_field() {
        $model = get_option('chatbot_openai_model', 'gpt-3.5-turbo');
        
        $models = array(
            // GPT-4 models - newest and most powerful
            'gpt-4.1-mini' => __('GPT-4.1 Mini (Latest & Economic)', 'chatbot-plugin'),
            'gpt-4o' => __('GPT-4o (Latest & Most Capable)', 'chatbot-plugin'),
            'gpt-4o-mini' => __('GPT-4o Mini (Latest, Fast & Economic)', 'chatbot-plugin'),
            'gpt-4-turbo' => __('GPT-4 Turbo (Powerful)', 'chatbot-plugin'),
            'gpt-4' => __('GPT-4 (Powerful)', 'chatbot-plugin'),
            
            // GPT-3.5 models - economic options
            'gpt-3.5-turbo' => __('GPT-3.5 Turbo (Fast & Most Economical)', 'chatbot-plugin')
        );
        
        echo '<select name="chatbot_openai_model" id="chatbot_openai_model">';
        foreach ($models as $model_id => $model_name) {
            echo '<option value="' . esc_attr($model_id) . '" ' . selected($model, $model_id, false) . '>' . esc_html($model_name) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . __('Select the OpenAI model to use. GPT-3.5 Turbo is fastest and most economical, while GPT-4.1 and GPT-4o models are more capable but cost more.', 'chatbot-plugin') . '</p>';
        echo '<p class="description">' . __('Recommended: GPT-4.1 Mini or GPT-4o Mini for a good balance of performance and cost.', 'chatbot-plugin') . '</p>';
    }
    
    /**
     * Render Max Tokens Field
     */
    public function render_max_tokens_field() {
        $max_tokens = get_option('chatbot_openai_max_tokens', 150);
        
        echo '<input type="number" name="chatbot_openai_max_tokens" id="chatbot_openai_max_tokens" class="small-text" value="' . esc_attr($max_tokens) . '" min="50" max="4000" step="10" />';
        echo '<p class="description">' . __('Maximum number of tokens to generate in the response. 1 token is roughly 4 characters.', 'chatbot-plugin') . '</p>';
    }
    
    /**
     * Render Temperature Field
     */
    public function render_temperature_field() {
        $temperature = get_option('chatbot_openai_temperature', 0.7);
        
        echo '<input type="range" name="chatbot_openai_temperature" id="chatbot_openai_temperature" min="0" max="2" step="0.1" value="' . esc_attr($temperature) . '" oninput="document.getElementById(\'temperature_value\').textContent = this.value" />';
        echo ' <span id="temperature_value">' . esc_html($temperature) . '</span>';
        echo '<p class="description">' . __('Controls randomness: Lower values (0-0.5) create focused, deterministic responses. Higher values (0.7-1.0) create more diverse, creative responses.', 'chatbot-plugin') . '</p>';
    }
    
    /**
     * Render System Prompt Field
     */
    public function render_system_prompt_field() {
        $system_prompt = get_option('chatbot_openai_system_prompt', $this->get_default_system_prompt());
        
        echo '<textarea name="chatbot_openai_system_prompt" id="chatbot_openai_system_prompt" class="large-text code" rows="10">' . esc_textarea($system_prompt) . '</textarea>';
        echo '<p class="description">' . __('The system prompt defines the behavior and personality of the AI chatbot. This initial instruction sets the tone and guides how the AI responds to user messages.', 'chatbot-plugin') . '</p>';
        echo '<p><button type="button" id="reset-system-prompt" class="button button-secondary">' . __('Reset to Default', 'chatbot-plugin') . '</button></p>';
        
        // Add JavaScript for the reset button
        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                $("#reset-system-prompt").on("click", function() {
                    var defaultPrompt = `' . esc_js($this->get_default_system_prompt()) . '`;
                    $("#chatbot_openai_system_prompt").val(defaultPrompt);
                });
            });
        </script>';
    }
    
    /**
     * Sanitize temperature value
     * 
     * @param float $input The temperature value
     * @return float Sanitized temperature between 0 and 2
     */
    public function sanitize_temperature($input) {
        $value = floatval($input);
        return min(max($value, 0), 2);
    }
    
    /**
     * Generate a response using OpenAI API based on conversation history
     * 
     * @param int $conversation_id The conversation ID
     * @param string $latest_message The latest user message
     * @return string The generated response
     */
    public function generate_response($conversation_id, $latest_message = '') {
        // If no API key, return a default response
        if (empty($this->api_key)) {
            return $this->get_default_response($latest_message);
        }
        
        try {
            // Get conversation history to provide context
            $messages = $this->get_conversation_history($conversation_id);
            
            // Prepare API request
            $api_url = 'https://api.openai.com/v1/chat/completions';
            
            // Validate the model
            $model = $this->validate_model($this->model);
            
            $request_body = array(
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => (int) $this->max_tokens,
                'temperature' => (float) $this->temperature,
            );
            
            $response = wp_remote_post($api_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode($request_body),
                'timeout' => 30,
                'data_format' => 'body',
            ));
            
            if (is_wp_error($response)) {
                error_log('OpenAI API Error: ' . $response->get_error_message());
                return $this->get_error_response();
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            
            if ($response_code !== 200) {
                error_log('OpenAI API Error: ' . json_encode($response_body));
                return $this->get_error_response();
            }
            
            if (isset($response_body['choices'][0]['message']['content'])) {
                return $response_body['choices'][0]['message']['content'];
            }
            
            return $this->get_error_response();
            
        } catch (Exception $e) {
            error_log('OpenAI API Exception: ' . $e->getMessage());
            return $this->get_error_response();
        }
    }
    
    /**
     * Get conversation history formatted for OpenAI API
     * 
     * @param int $conversation_id The conversation ID
     * @return array Messages formatted for OpenAI API
     */
    private function get_conversation_history($conversation_id) {
        $db = Chatbot_DB::get_instance();
        $raw_messages = $db->get_messages($conversation_id);
        
        // Start with system message - use custom system prompt from settings
        $formatted_messages = array(
            array(
                'role' => 'system',
                'content' => $this->system_prompt,
            ),
        );
        
        // Add recent messages (limit to last 10 to avoid token limit)
        $recent_messages = array_slice($raw_messages, -10);
        
        foreach ($recent_messages as $message) {
            $role = ($message->sender_type === 'user') ? 'user' : 'assistant';
            
            $formatted_messages[] = array(
                'role' => $role,
                'content' => $message->message,
            );
        }
        
        return $formatted_messages;
    }
    
    /**
     * Get the default system prompt that defines the chatbot's behavior
     * 
     * @return string The default system prompt
     */
    private function get_default_system_prompt() {
        $site_name = get_bloginfo('name');
        $site_description = get_bloginfo('description');
        
        return "You are a helpful customer service chatbot for the website {$site_name}. " . 
               "The website is described as: {$site_description}. " .
               "Be friendly, helpful, and concise in your responses. If you don't know the answer " .
               "to a question, politely say so and suggest contacting the site administrator for more information. " .
               "Keep responses under 3-4 sentences when possible.";
    }
    
    /**
     * Get a default response when API key is not set
     * 
     * @param string $message The user message
     * @return string A default response
     */
    private function get_default_response($message) {
        $message = strtolower($message);
        
        // Simple response system
        if (strpos($message, 'hello') !== false || strpos($message, 'hi') !== false) {
            return 'Hello! How can I help you today?';
        } elseif (strpos($message, 'help') !== false) {
            return 'I can help answer questions about our products, services, or website. What would you like to know?';
        } elseif (strpos($message, 'thank') !== false) {
            return 'You\'re welcome! Is there anything else I can help with?';
        } elseif (strpos($message, 'bye') !== false || strpos($message, 'goodbye') !== false) {
            return 'Goodbye! Have a great day!';
        } else {
            // Default responses
            $default_responses = array(
                'I\'m not sure I understand. Could you please rephrase that?',
                'Interesting question! Let me think about that.',
                'I don\'t have that information yet, but I\'m learning!',
                'Could you provide more details about your question?',
                'That\'s a good question. Let me find the answer for you.'
            );
            
            // Return a random default response
            return $default_responses[array_rand($default_responses)];
        }
    }
    
    /**
     * Get an error response when API call fails
     * 
     * @return string An error response
     */
    private function get_error_response() {
        $error_responses = array(
            'I apologize, but I\'m having trouble connecting right now. Please try again in a moment.',
            'I seem to be experiencing a technical issue. Could you please try again?',
            'I\'m sorry, but I couldn\'t process your request. Let\'s try again.',
            'There appears to be a temporary connection issue. Please try again shortly.'
        );
        
        return $error_responses[array_rand($error_responses)];
    }
    
    /**
     * Check if OpenAI integration is properly configured
     * 
     * @return bool Whether the integration is configured
     */
    public function is_configured() {
        return !empty($this->api_key);
    }
    
    /**
     * Test the OpenAI API connection
     */
    public function test_connection() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_test_openai_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'chatbot-plugin')));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'chatbot-plugin')));
        }
        
        // Check if API key is set
        if (empty($this->api_key)) {
            wp_send_json_error(array('message' => __('API key is not set. Please enter your OpenAI API key first.', 'chatbot-plugin')));
            return;
        }
        
        // Test the API connection with a simple request
        $api_url = 'https://api.openai.com/v1/chat/completions';
        
        $test_message = array(
            array(
                'role' => 'system',
                'content' => 'You are a helpful assistant that responds with only one word.',
            ),
            array(
                'role' => 'user',
                'content' => 'Say "CONNECTED" if you can receive this message.',
            ),
        );
        
        // Validate the model
        $model = $this->validate_model($this->model);
        
        $request_body = array(
            'model' => $model,
            'messages' => $test_message,
            'max_tokens' => (int) 10,
            'temperature' => 0.1,
        );
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($request_body),
            'timeout' => 30,
            'data_format' => 'body',
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => __('Error connecting to OpenAI API: ', 'chatbot-plugin') . $response->get_error_message()
            ));
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($response_body['error']['message']) 
                ? $response_body['error']['message'] 
                : __('Unknown error (HTTP status: ', 'chatbot-plugin') . $response_code . ')';
            
            wp_send_json_error(array(
                'message' => __('OpenAI API Error: ', 'chatbot-plugin') . $error_message
            ));
            return;
        }
        
        wp_send_json_success(array(
            'message' => __('Successfully connected to OpenAI API!', 'chatbot-plugin')
        ));
    }
}

// Initialize the OpenAI integration
function chatbot_openai_init() {
    return Chatbot_OpenAI::get_instance();
}
chatbot_openai_init();