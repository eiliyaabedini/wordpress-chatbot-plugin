<?php
/**
 * Speech-to-Text Capability Interface
 *
 * Defines the contract for AI speech-to-text capabilities.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Interface Chatbot_STT_Capability
 *
 * Contract for AI providers that support speech-to-text transcription.
 */
interface Chatbot_STT_Capability {

    /**
     * Transcribe audio to text.
     *
     * @param string $audio_data Base64 encoded audio data or file path.
     * @param array  $options    Optional parameters:
     *                           - model: string The STT model to use
     *                           - language: string Language hint for transcription
     *                           - format: string Input audio format
     * @return array Result array with keys:
     *               - success: bool Whether the request succeeded
     *               - text: string The transcribed text (if success)
     *               - language: string Detected language code
     *               - error: string Error message (if !success)
     */
    public function speech_to_text(string $audio_data, array $options = []): array;

    /**
     * Check if STT capability is currently available.
     *
     * @return bool True if STT can be used.
     */
    public function is_stt_available(): bool;

    /**
     * Get supported audio formats for STT.
     *
     * @return array Array of supported format identifiers.
     */
    public function get_supported_audio_formats(): array;

    /**
     * Get supported languages for STT.
     *
     * @return array Array of supported language codes.
     */
    public function get_supported_languages(): array;
}
