<?php
/**
 * Chatbot AIPass Proxy
 * 
 * Handles proxy requests to AIPass API
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_AIPass_Proxy {
    
    private static $instance = null;
    private $api_base_url = 'http://localhost:8000/api.php';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Add AJAX endpoint for proxying AIPass API requests
        add_action('wp_ajax_chatbot_aipass_proxy', array($this, 'handle_proxy_request'));
    }
    
    /**
     * Handle proxy requests to AIPass API
     */
    public function handle_proxy_request() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_aipass_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Get AIPass action
        if (!isset($_POST['aipass_action'])) {
            wp_send_json_error(array('message' => 'No AIPass action specified'));
            return;
        }
        
        $action = sanitize_text_field($_POST['aipass_action']);
        
        // Get tokens
        $access_token = get_option('chatbot_aipass_access_token', '');
        $session_token = get_option('chatbot_aipass_session_token', '');
        
        // Route to appropriate handler
        switch ($action) {
            case 'exchange_token':
                $this->handle_token_exchange();
                break;
                
            case 'get_wallet':
                $this->handle_get_wallet($access_token);
                break;
                
            case 'create_session':
                $this->handle_create_session($access_token);
                break;
                
            case 'text_completion':
                $this->handle_text_completion($access_token);
                break;
                
            case 'chat_completion':
                $this->handle_chat_completion($access_token);
                break;
                
            case 'get_usage':
                $this->handle_get_usage($access_token);
                break;
                
            default:
                wp_send_json_error(array('message' => 'Invalid AIPass action'));
                break;
        }
        
        // End execution to prevent WordPress from adding extra output
        wp_die();
    }
    
    /**
     * Handle token exchange
     */
    private function handle_token_exchange() {
        // Get required parameters
        if (!isset($_POST['code'])) {
            wp_send_json_error(array('message' => 'No authorization code provided'));
            return;
        }
        
        $code = sanitize_text_field($_POST['code']);
        $redirect_uri = isset($_POST['redirect_uri']) ? esc_url_raw($_POST['redirect_uri']) : site_url('wp-json/chatbot-plugin/v1/aipass-callback');
        
        // Get AIPass instance to use client credentials
        $aipass = Chatbot_AIPass::get_instance();
        
        // Exchange code for token
        $result = $aipass->exchange_code_for_token($code);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'access_token' => $result['access_token'],
                'expires_in' => $result['expires_in']
            ));
        } else {
            wp_send_json_error(array('message' => $result['error']));
        }
    }
    
    /**
     * Handle get wallet request
     */
    private function handle_get_wallet($access_token) {
        if (empty($access_token)) {
            wp_send_json_error(array('message' => 'Not authenticated'));
            return;
        }
        
        $response = wp_remote_get($this->api_base_url . '/api/wallet', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token
            ),
            'timeout' => 30
        ));
        
        $this->handle_api_response($response, 'wallet');
    }
    
    /**
     * Handle create session request
     */
    private function handle_create_session($access_token) {
        if (empty($access_token)) {
            wp_send_json_error(array('message' => 'Not authenticated'));
            return;
        }
        
        $response = wp_remote_post($this->api_base_url . '/api/realtime/session', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => '{}', // Empty body for POST request
            'timeout' => 30
        ));
        
        $this->handle_api_response($response, 'session');
    }
    
    /**
     * Handle text completion request
     */
    private function handle_text_completion($access_token) {
        if (empty($access_token)) {
            wp_send_json_error(array('message' => 'Not authenticated'));
            return;
        }
        
        // Get request parameters
        $prompt = isset($_POST['prompt']) ? sanitize_textarea_field($_POST['prompt']) : '';
        $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gpt-3.5-turbo';
        $max_tokens = isset($_POST['maxTokens']) ? intval($_POST['maxTokens']) : 1000;
        $temperature = isset($_POST['temperature']) ? floatval($_POST['temperature']) : 0.7;
        
        if (empty($prompt)) {
            wp_send_json_error(array('message' => 'No prompt provided'));
            return;
        }
        
        $body = array(
            'prompt' => $prompt,
            'model' => $model,
            'maxTokens' => $max_tokens,
            'temperature' => $temperature
        );
        
        $response = wp_remote_post($this->api_base_url . '/api/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
            'timeout' => 60
        ));
        
        $this->handle_api_response($response, 'text_completion');
    }
    
    /**
     * Handle chat completion request
     */
    private function handle_chat_completion($access_token) {
        if (empty($access_token)) {
            wp_send_json_error(array('message' => 'Not authenticated'));
            return;
        }
        
        // Get request parameters
        $messages_json = isset($_POST['messages']) ? $_POST['messages'] : '';
        $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gpt-3.5-turbo';
        $max_tokens = isset($_POST['maxTokens']) ? intval($_POST['maxTokens']) : 1000;
        $temperature = isset($_POST['temperature']) ? floatval($_POST['temperature']) : 0.7;
        
        if (empty($messages_json)) {
            wp_send_json_error(array('message' => 'No messages provided'));
            return;
        }
        
        // Parse messages from JSON
        $messages = json_decode(stripslashes($messages_json), true);
        if (!is_array($messages)) {
            wp_send_json_error(array('message' => 'Invalid messages format'));
            return;
        }
        
        $body = array(
            'messages' => $messages,
            'model' => $model,
            'maxTokens' => $max_tokens,
            'temperature' => $temperature
        );
        
        $response = wp_remote_post($this->api_base_url . '/api/chat', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
            'timeout' => 60
        ));
        
        $this->handle_api_response($response, 'chat_completion');
    }
    
    /**
     * Handle get usage request
     */
    private function handle_get_usage($access_token) {
        if (empty($access_token)) {
            wp_send_json_error(array('message' => 'Not authenticated'));
            return;
        }
        
        $response = wp_remote_get($this->api_base_url . '/api/usage', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token
            ),
            'timeout' => 30
        ));
        
        $this->handle_api_response($response, 'usage');
    }
    
    /**
     * Handle API response
     * 
     * @param array|WP_Error $response The response from wp_remote_*
     * @param string $context The context of the request
     */
    private function handle_api_response($response, $context) {
        if (is_wp_error($response)) {
            chatbot_log('ERROR', 'aipass_proxy_' . $context, 'WP Error: ' . $response->get_error_message());
            wp_send_json_error(array('message' => $response->get_error_message()));
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($response_code !== 200) {
            // Handle error - could be string or array
            $error_message = 'Unknown error';
            if (isset($response_body['error'])) {
                if (is_array($response_body['error'])) {
                    $error_message = isset($response_body['error']['message'])
                        ? $response_body['error']['message']
                        : json_encode($response_body['error']);
                } else {
                    $error_message = $response_body['error'];
                }
            }
            chatbot_log('ERROR', 'aipass_proxy_' . $context, 'API Error: ' . $error_message);
            wp_send_json_error(array('message' => $error_message));
            return;
        }
        
        // Handle different response types based on context
        switch ($context) {
            case 'wallet':
                wp_send_json_success(array('wallet' => $response_body));
                break;
                
            case 'session':
                // Store session token in options
                if (isset($response_body['session_token'])) {
                    update_option('chatbot_aipass_session_token', $response_body['session_token']);
                }
                wp_send_json_success($response_body);
                break;
                
            case 'text_completion':
            case 'chat_completion':
                wp_send_json_success($response_body);
                break;
                
            case 'usage':
                wp_send_json_success(array('usage' => $response_body));
                break;
                
            default:
                wp_send_json_success($response_body);
                break;
        }
    }
}

// Initialize the AIPass proxy
function chatbot_aipass_proxy_init() {
    return Chatbot_AIPass_Proxy::get_instance();
}
chatbot_aipass_proxy_init();