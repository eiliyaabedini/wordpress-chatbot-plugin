<?php
/**
 * Rate Limit Middleware
 *
 * Enforces rate limiting on message processing.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_Rate_Limit_Middleware
 *
 * Applies rate limiting to prevent abuse.
 */
class Chatbot_Rate_Limit_Middleware implements Chatbot_Message_Middleware {

    /**
     * Maximum requests per window.
     *
     * @var int
     */
    private $max_requests = 20;

    /**
     * Time window in seconds.
     *
     * @var int
     */
    private $window_seconds = 60;

    /**
     * Transient prefix.
     *
     * @var string
     */
    private $transient_prefix = 'chatbot_rate_';

    /**
     * Constructor.
     *
     * @param int $max_requests  Maximum requests per window.
     * @param int $window_seconds Time window in seconds.
     */
    public function __construct(int $max_requests = 20, int $window_seconds = 60) {
        $this->max_requests = $max_requests;
        $this->window_seconds = $window_seconds;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Chatbot_Message_Context $context, callable $next): Chatbot_Message_Response {
        $identifier = $this->get_rate_limit_identifier($context);
        $key = $this->transient_prefix . md5($identifier);

        // Get current request count
        $data = get_transient($key);

        if ($data === false) {
            // First request in window
            $data = [
                'count' => 1,
                'start' => time(),
            ];
            set_transient($key, $data, $this->window_seconds);
        } else {
            // Check if window has expired
            if (time() - $data['start'] >= $this->window_seconds) {
                // Reset the window
                $data = [
                    'count' => 1,
                    'start' => time(),
                ];
                set_transient($key, $data, $this->window_seconds);
            } else {
                // Check if rate limit exceeded
                if ($data['count'] >= $this->max_requests) {
                    $remaining_seconds = $this->window_seconds - (time() - $data['start']);

                    if (function_exists('chatbot_log')) {
                        chatbot_log('WARN', 'rate_limit_middleware', 'Rate limit exceeded', [
                            'identifier' => $identifier,
                            'count' => $data['count'],
                            'remaining_seconds' => $remaining_seconds,
                        ]);
                    }

                    return Chatbot_Message_Response::error(
                        sprintf(
                            'Too many requests. Please wait %d seconds before trying again.',
                            $remaining_seconds
                        ),
                        'rate_limit',
                        [
                            'retry_after' => $remaining_seconds,
                            'limit' => $this->max_requests,
                            'window' => $this->window_seconds,
                        ]
                    );
                }

                // Increment count
                $data['count']++;
                set_transient($key, $data, $this->window_seconds - (time() - $data['start']));
            }
        }

        // Continue to next middleware
        return $next($context);
    }

    /**
     * {@inheritdoc}
     */
    public function get_priority(): int {
        return 20; // Run after validation
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return 'rate_limit';
    }

    /**
     * Get rate limit identifier for the context.
     *
     * @param Chatbot_Message_Context $context The context.
     * @return string The identifier.
     */
    private function get_rate_limit_identifier(Chatbot_Message_Context $context): string {
        // Use platform chat ID if available
        if ($context->get_platform_chat_id() !== null) {
            return $context->get_platform() . ':' . $context->get_platform_chat_id();
        }

        // Fall back to IP address for web platform
        $ip = $this->get_client_ip();
        return 'ip:' . $ip;
    }

    /**
     * Get client IP address.
     *
     * @return string The IP address.
     */
    private function get_client_ip(): string {
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Set rate limit configuration.
     *
     * @param int $max_requests   Maximum requests.
     * @param int $window_seconds Window in seconds.
     * @return void
     */
    public function set_limits(int $max_requests, int $window_seconds): void {
        $this->max_requests = $max_requests;
        $this->window_seconds = $window_seconds;
    }
}
