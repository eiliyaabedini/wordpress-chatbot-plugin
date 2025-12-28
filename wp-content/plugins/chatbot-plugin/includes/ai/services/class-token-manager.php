<?php
/**
 * Token Manager Service
 *
 * Handles OAuth2 token lifecycle: storage, retrieval, expiry checking, and refresh.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_Token_Manager
 *
 * Manages OAuth2 tokens for AI API authentication.
 */
class Chatbot_Token_Manager {

    /**
     * API client for token refresh requests.
     *
     * @var Chatbot_API_Client
     */
    private $api_client;

    /**
     * OAuth2 client ID.
     *
     * @var string
     */
    private $client_id;

    /**
     * OAuth2 client secret (optional for PKCE).
     *
     * @var string|null
     */
    private $client_secret;

    /**
     * WordPress option name for access token.
     *
     * @var string
     */
    private $option_access_token = 'chatbot_aipass_access_token';

    /**
     * WordPress option name for refresh token.
     *
     * @var string
     */
    private $option_refresh_token = 'chatbot_aipass_refresh_token';

    /**
     * WordPress option name for token expiry.
     *
     * @var string
     */
    private $option_token_expiry = 'chatbot_aipass_token_expiry';

    /**
     * Cached access token.
     *
     * @var string
     */
    private $access_token = '';

    /**
     * Cached refresh token.
     *
     * @var string
     */
    private $refresh_token = '';

    /**
     * Cached token expiry timestamp.
     *
     * @var int
     */
    private $token_expiry = 0;

    /**
     * Seconds before expiry to trigger proactive refresh.
     *
     * @var int
     */
    private $refresh_margin = 300; // 5 minutes

    /**
     * Default token lifetime fallback (30 days in seconds).
     *
     * @var int
     */
    private $default_expiry = 2592000;

    /**
     * Constructor.
     *
     * @param Chatbot_API_Client $api_client    The API client for refresh requests.
     * @param string             $client_id     The OAuth2 client ID.
     * @param string|null        $client_secret Optional client secret.
     */
    public function __construct(Chatbot_API_Client $api_client, string $client_id, ?string $client_secret = null) {
        $this->api_client = $api_client;
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->load_tokens();
    }

    /**
     * Load tokens from WordPress options.
     *
     * @return void
     */
    public function load_tokens(): void {
        $this->access_token = get_option($this->option_access_token, '');
        $this->refresh_token = get_option($this->option_refresh_token, '');
        $this->token_expiry = (int) get_option($this->option_token_expiry, 0);
    }

    /**
     * Save tokens to WordPress options.
     *
     * @param string   $access_token  The access token.
     * @param string   $refresh_token The refresh token.
     * @param int|null $expires_in    Token lifetime in seconds (null uses default).
     * @return void
     */
    public function save_tokens(string $access_token, string $refresh_token, ?int $expires_in = null): void {
        $this->access_token = $access_token;
        $this->refresh_token = $refresh_token;
        $this->token_expiry = time() + ($expires_in ?? $this->default_expiry);

        update_option($this->option_access_token, $this->access_token);
        update_option($this->option_refresh_token, $this->refresh_token);
        update_option($this->option_token_expiry, $this->token_expiry);

        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'token_manager', 'Tokens saved', array(
                'expires_in' => $expires_in ?? $this->default_expiry,
                'expiry_date' => date('Y-m-d H:i:s', $this->token_expiry),
            ));
        }
    }

    /**
     * Clear all tokens (logout/disconnect).
     *
     * @return void
     */
    public function clear_tokens(): void {
        $this->access_token = '';
        $this->refresh_token = '';
        $this->token_expiry = 0;

        update_option($this->option_access_token, '');
        update_option($this->option_refresh_token, '');
        update_option($this->option_token_expiry, 0);

        // Clear any refresh cooldown
        delete_transient('chatbot_aipass_refresh_cooldown');

        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'token_manager', 'Tokens cleared');
        }
    }

    /**
     * Get the current access token.
     *
     * @return string The access token (empty if not set).
     */
    public function get_access_token(): string {
        return $this->access_token;
    }

    /**
     * Get the current refresh token.
     *
     * @return string The refresh token (empty if not set).
     */
    public function get_refresh_token(): string {
        return $this->refresh_token;
    }

    /**
     * Check if we have a valid access token.
     *
     * @return bool True if access token exists.
     */
    public function has_access_token(): bool {
        return !empty($this->access_token);
    }

    /**
     * Check if the access token is expired or expiring soon.
     *
     * @return bool True if token is expired or expiring within margin.
     */
    public function is_token_expiring(): bool {
        if ($this->token_expiry <= 0) {
            return false; // No expiry set, assume valid
        }
        return time() >= ($this->token_expiry - $this->refresh_margin);
    }

    /**
     * Check if tokens are valid and connected.
     * Automatically refreshes token if expiring soon.
     *
     * @return bool True if connected with valid token.
     */
    public function is_connected(): bool {
        $this->load_tokens();

        if (empty($this->access_token)) {
            return false;
        }

        // Check if token needs refresh
        if ($this->is_token_expiring()) {
            // Check cooldown to prevent refresh loops
            $cooldown_until = get_transient('chatbot_aipass_refresh_cooldown');
            if ($cooldown_until !== false && time() < $cooldown_until) {
                if (function_exists('chatbot_log')) {
                    chatbot_log('DEBUG', 'token_manager', 'In refresh cooldown, skipping refresh');
                }
                return false;
            }

            if (function_exists('chatbot_log')) {
                chatbot_log('INFO', 'token_manager', 'Token expiring soon, attempting refresh');
            }

            $result = $this->refresh_access_token();
            if (!$result['success']) {
                // Set cooldown on failure
                set_transient('chatbot_aipass_refresh_cooldown', time() + 300, 300);
                return false;
            }

            delete_transient('chatbot_aipass_refresh_cooldown');
        }

        return true;
    }

    /**
     * Refresh the access token using the refresh token.
     *
     * @return array Result with 'success' boolean and 'error' string on failure.
     */
    public function refresh_access_token(): array {
        if (empty($this->refresh_token)) {
            if (function_exists('chatbot_log')) {
                chatbot_log('ERROR', 'token_manager', 'No refresh token available');
            }
            return array(
                'success' => false,
                'error' => 'No refresh token available',
            );
        }

        // Use mutex to prevent concurrent refresh attempts
        $lock_key = 'chatbot_aipass_refresh_lock';
        $lock_timeout = 30;

        $existing_lock = get_transient($lock_key);
        if ($existing_lock !== false) {
            // Wait for other process to complete
            usleep(500000); // 0.5 seconds
            $this->load_tokens();
            if (!empty($this->access_token) && !$this->is_token_expiring()) {
                return array('success' => true);
            }
        }

        // Acquire lock
        set_transient($lock_key, time(), $lock_timeout);

        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'token_manager', 'Refreshing access token');
        }

        // Prepare refresh request
        $request_body = array(
            'grantType' => 'refresh_token',
            'refreshToken' => $this->refresh_token,
            'clientId' => $this->client_id,
        );

        if (!empty($this->client_secret)) {
            $request_body['clientSecret'] = $this->client_secret;
        }

        $response = $this->api_client->post('/oauth2/token', $request_body);
        $response_code = $this->api_client->get_last_response_code();

        if ($response === null) {
            delete_transient($lock_key);
            return array(
                'success' => false,
                'error' => $this->api_client->get_last_error() ?? 'Connection error',
            );
        }

        if ($response_code !== 200) {
            $error_msg = $this->extract_error_message($response);

            // Clear tokens if refresh token is invalid
            if ($response_code === 401 || $response_code === 400) {
                $this->clear_tokens();
            }

            delete_transient($lock_key);
            return array(
                'success' => false,
                'error' => $error_msg,
            );
        }

        if (!isset($response['access_token'])) {
            delete_transient($lock_key);
            return array(
                'success' => false,
                'error' => 'Invalid response format',
            );
        }

        // Update tokens
        $new_access = $response['access_token'];
        $new_refresh = $response['refresh_token'] ?? $this->refresh_token;
        $expires_in = isset($response['expires_in']) ? (int) $response['expires_in'] : null;

        $this->save_tokens($new_access, $new_refresh, $expires_in);

        delete_transient($lock_key);

        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'token_manager', 'Token refreshed successfully');
        }

        return array('success' => true);
    }

    /**
     * Extract error message from API response.
     *
     * @param array $response The API response.
     * @return string The error message.
     */
    private function extract_error_message(array $response): string {
        if (isset($response['error_description'])) {
            return $response['error_description'];
        }

        if (isset($response['error'])) {
            if (is_array($response['error'])) {
                return $response['error']['message'] ?? wp_json_encode($response['error']);
            }
            return $response['error'];
        }

        return 'Unknown error';
    }

    /**
     * Get token expiry timestamp.
     *
     * @return int Unix timestamp of token expiry.
     */
    public function get_token_expiry(): int {
        return $this->token_expiry;
    }

    /**
     * Get seconds until token expires.
     *
     * @return int Seconds until expiry (negative if expired).
     */
    public function get_seconds_until_expiry(): int {
        if ($this->token_expiry <= 0) {
            return PHP_INT_MAX; // No expiry set
        }
        return $this->token_expiry - time();
    }
}
