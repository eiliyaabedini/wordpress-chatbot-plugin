<?php
/**
 * Chatbot n8n Gateway Integration
 *
 * Enables the chatbot to execute actions via n8n webhooks.
 * The AI can call configured actions, and n8n handles the integrations
 * (calendar, CRM, email, etc.)
 *
 * Configuration is per-chatbot, stored in the chatbot's n8n_settings field.
 *
 * @package Chatbot_Plugin
 * @since 1.4.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_N8N_Gateway {

    private static $instance = null;

    /**
     * Get singleton instance
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
        // Add AJAX handlers
        add_action('wp_ajax_chatbot_n8n_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_chatbot_n8n_test_action', array($this, 'ajax_test_action'));
        add_action('wp_ajax_chatbot_n8n_save_settings', array($this, 'ajax_save_settings'));
    }

    /**
     * Parse n8n settings from chatbot configuration
     *
     * @param object|array $config The chatbot configuration object
     * @return array Parsed n8n settings
     */
    public function parse_config_settings($config) {
        $n8n_settings_json = '';

        if (is_object($config) && isset($config->n8n_settings)) {
            $n8n_settings_json = $config->n8n_settings;
        } elseif (is_array($config) && isset($config['n8n_settings'])) {
            $n8n_settings_json = $config['n8n_settings'];
        }

        if (empty($n8n_settings_json)) {
            return array(
                'enabled' => false,
                'webhook_url' => '',
                'webhook_secret' => '',
                'timeout' => 300,
                'actions' => array()
            );
        }

        $settings = json_decode($n8n_settings_json, true);
        if (!is_array($settings)) {
            return array(
                'enabled' => false,
                'webhook_url' => '',
                'webhook_secret' => '',
                'timeout' => 300,
                'actions' => array()
            );
        }

        return array(
            'enabled' => isset($settings['enabled']) ? (bool) $settings['enabled'] : false,
            'webhook_url' => isset($settings['webhook_url']) ? $settings['webhook_url'] : '',
            'webhook_secret' => isset($settings['webhook_secret']) ? $settings['webhook_secret'] : '',
            'timeout' => isset($settings['timeout']) ? (int) $settings['timeout'] : 300,
            'headers' => isset($settings['headers']) && is_array($settings['headers']) ? $settings['headers'] : array(),
            'actions' => isset($settings['actions']) && is_array($settings['actions']) ? $settings['actions'] : array()
        );
    }

    /**
     * Check if gateway is configured and enabled for a chatbot
     *
     * @param object|array $config The chatbot configuration
     * @return bool Whether n8n is configured for this chatbot
     */
    public function is_configured_for_chatbot($config) {
        $settings = $this->parse_config_settings($config);
        return $settings['enabled'] && !empty($settings['webhook_url']) && !empty($settings['actions']);
    }

    /**
     * Build OpenAI-compatible function definitions from chatbot's configured actions
     *
     * @param object|array $config The chatbot configuration
     * @return array OpenAI function definitions
     */
    public function build_function_definitions_for_chatbot($config) {
        $settings = $this->parse_config_settings($config);
        $functions = array();

        foreach ($settings['actions'] as $action) {
            $properties = array();
            $required = array();

            if (isset($action['parameters']) && is_array($action['parameters'])) {
                foreach ($action['parameters'] as $param) {
                    $properties[$param['name']] = array(
                        'type' => $this->map_parameter_type($param['type']),
                        'description' => isset($param['description']) ? $param['description'] : '',
                    );

                    if (isset($param['required']) && $param['required']) {
                        $required[] = $param['name'];
                    }
                }
            }

            $functions[] = array(
                'type' => 'function',
                'function' => array(
                    'name' => $action['name'],
                    'description' => isset($action['description']) ? $action['description'] : '',
                    'parameters' => array(
                        'type' => 'object',
                        'properties' => $properties,
                        'required' => $required,
                    ),
                ),
            );
        }

        return $functions;
    }

    /**
     * Map parameter types to JSON Schema types
     */
    private function map_parameter_type($type) {
        $type_map = array(
            'string' => 'string',
            'number' => 'number',
            'integer' => 'integer',
            'boolean' => 'boolean',
            'array' => 'array',
            'object' => 'object',
        );

        return isset($type_map[$type]) ? $type_map[$type] : 'string';
    }

    /**
     * Execute an action via n8n webhook for a specific chatbot
     *
     * @param object|array $config The chatbot configuration
     * @param string $action_name The action to execute
     * @param array $params The parameters for the action
     * @param array $context Optional context (conversation_id, user_name, etc.)
     * @return array|WP_Error The result from n8n or error
     */
    public function execute_action_for_chatbot($config, $action_name, $params, $context = array()) {
        $settings = $this->parse_config_settings($config);

        if (!$settings['enabled'] || empty($settings['webhook_url'])) {
            error_log('Chatbot: ERROR - n8n gateway not configured for this chatbot');
            return new WP_Error('not_configured', __('n8n integration is not configured for this chatbot', 'chatbot-plugin'));
        }

        // Validate action exists
        $action_exists = false;
        foreach ($settings['actions'] as $action) {
            if ($action['name'] === $action_name) {
                $action_exists = true;
                break;
            }
        }

        if (!$action_exists) {
            error_log("Chatbot: ERROR - n8n action not found: {$action_name}");
            return new WP_Error('action_not_found', sprintf(__('Action "%s" is not configured', 'chatbot-plugin'), $action_name));
        }

        // Get chatbot name for context
        $chatbot_name = '';
        if (is_object($config) && isset($config->name)) {
            $chatbot_name = $config->name;
        } elseif (is_array($config) && isset($config['name'])) {
            $chatbot_name = $config['name'];
        }

        // Build request payload
        $payload = array(
            'action' => $action_name,
            'params' => $params,
            'context' => array_merge(array(
                'site_url' => get_site_url(),
                'timestamp' => current_time('c'),
                'chatbot_name' => $chatbot_name,
            ), $context),
        );

        error_log("Chatbot: INFO - Executing n8n action: {$action_name} for chatbot: {$chatbot_name}");

        // Build request headers
        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );

        // Add custom headers from settings
        if (!empty($settings['headers']) && is_array($settings['headers'])) {
            foreach ($settings['headers'] as $custom_header) {
                if (!empty($custom_header['name'])) {
                    $headers[$custom_header['name']] = isset($custom_header['value']) ? $custom_header['value'] : '';
                }
            }
        }

        // Add HMAC signature if secret is configured
        if (!empty($settings['webhook_secret'])) {
            $signature = hash_hmac('sha256', wp_json_encode($payload), $settings['webhook_secret']);
            $headers['X-Webhook-Signature'] = $signature;
        }

        // Make the request
        $response = wp_remote_post($settings['webhook_url'], array(
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'timeout' => $settings['timeout'],
            'data_format' => 'body',
        ));

        // Handle errors
        if (is_wp_error($response)) {
            error_log("Chatbot: ERROR - n8n request failed: " . $response->get_error_message());
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Check for HTTP errors
        if ($status_code < 200 || $status_code >= 300) {
            return new WP_Error(
                'http_error',
                sprintf(__('n8n returned error %d: %s', 'chatbot-plugin'), $status_code, $body),
                array('status_code' => $status_code, 'body' => $body)
            );
        }

        // Parse response
        $result = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // If not JSON, return raw body as result
            return array(
                'success' => true,
                'result' => $body,
            );
        }

        // Extract message from deeply nested n8n/AI response structures
        $extracted = $this->extract_message_from_response($result);
        if ($extracted !== null) {
            return array(
                'success' => true,
                'message' => $extracted,
                'raw_response' => $result
            );
        }

        return $result;
    }

    /**
     * Extract the actual message from deeply nested n8n response structures
     *
     * Handles common patterns like:
     * - [0].output[0].content[0].text.message (OpenAI Responses API via n8n)
     * - [0].message (simple format)
     * - {message: "..."} (direct format)
     * - {output: "..."} (simple output)
     *
     * @param mixed $response The parsed JSON response
     * @return string|null The extracted message or null if not found
     */
    private function extract_message_from_response($response) {
        // If response is a string, return it directly
        if (is_string($response)) {
            return $response;
        }

        // If it's not an array, we can't extract from it
        if (!is_array($response)) {
            return null;
        }

        // Pattern 1: Direct {message: "..."} format
        if (isset($response['message']) && is_string($response['message'])) {
            return $response['message'];
        }

        // Pattern 2: Direct {output: "..."} format
        if (isset($response['output']) && is_string($response['output'])) {
            return $response['output'];
        }

        // Pattern 3: Direct {result: "..."} format
        if (isset($response['result']) && is_string($response['result'])) {
            return $response['result'];
        }

        // Pattern 4: Array with first element - [0].output[0].content[0].text.message (OpenAI via n8n)
        if (isset($response[0])) {
            $first = $response[0];

            // Try [0].output[0].content[0].text.message
            if (isset($first['output'][0]['content'][0]['text']['message'])) {
                return $first['output'][0]['content'][0]['text']['message'];
            }

            // Try [0].output[0].content[0].text (if text is string)
            if (isset($first['output'][0]['content'][0]['text']) && is_string($first['output'][0]['content'][0]['text'])) {
                return $first['output'][0]['content'][0]['text'];
            }

            // Try [0].message
            if (isset($first['message']) && is_string($first['message'])) {
                return $first['message'];
            }

            // Try [0].output (if string)
            if (isset($first['output']) && is_string($first['output'])) {
                return $first['output'];
            }

            // Try [0].result (if string)
            if (isset($first['result']) && is_string($first['result'])) {
                return $first['result'];
            }
        }

        // Pattern 5: {output: [{content: [{text: {message: "..."}}]}]}
        if (isset($response['output'][0]['content'][0]['text']['message'])) {
            return $response['output'][0]['content'][0]['text']['message'];
        }

        // Could not extract a simple message
        return null;
    }

    /**
     * Test connection to n8n webhook
     *
     * @param string $webhook_url The webhook URL to test
     * @param string $webhook_secret Optional webhook secret
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public function test_connection($webhook_url, $webhook_secret = '', $custom_headers = array()) {
        if (empty($webhook_url)) {
            return new WP_Error('no_url', __('Webhook URL is not configured', 'chatbot-plugin'));
        }

        // Send a test ping
        $payload = array(
            'action' => '_test_connection',
            'params' => array(),
            'context' => array(
                'site_url' => get_site_url(),
                'timestamp' => current_time('c'),
                'test' => true,
            ),
        );

        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );

        // Add custom headers
        if (!empty($custom_headers) && is_array($custom_headers)) {
            foreach ($custom_headers as $custom_header) {
                if (!empty($custom_header['name'])) {
                    $headers[$custom_header['name']] = isset($custom_header['value']) ? $custom_header['value'] : '';
                }
            }
        }

        if (!empty($webhook_secret)) {
            $signature = hash_hmac('sha256', wp_json_encode($payload), $webhook_secret);
            $headers['X-Webhook-Signature'] = $signature;
        }

        $response = wp_remote_post($webhook_url, array(
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'timeout' => 10,
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code >= 200 && $status_code < 300) {
            return true;
        }

        return new WP_Error(
            'connection_failed',
            sprintf(__('n8n returned status %d', 'chatbot-plugin'), $status_code)
        );
    }

    /**
     * AJAX handler: Test connection
     */
    public function ajax_test_connection() {
        // Accept both nonce formats
        $nonce_valid = false;
        if (isset($_POST['nonce'])) {
            $nonce_valid = wp_verify_nonce($_POST['nonce'], 'chatbot_n8n_test') || wp_verify_nonce($_POST['nonce'], 'chatbot-admin-nonce');
        }

        if (!$nonce_valid) {
            wp_send_json_error(array('message' => __('Security check failed', 'chatbot-plugin')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'chatbot-plugin')));
        }

        $test_url = isset($_POST['webhook_url']) ? esc_url_raw($_POST['webhook_url']) : '';
        $test_secret = isset($_POST['webhook_secret']) ? sanitize_text_field($_POST['webhook_secret']) : '';

        // Parse custom headers from POST request
        $custom_headers = array();
        if (isset($_POST['headers'])) {
            $headers_raw = wp_unslash($_POST['headers']);
            $decoded_headers = json_decode($headers_raw, true);
            if (is_array($decoded_headers)) {
                foreach ($decoded_headers as $header) {
                    $header_name = isset($header['name']) ? sanitize_text_field($header['name']) : '';
                    $header_value = isset($header['value']) ? sanitize_text_field($header['value']) : '';
                    if (!empty($header_name)) {
                        $custom_headers[] = array('name' => $header_name, 'value' => $header_value);
                    }
                }
            }
        }

        if (empty($test_url)) {
            wp_send_json_error(array('message' => __('Webhook URL is required', 'chatbot-plugin')));
        }

        $result = $this->test_connection($test_url, $test_secret, $custom_headers);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => __('Connection successful!', 'chatbot-plugin')));
    }

    /**
     * AJAX handler: Test a specific action with sample data
     */
    public function ajax_test_action() {
        // Verify nonce
        $nonce_valid = false;
        if (isset($_POST['nonce'])) {
            $nonce_valid = wp_verify_nonce($_POST['nonce'], 'chatbot_n8n_test') || wp_verify_nonce($_POST['nonce'], 'chatbot-admin-nonce');
        }

        if (!$nonce_valid) {
            wp_send_json_error(array('message' => __('Security check failed', 'chatbot-plugin')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'chatbot-plugin')));
        }

        $webhook_url = isset($_POST['webhook_url']) ? esc_url_raw($_POST['webhook_url']) : '';
        $webhook_secret = isset($_POST['webhook_secret']) ? sanitize_text_field($_POST['webhook_secret']) : '';
        $action_name = isset($_POST['action_name']) ? sanitize_text_field($_POST['action_name']) : '';
        $action_params_raw = isset($_POST['action_params']) ? wp_unslash($_POST['action_params']) : '{}';

        if (empty($webhook_url)) {
            wp_send_json_error(array('message' => __('Webhook URL is required', 'chatbot-plugin')));
        }

        if (empty($action_name)) {
            wp_send_json_error(array('message' => __('Action name is required', 'chatbot-plugin')));
        }

        // Parse action params
        $action_params = json_decode($action_params_raw, true);
        if (!is_array($action_params)) {
            $action_params = array();
        }

        // Parse custom headers
        $custom_headers = array();
        if (isset($_POST['headers'])) {
            $headers_raw = wp_unslash($_POST['headers']);
            $decoded_headers = json_decode($headers_raw, true);
            if (is_array($decoded_headers)) {
                foreach ($decoded_headers as $header) {
                    $header_name = isset($header['name']) ? sanitize_text_field($header['name']) : '';
                    $header_value = isset($header['value']) ? sanitize_text_field($header['value']) : '';
                    if (!empty($header_name)) {
                        $custom_headers[] = array('name' => $header_name, 'value' => $header_value);
                    }
                }
            }
        }

        error_log("Chatbot: INFO - Testing n8n action: {$action_name}");

        // Build request payload
        $payload = array(
            'action' => $action_name,
            'params' => $action_params,
            'context' => array(
                'site_url' => get_site_url(),
                'timestamp' => current_time('c'),
                'test' => true,
            ),
        );

        // Build request headers
        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );

        // Add custom headers
        if (!empty($custom_headers)) {
            foreach ($custom_headers as $custom_header) {
                if (!empty($custom_header['name'])) {
                    $headers[$custom_header['name']] = isset($custom_header['value']) ? $custom_header['value'] : '';
                }
            }
        }

        // Add HMAC signature if secret is configured
        if (!empty($webhook_secret)) {
            $signature = hash_hmac('sha256', wp_json_encode($payload), $webhook_secret);
            $headers['X-Webhook-Signature'] = $signature;
        }

        // Make the request
        $response = wp_remote_post($webhook_url, array(
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'timeout' => 30,
            'data_format' => 'body',
        ));

        // Handle errors
        if (is_wp_error($response)) {
            error_log("Chatbot: ERROR - n8n test action failed: " . $response->get_error_message());
            wp_send_json_error(array('message' => $response->get_error_message()));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Check for HTTP errors
        if ($status_code < 200 || $status_code >= 300) {
            wp_send_json_error(array(
                'message' => sprintf(__('n8n returned error %d: %s', 'chatbot-plugin'), $status_code, $body),
            ));
        }

        // Parse response
        $result = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // If not JSON, return raw body
            $result = $body;
        }

        wp_send_json_success(array(
            'message' => __('Action executed successfully!', 'chatbot-plugin'),
            'result' => $result,
        ));
    }

    /**
     * AJAX handler: Save n8n settings for a chatbot
     */
    public function ajax_save_settings() {
        // Verify nonce
        $nonce_valid = false;
        if (isset($_POST['nonce'])) {
            $nonce_valid = wp_verify_nonce($_POST['nonce'], 'chatbot_n8n_save') || wp_verify_nonce($_POST['nonce'], 'chatbot-admin-nonce');
        }

        if (!$nonce_valid) {
            wp_send_json_error(array('message' => __('Security check failed', 'chatbot-plugin')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'chatbot-plugin')));
        }

        $chatbot_id = isset($_POST['chatbot_id']) ? intval($_POST['chatbot_id']) : 0;
        $n8n_settings_raw = isset($_POST['n8n_settings']) ? wp_unslash($_POST['n8n_settings']) : '';

        if (empty($chatbot_id)) {
            wp_send_json_error(array('message' => __('Chatbot ID is required', 'chatbot-plugin')));
        }

        // Parse and validate the settings
        $settings = json_decode($n8n_settings_raw, true);
        if (!is_array($settings)) {
            wp_send_json_error(array('message' => __('Invalid settings format', 'chatbot-plugin')));
        }

        // Sanitize settings
        $sanitized_settings = array(
            'enabled' => isset($settings['enabled']) ? (bool) $settings['enabled'] : false,
            'webhook_url' => isset($settings['webhook_url']) ? esc_url_raw($settings['webhook_url']) : '',
            'webhook_secret' => isset($settings['webhook_secret']) ? sanitize_text_field($settings['webhook_secret']) : '',
            'timeout' => isset($settings['timeout']) ? intval($settings['timeout']) : 120,
            'headers' => array(),
            'actions' => array(),
        );

        // Sanitize headers
        if (isset($settings['headers']) && is_array($settings['headers'])) {
            foreach ($settings['headers'] as $header) {
                $header_name = isset($header['name']) ? sanitize_text_field($header['name']) : '';
                $header_value = isset($header['value']) ? sanitize_text_field($header['value']) : '';
                if (!empty($header_name)) {
                    $sanitized_settings['headers'][] = array('name' => $header_name, 'value' => $header_value);
                }
            }
        }

        // Sanitize actions
        if (isset($settings['actions']) && is_array($settings['actions'])) {
            foreach ($settings['actions'] as $action) {
                $sanitized_action = array(
                    'name' => isset($action['name']) ? sanitize_text_field($action['name']) : '',
                    'description' => isset($action['description']) ? sanitize_textarea_field($action['description']) : '',
                    'parameters' => array(),
                );

                if (!empty($sanitized_action['name'])) {
                    if (isset($action['parameters']) && is_array($action['parameters'])) {
                        foreach ($action['parameters'] as $param) {
                            $sanitized_action['parameters'][] = array(
                                'name' => isset($param['name']) ? sanitize_text_field($param['name']) : '',
                                'type' => isset($param['type']) ? sanitize_text_field($param['type']) : 'string',
                                'description' => isset($param['description']) ? sanitize_text_field($param['description']) : '',
                                'required' => isset($param['required']) ? (bool) $param['required'] : false,
                            );
                        }
                    }
                    $sanitized_settings['actions'][] = $sanitized_action;
                }
            }
        }

        // Save to database
        global $wpdb;
        $table_name = $wpdb->prefix . 'chatbot_configurations';

        $result = $wpdb->update(
            $table_name,
            array('n8n_settings' => wp_json_encode($sanitized_settings)),
            array('id' => $chatbot_id),
            array('%s'),
            array('%d')
        );

        if ($result === false) {
            error_log("Chatbot: ERROR - Failed to save n8n settings for chatbot {$chatbot_id}: " . $wpdb->last_error);
            wp_send_json_error(array('message' => __('Failed to save settings', 'chatbot-plugin')));
        }

        error_log("Chatbot: INFO - Saved n8n settings for chatbot {$chatbot_id}");

        wp_send_json_success(array('message' => __('Webhook settings saved!', 'chatbot-plugin')));
    }
}
