<?php
/**
 * Message Middleware Interface
 *
 * Defines the contract for message processing middleware.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Interface Chatbot_Message_Middleware
 *
 * Contract for middleware in the message processing pipeline.
 * Middleware can modify the context, short-circuit with a response,
 * or pass to the next middleware in the chain.
 */
interface Chatbot_Message_Middleware {

    /**
     * Process a message through this middleware.
     *
     * @param Chatbot_Message_Context $context The message context.
     * @param callable                $next    The next middleware in the pipeline.
     * @return Chatbot_Message_Response The response.
     */
    public function process(Chatbot_Message_Context $context, callable $next): Chatbot_Message_Response;

    /**
     * Get the middleware priority.
     *
     * Lower numbers run first. Default is 100.
     *
     * @return int The priority.
     */
    public function get_priority(): int;

    /**
     * Get the middleware name for logging/debugging.
     *
     * @return string The middleware name.
     */
    public function get_name(): string;
}
