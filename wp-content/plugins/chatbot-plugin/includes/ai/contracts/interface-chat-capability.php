<?php
/**
 * Chat Capability Interface
 *
 * Defines the contract for AI chat/completion capabilities.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Interface Chatbot_Chat_Capability
 *
 * Contract for AI providers that support chat completions.
 * This is the primary capability for conversational AI.
 */
interface Chatbot_Chat_Capability {

    /**
     * Generate a chat completion.
     *
     * @param array  $messages    Array of message objects with 'role' and 'content'.
     * @param string $model       The model identifier to use.
     * @param array  $options     Optional parameters:
     *                            - max_tokens: int Maximum tokens in response
     *                            - temperature: float Randomness (0-2)
     *                            - tools: array Function/tool definitions for function calling
     *                            - tool_choice: string|array Tool selection preference
     * @return array Result array with keys:
     *               - success: bool Whether the request succeeded
     *               - content: string The generated text (if success)
     *               - tool_calls: array Any tool calls requested by the model
     *               - usage: array Token usage statistics
     *               - error: string Error message (if !success)
     */
    public function generate_completion(array $messages, string $model, array $options = []): array;

    /**
     * Check if chat capability is currently available.
     *
     * @return bool True if chat completions can be made.
     */
    public function is_chat_available(): bool;

    /**
     * Get available chat models.
     *
     * @return array Array of model identifiers available for chat.
     */
    public function get_chat_models(): array;

    /**
     * Get the default chat model.
     *
     * @return string The default model identifier.
     */
    public function get_default_chat_model(): string;

    /**
     * Validate a model identifier for chat use.
     *
     * @param string $model The model identifier to validate.
     * @return bool True if the model is valid for chat.
     */
    public function validate_chat_model(string $model): bool;
}
