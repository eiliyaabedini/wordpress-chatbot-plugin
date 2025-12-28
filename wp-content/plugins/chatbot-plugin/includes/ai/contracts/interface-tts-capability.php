<?php
/**
 * Text-to-Speech Capability Interface
 *
 * Defines the contract for AI text-to-speech capabilities.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Interface Chatbot_TTS_Capability
 *
 * Contract for AI providers that support text-to-speech.
 */
interface Chatbot_TTS_Capability {

    /**
     * Convert text to speech audio.
     *
     * @param string $text    The text to convert to speech.
     * @param string $voice   The voice identifier to use.
     * @param array  $options Optional parameters:
     *                        - model: string The TTS model to use
     *                        - speed: float Speech speed multiplier
     *                        - format: string Output audio format (mp3, wav, etc.)
     * @return array Result array with keys:
     *               - success: bool Whether the request succeeded
     *               - audio_data: string Base64 encoded audio data (if success)
     *               - content_type: string MIME type of the audio
     *               - error: string Error message (if !success)
     */
    public function text_to_speech(string $text, string $voice, array $options = []): array;

    /**
     * Check if TTS capability is currently available.
     *
     * @return bool True if TTS can be used.
     */
    public function is_tts_available(): bool;

    /**
     * Get available voices for TTS.
     *
     * @return array Array of voice identifiers with metadata.
     */
    public function get_tts_voices(): array;

    /**
     * Get the default voice for TTS.
     *
     * @return string The default voice identifier.
     */
    public function get_default_voice(): string;
}
