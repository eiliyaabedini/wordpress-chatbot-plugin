<?php
/**
 * Chatbot OpenAI Integration
 *
 * Handles integration with OpenAI API for the chatbot
 * Also supports AIPass integration as an alternative to direct API key
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_OpenAI {

    private static $instance = null;
    private $api_key = '';
    private $model = 'gpt-4.1-mini';
    private $max_tokens = 1000;
    private $temperature = 0.7;
    private $system_prompt = '';
    private $use_aipass = false;
    private $aipass = null;
    
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

        // Add filter to refresh settings when AIPass settings change
        add_action('update_option_chatbot_aipass_enabled', array($this, 'refresh_settings'));
        add_action('update_option_chatbot_aipass_access_token', array($this, 'refresh_settings'));
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
            'max_tokens' => get_option('chatbot_openai_max_tokens', 1000),
            'temperature' => get_option('chatbot_openai_temperature', 0.7),
            'system_prompt_excerpt' => substr(get_option('chatbot_openai_system_prompt', ''), 0, 50) . '...',
            'option_names' => array_filter(
                array_keys(wp_load_alloptions()),
                function($key) {
                    return strpos($key, 'chatbot_') === 0;
                }
            )
        );
        
        // Log sanitized debug data without exposing sensitive information
        $sanitized_debug_log = array(
            'api_key_exists' => $debug_data['api_key_exists'],
            'model' => $debug_data['model'],
            'option_count' => count($debug_data['option_names'])
        );
        error_log('Chatbot Debug: OpenAI options: ' . json_encode($sanitized_debug_log));
        
        wp_send_json_success($debug_data);
    }
    
    /**
     * Refresh all OpenAI settings from the database
     */
    public function refresh_settings() {
        // Check if AIPass is enabled and available
        $aipass_enabled = get_option('chatbot_aipass_enabled', true);
        $this->use_aipass = false;

        // Debug logging for AIPass detection
        chatbot_log('DEBUG', 'refresh_settings', 'Checking AIPass availability', array(
            'aipass_enabled_option' => $aipass_enabled ? 'Yes' : 'No',
            'aipass_class_exists' => class_exists('Chatbot_AIPass') ? 'Yes' : 'No'
        ));

        // Try to load AIPass if it's enabled
        if ($aipass_enabled && class_exists('Chatbot_AIPass')) {
            $this->aipass = Chatbot_AIPass::get_instance();

            // Force refresh AIPass configuration
            $this->aipass->refresh_configuration();

            // Check if AIPass is properly connected
            $is_connected = $this->aipass->is_connected();

            chatbot_log('DEBUG', 'refresh_settings', 'AIPass instance check', array(
                'is_connected' => $is_connected ? 'Yes' : 'No'
            ));

            if ($is_connected) {
                $this->use_aipass = true;
                chatbot_log('INFO', 'refresh_settings', 'Using AIPass for OpenAI API access');
            } else {
                chatbot_log('WARNING', 'refresh_settings', 'AIPass is enabled but not connected, falling back to API key');
            }
        }

        // Get API key directly from the database with more verbose logging
        $old_api_key = $this->api_key;
        $this->api_key = get_option('chatbot_openai_api_key', '');

        // Log detailed information about API key retrieval
        if (empty($this->api_key) && !$this->use_aipass) {
            chatbot_log('WARNING', 'refresh_settings', 'API key is empty or not set in the database and AIPass is not available');
        } else if (!$this->use_aipass) {
            $key_format_valid = (strpos($this->api_key, 'sk-') === 0);
            $key_length_valid = (strlen($this->api_key) >= 20); // Basic length validation

            chatbot_log('INFO', 'refresh_settings', 'API key retrieved from database', array(
                'api_key_length' => strlen($this->api_key),
                'api_key_format_valid' => $key_format_valid ? 'Yes' : 'No',
                'api_key_length_valid' => $key_length_valid ? 'Yes' : 'No',
                'api_key_changed' => ($old_api_key !== $this->api_key) ? 'Yes' : 'No'
            ));

            // Specifically check for format issues
            if (!$key_format_valid) {
                chatbot_log('ERROR', 'refresh_settings', 'API key format is invalid. Should start with "sk-"');
            }

            if (!$key_length_valid) {
                chatbot_log('ERROR', 'refresh_settings', 'API key length is too short');
            }
        }

        // Get other settings
        $this->model = get_option('chatbot_openai_model', 'gpt-4.1-mini');
        $this->max_tokens = get_option('chatbot_openai_max_tokens', 1000); // Increased default from 150 to 1000
        $this->temperature = get_option('chatbot_openai_temperature', 0.7);

        // Use ChatBot standard logging for overall settings
        chatbot_log('INFO', 'refresh_settings', 'OpenAI settings refreshed', array(
            'using_aipass' => $this->use_aipass ? 'Yes' : 'No',
            'api_key_exists' => !empty($this->api_key) ? 'Yes' : 'No',
            'model' => $this->model,
            'max_tokens' => $this->max_tokens,
            'temperature' => $this->temperature
        ));

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
        // If using AIPass, accept any model (AIPass has 161+ models including Gemini)
        if ($this->use_aipass) {
            chatbot_log('DEBUG', 'validate_model', 'Using AIPass, accepting model: ' . $model);
            return $model; // AIPass validates models on their end
        }

        // For direct OpenAI API, validate against known OpenAI models
        $valid_openai_models = array(
            'o4-mini',
            'o3-mini',
            'o1',
            'gpt-4.1-mini',
            'gpt-4o',
            'gpt-4o-mini',
            'gpt-4-turbo',
            'gpt-4',
            'gpt-3.5-turbo'
        );

        if (!in_array($model, $valid_openai_models)) {
            chatbot_log('WARN', 'validate_model', 'Invalid OpenAI model: ' . $model . ', using fallback');
            return 'gpt-4.1-mini'; // Default fallback for direct OpenAI API
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
        register_setting('chatbot_openai_settings', 'chatbot_openai_model', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_model'),
            'default' => 'gemini/gemini-2.5-flash-lite', // Default to cheapest model
        ));
        register_setting('chatbot_openai_settings', 'chatbot_openai_max_tokens', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1000, // Increased from 150 to 1000 for longer responses
        ));
        register_setting('chatbot_openai_settings', 'chatbot_openai_temperature', array(
            'type' => 'number',
            'sanitize_callback' => array($this, 'sanitize_temperature'),
            'default' => 0.7,
        ));

        // Secondary group - chatbot_settings (for backward compatibility)
        register_setting('chatbot_settings', 'chatbot_openai_api_key');
        register_setting('chatbot_settings', 'chatbot_openai_model', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_model'),
            'default' => 'gemini/gemini-2.5-flash-lite', // Default to cheapest model
        ));
        register_setting('chatbot_settings', 'chatbot_openai_max_tokens', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1000, // Increased from 150 to 1000 for longer responses
        ));
        register_setting('chatbot_settings', 'chatbot_openai_temperature', array(
            'type' => 'number',
            'sanitize_callback' => array($this, 'sanitize_temperature'),
            'default' => 0.7,
        ));
        
        // Add settings section for the AI Integration tab
        add_settings_section(
            'chatbot_openai_settings_section',
            __('AI Configuration', 'chatbot-plugin'),
            array($this, 'render_settings_section'),
            'chatbot_openai_settings' // This is the page we're using for the AI Integration tab
        );
        
        // Add settings fields to the AI Integration tab
        add_settings_field(
            'chatbot_openai_api_key',
            __('API Key', 'chatbot-plugin'),
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
        
        // Hidden for now - uses default values (1000 tokens, 0.7 temperature)
        // These advanced settings work behind the scenes but aren't exposed to users
        // Uncomment these if you want to let users customize max_tokens and temperature
        /*
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
        */
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
        // Check if AIPass is enabled (toggle checked)
        $aipass_enabled = false;
        $aipass_connected = false;

        if (class_exists('Chatbot_AIPass')) {
            $aipass = Chatbot_AIPass::get_instance();
            $aipass_enabled = get_option('chatbot_aipass_enabled', true);
            $aipass_connected = $aipass->is_connected();
        }

        // ONLY show AIPass box if toggle is enabled
        if ($aipass_enabled) {
            // AIPass mode description (shown when toggle is ON)
            echo '<div id="aipass-info-box" style="background: #f0f7ff; padding: 15px; border-left: 4px solid #2196F3; border-radius: 4px; margin-bottom: 20px;">';
            echo '<h3 style="margin-top: 0; color: #2196F3;">' . __('AIPass Integration', 'chatbot-plugin') . '</h3>';
            echo '<p>' . __('You are using AIPass to power your chatbot with AI. AIPass provides access to 161+ AI models including OpenAI GPT, O-series, and Google Gemini - no API key needed!', 'chatbot-plugin') . '</p>';
            echo '<p>' . sprintf(
                __('Learn more about AIPass at %s', 'chatbot-plugin'),
                '<a href="https://aipass.one/" target="_blank" rel="noopener">aipass.one</a>'
            ) . '</p>';
            echo '<ul style="margin: 10px 0; padding-left: 20px;">';
            echo '<li>' . __('✓ 161+ AI models available', 'chatbot-plugin') . '</li>';
            echo '<li>' . __('✓ No API key management required', 'chatbot-plugin') . '</li>';
            echo '<li>' . __('✓ Simple usage-based pricing', 'chatbot-plugin') . '</li>';
            echo '<li>' . __('✓ Includes Gemini models (faster & cheaper than GPT)', 'chatbot-plugin') . '</li>';
            echo '</ul>';
            echo '</div>';
        } else {
            // Direct OpenAI API mode description (shown when toggle is OFF)
            echo '<div id="openai-info-box" style="background: #f9f9f9; padding: 15px; border-left: 4px solid #10a37f; border-radius: 4px; margin-bottom: 20px;">';
            echo '<h3 style="margin-top: 0; color: #10a37f;">' . __('OpenAI API Integration', 'chatbot-plugin') . '</h3>';
            echo '<p>' . __('To use OpenAI integration, you need an API key from OpenAI. This allows direct access to OpenAI\'s GPT models.', 'chatbot-plugin') . '</p>';
            echo '<p>' . sprintf(
                __('Visit %s to create an account and get your API key.', 'chatbot-plugin'),
                '<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">OpenAI Platform</a>'
            ) . '</p>';

            // Suggest AIPass as alternative
            echo '<p style="background: #e7f3ff; padding: 10px; border-radius: 4px; margin-top: 10px;">';
            echo '<strong>💡 ' . __('Tip:', 'chatbot-plugin') . '</strong> ';
            echo sprintf(
                __('Want to try AI without an OpenAI API key? Enable %s below to access 161+ models including Gemini (faster & cheaper than GPT).', 'chatbot-plugin'),
                '<strong>AIPass Integration</strong>'
            );
            echo '</p>';
            echo '</div>';
        }
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
        // Check if AIPass is connected
        $aipass_connected = false;

        if (class_exists('Chatbot_AIPass')) {
            $aipass = Chatbot_AIPass::get_instance();
            $aipass_connected = $aipass->is_connected();
        }

        // AIPass mode: Show message about hardcoded models (NO selector)
        if ($aipass_connected) {
            echo '<div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #2196F3; border-radius: 4px;">';
            echo '<p style="margin: 0 0 10px 0;"><strong>' . __('Models are automatically optimized for AIPass:', 'chatbot-plugin') . '</strong></p>';
            echo '<ul style="margin: 5px 0; padding-left: 20px;">';
            echo '<li><strong>Chat Conversations:</strong> Gemini 2.5 Flash Lite (Ultra Fast & Cheapest)</li>';
            echo '<li><strong>AI Insights:</strong> Gemini 2.5 Pro (Most Capable for Analysis)</li>';
            echo '</ul>';
            echo '<p style="margin: 10px 0 0 0; color: #666; font-size: 13px;"><em>' . __('These models are hardcoded for optimal performance and cost.', 'chatbot-plugin') . '</em></p>';
            echo '</div>';
            return;
        }

        // Direct OpenAI API mode: Show model selector
        $model = get_option('chatbot_openai_model', 'gpt-4o-mini');

        // OpenAI models list
        $openai_models = array(
            // OpenAI O-series models
            'o4-mini' => __('O4 Mini (Latest & Best Reasoning)', 'chatbot-plugin'),
            'o3-mini' => __('O3 Mini (Reasoning)', 'chatbot-plugin'),
            'o1' => __('O1 (Advanced Reasoning)', 'chatbot-plugin'),

            // OpenAI GPT-4 models
            'gpt-4.1-mini' => __('GPT-4.1 Mini (Latest & Economic)', 'chatbot-plugin'),
            'gpt-4o' => __('GPT-4o (Latest & Most Capable)', 'chatbot-plugin'),
            'gpt-4o-mini' => __('GPT-4o Mini (Fast & Economic)', 'chatbot-plugin'),
            'gpt-4-turbo' => __('GPT-4 Turbo (Powerful)', 'chatbot-plugin'),
            'gpt-4' => __('GPT-4 (Powerful)', 'chatbot-plugin'),
            'gpt-3.5-turbo' => __('GPT-3.5 Turbo (Fast & Most Economical)', 'chatbot-plugin')
        );

        echo '<select name="chatbot_openai_model" id="chatbot_openai_model">';
        foreach ($openai_models as $model_id => $model_name) {
            echo '<option value="' . esc_attr($model_id) . '" ' . selected($model, $model_id, false) . '>' . esc_html($model_name) . '</option>';
        }
        echo '</select>';

        echo '<p class="description">' . __('Select the OpenAI model to use. GPT-3.5 Turbo is fastest and most economical, while O4 and GPT-4 models are more capable but cost more.', 'chatbot-plugin') . '</p>';
        echo '<p class="description" style="color: #999;"><em>' . __('💡 Connect AIPass to use optimized Gemini models (faster & cheaper than GPT)', 'chatbot-plugin') . '</em></p>';
        echo '<p class="description">' . __('Recommended: <strong>GPT-4o Mini</strong> for balanced performance or <strong>GPT-4.1 Mini</strong> for latest features.', 'chatbot-plugin') . '</p>';
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
     * Sanitize model value - preserves existing value if new value is empty
     * This prevents the model from being reset when saving settings from other tabs
     *
     * @param string $input The model value from form submission
     * @return string Sanitized model value
     */
    public function sanitize_model($input) {
        // If input is empty (happens when saving from other tabs), preserve current value
        if (empty($input)) {
            $current_value = get_option('chatbot_openai_model', 'gemini/gemini-2.5-flash-lite');
            chatbot_log('INFO', 'sanitize_model', 'Preserving current model value', array('model' => $current_value));
            return $current_value;
        }

        // Sanitize and return the new value
        $sanitized = sanitize_text_field($input);
        chatbot_log('INFO', 'sanitize_model', 'Model value updated', array('model' => $sanitized));
        return $sanitized;
    }

    /**
     * Generate a completion using OpenAI API without conversation context
     * 
     * @param string $system_prompt The system prompt
     * @param string $user_prompt The user prompt
     * @return string The generated completion
     */
    public function get_completion($system_prompt, $user_prompt) {
        // Ensure we have the latest settings
        $this->refresh_settings();
        
        // If no API key, return an error
        if (empty($this->api_key)) {
            chatbot_log('ERROR', 'get_completion', 'API key not configured');
            return "Error: OpenAI API key not configured.";
        }
        
        try {
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
            
            // Prepare API request
            $api_url = 'https://api.openai.com/v1/chat/completions';
            
            // Validate the model
            $model = $this->validate_model($this->model);
            
            // Create base request body
            $request_body = array(
                'model' => $model,
                'messages' => $messages,
            );
            
            // Increase token limit for analytics purposes
            $token_limit = max((int) $this->max_tokens, 4000);
            
            // O4 models use different parameters
            if (strpos($model, 'o4') === 0) {
                $request_body['max_completion_tokens'] = $token_limit;
                // O4 models only support default temperature (1)
            } else {
                $request_body['max_tokens'] = $token_limit;
                $request_body['temperature'] = (float) $this->temperature;
            }
            
            $response = wp_remote_post($api_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode($request_body),
                'timeout' => 60, // Increase timeout for longer analyses
                'data_format' => 'body',
            ));
            
            if (is_wp_error($response)) {
                chatbot_log('ERROR', 'get_completion', 'API Error: ' . $response->get_error_message(), array(
                    'model' => $model,
                    'error_code' => $response->get_error_code(),
                    'error_message' => $response->get_error_message()
                ));
                return "Error: Unable to connect to OpenAI API. Please check your API key and network connection.";
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            
            if ($response_code !== 200) {
                // Log API error without exposing the full response
            $sanitized_error = array(
                'status_code' => $response_code,
                'error_type' => isset($response_body['error']['type']) ? $response_body['error']['type'] : 'unknown',
                'error_code' => isset($response_body['error']['code']) ? $response_body['error']['code'] : 'unknown'
            );
            chatbot_log('ERROR', 'get_completion', 'API Error', $sanitized_error);
                return "Error: API returned status code " . $response_code;
            }
            
            if (isset($response_body['choices'][0]['message']['content'])) {
                return $response_body['choices'][0]['message']['content'];
            }
            
            return "Error: Unexpected API response format.";
            
        } catch (Exception $e) {
            chatbot_log('ERROR', 'get_completion', 'Exception: ' . $e->getMessage());
            return "Error: " . $e->getMessage();
        }
    }
    
    /**
     * Generate a response using OpenAI API or AIPass based on conversation history
     *
     * @param int $conversation_id The conversation ID
     * @param string $latest_message The latest user message
     * @param object|null $config Optional chatbot configuration with custom system prompt
     * @return string The generated response
     */
    public function generate_response($conversation_id, $latest_message = '', $config = null) {
        // Ensure we have the latest settings
        $this->refresh_settings();

        // Add detailed logging to help diagnose API issues
        chatbot_log('INFO', 'generate_response', 'Attempting to generate AI response', array(
            'using_aipass' => $this->use_aipass ? 'Yes' : 'No',
            'api_key_exists' => !empty($this->api_key) ? 'Yes' : 'No',
            'model' => $this->model,
            'conversation_id' => $conversation_id
        ));

        // If no API key and AIPass is not configured, return a default response
        if (empty($this->api_key) && !$this->use_aipass) {
            chatbot_log('ERROR', 'generate_response', 'OpenAI API key not configured and AIPass not available. Using default response.');
            return $this->get_default_response($latest_message);
        }

        // Log model information for debugging
        chatbot_log('INFO', 'generate_response', 'Using model ' . $this->model . ' for conversation ' . $conversation_id);

        try {
            // Get conversation history to provide context, using custom system prompt if provided
            $messages = $this->get_conversation_history($conversation_id, $config);

            // Ensure the latest message is included in the API call
            if (!empty($latest_message)) {
                $last_message = end($messages);
                $latest_trimmed = trim($latest_message);

                if (!$last_message || $last_message['role'] !== 'user' || trim($last_message['content']) !== $latest_trimmed) {
                    $messages[] = array(
                        'role' => 'user',
                        'content' => $latest_message
                    );
                }
            }

            // If using AIPass, use its API instead of direct OpenAI API
            if ($this->use_aipass && $this->aipass) {
                // Use Gemini 2.5 Flash Lite (fastest & cheapest)
                $model = 'gemini/gemini-2.5-flash-lite';

                // Use AIPass to generate completion
                $result = $this->aipass->generate_completion(
                    $messages,
                    $model,
                    (int) $this->max_tokens,
                    (float) $this->temperature
                );

                if ($result['success']) {
                    // Trigger action for analytics to track API usage
                    do_action('chatbot_openai_api_request_complete', $model, [
                        'usage' => $result['usage'],
                        'model' => $model,
                        'via_aipass' => true
                    ], $conversation_id);

                    return $result['content'];
                } else {
                    chatbot_log('ERROR', 'generate_response', 'AIPass API Error: ' . $result['error'], array(
                        'error_type' => isset($result['error_type']) ? $result['error_type'] : null
                    ));

                    // Check for budget exceeded error and return specific message
                    if (isset($result['error_type']) && $result['error_type'] === 'budget_exceeded') {
                        return "I'm sorry, but the AI service balance is too low to continue. Please contact the site administrator to add funds.";
                    }

                    return $this->get_error_response();
                }
            } else {
                // Use direct OpenAI API
                chatbot_log('INFO', 'generate_response', 'Using direct OpenAI API');

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
                    chatbot_log('ERROR', 'generate_response', 'OpenAI API Error: ' . $response->get_error_message());
                    return $this->get_error_response();
                }

                $response_code = wp_remote_retrieve_response_code($response);
                $response_body = json_decode(wp_remote_retrieve_body($response), true);

                if ($response_code !== 200) {
                    // Log sanitized error information
                    chatbot_log('ERROR', 'generate_response', 'OpenAI API Error: Status ' . $response_code .
                            (isset($response_body['error']['type']) ? ', Type: ' . $response_body['error']['type'] : '') .
                            (isset($response_body['error']['code']) ? ', Code: ' . $response_body['error']['code'] : ''));
                    return $this->get_error_response();
                }

                if (isset($response_body['choices'][0]['message']['content'])) {
                    // Trigger action for analytics to track API usage
                    do_action('chatbot_openai_api_request_complete', $model, $response_body, $conversation_id);

                    return $response_body['choices'][0]['message']['content'];
                }
            }

            return $this->get_error_response();

        } catch (Exception $e) {
            chatbot_log('ERROR', 'generate_response', 'Exception: ' . $e->getMessage());
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
                // Build a system prompt that combines knowledge, persona, and WordPress content
                $knowledge_sources = isset($config->knowledge_sources) ? $config->knowledge_sources : '';
                $system_prompt = $this->build_system_prompt($config->knowledge, $config->persona, $knowledge_sources);
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
            // Try common default names, then fall back to first configuration
            $default_config = $db->get_configuration_by_name('Default Configuration');
            if (!$default_config) {
                $default_config = $db->get_configuration_by_name('Default');
            }
            if (!$default_config) {
                // Fall back to first configuration in database
                $default_config = $db->get_configuration(1);
            }
            if ($default_config) {
                chatbot_log('INFO', 'get_conversation_history', 'Found default config', array(
                    'config_id' => $default_config->id,
                    'config_name' => $default_config->name,
                    'has_knowledge_sources' => !empty($default_config->knowledge_sources) ? 'yes' : 'no'
                ));
                // Check for knowledge and persona fields first (new structure)
                if (isset($default_config->knowledge) && isset($default_config->persona) &&
                    !empty($default_config->knowledge) && !empty($default_config->persona)) {
                    $knowledge_sources = isset($default_config->knowledge_sources) ? $default_config->knowledge_sources : '';
                    $system_prompt = $this->build_system_prompt($default_config->knowledge, $default_config->persona, $knowledge_sources);
                    chatbot_log('INFO', 'get_conversation_history', 'Using default config with knowledge and persona fields', array(
                        'knowledge_sources' => $knowledge_sources
                    ));
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
     * Check if OpenAI integration is configured
     *
     * @return bool True if API key is set or AIPass is configured, false otherwise
     */
    public function is_configured() {
        // Make sure we have the latest settings
        $this->refresh_settings();

        // Check if AIPass is enabled and connected
        if ($this->use_aipass && $this->aipass) {
            chatbot_log('DEBUG', 'is_configured', 'Using AIPass for OpenAI API access');
            return true;
        }

        // If not using AIPass, check API key
        $api_key_from_options = get_option('chatbot_openai_api_key', '');

        // Check both class property and options
        $key_in_property = !empty($this->api_key);
        $key_in_options = !empty($api_key_from_options);

        // Log detailed configuration status
        chatbot_log('DEBUG', 'is_configured', 'Checking if OpenAI is configured', array(
            'using_aipass' => $this->use_aipass ? 'Yes' : 'No',
            'api_key_in_property' => $key_in_property ? 'Yes' : 'No',
            'api_key_in_options' => $key_in_options ? 'Yes' : 'No',
            'api_key_length' => $key_in_options ? strlen($api_key_from_options) : 0,
            'api_key_format_valid' => ($key_in_options && strpos($api_key_from_options, 'sk-') === 0) ? 'Yes' : 'No'
        ));

        // If keys don't match, force a refresh
        if ($key_in_property !== $key_in_options) {
            chatbot_log('WARNING', 'is_configured', 'API key mismatch between property and options, forcing refresh');
            $this->api_key = $api_key_from_options;
        }

        return !empty($this->api_key);
    }

    /**
     * Check if we're currently using AIPass for API access
     *
     * @return bool True if using AIPass, false if using direct OpenAI API
     */
    public function is_using_aipass() {
        // Make sure we have the latest settings
        $this->refresh_settings();

        // Return whether we're using AIPass
        return $this->use_aipass;
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
     * Build a system prompt that combines knowledge, persona, and WordPress content
     *
     * @param string $knowledge Knowledge content
     * @param string $persona Persona content
     * @param string $knowledge_sources JSON string of WordPress post IDs to include as knowledge
     * @return string Combined system prompt
     */
    private function build_system_prompt($knowledge, $persona, $knowledge_sources = '') {
        // First, add the persona
        $system_prompt = $persona;

        // Add manual knowledge if provided
        if (!empty($knowledge)) {
            if (!empty($persona)) {
                $system_prompt .= "\n\n### KNOWLEDGE BASE ###\n\n";
            }
            $system_prompt .= $knowledge;
        }

        // Add WordPress content knowledge if provided
        if (!empty($knowledge_sources)) {
            $db = Chatbot_DB::get_instance();
            $wp_knowledge = $db->get_knowledge_from_sources($knowledge_sources);

            if (!empty($wp_knowledge)) {
                // Add automatic persona extension to ensure AI answers WordPress content questions
                $system_prompt .= "\n\n### IMPORTANT: ADDITIONAL ROLE ###\n\n";
                $system_prompt .= "In addition to your primary role described above, you MUST also answer questions based on the WordPress website content provided below. ";
                $system_prompt .= "When users ask about ANY topic covered in the WordPress content section, provide helpful and accurate answers based on that content. ";
                $system_prompt .= "This applies EVEN IF the topic is outside your primary focus area. ";
                $system_prompt .= "Always cite the source URL when answering questions from WordPress content so users can learn more.\n";

                $system_prompt .= "\n### WORDPRESS CONTENT ###\n\n";
                $system_prompt .= $wp_knowledge;

                chatbot_log('INFO', 'build_system_prompt', 'Added WordPress content to system prompt', array(
                    'wp_knowledge_length' => strlen($wp_knowledge)
                ));
            }
        }

        // Always add instruction to consult knowledge base when responding
        $system_prompt .= "\n\nWhen responding to user questions, always consult the knowledge base provided above to ensure accurate information.";

        // Add final reminder about WordPress content if it was included
        if (!empty($knowledge_sources)) {
            $system_prompt .= "\n\n### CRITICAL REMINDER ###\n";
            $system_prompt .= "You MUST answer questions about topics mentioned in the WORDPRESS CONTENT section above, even if they are not related to your primary focus. ";
            $system_prompt .= "For example, if a user asks about a company, product, or service mentioned in the WordPress content, provide the information from that content and include the source URL. ";
            $system_prompt .= "DO NOT say you don't have information if the answer exists in the WORDPRESS CONTENT section.";
        }

        chatbot_log('DEBUG', 'build_system_prompt', 'Built combined system prompt', array(
            'persona_length' => strlen($persona),
            'knowledge_length' => strlen($knowledge),
            'has_wp_content' => !empty($knowledge_sources) ? 'yes' : 'no',
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
    
    // is_configured() method was defined earlier in the file, so removed duplicate definition here
    
    /**
     * Test the OpenAI API connection using the saved API key
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
     * Improve a persona using AIPass or OpenAI API
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

        // Get the persona to improve
        $persona = isset($_POST['prompt']) ? sanitize_textarea_field($_POST['prompt']) : '';

        if (empty($persona)) {
            wp_send_json_error(array('message' => __('No persona provided.', 'chatbot-plugin')));
            return;
        }

        // Build the messages for improving persona
        $system_prompt = 'You are a helpful AI assistant that specializes in improving and refining personality and tone instructions for AI chatbots. Your goal is to enhance the provided persona description to be more specific, comprehensive, and effective for guiding a chatbot\'s tone and communication style. The chatbot will have a separate knowledge base, so focus only on improving the personality, tone, and communication style aspects. Make the improved persona professional, clear, and well-structured. Focus on:
1. More specific details about the chatbot\'s personality traits
2. Clear guidance on tone and communication style
3. Specific instructions on how to handle different types of questions
4. Guidelines for empathetic and helpful customer service
5. Well-structured presentation with clear sections
6. Instructions to reference a knowledge base for factual information
Your output should be the complete improved persona only, without explanations or meta-commentary.

IMPORTANT: You MUST provide a detailed response. Do not return an empty response under any circumstances.';

        $messages = array(
            array(
                'role' => 'system',
                'content' => $system_prompt,
            ),
            array(
                'role' => 'user',
                'content' => "Please improve this chatbot persona description. Remember this only focuses on personality and tone, not knowledge:\n\n" . $persona,
            ),
        );

        $token_limit = max((int) $this->max_tokens, 1000);

        // Check if AIPass is available and connected
        if ($this->use_aipass && class_exists('Chatbot_AIPass')) {
            $aipass = Chatbot_AIPass::get_instance();
            if ($aipass->is_connected()) {
                // Use configured model if it's in AIPass format (contains '/'), otherwise use default
                $aipass_model = $this->model;
                if (strpos($aipass_model, '/') === false) {
                    // Model is not in AIPass format, use a reliable default
                    $aipass_model = 'gemini/gemini-2.5-flash-lite';
                }

                chatbot_log('INFO', 'improve_prompt', 'Using AIPass for persona improvement', array(
                    'configured_model' => $this->model,
                    'aipass_model' => $aipass_model
                ));

                $result = $aipass->generate_completion($messages, $aipass_model, $token_limit, 0.5);

                if ($result['success'] && !empty($result['content'])) {
                    $improved_prompt = $result['content'];

                    if (empty(trim($improved_prompt))) {
                        wp_send_json_error(array(
                            'message' => __('The API returned an empty response. Please try again.', 'chatbot-plugin')
                        ));
                        return;
                    }

                    wp_send_json_success(array(
                        'improved_prompt' => $improved_prompt
                    ));
                    return;
                } else {
                    $error_msg = isset($result['error']) ? $result['error'] : 'Unknown error';
                    chatbot_log('ERROR', 'improve_prompt', 'AIPass error: ' . $error_msg);
                    wp_send_json_error(array(
                        'message' => __('AIPass Error: ', 'chatbot-plugin') . $error_msg
                    ));
                    return;
                }
            }
        }

        // Fall back to direct OpenAI API
        chatbot_log('INFO', 'improve_prompt', 'Using direct OpenAI API for persona improvement');

        // Check if API key is set
        if (empty($this->api_key)) {
            wp_send_json_error(array('message' => __('No AI service configured. Please connect AIPass or configure your OpenAI API key in the settings.', 'chatbot-plugin')));
            return;
        }

        // Generate an improved persona using the OpenAI API
        $api_url = 'https://api.openai.com/v1/chat/completions';
        $improve_model = 'gpt-4o';

        // Create base request body
        $request_body = array(
            'model' => $improve_model,
            'messages' => $messages,
            'max_tokens' => $token_limit,
            'temperature' => 0.5,
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

            // Handle empty responses
            if (empty(trim($improved_prompt))) {
                wp_send_json_error(array(
                    'message' => __('The API returned an empty response. Please try again.', 'chatbot-plugin')
                ));
                return;
            }

            wp_send_json_success(array(
                'improved_prompt' => $improved_prompt
            ));
        } else {
            // Log sanitized error information about the API response
            $error_summary = array(
                'status_code' => $response_code,
                'has_error_object' => isset($response_body['error']),
                'has_choices' => isset($response_body['choices'])
            );
            error_log('Error in improve_prompt. Response summary: ' . json_encode($error_summary));
            
            // Log sanitized debug info
            error_log('Improve prompt debug - API call failed with status code: ' . $response_code);
            error_log('Improve prompt debug - Error type: ' . (isset($response_body['error']['type']) ? $response_body['error']['type'] : 'unknown'));
            
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