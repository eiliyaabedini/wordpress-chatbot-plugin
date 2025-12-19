<?php
/**
 * Chatbot AIPass Integration
 *
 * Production implementation compatible with AIKey OAuth2 backend
 * Based on OAuth2 Authorization Code + PKCE flow (RFC 6749 + RFC 7636)
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Chatbot_AIPass {

    private static $instance = null;

    // HARDCODED CREDENTIALS - Set by plugin developer
    // These credentials are shared across all plugin installations
    private $base_url = 'https://aipass.one'; // AIPass server URL
    private $client_id = 'client_B44Woc2V6Jc_ywmlbIKLEA'; // OAuth2 Client ID
    private $client_secret = null; // Optional for public clients (PKCE is used)

    // User-specific tokens (stored per WordPress installation)
    private $access_token = '';
    private $refresh_token = '';
    private $token_expiry = 0;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Load configuration from options
        $this->refresh_configuration();

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Add AJAX handlers
        add_action('wp_ajax_chatbot_aipass_auth_status', array($this, 'get_auth_status'));
        add_action('wp_ajax_chatbot_aipass_authorize', array($this, 'initiate_authorization'));
        add_action('wp_ajax_chatbot_aipass_callback', array($this, 'handle_authorization_callback'));
        add_action('wp_ajax_chatbot_aipass_disconnect', array($this, 'disconnect'));
        add_action('wp_ajax_chatbot_aipass_get_balance', array($this, 'get_balance_info'));
        add_action('wp_ajax_chatbot_aipass_store_flow_state', array($this, 'store_flow_state'));
        add_action('wp_ajax_chatbot_aipass_get_models', array($this, 'ajax_get_models'));
        add_action('wp_ajax_chatbot_aipass_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_chatbot_aipass_sync_token', array($this, 'sync_token_from_sdk'));

        // TTS/STT AJAX handlers (for both logged-in and non-logged-in users)
        add_action('wp_ajax_chatbot_generate_speech', array($this, 'ajax_generate_speech'));
        add_action('wp_ajax_nopriv_chatbot_generate_speech', array($this, 'ajax_generate_speech'));
        add_action('wp_ajax_chatbot_transcribe_audio', array($this, 'ajax_transcribe_audio'));
        add_action('wp_ajax_nopriv_chatbot_transcribe_audio', array($this, 'ajax_transcribe_audio'));

        // Add callback route for OAuth flow
        add_action('rest_api_init', array($this, 'register_callback_endpoint'));

        // Add scripts for AIPass integration
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Refresh configuration from WordPress options
     * Note: base_url and client_id are hardcoded and not loaded from options
     */
    public function refresh_configuration() {
        // Load user-specific tokens only (NOT client credentials)
        $this->access_token = get_option('chatbot_aipass_access_token', '');
        $this->refresh_token = get_option('chatbot_aipass_refresh_token', '');
        $this->token_expiry = get_option('chatbot_aipass_token_expiry', 0);
    }

    /**
     * Register AIPass settings
     * Note: Only user tokens and enabled status are stored in WordPress options
     * Client credentials (base_url, client_id) are hardcoded in the plugin
     */
    public function register_settings() {
        // Register user-specific settings only (NOT client credentials)
        register_setting('chatbot_openai_settings', 'chatbot_aipass_access_token');
        register_setting('chatbot_openai_settings', 'chatbot_aipass_refresh_token');
        register_setting('chatbot_openai_settings', 'chatbot_aipass_token_expiry');
        register_setting('chatbot_openai_settings', 'chatbot_aipass_enabled', array(
            'type' => 'boolean',
            'default' => true, // AIPass is the default integration method
        ));

        // Add AIPass settings field to AI Integration tab
        add_settings_field(
            'chatbot_aipass_integration',
            __('AIPass Integration', 'chatbot-plugin'),
            array($this, 'render_aipass_field'),
            'chatbot_openai_settings',
            'chatbot_openai_settings_section'
        );
    }

    /**
     * Render AIPass integration field
     */
    public function render_aipass_field() {
        $aipass_enabled = get_option('chatbot_aipass_enabled', true);
        $is_connected = $this->is_connected();

        echo '<div class="chatbot-aipass-container">';

        // Hidden inputs to preserve token values when form is saved
        echo '<input type="hidden" name="chatbot_aipass_access_token" value="' . esc_attr($this->access_token) . '" />';
        echo '<input type="hidden" name="chatbot_aipass_refresh_token" value="' . esc_attr($this->refresh_token) . '" />';
        echo '<input type="hidden" name="chatbot_aipass_token_expiry" value="' . esc_attr($this->token_expiry) . '" />';

        // Create a toggle switch for enabling/disabling AIPass
        echo '<div class="chatbot-aipass-toggle">';
        echo '<label class="chatbot-toggle-switch">';
        echo '<input type="checkbox" name="chatbot_aipass_enabled" id="chatbot_aipass_enabled" value="1" ' . checked($aipass_enabled, true, false) . '>';
        echo '<span class="chatbot-toggle-slider"></span>';
        echo '</label>';
        echo '<span class="toggle-label">' . __('Enable AIPass Integration', 'chatbot-plugin') . '</span>';
        echo '</div>';

        // AIPass description
        echo '<p class="description">' . __('AIPass allows you to use AI services without providing your own API key. Simply connect your AIPass account to get started.', 'chatbot-plugin') . '</p>';

        // Connection status section (no configuration fields needed)
        echo '<div id="chatbot-aipass-connection" style="margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 5px; border: 1px solid #ddd;">';

        if ($is_connected) {
            // Show connected state with disconnect button
            echo '<div class="aipass-status connected">';

            // AIPass logo - clickable to dashboard
            echo '<a href="https://aipass.one/panel/dashboard.html" target="_blank" rel="noopener" class="aipass-logo-link" title="' . esc_attr__('Open AIPass Dashboard', 'chatbot-plugin') . '">';
            echo '<div class="aipass-logo">';
            echo '<div class="aipass-logo-icon">AI</div>';
            echo '<div class="aipass-logo-text">Pass</div>';
            echo '<div class="aipass-connected">CONNECTED</div>';
            echo '</div>';
            echo '</a>';

            // Add balance info placeholder - clickable to dashboard
            echo '<a href="https://aipass.one/panel/dashboard.html" target="_blank" rel="noopener" id="aipass-balance-info" class="aipass-balance-info" title="' . esc_attr__('View balance in AIPass Dashboard', 'chatbot-plugin') . '">';
            echo '<span class="balance-loading">' . __('Loading balance...', 'chatbot-plugin') . '</span>';
            echo '</a>';

            echo '<button type="button" id="chatbot-aipass-test" class="button button-secondary" style="margin-right: 8px;">' . __('Test Connection', 'chatbot-plugin') . '</button>';
            echo '<span id="aipass-test-result" style="margin-right: 8px;"></span>';
            echo '<button type="button" id="chatbot-aipass-disconnect" class="button button-secondary">' . __('Disconnect', 'chatbot-plugin') . '</button>';
            echo '</div>';
        } else {
            // Show connect button
            echo '<div class="aipass-status not-connected">';
            echo '<p>' . __('Connect with AIPass to use AI services without an API key.', 'chatbot-plugin') . '</p>';
            echo '<button type="button" id="chatbot-aipass-connect" class="button button-primary aipass-connect-button">';
            echo '<span class="aipass-logo">';
            echo '<span class="aipass-logo-icon">AI</span>';
            echo '<span class="aipass-logo-text">Pass</span>';
            echo '</span>';
            echo '<span class="aipass-connect-text">' . __('Connect with AIPass', 'chatbot-plugin') . '</span>';
            echo '</button>';
            echo '</div>';
        }

        echo '</div>'; // End of AIPass connection section

        // Add explainer for the relationship between AIPass and API Key
        echo '<div class="aipass-api-key-note" style="margin-top: 15px; padding: 12px; background: #e7f3ff; border-left: 4px solid #2196F3; border-radius: 4px;">';
        echo '<p style="margin: 0;"><strong>💡 ' . __('Tip:', 'chatbot-plugin') . '</strong> ';
        echo __('When AIPass is enabled, the API Key field will be hidden and AIPass will be used. Disable this toggle to use your own OpenAI API key instead.', 'chatbot-plugin');
        echo '</p>';
        echo '</div>';

        echo '</div>'; // End of AIPass container

        // Add inline styles
        $this->render_inline_styles();

        // Add inline JavaScript for toggle functionality
        // Note: Connect/Disconnect handlers removed - now in aipass-integration.js
        $this->render_inline_javascript();
    }

    /**
     * Render inline styles for AIPass UI
     */
    private function render_inline_styles() {
        ?>
        <style>
        .chatbot-toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
            vertical-align: middle;
        }
        .chatbot-toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .chatbot-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        .chatbot-toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .chatbot-toggle-slider {
            background-color: #2196F3;
        }
        input:checked + .chatbot-toggle-slider:before {
            transform: translateX(26px);
        }
        .toggle-label {
            margin-left: 10px;
            vertical-align: middle;
            font-weight: 500;
        }
        .aipass-logo-link {
            text-decoration: none;
            margin-right: 15px;
        }
        .aipass-logo-link:hover .aipass-logo {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .aipass-logo {
            display: flex;
            align-items: center;
            background: white;
            padding: 5px 10px;
            border-radius: 8px 0 8px 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: inline-flex;
            transition: box-shadow 0.2s;
            cursor: pointer;
        }
        .aipass-logo-icon {
            background: #8A4FFF;
            color: white;
            font-weight: bold;
            padding: 5px 7px;
            border-radius: 5px 0 5px 5px;
            margin-right: 5px;
        }
        .aipass-logo-text {
            color: #8A4FFF;
            font-weight: bold;
        }
        .aipass-connect-button {
            display: flex !important;
            align-items: center;
            padding: 5px 15px !important;
        }
        .aipass-connect-text {
            margin-left: 10px;
        }
        .aipass-status {
            display: flex;
            align-items: center;
        }
        .aipass-connected {
            margin-left: 10px;
            background: #4CAF50;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
        }
        .aipass-balance-info {
            margin: 0 15px;
            padding: 5px 10px;
            background: #f0f0f0;
            border-radius: 4px;
            flex-grow: 1;
            text-decoration: none;
            color: inherit;
            transition: background-color 0.2s;
            cursor: pointer;
            display: block;
        }
        .aipass-balance-info:hover {
            background: #e5e5e5;
        }
        .balance-summary {
            font-size: 14px;
        }
        .balance-amount {
            font-weight: bold;
            color: #8A4FFF;
        }
        .balance-loading, .balance-error {
            font-style: italic;
            color: #666;
            font-size: 13px;
        }
        .balance-error {
            color: #d32f2f;
        }
        .aipass-config-field {
            margin-bottom: 15px;
        }
        </style>
        <?php
    }

    /**
     * Render inline JavaScript for AIPass integration
     */
    private function render_inline_javascript() {
        $client_id = $this->get_client_id();
        $base_url = $this->get_base_url();
        $callback_url = site_url('wp-json/chatbot-plugin/v1/aipass-callback');
        $nonce = wp_create_nonce('chatbot_aipass_nonce');
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            console.log('AIPass settings page script loaded');

            // Note: SDK initialization and connect/disconnect handlers are now in aipass-integration.js
            // This inline script only handles toggle functionality and other settings page features

            // Load balance info if connected
            <?php if ($this->is_connected()): ?>
            if ($('#aipass-balance-info').length) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'chatbot_aipass_get_balance',
                        nonce: '<?php echo $nonce; ?>'
                    },
                    success: function(response) {
                        if (response.success && response.data.balance) {
                            var balance = response.data.balance;
                            var html = '<div class="balance-summary">';
                            html += '<span class="balance-label"><?php _e('Remaining Budget:', 'chatbot-plugin'); ?></span> ';
                            html += '<span class="balance-amount">$' + balance.remainingBudget.toFixed(2) + '</span>';
                            html += '<span style="color: #666; font-size: 12px; margin-left: 10px;">';
                            html += '(<?php _e('Used:', 'chatbot-plugin'); ?> $' + balance.totalCost.toFixed(2) + ' / <?php _e('Max:', 'chatbot-plugin'); ?> $' + balance.maxBudget.toFixed(2) + ')';
                            html += '</span>';
                            html += '</div>';
                            $('#aipass-balance-info').html(html);
                        } else {
                            $('#aipass-balance-info').html('<div class="balance-error"><?php _e('Could not load balance info', 'chatbot-plugin'); ?></div>');
                        }
                    },
                    error: function() {
                        $('#aipass-balance-info').html('<div class="balance-error"><?php _e('Connection error', 'chatbot-plugin'); ?></div>');
                    }
                });
            }
            <?php endif; ?>

            // Handle test connection button
            $(document).on('click', '#chatbot-aipass-test', function() {
                var $button = $(this);
                var $result = $('#aipass-test-result');

                $button.prop('disabled', true).text('<?php _e('Testing...', 'chatbot-plugin'); ?>');
                $result.html('<span style="color: blue;"><?php _e('Testing...', 'chatbot-plugin'); ?></span>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'chatbot_aipass_test_connection',
                        nonce: '<?php echo $nonce; ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            $result.html('<span style="color: green;">✓ ' + data.message + '</span>');
                            setTimeout(function() { $result.html(''); }, 5000);
                        } else {
                            $result.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                    },
                    error: function() {
                        $result.html('<span style="color: red;">✗ <?php _e('Connection error', 'chatbot-plugin'); ?></span>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php _e('Test Connection', 'chatbot-plugin'); ?>');
                    }
                });
            });

            // Toggle visibility between AIPass and API key sections
            function toggleAIPassMode() {
                const aipassEnabled = $('#chatbot_aipass_enabled').is(':checked');
                const $apiKeyRow = $('input[name="chatbot_openai_api_key"]').closest('tr');
                const $aipassConnectionSection = $('#chatbot-aipass-connection');
                const $aipassInfoBox = $('#aipass-info-box');
                const $openaiInfoBox = $('#openai-info-box');
                const $aipassDocCard = $('#aipass-doc-card');
                const $openaiDocCard = $('#openai-doc-card');

                console.log('AIPass toggle changed:', aipassEnabled);

                if (aipassEnabled) {
                    // AIPass mode: Hide API key & OpenAI sections, show AIPass sections
                    $apiKeyRow.hide();
                    $aipassConnectionSection.show();
                    $aipassInfoBox.show();
                    $openaiInfoBox.hide();
                    $aipassDocCard.show();
                    $openaiDocCard.hide();
                } else {
                    // Direct API mode: Show API key & OpenAI sections, hide AIPass sections
                    $apiKeyRow.show();
                    $aipassConnectionSection.hide();
                    $aipassInfoBox.hide();
                    $openaiInfoBox.show();
                    $aipassDocCard.hide();
                    $openaiDocCard.show();
                }
            }

            // Run on page load
            toggleAIPassMode();

            // Run when checkbox changes
            $('#chatbot_aipass_enabled').on('change', function() {
                toggleAIPassMode();
            });
        });
        </script>
        <?php
    }

    /**
     * Enqueue admin scripts for AIPass integration
     */
    public function enqueue_admin_scripts($hook) {
        // Load on all admin pages that contain 'chatbot'
        // This ensures the SDK is available when needed
        if (strpos($hook, 'chatbot') === false) {
            return;
        }

        // Prevent caching by adding query parameter version with timestamp
        $cache_buster = CHATBOT_PLUGIN_VERSION . '.' . time();

        // Enqueue official AIPass SDK from hosted source
        // This replaces our custom SDK implementation with the official one
        wp_enqueue_script(
            'chatbot-aipass-sdk-hosted',
            'https://aipass.one/aipass-sdk.js',
            array(),
            null, // No version for external script
            true  // Load in footer
        );

        // Enqueue our integration script
        wp_enqueue_script(
            'chatbot-aipass-integration',
            CHATBOT_PLUGIN_URL . 'assets/js/aipass-integration.js',
            array('jquery', 'chatbot-aipass-sdk-hosted'),
            $cache_buster,
            true
        );

        // Pass variables to the script
        wp_localize_script(
            'chatbot-aipass-integration',
            'chatbotAIPassVars',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('chatbot_aipass_nonce'),
                'pluginUrl' => CHATBOT_PLUGIN_URL,
                'callbackUrl' => site_url('wp-json/chatbot-plugin/v1/aipass-callback'),
                'isConnected' => $this->is_connected(),
                'clientId' => $this->get_client_id(),
                'baseUrl' => $this->get_base_url()
            )
        );
    }

    /**
     * Register REST API endpoint for AIPass callback
     */
    public function register_callback_endpoint() {
        register_rest_route('chatbot-plugin/v1', '/aipass-callback', array(
            'methods' => 'GET',
            'callback' => array($this, 'process_callback_popup'),
            'permission_callback' => '__return_true' // Public endpoint for OAuth flow
        ));
    }

    /**
     * Process AIPass OAuth callback for popup flow (hosted SDK)
     * This simply renders a page that communicates with the opener window
     * The hosted SDK handles the actual token exchange
     */
    public function process_callback_popup($request) {
        chatbot_log('INFO', 'process_callback_popup', 'AIPass OAuth callback received (popup mode)');

        $code = $request->get_param('code');
        $error = $request->get_param('error');
        $state = $request->get_param('state');

        chatbot_log('DEBUG', 'process_callback_popup', 'Callback params', array(
            'code_exists' => !empty($code),
            'error' => $error,
            'state_exists' => !empty($state)
        ));

        // Send proper headers for HTML content
        header('Content-Type: text/html; charset=UTF-8');

        // Output HTML directly and exit to bypass REST API encoding
        echo $this->get_callback_popup_html($code, $state, $error);
        exit;
    }

    /**
     * Process AIPass OAuth callback
     * This endpoint receives the authorization code from AIPass
     * and exchanges it for tokens on the server side
     */
    public function process_callback($request) {
        // Log the callback
        chatbot_log('INFO', 'process_callback', 'AIPass callback received');

        $code = $request->get_param('code');
        $error = $request->get_param('error');
        $state = $request->get_param('state');

        chatbot_log('DEBUG', 'process_callback', 'Callback params', array(
            'code_exists' => !empty($code),
            'error' => $error,
            'state_exists' => !empty($state)
        ));

        // Handle errors from AIPass
        if ($error) {
            chatbot_log('ERROR', 'process_callback', 'OAuth error: ' . $error);
            return $this->render_callback_page(false, 'Authorization failed: ' . $error);
        }

        // Validate we have a code
        if (empty($code)) {
            chatbot_log('ERROR', 'process_callback', 'No authorization code received');
            return $this->render_callback_page(false, 'No authorization code received');
        }

        // Validate state (CSRF protection)
        $stored_state = get_transient('chatbot_aipass_oauth_state');
        if (empty($state) || $state !== $stored_state) {
            chatbot_log('ERROR', 'process_callback', 'State mismatch - CSRF protection failed');
            return $this->render_callback_page(false, 'Invalid state parameter - CSRF protection failed');
        }

        // Get PKCE verifier
        $code_verifier = get_transient('chatbot_aipass_pkce_verifier');
        if (empty($code_verifier)) {
            chatbot_log('ERROR', 'process_callback', 'PKCE verifier not found');
            return $this->render_callback_page(false, 'PKCE code verifier not found');
        }

        // Exchange code for token on server side
        $token_result = $this->exchange_code_for_token($code, $code_verifier);

        // Clear transients
        delete_transient('chatbot_aipass_oauth_state');
        delete_transient('chatbot_aipass_pkce_verifier');

        if (!$token_result['success']) {
            chatbot_log('ERROR', 'process_callback', 'Token exchange failed: ' . $token_result['error']);
            return $this->render_callback_page(false, 'Connection failed: ' . $token_result['error']);
        }

        // Store tokens
        update_option('chatbot_aipass_access_token', $token_result['access_token']);
        update_option('chatbot_aipass_refresh_token', $token_result['refresh_token']);
        update_option('chatbot_aipass_token_expiry', time() + $token_result['expires_in']);
        update_option('chatbot_aipass_enabled', true);

        chatbot_log('INFO', 'process_callback', 'AIPass connected successfully');

        return $this->render_callback_page(true, '');
    }

    /**
     * Exchange authorization code for access token (server-side)
     */
    private function exchange_code_for_token($code, $code_verifier) {
        $request_body = array(
            'grantType' => 'authorization_code',
            'code' => $code,
            'redirectUri' => site_url('wp-json/chatbot-plugin/v1/aipass-callback'),
            'clientId' => $this->client_id,
            'codeVerifier' => $code_verifier
        );

        // Add client secret if available
        if (!empty($this->client_secret)) {
            $request_body['clientSecret'] = $this->client_secret;
        }

        chatbot_log('INFO', 'exchange_code_for_token', 'Exchanging authorization code for token');

        $response = wp_remote_post($this->base_url . '/oauth2/token', array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $response->get_error_message()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        chatbot_log('DEBUG', 'exchange_code_for_token', 'Token response', array(
            'status_code' => $response_code,
            'has_access_token' => isset($response_body['access_token'])
        ));

        if ($response_code !== 200) {
            $error_msg = isset($response_body['error_description']) ? $response_body['error_description'] : 'Unknown error';
            return array(
                'success' => false,
                'error' => $error_msg
            );
        }

        if (!isset($response_body['access_token'])) {
            return array(
                'success' => false,
                'error' => 'Invalid response format'
            );
        }

        // Log what we received from the API
        chatbot_log('INFO', 'exchange_authorization_code_for_token', 'API Response received', array(
            'has_access_token' => isset($response_body['access_token']),
            'has_refresh_token' => isset($response_body['refresh_token']),
            'has_expires_in' => isset($response_body['expires_in']),
            'expires_in_value' => isset($response_body['expires_in']) ? $response_body['expires_in'] : 'NOT PROVIDED',
            'response_keys' => array_keys($response_body)
        ));

        // Get expires_in from API response, fallback to 30 days (2592000 seconds) if not provided
        $expires_in = isset($response_body['expires_in']) ? intval($response_body['expires_in']) : 2592000;

        if (!isset($response_body['expires_in'])) {
            chatbot_log('WARN', 'exchange_authorization_code_for_token', 'API did not return expires_in, using 30-day fallback', array(
                'fallback_seconds' => 2592000,
                'fallback_days' => 30
            ));
        }

        return array(
            'success' => true,
            'access_token' => $response_body['access_token'],
            'refresh_token' => isset($response_body['refresh_token']) ? $response_body['refresh_token'] : '',
            'expires_in' => $expires_in
        );
    }

    /**
     * Get callback page HTML for popup flow (hosted SDK)
     * This page communicates the authorization code/error back to the opener window
     */
    private function get_callback_popup_html($code, $state, $error) {
        return '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>AIPass Authorization</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    text-align: center;
                    padding: 50px 20px;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .container {
                    max-width: 400px;
                }
                .spinner {
                    margin: 20px auto;
                    width: 50px;
                    height: 50px;
                    border: 3px solid rgba(255, 255, 255, 0.3);
                    border-top-color: white;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                }
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
                h2 { margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="spinner"></div>
                <h2>Connecting to AIPass...</h2>
                <p>Please wait while we complete the authorization.</p>
            </div>
            <script>
                console.log("AIPass callback page loaded");

                // Get URL parameters
                const urlParams = new URLSearchParams(window.location.search);
                const code = urlParams.get("code") || "' . esc_js($code) . '";
                const state = urlParams.get("state") || "' . esc_js($state) . '";
                const error = urlParams.get("error") || "' . esc_js($error) . '";

                console.log("Callback params:", {
                    code: code ? "present" : "missing",
                    state: state ? "present" : "missing",
                    error: error || "none"
                });

                // Try multiple methods to communicate with opener
                if (window.opener) {
                    console.log("Opener window found, sending message...");

                    // Prepare message in the format the SDK expects
                    const message = error ? {
                        type: "aipass_oauth_callback",
                        error: error,
                        error_description: error,
                        state: state
                    } : {
                        type: "aipass_oauth_callback",
                        code: code,
                        state: state
                    };

                    console.log("Message to send:", message);

                    // Method 1: postMessage (primary) - Send multiple times like the SDK does
                    const sendToOpener = function() {
                        try {
                            window.opener.postMessage(message, window.location.origin);
                            console.log("✓ postMessage sent to origin:", window.location.origin);
                        } catch (e) {
                            console.error("postMessage failed:", e);
                        }
                    };

                    // Send multiple times to ensure delivery (matches SDK behavior)
                    sendToOpener();
                    setTimeout(sendToOpener, 100);
                    setTimeout(sendToOpener, 300);
                    setTimeout(sendToOpener, 600);

                    // Method 2: Storage event (fallback)
                    try {
                        if (code && !error) {
                            localStorage.setItem("aipass_auth_event", JSON.stringify({
                                type: "oauth_success",
                                timestamp: Date.now()
                            }));
                            console.log("✓ localStorage event set");
                        }
                    } catch (e) {
                        console.error("localStorage failed:", e);
                    }

                    // Close popup after ensuring messages are sent
                    setTimeout(function() {
                        console.log("Closing popup...");
                        window.close();
                    }, 1500);
                } else {
                    console.error("No opener window found!");
                    document.querySelector(".container").innerHTML =
                        "<h2>⚠️ Error</h2><p>This window was not opened correctly. Please try again.</p>";
                }
            </script>
        </body>
        </html>';
    }

    /**
     * Render callback page HTML (legacy full-page redirect flow)
     */
    private function render_callback_page($success, $error_message) {
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>AIPass Authorization</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    text-align: center;
                    padding: 50px 20px;
                    max-width: 600px;
                    margin: 0 auto;
                    line-height: 1.6;
                }
                .result {
                    margin: 30px 0;
                    padding: 20px;
                    border-radius: 5px;
                }
                .success {
                    background-color: #e8f5e9;
                    border: 1px solid #a5d6a7;
                    color: #2e7d32;
                }
                .error {
                    background-color: #ffebee;
                    border: 1px solid #ef9a9a;
                    color: #c62828;
                }
                .button {
                    display: inline-block;
                    padding: 10px 20px;
                    background-color: #8A4FFF;
                    color: white;
                    text-decoration: none;
                    border-radius: 4px;
                    font-weight: bold;
                    margin-top: 20px;
                }
                .aipass-logo {
                    display: flex;
                    align-items: center;
                    background: white;
                    padding: 10px 15px;
                    border-radius: 12px 0 12px 12px;
                    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
                    margin: 0 auto 30px;
                    width: fit-content;
                }
                .aipass-logo-icon {
                    background-color: #8A4FFF;
                    color: white;
                    font-weight: bold;
                    font-size: 24px;
                    padding: 8px 10px;
                    border-radius: 8px 0 8px 8px;
                    margin-right: 8px;
                }
                .aipass-logo-text {
                    color: #8A4FFF;
                    font-weight: bold;
                    font-size: 24px;
                }
            </style>';

        if ($success) {
            $html .= '<script>
                setTimeout(function() {
                    window.location.href = "' . admin_url('admin.php?page=chatbot-settings&tab=openai&aipass_connected=1') . '";
                }, 2000);
            </script>';
        }

        $html .= '</head>
        <body>
            <div class="aipass-logo">
                <div class="aipass-logo-icon">AI</div>
                <div class="aipass-logo-text">Pass</div>
            </div>
            <h1>AIPass Authorization</h1>';

        if ($success) {
            $html .= '<div class="result success">
                <h2>Successfully Connected!</h2>
                <p>Your WordPress chatbot is now connected to AIPass.</p>
                <p>You can now use AIPass to power your chatbot without an API key.</p>
                <p style="font-size: 12px; color: #666;">Redirecting to settings...</p>
            </div>';
        } else {
            $html .= '<div class="result error">
                <h2>Connection Failed</h2>
                <p>Error: ' . esc_html($error_message) . '</p>
                <p>Please try again or contact support if the issue persists.</p>
            </div>';
        }

        $html .= '<a href="' . admin_url('admin.php?page=chatbot-settings&tab=openai') . '" class="button">Return to Settings</a>
        </body>
        </html>';

        // Set proper headers and return HTML
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    /**
     * AJAX handler to store OAuth flow state (PKCE verifier and state)
     */
    public function store_flow_state() {
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

        $code_verifier = sanitize_text_field($_POST['code_verifier']);
        $state = sanitize_text_field($_POST['state']);

        // Store in transients (expire after 10 minutes)
        set_transient('chatbot_aipass_pkce_verifier', $code_verifier, 600);
        set_transient('chatbot_aipass_oauth_state', $state, 600);

        chatbot_log('INFO', 'store_flow_state', 'OAuth flow state stored');

        wp_send_json_success(array('message' => 'Flow state stored'));
    }

    /**
     * AJAX handler to store tokens after JavaScript exchange
     */
    public function store_tokens() {
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

        $access_token = sanitize_text_field($_POST['access_token']);
        $refresh_token = sanitize_text_field($_POST['refresh_token']);
        $expires_in = intval($_POST['expires_in']);

        // Store tokens
        update_option('chatbot_aipass_access_token', $access_token);
        update_option('chatbot_aipass_refresh_token', $refresh_token);
        update_option('chatbot_aipass_token_expiry', time() + $expires_in);
        update_option('chatbot_aipass_enabled', true);

        chatbot_log('INFO', 'store_tokens', 'AIPass tokens stored successfully');

        wp_send_json_success(array('message' => 'Tokens stored'));
    }

    /**
     * Get client ID (hardcoded in plugin)
     */
    public function get_client_id() {
        return $this->client_id;
    }

    /**
     * Get base URL (hardcoded in plugin)
     */
    public function get_base_url() {
        return $this->base_url;
    }

    /**
     * Check if AIPass is connected
     */
    public function is_connected() {
        $this->refresh_configuration();

        // Check if we have an access token
        if (empty($this->access_token)) {
            return false;
        }

        // Check if token is expired or will expire in next 5 minutes
        // Refresh proactively before expiry to avoid mid-request expiration
        if ($this->token_expiry > 0 && time() >= ($this->token_expiry - 300)) {
            chatbot_log('INFO', 'is_connected', 'Access token expired or expiring soon, attempting refresh');

            // Attempt to refresh the token
            $refresh_result = $this->refresh_access_token();

            if (!$refresh_result['success']) {
                chatbot_log('ERROR', 'is_connected', 'Token refresh failed: ' . $refresh_result['error']);
                return false;
            }

            chatbot_log('INFO', 'is_connected', 'Access token refreshed successfully');
        }

        return true;
    }

    /**
     * Refresh the access token using the refresh token
     * Uses a mutex pattern via transients to prevent concurrent refresh attempts
     *
     * @return array Result with success status and error message if failed
     */
    private function refresh_access_token() {
        // Check if we have a refresh token
        if (empty($this->refresh_token)) {
            chatbot_log('ERROR', 'refresh_access_token', 'No refresh token available');
            return array(
                'success' => false,
                'error' => 'No refresh token available'
            );
        }

        // Mutex: Check if another process is already refreshing the token
        $lock_key = 'chatbot_aipass_refresh_lock';
        $lock_timeout = 30; // Lock expires after 30 seconds (failsafe)
        $max_wait_attempts = 10; // Max times to check if refresh completed
        $wait_interval = 500000; // 0.5 seconds in microseconds

        // Try to acquire lock
        $existing_lock = get_transient($lock_key);

        if ($existing_lock !== false) {
            // Another process is refreshing - wait for it to complete
            chatbot_log('DEBUG', 'refresh_access_token', 'Another refresh in progress, waiting...');

            for ($i = 0; $i < $max_wait_attempts; $i++) {
                usleep($wait_interval); // Wait 0.5 seconds

                // Check if lock was released
                if (get_transient($lock_key) === false) {
                    // Lock released - reload configuration to get new token
                    $this->refresh_configuration();

                    // Verify the token was actually refreshed
                    if (!empty($this->access_token) && $this->token_expiry > time() + 300) {
                        chatbot_log('INFO', 'refresh_access_token', 'Token was refreshed by another process');
                        return array('success' => true);
                    }
                    break; // Lock released but token not valid, proceed with our own refresh
                }
            }

            // If we're still here, either lock is stuck or refresh failed
            // Check if token is now valid (another process might have succeeded)
            $this->refresh_configuration();
            if (!empty($this->access_token) && $this->token_expiry > time() + 300) {
                chatbot_log('INFO', 'refresh_access_token', 'Token valid after waiting');
                return array('success' => true);
            }

            chatbot_log('WARN', 'refresh_access_token', 'Lock timeout or failed refresh, proceeding with own refresh');
        }

        // Acquire the lock
        set_transient($lock_key, time(), $lock_timeout);
        chatbot_log('INFO', 'refresh_access_token', 'Acquired refresh lock, refreshing access token');

        // Prepare request body for token refresh
        $request_body = array(
            'grantType' => 'refresh_token',
            'refreshToken' => $this->refresh_token,
            'clientId' => $this->client_id
        );

        // Add client secret if available
        if (!empty($this->client_secret)) {
            $request_body['clientSecret'] = $this->client_secret;
        }

        // Make request to AIPass token endpoint
        $response = wp_remote_post($this->base_url . '/oauth2/token', array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            chatbot_log('ERROR', 'refresh_access_token', 'WP Error: ' . $response->get_error_message());
            delete_transient($lock_key); // Release lock on error
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $response->get_error_message()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        chatbot_log('DEBUG', 'refresh_access_token', 'Token refresh response', array(
            'status_code' => $response_code,
            'has_access_token' => isset($response_body['access_token'])
        ));

        if ($response_code !== 200) {
            // Handle error - could be string or array
            $error_msg = 'Unknown error';
            if (isset($response_body['error_description'])) {
                $error_msg = $response_body['error_description'];
            } elseif (isset($response_body['error'])) {
                if (is_array($response_body['error'])) {
                    $error_msg = isset($response_body['error']['message'])
                        ? $response_body['error']['message']
                        : json_encode($response_body['error']);
                } else {
                    $error_msg = $response_body['error'];
                }
            }
            chatbot_log('ERROR', 'refresh_access_token', 'Token refresh failed: ' . $error_msg);

            // If refresh token is invalid or expired, clear all tokens
            if ($response_code === 401 || $response_code === 400) {
                chatbot_log('WARN', 'refresh_access_token', 'Refresh token invalid, clearing all tokens');
                update_option('chatbot_aipass_access_token', '');
                update_option('chatbot_aipass_refresh_token', '');
                update_option('chatbot_aipass_token_expiry', 0);
                $this->access_token = '';
                $this->refresh_token = '';
                $this->token_expiry = 0;
            }

            delete_transient($lock_key); // Release lock on error
            return array(
                'success' => false,
                'error' => $error_msg
            );
        }

        if (!isset($response_body['access_token'])) {
            chatbot_log('ERROR', 'refresh_access_token', 'Invalid response format - no access token');
            delete_transient($lock_key); // Release lock on error
            return array(
                'success' => false,
                'error' => 'Invalid response format'
            );
        }

        // Log what we received from the API
        chatbot_log('INFO', 'refresh_access_token', 'API Response received', array(
            'has_access_token' => isset($response_body['access_token']),
            'has_refresh_token' => isset($response_body['refresh_token']),
            'has_expires_in' => isset($response_body['expires_in']),
            'expires_in_value' => isset($response_body['expires_in']) ? $response_body['expires_in'] : 'NOT PROVIDED',
            'response_keys' => array_keys($response_body)
        ));

        // Update stored tokens
        $new_access_token = $response_body['access_token'];
        $new_refresh_token = isset($response_body['refresh_token']) ? $response_body['refresh_token'] : $this->refresh_token;

        // Get expires_in from API response, fallback to 30 days (2592000 seconds) if not provided
        $expires_in = isset($response_body['expires_in']) ? intval($response_body['expires_in']) : 2592000;

        if (!isset($response_body['expires_in'])) {
            chatbot_log('WARN', 'refresh_access_token', 'API did not return expires_in, using 30-day fallback', array(
                'fallback_seconds' => 2592000,
                'fallback_days' => 30
            ));
        }

        $new_expiry = time() + $expires_in;

        update_option('chatbot_aipass_access_token', $new_access_token);
        update_option('chatbot_aipass_refresh_token', $new_refresh_token);
        update_option('chatbot_aipass_token_expiry', $new_expiry);

        // Update internal state
        $this->access_token = $new_access_token;
        $this->refresh_token = $new_refresh_token;
        $this->token_expiry = $new_expiry;

        chatbot_log('INFO', 'refresh_access_token', 'Token refreshed successfully', array(
            'expires_in' => $expires_in,
            'new_expiry' => date('Y-m-d H:i:s', $new_expiry)
        ));

        delete_transient($lock_key); // Release lock on success
        return array(
            'success' => true
        );
    }

    /**
     * AJAX handler for initiating AIPass authorization
     */
    public function initiate_authorization() {
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

        $client_id = $this->get_client_id();

        if (empty($client_id)) {
            wp_send_json_error(array('message' => 'Client ID not configured'));
            return;
        }

        // The authorization URL will be built in JavaScript using the SDK
        // We just return success to trigger the JS authorization flow
        wp_send_json_success(array(
            'message' => 'Ready to authorize',
            'client_id' => $client_id,
            'base_url' => $this->base_url
        ));
    }

    /**
     * AJAX handler for getting authorization status
     */
    public function get_auth_status() {
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

        $this->refresh_configuration();

        wp_send_json_success(array(
            'connected' => $this->is_connected(),
            'enabled' => get_option('chatbot_aipass_enabled', true),
            'token_exists' => !empty($this->access_token)
        ));
    }

    /**
     * AJAX handler for disconnecting AIPass
     */
    public function disconnect() {
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

        // Clear AIPass options
        update_option('chatbot_aipass_access_token', '');
        update_option('chatbot_aipass_refresh_token', '');
        update_option('chatbot_aipass_token_expiry', 0);
        update_option('chatbot_aipass_enabled', false);

        $this->access_token = '';
        $this->refresh_token = '';
        $this->token_expiry = 0;

        chatbot_log('INFO', 'disconnect', 'AIPass disconnected');

        wp_send_json_success(array('message' => 'Successfully disconnected'));
    }

    /**
     * AJAX handler for getting balance info
     */
    public function get_balance_info() {
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

        $this->refresh_configuration();

        // Check connection and refresh token if needed
        if (!$this->is_connected()) {
            wp_send_json_error(array('message' => 'Not connected to AIPass'));
            return;
        }

        // Fetch balance with retry support
        $result = $this->fetch_balance_from_api();

        if ($result['success']) {
            wp_send_json_success(array('balance' => $result['data']));
        } else {
            wp_send_json_error(array('message' => $result['error']));
        }
    }

    /**
     * Fetch balance from AIPass API with 401 retry support
     *
     * @param bool $is_retry Whether this is a retry after token refresh
     * @return array Result with success status and data or error
     */
    private function fetch_balance_from_api($is_retry = false) {
        // Make request to get balance
        $response = wp_remote_get($this->base_url . '/api/v1/usage/me/summary', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            chatbot_log('ERROR', 'fetch_balance_from_api', 'WP Error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $response->get_error_message()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        // Failsafe: If we get 401 Unauthorized, try to refresh token and retry once
        if ($response_code === 401 && !$is_retry) {
            chatbot_log('WARN', 'fetch_balance_from_api', 'Got 401 Unauthorized - attempting token refresh');

            $refresh_result = $this->refresh_access_token();

            if ($refresh_result['success']) {
                chatbot_log('INFO', 'fetch_balance_from_api', 'Token refreshed, retrying API call');
                $this->refresh_configuration();
                return $this->fetch_balance_from_api(true); // Retry once
            } else {
                chatbot_log('ERROR', 'fetch_balance_from_api', 'Token refresh failed: ' . $refresh_result['error']);
                return array(
                    'success' => false,
                    'error' => 'Authentication failed: ' . $refresh_result['error']
                );
            }
        }

        if ($response_code !== 200) {
            $error_message = isset($response_body['message']) ? $response_body['message'] : 'Unknown error';
            chatbot_log('ERROR', 'fetch_balance_from_api', 'API Error: ' . $error_message);
            return array(
                'success' => false,
                'error' => 'API error: ' . $error_message
            );
        }

        if (!isset($response_body['success']) || !$response_body['success'] || !isset($response_body['data'])) {
            return array(
                'success' => false,
                'error' => 'Invalid response format'
            );
        }

        return array(
            'success' => true,
            'data' => $response_body['data']
        );
    }

    /**
     * AJAX handler for testing AIPass connection
     * Sends a real AI completion request to verify the integration is working
     */
    public function test_connection() {
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

        $this->refresh_configuration();

        // Check if connected
        if (!$this->is_connected()) {
            wp_send_json_error(array('message' => 'Not connected to AIPass'));
            return;
        }

        chatbot_log('INFO', 'test_connection', 'Testing AIPass connection with completion request');

        // Send a test AI completion request
        $test_messages = array(
            array(
                'role' => 'system',
                'content' => 'You are a helpful assistant. Respond with only one word.'
            ),
            array(
                'role' => 'user',
                'content' => 'Say "CONNECTED" if you can receive this message.'
            )
        );

        // Use a fast, cheap model for testing
        $result = $this->generate_completion(
            $test_messages,
            'gemini/gemini-2.5-flash-lite', // Fastest and cheapest
            10, // Very low token limit
            0.1 // Low temperature
        );

        if (!$result['success']) {
            chatbot_log('ERROR', 'test_connection', 'Completion test failed: ' . $result['error']);
            wp_send_json_error(array('message' => 'Completion test failed: ' . $result['error']));
            return;
        }

        // Get balance to show in response
        $balance_response = wp_remote_get($this->base_url . '/api/v1/usage/me/summary', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token
            ),
            'timeout' => 30
        ));

        $balance_data = null;
        if (!is_wp_error($balance_response) && wp_remote_retrieve_response_code($balance_response) === 200) {
            $balance_body = json_decode(wp_remote_retrieve_body($balance_response), true);
            if (isset($balance_body['success']) && $balance_body['success']) {
                $balance_data = $balance_body['data'];
            }
        }

        // Build success message
        $ai_response = substr($result['content'], 0, 50); // First 50 chars
        $message = sprintf(
            __('✓ Connection successful! AI responded: "%s"', 'chatbot-plugin'),
            $ai_response
        );

        if ($balance_data) {
            $message .= sprintf(
                __(' | Balance: $%.2f', 'chatbot-plugin'),
                $balance_data['remainingBudget']
            );
        }

        chatbot_log('INFO', 'test_connection', 'Connection test successful', array(
            'model' => $result['model'],
            'tokens_used' => isset($result['usage']['total_tokens']) ? $result['usage']['total_tokens'] : 'N/A'
        ));

        wp_send_json_success(array(
            'message' => $message,
            'ai_response' => $result['content'],
            'model' => $result['model'],
            'usage' => $result['usage'],
            'balance' => $balance_data
        ));
    }

    /**
     * AJAX handler for getting available models
     */
    public function ajax_get_models() {
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

        $result = $this->get_available_models();

        if ($result['success']) {
            wp_send_json_success(array('models' => $result['models']));
        } else {
            wp_send_json_error(array('message' => $result['error']));
        }
    }

    /**
     * Get list of available models from AIPass
     *
     * @param bool $is_retry Whether this is a retry after token refresh (internal use)
     * @return array Result with success status and models list or error
     */
    public function get_available_models($is_retry = false) {
        $this->refresh_configuration();

        // Check if AIPass is connected
        if (!$this->is_connected()) {
            chatbot_log('ERROR', 'get_available_models', 'AIPass not connected');
            return array(
                'success' => false,
                'error' => 'AIPass not connected'
            );
        }

        chatbot_log('INFO', 'get_available_models', 'Fetching models from AIPass');

        // Make request to AIPass models endpoint
        // Using /api/v1/usage/models which returns the format:
        // {"success": true, "data": ["model1", "model2", ...]}
        $response = wp_remote_get($this->base_url . '/api/v1/usage/models', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            chatbot_log('ERROR', 'get_available_models', 'WP Error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $response->get_error_message()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        chatbot_log('DEBUG', 'get_available_models', 'Response received', array(
            'status_code' => $response_code,
            'has_data' => isset($response_body['data']),
            'response_keys' => is_array($response_body) ? array_keys($response_body) : array()
        ));

        // Failsafe: If we get 401 Unauthorized, try to refresh token and retry once
        if ($response_code === 401 && !$is_retry) {
            chatbot_log('WARN', 'get_available_models', 'Got 401 Unauthorized - attempting token refresh');

            $refresh_result = $this->refresh_access_token();

            if ($refresh_result['success']) {
                chatbot_log('INFO', 'get_available_models', 'Token refreshed, retrying API call');
                $this->refresh_configuration();
                return $this->get_available_models(true); // Retry once
            } else {
                chatbot_log('ERROR', 'get_available_models', 'Token refresh failed: ' . $refresh_result['error']);
                return array(
                    'success' => false,
                    'error' => 'Authentication failed: ' . $refresh_result['error']
                );
            }
        }

        if ($response_code !== 200) {
            $error_message = isset($response_body['message']) ? $response_body['message'] : 'Unknown error';
            chatbot_log('ERROR', 'get_available_models', 'API Error: ' . $error_message);
            return array(
                'success' => false,
                'error' => 'API error: ' . $error_message
            );
        }

        // Parse models from AIPass response format
        // Expected: {"success": true, "data": ["model1", "model2", ...]}
        if (!isset($response_body['success']) || !$response_body['success']) {
            chatbot_log('ERROR', 'get_available_models', 'API returned success=false');
            return array(
                'success' => false,
                'error' => isset($response_body['message']) ? $response_body['message'] : 'Request failed'
            );
        }

        if (!isset($response_body['data']) || !is_array($response_body['data'])) {
            chatbot_log('ERROR', 'get_available_models', 'Invalid response format - missing data array');
            return array(
                'success' => false,
                'error' => 'Invalid response format'
            );
        }

        // Models are already in the correct format (array of strings)
        $models = $response_body['data'];

        chatbot_log('INFO', 'get_available_models', 'Models fetched successfully', array(
            'count' => count($models)
        ));

        return array(
            'success' => true,
            'models' => $models
        );
    }

    /**
     * Generate a completion using AIPass API
     *
     * @param array $messages Messages for chat completion
     * @param string $model OpenAI model to use
     * @param int $max_tokens Maximum tokens to generate
     * @param float $temperature Temperature parameter
     * @param bool $is_retry Whether this is a retry after token refresh (internal use)
     * @return array Result with success status and completion or error
     */
    public function generate_completion($messages, $model = 'gpt-4o-mini', $max_tokens = 1000, $temperature = 0.7, $is_retry = false) {
        $this->refresh_configuration();

        // Check if AIPass is enabled and connected
        if (!$this->is_connected()) {
            chatbot_log('ERROR', 'generate_completion', 'AIPass not connected');
            return array(
                'success' => false,
                'error' => 'AIPass not connected'
            );
        }

        // Prepare request body
        $request_body = array(
            'model' => $model,
            'messages' => $messages,
            'stream' => false
        );

        if ($temperature !== null) {
            $request_body['temperature'] = floatval($temperature);
        }

        if ($max_tokens) {
            $request_body['max_tokens'] = intval($max_tokens);
        }

        chatbot_log('INFO', 'generate_completion', 'Making AIPass completion request', array(
            'model' => $model,
            'max_tokens' => $max_tokens,
            'message_count' => count($messages),
            'system_prompt_length' => isset($messages[0]['content']) ? strlen($messages[0]['content']) : 0
        ));

        // Make request to AIPass chat completion endpoint
        $response = wp_remote_post($this->base_url . '/oauth2/v1/chat/completions', array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->access_token
            ),
            'body' => json_encode($request_body),
            'timeout' => 60
        ));

        if (is_wp_error($response)) {
            chatbot_log('ERROR', 'generate_completion', 'WP Error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $response->get_error_message()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        // Failsafe: If we get 401 Unauthorized, token might be expired even though expiry time says otherwise
        // This happens when stored expiry is wrong (e.g., from old fallback code)
        // Try to refresh the token ONCE and retry the request
        if ($response_code === 401 && !$is_retry) {
            // Handle error - could be string or array
            $error_message = 'Unauthorized';
            if (isset($response_body['error'])) {
                if (is_array($response_body['error'])) {
                    $error_message = isset($response_body['error']['message'])
                        ? $response_body['error']['message']
                        : json_encode($response_body['error']);
                } else {
                    $error_message = $response_body['error'];
                }
            }

            chatbot_log('WARN', 'generate_completion', 'Got 401 Unauthorized - token might be expired despite expiry time', array(
                'error' => $error_message,
                'stored_expiry' => date('Y-m-d H:i:s', $this->token_expiry),
                'attempting_refresh' => true
            ));

            // Force refresh the token
            $refresh_result = $this->refresh_access_token();

            if ($refresh_result['success']) {
                chatbot_log('INFO', 'generate_completion', 'Token refreshed successfully, retrying API call');

                // Reload the refreshed token
                $this->refresh_configuration();

                // Retry the request with new token (mark as retry to avoid infinite loop)
                return $this->generate_completion($messages, $model, $max_tokens, $temperature, true);
            } else {
                chatbot_log('ERROR', 'generate_completion', 'Token refresh failed after 401: ' . $refresh_result['error']);
                return array(
                    'success' => false,
                    'error' => 'Authentication failed and token refresh failed: ' . $refresh_result['error']
                );
            }
        }

        if ($response_code !== 200) {
            // Handle error - could be string or array (OpenAI format: {"error": {"message": "...", "type": "..."}})
            $error_message = 'Unknown error';
            $error_type = null;

            if (isset($response_body['error'])) {
                if (is_array($response_body['error'])) {
                    // OpenAI-style error format
                    $error_message = isset($response_body['error']['message'])
                        ? $response_body['error']['message']
                        : json_encode($response_body['error']);

                    // Capture error type (e.g., 'budget_exceeded')
                    if (isset($response_body['error']['type'])) {
                        $error_type = $response_body['error']['type'];
                    }
                } else {
                    // Simple string error
                    $error_message = $response_body['error'];
                }
            }

            // Check for budget/balance related errors
            if ($error_type === 'budget_exceeded' ||
                stripos($error_message, 'budget') !== false ||
                stripos($error_message, 'balance') !== false ||
                stripos($error_message, 'insufficient') !== false) {
                $error_type = 'budget_exceeded';
            }

            chatbot_log('ERROR', 'generate_completion', 'API Error: ' . $error_message, array(
                'status_code' => $response_code,
                'error_type' => $error_type
            ));

            return array(
                'success' => false,
                'error' => $error_message,
                'error_type' => $error_type
            );
        }

        if (!isset($response_body['choices'][0]['message']['content'])) {
            chatbot_log('ERROR', 'generate_completion', 'Invalid response format');
            return array(
                'success' => false,
                'error' => 'Invalid response format'
            );
        }

        // Return success with completion text and usage info
        return array(
            'success' => true,
            'content' => $response_body['choices'][0]['message']['content'],
            'usage' => isset($response_body['usage']) ? $response_body['usage'] : null,
            'model' => isset($response_body['model']) ? $response_body['model'] : $model
        );
    }

    /**
     * Sync access token and refresh token from client-side SDK to WordPress backend
     * This is called after successful OAuth login via the hosted AIPass SDK
     */
    public function sync_token_from_sdk() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot_aipass_nonce')) {
            chatbot_log('ERROR', 'sync_token_from_sdk', 'Nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        // Get access token from request
        $access_token = isset($_POST['access_token']) ? sanitize_text_field($_POST['access_token']) : '';
        $refresh_token = isset($_POST['refresh_token']) ? sanitize_text_field($_POST['refresh_token']) : '';
        $expires_in = isset($_POST['expires_in']) ? intval($_POST['expires_in']) : 0;

        if (empty($access_token)) {
            chatbot_log('ERROR', 'sync_token_from_sdk', 'No access token provided');
            wp_send_json_error(array('message' => 'No access token provided'));
            return;
        }

        chatbot_log('INFO', 'sync_token_from_sdk', 'Syncing tokens from SDK to backend', array(
            'has_refresh_token' => !empty($refresh_token),
            'has_expires_in' => $expires_in > 0
        ));

        // Store the access token
        update_option('chatbot_aipass_access_token', $access_token);

        // Store the refresh token if provided
        if (!empty($refresh_token)) {
            update_option('chatbot_aipass_refresh_token', $refresh_token);
            chatbot_log('INFO', 'sync_token_from_sdk', 'Refresh token stored');
        } else {
            chatbot_log('WARN', 'sync_token_from_sdk', 'No refresh token provided - automatic token refresh may not work');
        }

        // Enable AIPass if not already enabled
        update_option('chatbot_aipass_enabled', true);

        // Set expiry time based on expires_in from SDK or API
        if ($expires_in > 0) {
            // Use the actual expiry time from SDK/API
            $expiry_time = time() + $expires_in;
            chatbot_log('INFO', 'sync_token_from_sdk', 'Token expiry set from API', array(
                'expires_in_seconds' => $expires_in,
                'expires_in_hours' => round($expires_in / 3600, 1),
                'expires_at' => date('Y-m-d H:i:s', $expiry_time)
            ));
        } else {
            // SDK didn't provide expiry - try to get it from AIPass API
            chatbot_log('WARN', 'sync_token_from_sdk', 'No expires_in provided, fetching from API');

            // Temporarily store token to make API call
            $this->access_token = $access_token;

            // Make a quick API call to get token info (user balance endpoint will validate the token)
            $balance_response = wp_remote_get($this->base_url . '/api/v1/usage/me/summary', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token
                ),
                'timeout' => 10
            ));

            // Default to 30 days if we can't determine actual expiry
            // This is much safer than 1 hour and provides a longer connection window
            $expiry_time = time() + (30 * 24 * 3600); // 30 days = 2592000 seconds

            if (!is_wp_error($balance_response) && wp_remote_retrieve_response_code($balance_response) === 200) {
                chatbot_log('INFO', 'sync_token_from_sdk', 'Token validated via API, using default 30-day expiry');
            } else {
                chatbot_log('WARN', 'sync_token_from_sdk', 'Could not validate token via API, using default 30-day expiry');
            }

            chatbot_log('INFO', 'sync_token_from_sdk', 'Token expiry set to default', array(
                'expires_in_seconds' => 30 * 24 * 3600,
                'expires_in_days' => 30,
                'expires_at' => date('Y-m-d H:i:s', $expiry_time)
            ));
        }

        update_option('chatbot_aipass_token_expiry', $expiry_time);

        // Refresh our internal configuration
        $this->refresh_configuration();

        // Try to get user info to validate the token works
        $balance_result = $this->get_user_balance();
        if (isset($balance_result['error'])) {
            chatbot_log('WARN', 'sync_token_from_sdk', 'Token validation failed: ' . $balance_result['error']);
            wp_send_json_error(array('message' => 'Token appears invalid: ' . $balance_result['error']));
            return;
        }

        chatbot_log('INFO', 'sync_token_from_sdk', 'Tokens synced and validated successfully');

        wp_send_json_success(array(
            'message' => 'Tokens synced successfully',
            'balance' => $balance_result,
            'has_refresh_token' => !empty($refresh_token)
        ));
    }

    /**
     * Generate speech from text (Text-to-Speech)
     *
     * @param string $text Text to convert to speech
     * @param string $model TTS model (default: tts-1)
     * @param string $voice Voice to use (alloy, echo, fable, onyx, nova, shimmer)
     * @param float $speed Speed of speech (0.25 to 4.0)
     * @return array Result with success status and audio data or error
     */
    public function generate_speech($text, $model = 'tts-1', $voice = 'alloy', $speed = 1.0) {
        $this->refresh_configuration();

        // Check if AIPass is connected
        if (!$this->is_connected()) {
            chatbot_log('ERROR', 'generate_speech', 'AIPass not connected');
            return array(
                'success' => false,
                'error' => 'AIPass not connected'
            );
        }

        // Validate text
        if (empty($text)) {
            return array(
                'success' => false,
                'error' => 'No text provided'
            );
        }

        // Limit text length (OpenAI TTS has a 4096 character limit)
        if (strlen($text) > 4096) {
            $text = substr($text, 0, 4096);
        }

        // Prepare request body
        $request_body = array(
            'model' => $model,
            'input' => $text,
            'voice' => $voice,
            'speed' => floatval($speed),
            'response_format' => 'mp3'
        );

        chatbot_log('INFO', 'generate_speech', 'Making AIPass TTS request', array(
            'model' => $model,
            'voice' => $voice,
            'text_length' => strlen($text)
        ));

        // Make request to AIPass speech endpoint
        $response = wp_remote_post($this->base_url . '/oauth2/v1/audio/speech', array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->access_token
            ),
            'body' => json_encode($request_body),
            'timeout' => 60
        ));

        if (is_wp_error($response)) {
            chatbot_log('ERROR', 'generate_speech', 'WP Error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $response->get_error_message()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');

        if ($response_code !== 200) {
            $raw_body = wp_remote_retrieve_body($response);
            $response_body = json_decode($raw_body, true);

            // Try to extract error message from various formats
            $error_message = 'Unknown error';
            if (isset($response_body['error']['message'])) {
                $error_message = $response_body['error']['message'];
            } elseif (isset($response_body['error']) && is_string($response_body['error'])) {
                $error_message = $response_body['error'];
            } elseif (isset($response_body['message'])) {
                $error_message = $response_body['message'];
            } elseif (!empty($raw_body)) {
                $error_message = 'API Error: ' . substr($raw_body, 0, 200);
            }

            chatbot_log('ERROR', 'generate_speech', 'API Error: ' . $error_message, array(
                'status_code' => $response_code,
                'raw_body' => substr($raw_body, 0, 500)
            ));
            return array(
                'success' => false,
                'error' => $error_message
            );
        }

        // Get audio data
        $audio_data = wp_remote_retrieve_body($response);

        if (empty($audio_data)) {
            chatbot_log('ERROR', 'generate_speech', 'Empty audio response');
            return array(
                'success' => false,
                'error' => 'Empty audio response'
            );
        }

        chatbot_log('INFO', 'generate_speech', 'TTS audio generated successfully', array(
            'audio_size' => strlen($audio_data)
        ));

        return array(
            'success' => true,
            'audio_data' => base64_encode($audio_data),
            'content_type' => 'audio/mpeg'
        );
    }

    /**
     * AJAX handler for text-to-speech
     */
    public function ajax_generate_speech() {
        chatbot_log('INFO', 'ajax_generate_speech', 'TTS AJAX request received');

        // Verify nonce (use same nonce as main chatbot plugin)
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-plugin-nonce')) {
            chatbot_log('ERROR', 'ajax_generate_speech', 'Nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        $text = isset($_POST['text']) ? sanitize_textarea_field($_POST['text']) : '';
        $voice = isset($_POST['voice']) ? sanitize_text_field($_POST['voice']) : 'alloy';

        chatbot_log('INFO', 'ajax_generate_speech', 'Processing TTS request', array(
            'text_length' => strlen($text),
            'voice' => $voice
        ));

        if (empty($text)) {
            wp_send_json_error(array('message' => 'No text provided'));
            return;
        }

        $result = $this->generate_speech($text, 'tts-1', $voice);

        if ($result['success']) {
            chatbot_log('INFO', 'ajax_generate_speech', 'TTS success, returning audio');
            wp_send_json_success(array(
                'audio_data' => $result['audio_data'],
                'content_type' => $result['content_type']
            ));
        } else {
            chatbot_log('ERROR', 'ajax_generate_speech', 'TTS failed: ' . $result['error']);
            wp_send_json_error(array('message' => $result['error']));
        }
    }

    /**
     * Transcribe audio to text (Speech-to-Text)
     *
     * @param string $audio_data Base64 encoded audio data
     * @param string $model STT model (default: whisper-1)
     * @param string $language Optional language code
     * @return array Result with success status and transcription or error
     */
    public function transcribe_audio($audio_data, $model = 'whisper-1', $language = '') {
        $this->refresh_configuration();

        // Check if AIPass is connected
        if (!$this->is_connected()) {
            chatbot_log('ERROR', 'transcribe_audio', 'AIPass not connected');
            return array(
                'success' => false,
                'error' => 'AIPass not connected'
            );
        }

        // Validate audio data
        if (empty($audio_data)) {
            return array(
                'success' => false,
                'error' => 'No audio data provided'
            );
        }

        // Decode base64 audio
        $decoded_audio = base64_decode($audio_data);
        if ($decoded_audio === false) {
            return array(
                'success' => false,
                'error' => 'Invalid audio data'
            );
        }

        chatbot_log('INFO', 'transcribe_audio', 'Making AIPass STT request', array(
            'model' => $model,
            'audio_size' => strlen($decoded_audio)
        ));

        // Create a temporary file for the audio
        $temp_file = wp_tempnam('audio_');
        file_put_contents($temp_file, $decoded_audio);

        // Prepare multipart form data
        $boundary = wp_generate_password(24, false);

        // Build multipart body
        $body = '';

        // Add model field
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
        $body .= "{$model}\r\n";

        // Add language field if provided
        if (!empty($language)) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"language\"\r\n\r\n";
            $body .= "{$language}\r\n";
        }

        // Add audio file
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"audio.webm\"\r\n";
        $body .= "Content-Type: audio/webm\r\n\r\n";
        $body .= $decoded_audio . "\r\n";
        $body .= "--{$boundary}--\r\n";

        // Make request to AIPass transcription endpoint
        $response = wp_remote_post($this->base_url . '/oauth2/v1/audio/transcriptions', array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                'Authorization' => 'Bearer ' . $this->access_token
            ),
            'body' => $body,
            'timeout' => 60
        ));

        // Clean up temp file
        @unlink($temp_file);

        if (is_wp_error($response)) {
            chatbot_log('ERROR', 'transcribe_audio', 'WP Error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $response->get_error_message()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code !== 200) {
            $error_message = isset($response_body['error']['message'])
                ? $response_body['error']['message']
                : (isset($response_body['error']) ? $response_body['error'] : 'Unknown error');
            chatbot_log('ERROR', 'transcribe_audio', 'API Error: ' . $error_message, array(
                'status_code' => $response_code
            ));
            return array(
                'success' => false,
                'error' => $error_message
            );
        }

        if (!isset($response_body['text'])) {
            chatbot_log('ERROR', 'transcribe_audio', 'Invalid response format');
            return array(
                'success' => false,
                'error' => 'Invalid response format'
            );
        }

        chatbot_log('INFO', 'transcribe_audio', 'Audio transcribed successfully', array(
            'text_length' => strlen($response_body['text'])
        ));

        return array(
            'success' => true,
            'text' => $response_body['text']
        );
    }

    /**
     * AJAX handler for speech-to-text
     */
    public function ajax_transcribe_audio() {
        chatbot_log('INFO', 'ajax_transcribe_audio', 'STT AJAX request received');

        // Verify nonce (use same nonce as main chatbot plugin)
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'chatbot-plugin-nonce')) {
            chatbot_log('ERROR', 'ajax_transcribe_audio', 'Nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        $audio_data = isset($_POST['audio_data']) ? $_POST['audio_data'] : '';

        chatbot_log('INFO', 'ajax_transcribe_audio', 'Processing STT request', array(
            'audio_size' => strlen($audio_data)
        ));

        if (empty($audio_data)) {
            wp_send_json_error(array('message' => 'No audio data provided'));
            return;
        }

        $result = $this->transcribe_audio($audio_data);

        if ($result['success']) {
            chatbot_log('INFO', 'ajax_transcribe_audio', 'STT success', array(
                'text_length' => strlen($result['text'])
            ));
            wp_send_json_success(array(
                'text' => $result['text']
            ));
        } else {
            chatbot_log('ERROR', 'ajax_transcribe_audio', 'STT failed: ' . $result['error']);
            wp_send_json_error(array('message' => $result['error']));
        }
    }
}

// Add AJAX handler for storing tokens (must be outside class)
add_action('wp_ajax_chatbot_aipass_store_tokens', function() {
    $aipass = Chatbot_AIPass::get_instance();
    $aipass->store_tokens();
});

// Initialize the AIPass integration
function chatbot_aipass_init() {
    return Chatbot_AIPass::get_instance();
}
chatbot_aipass_init();
