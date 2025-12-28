<?php
/**
 * Chatbot AI Integration
 *
 * Thin wrapper that delegates all AI operations to AIPass.
 * This class maintains backward compatibility for callers while
 * simplifying the architecture to use only AIPass for AI services.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_AI {

    private static $instance = null;
    private $model = null;
    private $max_tokens = 1000;
    private $temperature = 0.7;
    private $aipass = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Load settings
        $this->refresh_settings();

        // Add settings fields and section
        add_action('admin_init', array($this, 'register_settings'));

        // Add AJAX handler for improving prompts with AI
        add_action('wp_ajax_chatbot_improve_prompt', array($this, 'improve_prompt'));

        // Add AJAX handler for debugging options
        add_action('wp_ajax_chatbot_debug_get_options', array($this, 'debug_get_options'));

        // Add AJAX handler for testing AI configuration
        add_action('wp_ajax_chatbot_test_ai', array($this, 'test_ai_configuration'));
    }

    /**
     * AJAX handler to test if AI (AIPass) is configured
     */
    public function test_ai_configuration() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_test_ai_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }

        // Check if AI is configured (via AIPass)
        if ($this->is_configured()) {
            wp_send_json_success(array('configured' => true));
        } else {
            wp_send_json_error(array('message' => 'AI is not configured. Please connect to AIPass.'));
        }
    }

    /**
     * Debug method to retrieve AI-related options
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

        // Get AI-related options for debugging
        $debug_data = array(
            'aipass_connected' => $this->is_configured(),
            'model' => get_option('chatbot_ai_model', CHATBOT_DEFAULT_MODEL),
            'max_tokens' => get_option('chatbot_ai_max_tokens', 1000),
            'temperature' => get_option('chatbot_ai_temperature', 0.7),
            'option_names' => array_filter(
                array_keys(wp_load_alloptions()),
                function($key) {
                    return strpos($key, 'chatbot_') === 0;
                }
            )
        );

        chatbot_log('DEBUG', 'debug_get_options', 'AI options retrieved', array(
            'aipass_connected' => $debug_data['aipass_connected'],
            'model' => $debug_data['model']
        ));

        wp_send_json_success($debug_data);
    }

    /**
     * Refresh all AI settings from the database
     */
    public function refresh_settings() {
        // Initialize AIPass
        if (class_exists('Chatbot_AIPass')) {
            $this->aipass = Chatbot_AIPass::get_instance();
            $this->aipass->refresh_configuration();
        }

        // Get model settings
        $this->model = get_option('chatbot_ai_model', CHATBOT_DEFAULT_MODEL);
        $this->max_tokens = get_option('chatbot_ai_max_tokens', 1000);
        $this->temperature = get_option('chatbot_ai_temperature', 0.7);

        // Log settings without calling is_configured() to avoid recursion
        $aipass_connected = ($this->aipass && $this->aipass->is_connected()) ? 'Yes' : 'No';
        chatbot_log('DEBUG', 'refresh_settings', 'AI settings refreshed', array(
            'aipass_connected' => $aipass_connected,
            'model' => $this->model,
            'max_tokens' => $this->max_tokens,
            'temperature' => $this->temperature
        ));
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Register settings for model selection
        register_setting('chatbot_ai_settings', 'chatbot_ai_model', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_model'),
            'default' => CHATBOT_DEFAULT_MODEL,
        ));
        register_setting('chatbot_ai_settings', 'chatbot_ai_max_tokens', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1000,
        ));
        register_setting('chatbot_ai_settings', 'chatbot_ai_temperature', array(
            'type' => 'number',
            'sanitize_callback' => array($this, 'sanitize_temperature'),
            'default' => 0.7,
        ));

        // Secondary group for backward compatibility
        register_setting('chatbot_settings', 'chatbot_ai_model', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_model'),
            'default' => CHATBOT_DEFAULT_MODEL,
        ));
        register_setting('chatbot_settings', 'chatbot_ai_max_tokens', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1000,
        ));
        register_setting('chatbot_settings', 'chatbot_ai_temperature', array(
            'type' => 'number',
            'sanitize_callback' => array($this, 'sanitize_temperature'),
            'default' => 0.7,
        ));

        // Add settings section for the AI Integration tab
        add_settings_section(
            'chatbot_ai_settings_section',
            __('AI Configuration', 'chatbot-plugin'),
            array($this, 'render_settings_section'),
            'chatbot_ai_settings'
        );

        // Add model field
        add_settings_field(
            'chatbot_ai_model',
            __('Model', 'chatbot-plugin'),
            array($this, 'render_model_field'),
            'chatbot_ai_settings',
            'chatbot_ai_settings_section'
        );
    }

    /**
     * Render Settings Section
     */
    public function render_settings_section() {
        $is_connected = $this->is_configured();

        echo '<div style="background: #f0f7ff; padding: 15px; border-left: 4px solid #2196F3; border-radius: 4px; margin-bottom: 20px;">';
        echo '<h3 style="margin-top: 0; color: #2196F3;">' . __('AIPass Integration', 'chatbot-plugin') . '</h3>';

        if ($is_connected) {
            echo '<p>' . __('You are connected to AIPass! Your chatbot has access to 161+ AI models including OpenAI GPT, O-series, and Google Gemini.', 'chatbot-plugin') . '</p>';
        } else {
            echo '<p>' . __('Connect with AIPass to power your chatbot with AI. AIPass provides access to 161+ AI models - no API key needed!', 'chatbot-plugin') . '</p>';
            echo '<p style="color: #d63638;"><strong>' . __('Please connect AIPass below to enable AI responses.', 'chatbot-plugin') . '</strong></p>';
        }

        echo '<p>' . sprintf(
            __('Learn more at %s', 'chatbot-plugin'),
            '<a href="https://aipass.one/" target="_blank" rel="noopener">aipass.one</a>'
        ) . '</p>';
        echo '<ul style="margin: 10px 0; padding-left: 20px;">';
        echo '<li>' . __('✓ 161+ AI models available', 'chatbot-plugin') . '</li>';
        echo '<li>' . __('✓ Includes Gemini models (faster & cheaper than GPT)', 'chatbot-plugin') . '</li>';
        echo '<li>' . __('✓ Simple usage-based pricing', 'chatbot-plugin') . '</li>';
        echo '</ul>';
        echo '</div>';
    }

    /**
     * Render Model Field
     */
    public function render_model_field() {
        $model = get_option('chatbot_ai_model', CHATBOT_DEFAULT_MODEL);
        $is_connected = $this->is_configured();

        if (!$is_connected) {
            echo '<p class="description" style="color: #d63638;">';
            echo __('Connect AIPass to select from 161+ AI models.', 'chatbot-plugin');
            echo '</p>';
            echo '<input type="hidden" name="chatbot_ai_model" value="' . esc_attr($model) . '" />';
            return;
        }

        // Get models from AIPass
        $models_result = $this->aipass->get_available_models();

        echo '<select name="chatbot_ai_model" id="chatbot_ai_model">';

        if ($models_result['success'] && !empty($models_result['models'])) {
            // Filter to only chat-compatible models
            $chat_models = array_filter($models_result['models'], function($model_id) {
                // Exclude non-chat models
                if (strpos($model_id, 'tts') !== false) return false;
                if (strpos($model_id, 'whisper') !== false) return false;
                if (strpos($model_id, 'flux') !== false) return false;
                if (strpos($model_id, 'imagen') !== false) return false;
                if (strpos($model_id, 'recraft') !== false) return false;
                if (strpos($model_id, 'seedream') !== false) return false;
                if (strpos($model_id, 'dreamina') !== false) return false;
                if (strpos($model_id, 'dall-e') !== false) return false;
                if (strpos($model_id, 'gpt-image') !== false) return false;
                if (strpos($model_id, '-image-preview') !== false) return false;
                if (strpos($model_id, 'sora') !== false) return false;
                if (strpos($model_id, 'veo') !== false) return false;
                return true;
            });

            // Group models by provider
            $grouped_models = array();
            foreach ($chat_models as $model_id) {
                if (strpos($model_id, '/') !== false) {
                    $parts = explode('/', $model_id);
                    $provider = ucfirst($parts[0]);
                } elseif (strpos($model_id, 'gpt-') === 0 || strpos($model_id, 'gpt') === 0) {
                    $provider = 'OpenAI';
                } elseif (strpos($model_id, 'claude') === 0) {
                    $provider = 'Anthropic';
                } else {
                    $provider = 'Other';
                }
                if (!isset($grouped_models[$provider])) {
                    $grouped_models[$provider] = array();
                }
                $grouped_models[$provider][] = $model_id;
            }

            // Put recommended model at top
            $default_model = CHATBOT_DEFAULT_MODEL;
            $has_default = in_array($default_model, $chat_models);

            if ($has_default) {
                echo '<option value="' . esc_attr($default_model) . '" ' . selected($model, $default_model, false) . '>' . esc_html($default_model) . ' (Recommended - Fast & Cheapest)</option>';
            }

            // Sort providers
            uksort($grouped_models, function($a, $b) {
                $priority = array('Gemini' => 0, 'OpenAI' => 1, 'Anthropic' => 2, 'Cerebras' => 3);
                $a_priority = isset($priority[$a]) ? $priority[$a] : 99;
                $b_priority = isset($priority[$b]) ? $priority[$b] : 99;
                if ($a_priority !== $b_priority) {
                    return $a_priority - $b_priority;
                }
                return strcmp($a, $b);
            });

            foreach ($grouped_models as $provider => $provider_models) {
                echo '<optgroup label="' . esc_attr($provider) . '">';
                foreach ($provider_models as $model_id) {
                    if ($model_id === $default_model) continue;
                    $display_name = $model_id;
                    if ($model_id === 'gemini/gemini-2.5-pro') {
                        $display_name .= ' (Most Capable)';
                    } elseif ($model_id === 'gemini/gemini-2.5-flash') {
                        $display_name .= ' (Fast)';
                    }
                    echo '<option value="' . esc_attr($model_id) . '" ' . selected($model, $model_id, false) . '>' . esc_html($display_name) . '</option>';
                }
                echo '</optgroup>';
            }
        } else {
            // Fallback if models couldn't be loaded
            echo '<option value="' . esc_attr(CHATBOT_DEFAULT_MODEL) . '" ' . selected($model, CHATBOT_DEFAULT_MODEL, false) . '>' . esc_html(CHATBOT_DEFAULT_MODEL) . ' (Recommended)</option>';
            echo '<option value="gemini/gemini-2.5-pro" ' . selected($model, 'gemini/gemini-2.5-pro', false) . '>gemini/gemini-2.5-pro</option>';
            echo '<option value="openai/gpt-4o-mini" ' . selected($model, 'openai/gpt-4o-mini', false) . '>openai/gpt-4o-mini</option>';
        }

        echo '</select>';
        echo '<p class="description">' . __('Select the AI model to use. Gemini 2.5 Flash Lite is recommended for speed and cost.', 'chatbot-plugin') . '</p>';
    }

    /**
     * Sanitize temperature value
     */
    public function sanitize_temperature($input) {
        $value = floatval($input);
        return min(max($value, 0), 2);
    }

    /**
     * Sanitize model value - preserves existing value if new value is empty
     */
    public function sanitize_model($input) {
        if (empty($input)) {
            $current_value = get_option('chatbot_ai_model', CHATBOT_DEFAULT_MODEL);
            return $current_value;
        }
        return sanitize_text_field($input);
    }

    /**
     * Generate a completion using AIPass without conversation context
     * Used by analytics and notifications for summaries
     *
     * @param string $system_prompt The system prompt
     * @param string $user_prompt The user prompt
     * @return string The generated completion
     */
    public function get_completion($system_prompt, $user_prompt) {
        $this->refresh_settings();

        if (!$this->is_configured()) {
            chatbot_log('ERROR', 'get_completion', 'AIPass not connected');
            return "Error: AIPass not connected. Please connect AIPass in settings.";
        }

        $messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => $user_prompt),
        );

        $token_limit = max((int) $this->max_tokens, 4000);

        $result = $this->aipass->generate_completion(
            $messages,
            $this->model,
            $token_limit,
            (float) $this->temperature
        );

        if ($result['success']) {
            return $result['content'];
        }

        chatbot_log('ERROR', 'get_completion', 'AIPass error: ' . $result['error']);
        return "Error: " . $result['error'];
    }

    /**
     * Generate a response using AIPass based on conversation history
     * Supports function calling via n8n gateway
     *
     * @param int $conversation_id The conversation ID
     * @param string $latest_message The latest user message
     * @param object|null $config Optional chatbot configuration
     * @return string The generated response
     */
    public function generate_response($conversation_id, $latest_message = '', $config = null) {
        $this->refresh_settings();

        // Check if n8n gateway is configured for function calling
        $n8n_gateway = null;
        $tools = null;

        if (class_exists('Chatbot_N8N_Gateway') && $config !== null) {
            $n8n_gateway = Chatbot_N8N_Gateway::get_instance();
            if ($n8n_gateway->is_configured_for_chatbot($config)) {
                $tools = $n8n_gateway->build_function_definitions_for_chatbot($config);
                chatbot_log('INFO', 'generate_response', 'n8n gateway configured with ' . count($tools) . ' actions');
            }
        }

        chatbot_log('INFO', 'generate_response', 'Generating AI response', array(
            'aipass_connected' => $this->is_configured() ? 'Yes' : 'No',
            'model' => $this->model,
            'conversation_id' => $conversation_id,
            'n8n_enabled' => !empty($tools) ? 'Yes' : 'No'
        ));

        // Check if AIPass is connected
        if (!$this->is_configured()) {
            chatbot_log('ERROR', 'generate_response', 'AIPass not connected. Using default response.');
            return $this->get_default_response($latest_message);
        }

        try {
            // Get conversation history with system prompt
            $messages = $this->get_conversation_history($conversation_id, $config);

            // Ensure the latest message is included
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

            // Use AIPass to generate completion
            $result = $this->aipass->generate_completion(
                $messages,
                $this->model,
                (int) $this->max_tokens,
                (float) $this->temperature,
                $tools
            );

            // Handle function calling loop
            $max_iterations = 10;
            $iteration = 0;

            while ($result['success'] && isset($result['tool_calls']) && !empty($result['tool_calls']) && $iteration < $max_iterations) {
                $iteration++;
                chatbot_log('INFO', 'generate_response', "Processing tool calls (iteration {$iteration})", array(
                    'tool_count' => count($result['tool_calls'])
                ));

                // Add assistant message with tool calls
                $messages[] = array(
                    'role' => 'assistant',
                    'content' => isset($result['content']) ? $result['content'] : null,
                    'tool_calls' => $result['tool_calls']
                );

                // Execute each tool call via n8n gateway
                foreach ($result['tool_calls'] as $tool_call) {
                    $function_name = $tool_call['function']['name'];
                    $function_args = json_decode($tool_call['function']['arguments'], true);
                    $tool_call_id = $tool_call['id'];

                    chatbot_log('INFO', 'generate_response', "Executing n8n action: {$function_name}");

                    // Execute via n8n
                    $action_result = $n8n_gateway->execute_action_for_chatbot($config, $function_name, $function_args, array(
                        'conversation_id' => $conversation_id
                    ));

                    $is_error = is_wp_error($action_result);
                    $result_content = $is_error
                        ? json_encode(array('error' => $action_result->get_error_message()))
                        : json_encode($action_result);

                    // Log function call for admin visibility
                    $this->log_function_call($conversation_id, $function_name, $function_args, $action_result, $is_error);

                    // Add tool result
                    $messages[] = array(
                        'role' => 'tool',
                        'tool_call_id' => $tool_call_id,
                        'content' => $result_content
                    );
                }

                // Call AI again with tool results
                $result = $this->aipass->generate_completion(
                    $messages,
                    $this->model,
                    (int) $this->max_tokens,
                    (float) $this->temperature,
                    $tools
                );
            }

            if ($result['success']) {
                // Trigger action for analytics
                do_action('chatbot_ai_request_complete', $this->model, [
                    'usage' => $result['usage'],
                    'model' => $this->model,
                    'via_aipass' => true
                ], $conversation_id);

                return $result['content'];
            } else {
                chatbot_log('ERROR', 'generate_response', 'AIPass Error: ' . $result['error']);

                // Check for budget exceeded error
                if (isset($result['error_type']) && $result['error_type'] === 'budget_exceeded') {
                    return "I'm sorry, but the AI service balance is too low. Please contact the site administrator.";
                }

                return $this->get_error_response();
            }

        } catch (Exception $e) {
            chatbot_log('ERROR', 'generate_response', 'Exception: ' . $e->getMessage());
            return $this->get_error_response();
        }
    }

    /**
     * Get conversation history formatted for AI API
     */
    private function get_conversation_history($conversation_id, $config = null) {
        $db = Chatbot_DB::get_instance();
        $raw_messages = $db->get_messages($conversation_id);

        // Get visitor name from conversation
        $visitor_name = '';
        $conversation = $db->get_conversation($conversation_id);
        if ($conversation && !empty($conversation->visitor_name)) {
            $visitor_name = $conversation->visitor_name;
        }

        // Determine system prompt
        $system_prompt = $this->get_default_system_prompt();

        if (isset($config)) {
            if (isset($config->knowledge) && isset($config->persona) && !empty($config->knowledge) && !empty($config->persona)) {
                $knowledge_sources = isset($config->knowledge_sources) ? $config->knowledge_sources : '';
                $system_prompt = $this->build_system_prompt($config->knowledge, $config->persona, $knowledge_sources, $visitor_name);
            } elseif (isset($config->system_prompt) && !empty($config->system_prompt)) {
                $system_prompt = $this->get_datetime_context() . "\n\n" . $config->system_prompt;
                // Add visitor name context
                if (!empty($visitor_name) && $visitor_name !== 'Visitor') {
                    $system_prompt .= $this->get_visitor_context($visitor_name);
                }
            }
        } else {
            // Try to find default config
            $default_config = $db->get_configuration_by_name('Default Configuration');
            if (!$default_config) {
                $default_config = $db->get_configuration_by_name('Default');
            }
            if (!$default_config) {
                $default_config = $db->get_configuration(1);
            }
            if ($default_config) {
                if (isset($default_config->knowledge) && isset($default_config->persona) &&
                    !empty($default_config->knowledge) && !empty($default_config->persona)) {
                    $knowledge_sources = isset($default_config->knowledge_sources) ? $default_config->knowledge_sources : '';
                    $system_prompt = $this->build_system_prompt($default_config->knowledge, $default_config->persona, $knowledge_sources, $visitor_name);
                } elseif (!empty($default_config->system_prompt)) {
                    $system_prompt = $this->get_datetime_context() . "\n\n" . $default_config->system_prompt;
                    // Add visitor name context
                    if (!empty($visitor_name) && $visitor_name !== 'Visitor') {
                        $system_prompt .= $this->get_visitor_context($visitor_name);
                    }
                }
            }
        }

        // Add visitor context to default system prompt if not already added
        if (!empty($visitor_name) && $visitor_name !== 'Visitor' && strpos($system_prompt, 'Current User Information') === false) {
            $system_prompt .= $this->get_visitor_context($visitor_name);
        }

        // Start with system message
        $formatted_messages = array(
            array('role' => 'system', 'content' => $system_prompt),
        );

        // Filter out function-type messages
        $filtered_messages = array_filter($raw_messages, function($msg) {
            return $msg->sender_type !== 'function';
        });

        // Get last 10 messages
        $recent_messages = array_slice(array_values($filtered_messages), -10);

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
     * Check if AI integration is configured (AIPass connected)
     */
    public function is_configured() {
        $this->refresh_settings();

        if ($this->aipass && $this->aipass->is_connected()) {
            return true;
        }

        return false;
    }

    /**
     * Check if we're using AIPass (always true now)
     */
    public function is_using_aipass() {
        return true;
    }

    /**
     * Get the default system prompt
     */
    private function get_default_system_prompt() {
        $site_name = get_bloginfo('name');
        $site_description = get_bloginfo('description');
        $context = $this->get_datetime_context();

        return $context . "\n\n" .
               "You are a helpful customer service chatbot for the website {$site_name}. " .
               "The website is described as: {$site_description}. " .
               "Be friendly, helpful, and concise in your responses. If you don't know the answer " .
               "to a question, politely say so and suggest contacting the site administrator for more information. " .
               "Keep responses under 3-4 sentences when possible.";
    }

    /**
     * Get current date/time context for the AI
     */
    private function get_datetime_context() {
        $timezone = wp_timezone();
        $now = new DateTime('now', $timezone);

        $current_date = $now->format('l, F j, Y');
        $current_time = $now->format('g:i A');
        $timezone_name = $timezone->getName();

        return "### CURRENT DATE & TIME ###\n" .
               "Today is: {$current_date}\n" .
               "Current time: {$current_time} ({$timezone_name})\n\n" .
               "### CRITICAL TOOL USAGE RULES ###\n" .
               "You have access to tools/functions that perform REAL actions (scheduling, booking, etc.).\n" .
               "1. You MUST use the appropriate tool to perform ANY action. NEVER claim to have done something without actually calling the tool.\n" .
               "2. NEVER say 'I have scheduled', 'I booked', 'Done', etc. unless you have ACTUALLY called the tool and received a success response.\n" .
               "3. If you need information to call a tool (name, email, date, etc.), ask the user FIRST, then call the tool.\n" .
               "4. After calling a tool successfully, report the ACTUAL result from the tool response - do not make up details.\n" .
               "5. If a tool call fails, tell the user honestly and offer alternatives.\n\n" .
               "### DATE/TIME HANDLING ###\n" .
               "1. When users mention relative dates like 'tomorrow', 'next week', 'in 3 days', 'next Monday', etc., " .
               "you MUST calculate the actual date based on today's date above.\n" .
               "2. When calling functions/tools:\n" .
               "   - DATE parameters should contain ONLY the date in DD/MM/YYYY format (e.g., '24/12/2025')\n" .
               "   - TIME parameters should contain ONLY the time in HH:MM format (e.g., '14:00')\n" .
               "   - NEVER mix date and time in a single parameter\n" .
               "3. Do NOT ask the user to provide a specific format - convert it yourself.\n\n" .
               "Example: If today is Monday, December 23, 2024 and user says 'schedule for tomorrow at 2pm', " .
               "use date='24/12/2024' and time='14:00' as SEPARATE parameters.";
    }

    /**
     * Build system prompt combining knowledge, persona, and WordPress content
     *
     * @param string $knowledge        The knowledge base content.
     * @param string $persona          The persona/role description.
     * @param string $knowledge_sources The WordPress knowledge sources.
     * @param string $visitor_name     The visitor's name (optional).
     * @return string The complete system prompt.
     */
    private function build_system_prompt($knowledge, $persona, $knowledge_sources = '', $visitor_name = '') {
        $system_prompt = $this->get_datetime_context() . "\n\n";
        $system_prompt .= $persona;

        // Add visitor context if available
        if (!empty($visitor_name) && $visitor_name !== 'Visitor') {
            $system_prompt .= $this->get_visitor_context($visitor_name);
        }

        if (!empty($knowledge)) {
            if (!empty($persona)) {
                $system_prompt .= "\n\n### KNOWLEDGE BASE ###\n\n";
            }
            $system_prompt .= $knowledge;
        }

        if (!empty($knowledge_sources)) {
            $db = Chatbot_DB::get_instance();
            $wp_knowledge = $db->get_knowledge_from_sources($knowledge_sources);

            if (!empty($wp_knowledge)) {
                $system_prompt .= "\n\n### IMPORTANT: ADDITIONAL ROLE ###\n\n";
                $system_prompt .= "In addition to your primary role described above, you MUST also answer questions based on the WordPress website content provided below. ";
                $system_prompt .= "When users ask about ANY topic covered in the WordPress content section, provide helpful and accurate answers based on that content. ";
                $system_prompt .= "Always cite the source URL when answering questions from WordPress content.\n";
                $system_prompt .= "\n### WORDPRESS CONTENT ###\n\n";
                $system_prompt .= $wp_knowledge;
            }
        }

        $system_prompt .= "\n\nWhen responding to user questions, always consult the knowledge base provided above to ensure accurate information.";

        if (!empty($knowledge_sources)) {
            $system_prompt .= "\n\n### CRITICAL REMINDER ###\n";
            $system_prompt .= "You MUST answer questions about topics mentioned in the WORDPRESS CONTENT section above, even if they are not related to your primary focus. ";
            $system_prompt .= "DO NOT say you don't have information if the answer exists in the WORDPRESS CONTENT section.";
        }

        return $system_prompt;
    }

    /**
     * Get visitor context to add to system prompt.
     *
     * @param string $visitor_name The visitor's name.
     * @return string The visitor context string.
     */
    private function get_visitor_context($visitor_name) {
        return "\n\n### CURRENT USER INFORMATION ###\n" .
               "The user you are chatting with has provided their name: " . $visitor_name . "\n" .
               "IMPORTANT: When the user needs to provide their name (e.g., for scheduling meetings, filling forms, or making bookings), " .
               "use the name \"" . $visitor_name . "\" - do NOT ask for their name again since they already provided it at the start of the conversation.";
    }

    /**
     * Log a function call to the conversation for admin visibility
     */
    private function log_function_call($conversation_id, $function_name, $args, $result, $is_error) {
        if (!$conversation_id) return;

        $db = Chatbot_DB::get_instance();

        $result_display = $is_error
            ? $result->get_error_message()
            : (is_array($result) ? json_encode($result, JSON_PRETTY_PRINT) : $result);

        if (strlen($result_display) > 500) {
            $result_display = substr($result_display, 0, 500) . '...';
        }

        $status_icon = $is_error ? '❌' : '✅';
        $status_text = $is_error ? 'FAILED' : 'SUCCESS';

        $message = "🔧 **Function Call: {$function_name}**\n";
        $message .= "Status: {$status_icon} {$status_text}\n";
        $message .= "Arguments: " . json_encode($args) . "\n";
        $message .= "Result: {$result_display}";

        $db->add_message($conversation_id, 'function', $message);
    }

    /**
     * Get a default response when AIPass is not connected
     */
    private function get_default_response($message) {
        $message = strtolower($message);

        if (strpos($message, 'hello') !== false || strpos($message, 'hi') !== false) {
            return 'Hello! How can I help you today?';
        } elseif (strpos($message, 'help') !== false) {
            return 'I can help answer questions about our products, services, or website. What would you like to know?';
        } elseif (strpos($message, 'thank') !== false) {
            return 'You\'re welcome! Is there anything else I can help with?';
        } elseif (strpos($message, 'bye') !== false || strpos($message, 'goodbye') !== false) {
            return 'Goodbye! Have a great day!';
        } else {
            $default_responses = array(
                'I\'m not sure I understand. Could you please rephrase that?',
                'Interesting question! Let me think about that.',
                'I don\'t have that information yet, but I\'m learning!',
                'Could you provide more details about your question?',
                'That\'s a good question. Let me find the answer for you.'
            );
            return $default_responses[array_rand($default_responses)];
        }
    }

    /**
     * Get an error response when API call fails
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
     * Improve a persona using AIPass
     */
    public function improve_prompt() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_improve_prompt_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'chatbot-plugin')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'chatbot-plugin')));
        }

        $this->refresh_settings();

        if (!$this->is_configured()) {
            wp_send_json_error(array('message' => __('Please connect AIPass to use this feature.', 'chatbot-plugin')));
            return;
        }

        $persona = isset($_POST['prompt']) ? sanitize_textarea_field($_POST['prompt']) : '';

        if (empty($persona)) {
            wp_send_json_error(array('message' => __('No persona provided.', 'chatbot-plugin')));
            return;
        }

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
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => "Please improve this chatbot persona description. Remember this only focuses on personality and tone, not knowledge:\n\n" . $persona),
        );

        $token_limit = max((int) $this->max_tokens, 1000);
        $result = $this->aipass->generate_completion($messages, $this->model, $token_limit, 0.5);

        if ($result['success'] && !empty($result['content'])) {
            $improved_prompt = $result['content'];

            if (empty(trim($improved_prompt))) {
                wp_send_json_error(array('message' => __('The API returned an empty response. Please try again.', 'chatbot-plugin')));
                return;
            }

            wp_send_json_success(array('improved_prompt' => $improved_prompt));
        } else {
            $error_msg = isset($result['error']) ? $result['error'] : 'Unknown error';
            wp_send_json_error(array('message' => __('Error: ', 'chatbot-plugin') . $error_msg));
        }
    }
}

// Initialize the AI integration
function chatbot_ai_init() {
    return Chatbot_AI::get_instance();
}
chatbot_ai_init();
