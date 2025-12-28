<?php
/**
 * AI Provider Interface
 *
 * Defines the contract for AI service providers.
 * Providers implement various capabilities (chat, TTS, STT, vision, etc.)
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Interface Chatbot_AI_Provider
 *
 * Contract for AI service providers.
 * A provider is a connection to an AI service (like AIPass, OpenAI, etc.)
 * that may support multiple capabilities.
 */
interface Chatbot_AI_Provider {

    /**
     * Get the provider name.
     *
     * @return string The provider name (e.g., 'aipass', 'openai').
     */
    public function get_name(): string;

    /**
     * Check if the provider is configured and ready to use.
     *
     * @return bool True if the provider is properly configured.
     */
    public function is_configured(): bool;

    /**
     * Check if the provider is currently connected/authenticated.
     *
     * @return bool True if the provider is connected.
     */
    public function is_connected(): bool;

    /**
     * Get the list of capabilities this provider supports.
     *
     * @return array Array of capability interface names this provider implements.
     */
    public function get_capabilities(): array;

    /**
     * Check if the provider supports a specific capability.
     *
     * @param string $capability The capability interface name.
     * @return bool True if the capability is supported.
     */
    public function has_capability(string $capability): bool;

    /**
     * Get available models for a specific capability.
     *
     * @param string $capability The capability to get models for.
     * @return array Array of available model identifiers.
     */
    public function get_models_for_capability(string $capability): array;

    /**
     * Get the last error message if any operation failed.
     *
     * @return string|null The last error message or null.
     */
    public function get_last_error(): ?string;
}
