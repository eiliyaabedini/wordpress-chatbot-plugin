<?php
/**
 * Validation Middleware
 *
 * Validates incoming messages before processing.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_Validation_Middleware
 *
 * Validates message content and context.
 */
class Chatbot_Validation_Middleware implements Chatbot_Message_Middleware {

    /**
     * Minimum message length.
     *
     * @var int
     */
    private $min_length = 1;

    /**
     * Maximum message length.
     *
     * @var int
     */
    private $max_length = 10000;

    /**
     * Constructor.
     *
     * @param int $min_length Minimum message length.
     * @param int $max_length Maximum message length.
     */
    public function __construct(int $min_length = 1, int $max_length = 10000) {
        $this->min_length = $min_length;
        $this->max_length = $max_length;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Chatbot_Message_Context $context, callable $next): Chatbot_Message_Response {
        $message = trim($context->get_message());

        // Check for empty message
        if (strlen($message) < $this->min_length) {
            return Chatbot_Message_Response::error(
                'Please enter a message.',
                'validation_error',
                ['field' => 'message', 'reason' => 'empty']
            );
        }

        // Check message length
        if (strlen($message) > $this->max_length) {
            return Chatbot_Message_Response::error(
                sprintf('Message is too long. Maximum %d characters allowed.', $this->max_length),
                'validation_error',
                ['field' => 'message', 'reason' => 'too_long', 'max' => $this->max_length]
            );
        }

        // Basic content validation (no script injection)
        if ($this->contains_script_tags($message)) {
            return Chatbot_Message_Response::error(
                'Invalid message content.',
                'validation_error',
                ['field' => 'message', 'reason' => 'invalid_content']
            );
        }

        // Continue to next middleware
        return $next($context);
    }

    /**
     * {@inheritdoc}
     */
    public function get_priority(): int {
        return 10; // Run early
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return 'validation';
    }

    /**
     * Check if message contains script tags.
     *
     * @param string $message The message.
     * @return bool
     */
    private function contains_script_tags(string $message): bool {
        return preg_match('/<script\b[^>]*>/i', $message) === 1;
    }

    /**
     * Set maximum message length.
     *
     * @param int $length The maximum length.
     * @return void
     */
    public function set_max_length(int $length): void {
        $this->max_length = $length;
    }
}
