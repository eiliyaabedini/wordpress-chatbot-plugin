<?php
/**
 * API Client Service
 *
 * Handles HTTP requests to AI API endpoints.
 * Provides a clean interface for making authenticated API calls.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_API_Client
 *
 * HTTP client for AI API requests with authentication support.
 */
class Chatbot_API_Client {

    /**
     * Base URL for API requests.
     *
     * @var string
     */
    private $base_url;

    /**
     * Default timeout in seconds.
     *
     * @var int
     */
    private $default_timeout = 30;

    /**
     * Last error message.
     *
     * @var string|null
     */
    private $last_error = null;

    /**
     * Last HTTP response code.
     *
     * @var int|null
     */
    private $last_response_code = null;

    /**
     * Constructor.
     *
     * @param string $base_url The base URL for API requests.
     */
    public function __construct(string $base_url) {
        $this->base_url = rtrim($base_url, '/');
    }

    /**
     * Make a GET request.
     *
     * @param string      $endpoint The API endpoint (relative to base URL).
     * @param array       $headers  Optional headers to include.
     * @param int|null    $timeout  Optional timeout override.
     * @return array|null Response data as array or null on error.
     */
    public function get(string $endpoint, array $headers = [], ?int $timeout = null): ?array {
        return $this->request('GET', $endpoint, null, $headers, $timeout);
    }

    /**
     * Make a POST request.
     *
     * @param string      $endpoint The API endpoint (relative to base URL).
     * @param array       $data     Request body data.
     * @param array       $headers  Optional headers to include.
     * @param int|null    $timeout  Optional timeout override.
     * @return array|null Response data as array or null on error.
     */
    public function post(string $endpoint, array $data, array $headers = [], ?int $timeout = null): ?array {
        return $this->request('POST', $endpoint, $data, $headers, $timeout);
    }

    /**
     * Make an authenticated GET request.
     *
     * @param string      $endpoint     The API endpoint.
     * @param string      $access_token The bearer token for authentication.
     * @param array       $headers      Additional headers.
     * @param int|null    $timeout      Optional timeout override.
     * @return array|null Response data as array or null on error.
     */
    public function authenticated_get(string $endpoint, string $access_token, array $headers = [], ?int $timeout = null): ?array {
        $headers['Authorization'] = 'Bearer ' . $access_token;
        return $this->get($endpoint, $headers, $timeout);
    }

    /**
     * Make an authenticated POST request.
     *
     * @param string      $endpoint     The API endpoint.
     * @param array       $data         Request body data.
     * @param string      $access_token The bearer token for authentication.
     * @param array       $headers      Additional headers.
     * @param int|null    $timeout      Optional timeout override.
     * @return array|null Response data as array or null on error.
     */
    public function authenticated_post(string $endpoint, array $data, string $access_token, array $headers = [], ?int $timeout = null): ?array {
        $headers['Authorization'] = 'Bearer ' . $access_token;
        return $this->post($endpoint, $data, $headers, $timeout);
    }

    /**
     * Make an HTTP request.
     *
     * @param string      $method   HTTP method (GET, POST, etc.).
     * @param string      $endpoint The API endpoint.
     * @param array|null  $data     Request body data for POST requests.
     * @param array       $headers  Headers to include.
     * @param int|null    $timeout  Timeout in seconds.
     * @return array|null Response data as array or null on error.
     */
    private function request(string $method, string $endpoint, ?array $data, array $headers, ?int $timeout): ?array {
        $this->last_error = null;
        $this->last_response_code = null;

        $url = $this->base_url . '/' . ltrim($endpoint, '/');
        $timeout = $timeout ?? $this->default_timeout;

        // Default headers
        $default_headers = array(
            'Content-Type' => 'application/json',
        );
        $headers = array_merge($default_headers, $headers);

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => $timeout,
        );

        if ($data !== null && $method === 'POST') {
            $args['body'] = wp_json_encode($data);
        }

        if (function_exists('chatbot_log')) {
            chatbot_log('DEBUG', 'api_client', 'Making API request', array(
                'method' => $method,
                'endpoint' => $endpoint,
                'timeout' => $timeout,
            ));
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            if (function_exists('chatbot_log')) {
                chatbot_log('ERROR', 'api_client', 'WP Error: ' . $this->last_error);
            }
            return null;
        }

        $this->last_response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->last_error = 'Invalid JSON response';
            if (function_exists('chatbot_log')) {
                chatbot_log('ERROR', 'api_client', 'Invalid JSON response', array(
                    'body_preview' => substr($body, 0, 200),
                ));
            }
            return null;
        }

        return $decoded;
    }

    /**
     * Get the last error message.
     *
     * @return string|null The last error message or null.
     */
    public function get_last_error(): ?string {
        return $this->last_error;
    }

    /**
     * Get the last HTTP response code.
     *
     * @return int|null The last response code or null.
     */
    public function get_last_response_code(): ?int {
        return $this->last_response_code;
    }

    /**
     * Check if the last request was successful.
     *
     * @return bool True if last response was 2xx.
     */
    public function was_successful(): bool {
        return $this->last_response_code !== null
            && $this->last_response_code >= 200
            && $this->last_response_code < 300;
    }

    /**
     * Check if the last request was unauthorized (401).
     *
     * @return bool True if last response was 401.
     */
    public function was_unauthorized(): bool {
        return $this->last_response_code === 401;
    }

    /**
     * Set the default timeout.
     *
     * @param int $timeout Timeout in seconds.
     * @return void
     */
    public function set_default_timeout(int $timeout): void {
        $this->default_timeout = $timeout;
    }

    /**
     * Get the base URL.
     *
     * @return string The base URL.
     */
    public function get_base_url(): string {
        return $this->base_url;
    }
}
