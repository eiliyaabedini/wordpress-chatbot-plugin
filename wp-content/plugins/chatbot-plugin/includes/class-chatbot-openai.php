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
        $this->refresh_settings();
        
        // Add settings fields and section
        add_action('admin_init', array($this, 'register_settings'));
        
        // We're not using this hook anymore since it expects different parameters
        // add_action('chatbot_settings_sections', array($this, 'add_settings_section'));
        
        // AJAX handler for testing saved OpenAI connection
        add_action('wp_ajax_chatbot_test_openai', array($this, 'test_connection'));
        
        // AJAX handler for testing a live API key before saving
        add_action('wp_ajax_chatbot_test_live_key', array($this, 'test_live_key'));
        
        // Add AJAX handler for improving prompts with AI
        add_action('wp_ajax_chatbot_improve_prompt', array($this, 'improve_prompt'));
        
        // Add AJAX handler for debugging options
        add_action('wp_ajax_chatbot_debug_get_options', array($this, 'debug_get_options'));
        
        // Add filter to refresh settings when saving them
        add_action('update_option_chatbot_openai_api_key', array($this, 'refresh_settings'));
    }
    
    /**
     * Debug method to retrieve OpenAI related options
     */
    public function debug_get_options() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_debug_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }
        
        // Get all OpenAI related options for debugging
        $debug_data = array(
            'api_key_exists' => !empty(get_option('chatbot_openai_api_key', '')),
            'model' => get_option('chatbot_openai_model', 'gpt-3.5-turbo'),
            'max_tokens' => get_option('chatbot_openai_max_tokens', 150),
            'temperature' => get_option('chatbot_openai_temperature', 0.7),
            'system_prompt_excerpt' => substr(get_option('chatbot_openai_system_prompt', ''), 0, 50) . '...',
            'option_names' => array_filter(
                array_keys(wp_load_alloptions()),
                function($key) {
                    return strpos($key, 'chatbot_') === 0;
                }
            )
        );
        
        // Log the debug data to the error log as well
        error_log('Chatbot Debug: OpenAI options: ' . print_r($debug_data, true));
        
        wp_send_json_success($debug_data);
    }
    
    /**
     * Refresh all OpenAI settings from the database
     */
    public function refresh_settings() {
        // Original settings retrieval with detailed logging
        $this->api_key = get_option('chatbot_openai_api_key', '');
        $this->model = get_option('chatbot_openai_model', 'gpt-3.5-turbo');
        $this->max_tokens = get_option('chatbot_openai_max_tokens', 150);
        $this->temperature = get_option('chatbot_openai_temperature', 0.7);
        
        error_log('Chatbot: Settings refreshed - API key exists: ' . (!empty($this->api_key) ? 'Yes' : 'No'));
        
        // Check for potential legacy settings in the wrong group and sync them
        // This helps ensure backward compatibility if settings were saved under the wrong group
        $this->sync_settings_between_groups();
    }
    
    /**
     * Sync settings between different option groups
     * This helps prevent issues when settings might have been registered 
     * under one group name but are being accessed from another
     */
    public function sync_settings_between_groups() {
        // Array of all OpenAI settings to check
        $settings = array(
            'chatbot_openai_api_key',
            'chatbot_openai_model',
            'chatbot_openai_max_tokens',
            'chatbot_openai_temperature'
        );
        
        // Log current state
        chatbot_log('INFO', 'sync_settings', 'Running settings sync between groups');
        
        foreach ($settings as $setting) {
            // Get the value from the option
            $openai_value = get_option($setting, '');
            $has_value = !empty($openai_value);
            
            if ($setting === 'chatbot_openai_api_key') {
                chatbot_log('DEBUG', 'sync_settings', "Checking setting '$setting'", 
                    "Value exists: " . ($has_value ? 'Yes' : 'No'));
            } else {
                chatbot_log('DEBUG', 'sync_settings', "Checking setting '$setting'", 
                    "Value: " . (is_string($openai_value) ? substr($openai_value, 0, 30) : $openai_value));
            }
            
            // If the setting exists and has a value, ensure it's set properly
            if ($has_value) {
                chatbot_log('INFO', 'sync_settings', "Found setting '$setting' with value");
                
                // Validate the setting value based on its type if needed
                $sanitized_value = $openai_value;
                if ($setting === 'chatbot_openai_max_tokens') {
                    $sanitized_value = absint($openai_value);
                } elseif ($setting === 'chatbot_openai_temperature') {
                    $sanitized_value = min(max(floatval($openai_value), 0), 2);
                }
                
                // Update the option without triggering hooks to avoid infinite loops
                update_option($setting, $sanitized_value, false);
                chatbot_log('INFO', 'sync_settings', "Updated setting '$setting'");
            }
        }
        
        chatbot_log('INFO', 'sync_settings', 'Settings sync complete');
    }
    
    /**
     * Validate the model and return a fallback if invalid
     * 
     * @param string $model The model to validate
     * @return string The validated model or fallback to gpt-3.5-turbo
     */
    private function validate_model($model) {
        $valid_models = array(
            'o4-mini',
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
        error_log('Chatbot: Registering OpenAI settings');
        
        // Register settings in both groups for backward compatibility
        // We'll register in both chatbot_settings and chatbot_openai_settings groups to 
        // ensure all settings work regardless of which group is used
        
        // Primary group - chatbot_openai_settings
        register_setting('chatbot_openai_settings', 'chatbot_openai_api_key');
        register_setting('chatbot_openai_settings', 'chatbot_openai_model');
        register_setting('chatbot_openai_settings', 'chatbot_openai_max_tokens', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 150,
        ));
        register_setting('chatbot_openai_settings', 'chatbot_openai_temperature', array(
            'type' => 'number',
            'sanitize_callback' => array($this, 'sanitize_temperature'),
            'default' => 0.7,
        ));
        
        // Secondary group - chatbot_settings (for backward compatibility)
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
        // Debug settings retrieval
        chatbot_log('DEBUG', 'render_api_key_field', 'Rendering API key field');
        $api_key = $this->api_key;
        chatbot_log('DEBUG', 'render_api_key_field', 'API key from class property: ' . (!empty($api_key) ? 'Has value' : 'Empty'));
        
        // Creating field with test button next to it
        echo '<div class="chatbot-api-key-container" style="display: flex; align-items: center; margin-bottom: 8px;">';
        echo '<input type="password" name="chatbot_openai_api_key" id="chatbot_openai_api_key" class="regular-text" value="' . esc_attr($api_key) . '" placeholder="sk-..." />';
        echo '<button type="button" id="toggle-api-key" class="button button-secondary" style="margin-left: 8px;">' . __('Show/Hide', 'chatbot-plugin') . '</button>';
        echo '<button type="button" id="test-live-api-key" class="button button-secondary" style="margin-left: 8px;">' . __('Test Connection', 'chatbot-plugin') . '</button>';
        echo '<span id="test-api-key-result" style="margin-left: 10px;"></span>';
        echo '</div>';
        
        echo '<p class="description">' . __('Your OpenAI API key. Keep this secure and never expose it to the public.', 'chatbot-plugin') . '</p>';
        
        if (!empty($api_key)) {
            echo '<p class="description">' . __('API key is set. For security, the key is not displayed.', 'chatbot-plugin') . '</p>';
        }
        
        // Add JavaScript for the test button and password visibility
        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                // Toggle password visibility
                $("#toggle-api-key").on("click", function() {
                    var apiKeyField = $("#chatbot_openai_api_key");
                    if (apiKeyField.attr("type") === "password") {
                        apiKeyField.attr("type", "text");
                    } else {
                        apiKeyField.attr("type", "password");
                    }
                });
                
                // Test the currently entered API key
                $("#test-live-api-key").on("click", function() {
                    var $button = $(this);
                    var $result = $("#test-api-key-result");
                    var currentApiKey = $("#chatbot_openai_api_key").val().trim();
                    
                    // Validate key format before testing
                    if (!currentApiKey || !currentApiKey.startsWith("sk-") || currentApiKey.length < 20) {
                        $result.html(\'<span style="color: red;">' . __('Invalid API key format. Should start with "sk-".', 'chatbot-plugin') . '</span>\');
                        return;
                    }
                    
                    $button.prop("disabled", true);
                    $result.html(\'<span style="color: blue;">' . __('Testing...', 'chatbot-plugin') . '</span>\');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "chatbot_test_live_key",
                            api_key: currentApiKey,
                            nonce: "' . wp_create_nonce('chatbot_test_live_key_nonce') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.html(\'<span style="color: green;">\' + response.data.message + \'</span>\');
                            } else {
                                $result.html(\'<span style="color: red;">\' + response.data.message + \'</span>\');
                            }
                        },
                        error: function() {
                            $result.html(\'<span style="color: red;">' . __('Connection error', 'chatbot-plugin') . '</span>\');
                        },
                        complete: function() {
                            $button.prop("disabled", false);
                        }
                    });
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
            // O4 models - newest and most powerful
            'o4-mini' => __('O4 Mini (Latest & Best Reasoning)', 'chatbot-plugin'),
            
            // GPT-4 models - powerful
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
        echo '<p class="description">' . __('Select the OpenAI model to use. GPT-3.5 Turbo is fastest and most economical, while O4 and GPT-4 models are more capable but cost more.', 'chatbot-plugin') . '</p>';
        echo '<p class="description">' . __('Recommended: O4 Mini for best reasoning capabilities or GPT-4o Mini for a good balance of performance and cost.', 'chatbot-plugin') . '</p>';
        echo '<p class="description"><strong>' . __('Note: O4 models do not support temperature adjustments and will always use the default temperature (1).', 'chatbot-plugin') . '</strong></p>';
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
    
    // Removed render_system_prompt_field method since we're now handling all prompts via the configurations interface
    
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
     * @param object|null $config Optional chatbot configuration with custom system prompt
     * @return string The generated response
     */
    public function generate_response($conversation_id, $latest_message = '', $config = null) {
        // Ensure we have the latest settings
        $this->refresh_settings();
        
        // If no API key, return a default response
        if (empty($this->api_key)) {
            error_log('OpenAI API key not configured. Using default response.');
            return $this->get_default_response($latest_message);
        }
        
        // Log model information for debugging
        error_log('Chatbot: Using model ' . $this->model . ' for conversation ' . $conversation_id);
        
        try {
            // Get conversation history to provide context, using custom system prompt if provided
            $messages = $this->get_conversation_history($conversation_id, $config);
            
            // Prepare API request
            $api_url = 'https://api.openai.com/v1/chat/completions';
            
            // Validate the model
            $model = $this->validate_model($this->model);
            
            // Create base request body
            $request_body = array(
                'model' => $model,
                'messages' => $messages,
            );
            
            // O4 models use different parameters
            if (strpos($model, 'o4') === 0) {
                $request_body['max_completion_tokens'] = (int) $this->max_tokens;
                // O4 models only support default temperature (1)
                // No need to specify temperature as it defaults to 1
            } else {
                $request_body['max_tokens'] = (int) $this->max_tokens;
                $request_body['temperature'] = (float) $this->temperature;
            }
            
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
     * @param object|null $config Optional chatbot configuration with custom system prompt
     * @return array Messages formatted for OpenAI API
     */
    private function get_conversation_history($conversation_id, $config = null) {
        $db = Chatbot_DB::get_instance();
        $raw_messages = $db->get_messages($conversation_id);
        
        // Determine which system prompt to use
        $system_prompt = $this->get_default_system_prompt(); // Fallback default
        
        // If a specific chatbot configuration is provided
        if (isset($config)) {
            // Check if we have knowledge and persona fields (new structure)
            if (isset($config->knowledge) && isset($config->persona) && !empty($config->knowledge) && !empty($config->persona)) {
                // Build a system prompt that combines knowledge and persona
                $system_prompt = $this->build_system_prompt($config->knowledge, $config->persona);
                chatbot_log('INFO', 'get_conversation_history', 'Using combined knowledge and persona for system prompt');
            }
            // Fall back to system_prompt if available (backward compatibility)
            elseif (isset($config->system_prompt) && !empty($config->system_prompt)) {
                $system_prompt = $config->system_prompt;
                chatbot_log('INFO', 'get_conversation_history', 'Using legacy system_prompt field');
            }
        }
        // If no config provided, try to find the default configuration
        else {
            $default_config = $db->get_configuration_by_name('Default Configuration');
            if ($default_config) {
                // Check for knowledge and persona fields first (new structure)
                if (isset($default_config->knowledge) && isset($default_config->persona) && 
                    !empty($default_config->knowledge) && !empty($default_config->persona)) {
                    $system_prompt = $this->build_system_prompt($default_config->knowledge, $default_config->persona);
                    chatbot_log('INFO', 'get_conversation_history', 'Using default config with knowledge and persona fields');
                }
                // Fall back to system_prompt if available (backward compatibility)
                elseif (!empty($default_config->system_prompt)) {
                    $system_prompt = $default_config->system_prompt;
                    chatbot_log('INFO', 'get_conversation_history', 'Using default config with legacy system_prompt field');
                }
            }
        }
        
        // Start with system message
        $formatted_messages = array(
            array(
                'role' => 'system',
                'content' => $system_prompt,
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
        
        chatbot_log('DEBUG', 'build_system_prompt', 'Built combined system prompt', array(
            'persona_length' => strlen($persona),
            'knowledge_length' => strlen($knowledge),
            'total_length' => strlen($system_prompt)
        ));
        
        return $system_prompt;
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
        // Always get the latest API key
        $this->api_key = get_option('chatbot_openai_api_key', '');
        return !empty($this->api_key);
    }
    
    /**
     * Test the OpenAI API connection
     */
    /**
     * Test connection using the saved API key
     */
    public function test_connection() {
        // Get the saved API key
        $this->refresh_settings();
        
        // Log model being used
        chatbot_log('INFO', 'test_connection', 'Testing with saved API key and model ' . $this->model);
        
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_test_openai_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'chatbot-plugin')));
            return;
        }
        
        // Check user capabilities - only skip this when called internally
        if (!isset($_POST['_internal_check']) && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'chatbot-plugin')));
            return;
        }
        
        // Check if API key is set
        if (empty($this->api_key)) {
            wp_send_json_error(array('message' => __('API key is not set. Please enter your OpenAI API key first.', 'chatbot-plugin')));
            return;
        }
        
        // If this is just a key validation check and not a full connection test
        if (isset($_POST['validate_key_only']) && $_POST['validate_key_only']) {
            // Just check if key exists and has valid format (sk-*)
            if (strpos($this->api_key, 'sk-') === 0 && strlen($this->api_key) > 20) {
                wp_send_json_success(array(
                    'message' => __('API key is set.', 'chatbot-plugin')
                ));
                return;
            } else {
                wp_send_json_error(array(
                    'message' => __('API key has invalid format. It should start with "sk-".', 'chatbot-plugin')
                ));
                return;
            }
        }
        
        // Perform the actual test using the saved API key
        $result = $this->test_api_connection($this->api_key, $this->model);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $result['message']
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }
    
    /**
     * Test connection using a provided API key before saving
     */
    public function test_live_key() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_test_live_key_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'chatbot-plugin')));
            return;
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'chatbot-plugin')));
            return;
        }
        
        // Get the API key from the form
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        
        // Check if API key is provided
        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('No API key provided.', 'chatbot-plugin')));
            return;
        }
        
        // Check if API key has correct format
        if (strpos($api_key, 'sk-') !== 0 || strlen($api_key) < 20) {
            wp_send_json_error(array('message' => __('Invalid API key format. Should start with "sk-".', 'chatbot-plugin')));
            return;
        }
        
        chatbot_log('INFO', 'test_live_key', 'Testing with unsaved API key');
        
        // Perform the actual test using the provided API key and current model
        $result = $this->test_api_connection($api_key, $this->model);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $result['message']
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }
    
    /**
     * Common method to test API connection with any key
     * 
     * @param string $api_key API key to test
     * @param string $model Model to use for testing
     * @return array Result with success status and message
     */
    private function test_api_connection($api_key, $model) {
        chatbot_log('INFO', 'test_api_connection', 'Testing API connection with model: ' . $model);
        
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
        $model = $this->validate_model($model);
        
        // Create base request body
        $request_body = array(
            'model' => $model,
            'messages' => $test_message,
        );
        
        // O4 models use different parameters
        if (strpos($model, 'o4') === 0) {
            $request_body['max_completion_tokens'] = 10; // Keep this small for quick test
            // O4 models only support default temperature (1)
            // No need to specify temperature as it defaults to 1
        } else {
            $request_body['max_tokens'] = 10; // Keep this small for quick test
            $request_body['temperature'] = 0.3; // Use low temperature for consistency
        }
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($request_body),
            'timeout' => 30,
            'data_format' => 'body',
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => __('Error connecting to OpenAI API: ', 'chatbot-plugin') . $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($response_body['error']['message']) 
                ? $response_body['error']['message'] 
                : __('Unknown error (HTTP status: ', 'chatbot-plugin') . $response_code . ')';
            
            return array(
                'success' => false,
                'message' => __('OpenAI API Error: ', 'chatbot-plugin') . $error_message
            );
        }
        
        // Success!
        return array(
            'success' => true,
            'message' => __('Successfully connected to OpenAI API!', 'chatbot-plugin')
        );
    }
    
    /**
     * Improve a persona using the OpenAI API
     */
    public function improve_prompt() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_improve_prompt_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'chatbot-plugin')));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'chatbot-plugin')));
        }
        
        // Ensure we have the latest settings
        $this->refresh_settings();
        
        // Log model being used
        error_log('Chatbot: Using model ' . $this->model . ' for improving persona');
        
        // Check if API key is set
        if (empty($this->api_key)) {
            wp_send_json_error(array('message' => __('API key is not set. Please configure your OpenAI API key in the OpenAI settings tab.', 'chatbot-plugin')));
            return;
        }
        
        // Get the persona to improve
        $persona = isset($_POST['prompt']) ? sanitize_textarea_field($_POST['prompt']) : '';
        
        if (empty($persona)) {
            wp_send_json_error(array('message' => __('No persona provided.', 'chatbot-plugin')));
            return;
        }
        
        // Generate an improved persona using the OpenAI API
        $api_url = 'https://api.openai.com/v1/chat/completions';
        
        $messages = array(
            array(
                'role' => 'system',
                'content' => 'You are a helpful AI assistant that specializes in improving and refining personality and tone instructions for AI chatbots. Your goal is to enhance the provided persona description to be more specific, comprehensive, and effective for guiding a chatbot\'s tone and communication style. The chatbot will have a separate knowledge base, so focus only on improving the personality, tone, and communication style aspects. Make the improved persona professional, clear, and well-structured. Focus on:
1. More specific details about the chatbot\'s personality traits
2. Clear guidance on tone and communication style
3. Specific instructions on how to handle different types of questions
4. Guidelines for empathetic and helpful customer service
5. Well-structured presentation with clear sections
6. Instructions to reference a knowledge base for factual information
Your output should be the complete improved persona only, without explanations or meta-commentary.',
            ),
            array(
                'role' => 'user',
                'content' => "Please improve this chatbot persona description. Remember this only focuses on personality and tone, not knowledge:\n\n" . $persona,
            ),
        );
        
        // Use GPT-4o for more reliable responses
        $improve_model = 'gpt-4o';
        
        // Use the configured tokens and temperature from settings
        // but use higher token limits for prompts since they can be longer
        $token_limit = max((int) $this->max_tokens, 1000); // Ensure enough tokens for comprehensive prompts
        
        // Create base request body
        $request_body = array(
            'model' => $improve_model,
            'messages' => $messages,
        );
        
        // O4 models use different parameters
        if (strpos($improve_model, 'o4') === 0) {
            $request_body['max_completion_tokens'] = $token_limit;
            // O4 models only support default temperature (1)
            // No need to specify temperature as it defaults to 1
        } else {
            $request_body['max_tokens'] = $token_limit;
            // Use a lower temperature to reduce randomness and get more consistent responses
            $request_body['temperature'] = 0.5;
        }
        
        // Force response not to be empty
        $request_body['stop'] = null; // Ensure no premature stopping
        // Add a system level request to ensure a response
        $messages[0]['content'] .= "\n\nIMPORTANT: You MUST provide a detailed response. Do not return an empty response under any circumstances.";
        
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
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($response_code !== 200) {
            $error_message = isset($response_body['error']['message']) 
                ? $response_body['error']['message'] 
                : __('Unknown error (HTTP status: ', 'chatbot-plugin') . $response_code . ')';
            
            wp_send_json_error(array(
                'message' => __('OpenAI API Error: ', 'chatbot-plugin') . $error_message
            ));
            return;
        }
        
        if (isset($response_body['choices'][0]['message']['content'])) {
            $improved_prompt = $response_body['choices'][0]['message']['content'];
            
            // Log the improved prompt for debugging
            error_log('Improved prompt success. Content length: ' . strlen($improved_prompt));
            error_log('First 100 chars: ' . substr($improved_prompt, 0, 100));
            
            // Handle empty responses
            if (empty(trim($improved_prompt))) {
                error_log('WARNING: OpenAI returned empty response content');
                wp_send_json_error(array(
                    'message' => __('The API returned an empty response. Please try again.', 'chatbot-plugin'),
                    'debug_info' => 'Empty content in valid response structure'
                ));
                return;
            }
            
            wp_send_json_success(array(
                'improved_prompt' => $improved_prompt
            ));
        } else {
            // Log the full API response for debugging
            error_log('Error in improve_prompt. Response body: ' . wp_json_encode($response_body));
            
            // Dump the full API request and response for debugging
            error_log('Full debug - Request: ' . json_encode($request_body));
            error_log('Full debug - Response: ' . json_encode($response_body));
            
            // Create detailed error message
            $error_message = __('Error generating improved prompt.', 'chatbot-plugin');
            
            // Add more specific details if available
            if (isset($response_body['error']) && is_array($response_body['error'])) {
                if (isset($response_body['error']['message'])) {
                    $error_message .= ' API error: ' . $response_body['error']['message'];
                }
                if (isset($response_body['error']['type'])) {
                    $error_message .= ' (Type: ' . $response_body['error']['type'] . ')';
                }
            }
            
            wp_send_json_error(array(
                'message' => $error_message,
                'debug_info' => wp_json_encode($response_body)
            ));
        }
    }
}

// Initialize the OpenAI integration
function chatbot_openai_init() {
    return Chatbot_OpenAI::get_instance();
}
chatbot_openai_init();