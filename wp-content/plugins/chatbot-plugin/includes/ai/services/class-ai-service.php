<?php
/**
 * AI Service Facade
 *
 * Provides a unified interface for AI operations.
 * Handles provider selection and capability routing.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_AI_Service
 *
 * Unified facade for all AI operations.
 */
class Chatbot_AI_Service {

    /**
     * Primary AI provider.
     *
     * @var Chatbot_AI_Provider|null
     */
    private $provider = null;

    /**
     * Fallback providers.
     *
     * @var array
     */
    private $fallback_providers = array();

    /**
     * Last error message.
     *
     * @var string|null
     */
    private $last_error = null;

    /**
     * Constructor.
     *
     * @param Chatbot_AI_Provider|null $primary_provider The primary AI provider.
     */
    public function __construct(?Chatbot_AI_Provider $primary_provider = null) {
        $this->provider = $primary_provider;
    }

    /**
     * Set the primary AI provider.
     *
     * @param Chatbot_AI_Provider $provider The provider to use.
     * @return void
     */
    public function set_provider(Chatbot_AI_Provider $provider): void {
        $this->provider = $provider;
    }

    /**
     * Add a fallback provider.
     *
     * @param Chatbot_AI_Provider $provider The fallback provider.
     * @return void
     */
    public function add_fallback_provider(Chatbot_AI_Provider $provider): void {
        $this->fallback_providers[] = $provider;
    }

    /**
     * Get the active provider (first available).
     *
     * @return Chatbot_AI_Provider|null The active provider or null.
     */
    public function get_active_provider(): ?Chatbot_AI_Provider {
        // Check primary provider
        if ($this->provider !== null && $this->provider->is_connected()) {
            return $this->provider;
        }

        // Check fallback providers
        foreach ($this->fallback_providers as $fallback) {
            if ($fallback->is_connected()) {
                return $fallback;
            }
        }

        return null;
    }

    /**
     * Check if any AI provider is available.
     *
     * @return bool True if a provider is available.
     */
    public function is_available(): bool {
        return $this->get_active_provider() !== null;
    }

    /**
     * Check if a specific capability is available.
     *
     * @param string $capability The capability interface name.
     * @return bool True if the capability is available.
     */
    public function has_capability(string $capability): bool {
        $provider = $this->get_active_provider();
        return $provider !== null && $provider->has_capability($capability);
    }

    /**
     * Get a provider that supports a specific capability.
     *
     * @param string $capability The capability interface name.
     * @return Chatbot_AI_Provider|null Provider or null if not available.
     */
    public function get_provider_for_capability(string $capability): ?Chatbot_AI_Provider {
        // Check primary provider
        if ($this->provider !== null && $this->provider->is_connected() && $this->provider->has_capability($capability)) {
            return $this->provider;
        }

        // Check fallback providers
        foreach ($this->fallback_providers as $fallback) {
            if ($fallback->is_connected() && $fallback->has_capability($capability)) {
                return $fallback;
            }
        }

        return null;
    }

    // =========================================================================
    // Chat Operations
    // =========================================================================

    /**
     * Generate a chat completion.
     *
     * @param array  $messages Array of message objects.
     * @param string $model    The model to use (optional).
     * @param array  $options  Additional options (max_tokens, temperature, tools).
     * @return array Result with success, content, error, etc.
     */
    public function generate_completion(array $messages, string $model = '', array $options = []): array {
        $this->last_error = null;

        /** @var Chatbot_Chat_Capability|null $provider */
        $provider = $this->get_provider_for_capability('Chatbot_Chat_Capability');

        if ($provider === null) {
            $this->last_error = 'No AI provider available for chat';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        // Use default model if not specified
        if (empty($model)) {
            $model = $provider->get_default_chat_model();
        }

        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'ai_service', 'Generating completion', array(
                'provider' => $provider->get_name(),
                'model' => $model,
                'message_count' => count($messages),
            ));
        }

        $result = $provider->generate_completion($messages, $model, $options);

        if (!$result['success']) {
            $this->last_error = $result['error'] ?? 'Unknown error';
        }

        return $result;
    }

    /**
     * Check if chat is available.
     *
     * @return bool True if chat capability is available.
     */
    public function is_chat_available(): bool {
        return $this->has_capability('Chatbot_Chat_Capability');
    }

    /**
     * Get available chat models.
     *
     * @return array Array of model identifiers.
     */
    public function get_chat_models(): array {
        /** @var Chatbot_Chat_Capability|null $provider */
        $provider = $this->get_provider_for_capability('Chatbot_Chat_Capability');

        if ($provider === null) {
            return array();
        }

        return $provider->get_chat_models();
    }

    /**
     * Get the default chat model.
     *
     * @return string The default model identifier.
     */
    public function get_default_model(): string {
        /** @var Chatbot_Chat_Capability|null $provider */
        $provider = $this->get_provider_for_capability('Chatbot_Chat_Capability');

        if ($provider === null) {
            return defined('CHATBOT_DEFAULT_MODEL') ? CHATBOT_DEFAULT_MODEL : 'gpt-3.5-turbo';
        }

        return $provider->get_default_chat_model();
    }

    // =========================================================================
    // TTS Operations
    // =========================================================================

    /**
     * Convert text to speech.
     *
     * @param string $text    The text to convert.
     * @param string $voice   The voice to use.
     * @param array  $options Additional options.
     * @return array Result with success, audio_data, error, etc.
     */
    public function text_to_speech(string $text, string $voice = '', array $options = []): array {
        $this->last_error = null;

        /** @var Chatbot_TTS_Capability|null $provider */
        $provider = $this->get_provider_for_capability('Chatbot_TTS_Capability');

        if ($provider === null) {
            $this->last_error = 'No AI provider available for TTS';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        if (empty($voice)) {
            $voice = $provider->get_default_voice();
        }

        return $provider->text_to_speech($text, $voice, $options);
    }

    /**
     * Check if TTS is available.
     *
     * @return bool True if TTS capability is available.
     */
    public function is_tts_available(): bool {
        return $this->has_capability('Chatbot_TTS_Capability');
    }

    /**
     * Get available TTS voices.
     *
     * @return array Array of voice info.
     */
    public function get_tts_voices(): array {
        /** @var Chatbot_TTS_Capability|null $provider */
        $provider = $this->get_provider_for_capability('Chatbot_TTS_Capability');

        if ($provider === null) {
            return array();
        }

        return $provider->get_tts_voices();
    }

    // =========================================================================
    // STT Operations
    // =========================================================================

    /**
     * Convert speech to text.
     *
     * @param string $audio_data The audio data.
     * @param array  $options    Additional options.
     * @return array Result with success, text, error, etc.
     */
    public function speech_to_text(string $audio_data, array $options = []): array {
        $this->last_error = null;

        /** @var Chatbot_STT_Capability|null $provider */
        $provider = $this->get_provider_for_capability('Chatbot_STT_Capability');

        if ($provider === null) {
            $this->last_error = 'No AI provider available for STT';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        return $provider->speech_to_text($audio_data, $options);
    }

    /**
     * Check if STT is available.
     *
     * @return bool True if STT capability is available.
     */
    public function is_stt_available(): bool {
        return $this->has_capability('Chatbot_STT_Capability');
    }

    // =========================================================================
    // Vision Operations
    // =========================================================================

    /**
     * Analyze a file with AI.
     *
     * @param array  $file    The file data (type, data/url, mime_type, name).
     * @param string $prompt  The prompt/question about the file.
     * @param array  $options Additional options.
     * @return array Result with success, content, error, etc.
     */
    public function analyze_file(array $file, string $prompt, array $options = []): array {
        return $this->analyze_files(array($file), $prompt, $options);
    }

    /**
     * Analyze multiple files with AI.
     *
     * @param array  $files   Array of file data.
     * @param string $prompt  The prompt/question about the files.
     * @param array  $options Additional options.
     * @return array Result with success, content, error, etc.
     */
    public function analyze_files(array $files, string $prompt, array $options = []): array {
        $this->last_error = null;

        /** @var Chatbot_Vision_Capability|null $provider */
        $provider = $this->get_provider_for_capability('Chatbot_Vision_Capability');

        if ($provider === null) {
            $this->last_error = 'No AI provider available for vision/file analysis';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'ai_service', 'Analyzing files', array(
                'provider' => $provider->get_name(),
                'file_count' => count($files),
            ));
        }

        $result = $provider->analyze_files($files, $prompt, $options);

        if (!$result['success']) {
            $this->last_error = $result['error'] ?? 'Unknown error';
        }

        return $result;
    }

    /**
     * Generate a chat completion with file attachments.
     *
     * @param array  $messages The conversation messages.
     * @param array  $files    The file attachments.
     * @param string $model    The model to use (optional).
     * @param array  $options  Additional options.
     * @return array Result with success, content, error, etc.
     */
    public function generate_completion_with_files(
        array $messages,
        array $files,
        string $model = '',
        array $options = []
    ): array {
        $this->last_error = null;

        /** @var Chatbot_Vision_Capability|null $provider */
        $provider = $this->get_provider_for_capability('Chatbot_Vision_Capability');

        if ($provider === null) {
            $this->last_error = 'No AI provider available for vision/file processing';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        // Use default model if not specified
        if (empty($model) && $provider instanceof Chatbot_Chat_Capability) {
            $model = $provider->get_default_chat_model();
        }

        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'ai_service', 'Generating completion with files', array(
                'provider' => $provider->get_name(),
                'model' => $model,
                'message_count' => count($messages),
                'file_count' => count($files),
            ));
        }

        $result = $provider->generate_completion_with_files($messages, $files, $model, $options);

        if (!$result['success']) {
            $this->last_error = $result['error'] ?? 'Unknown error';
        }

        return $result;
    }

    /**
     * Check if vision/file capability is available.
     *
     * @return bool True if vision capability is available.
     */
    public function is_vision_available(): bool {
        return $this->has_capability('Chatbot_Vision_Capability');
    }

    /**
     * Get supported file types for vision.
     *
     * @return array Associative array of file types to MIME types.
     */
    public function get_supported_file_types(): array {
        /** @var Chatbot_Vision_Capability|null $provider */
        $provider = $this->get_provider_for_capability('Chatbot_Vision_Capability');

        if ($provider === null) {
            return array();
        }

        return $provider->get_supported_file_types();
    }

    /**
     * Check if a file type is supported.
     *
     * @param string $mime_type The MIME type to check.
     * @return bool True if supported.
     */
    public function is_file_type_supported(string $mime_type): bool {
        /** @var Chatbot_Vision_Capability|null $provider */
        $provider = $this->get_provider_for_capability('Chatbot_Vision_Capability');

        if ($provider === null) {
            return false;
        }

        return $provider->is_file_type_supported($mime_type);
    }

    /**
     * Get maximum file size for a file type.
     *
     * @param string $file_type The file type.
     * @return int Maximum size in bytes.
     */
    public function get_max_file_size(string $file_type): int {
        /** @var Chatbot_Vision_Capability|null $provider */
        $provider = $this->get_provider_for_capability('Chatbot_Vision_Capability');

        if ($provider === null) {
            return 10 * 1024 * 1024; // 10MB default
        }

        return $provider->get_max_file_size($file_type);
    }

    // =========================================================================
    // Status and Info
    // =========================================================================

    /**
     * Get the last error message.
     *
     * @return string|null The last error or null.
     */
    public function get_last_error(): ?string {
        return $this->last_error;
    }

    /**
     * Get status information about the AI service.
     *
     * @return array Status information.
     */
    public function get_status(): array {
        $provider = $this->get_active_provider();

        return array(
            'available' => $provider !== null,
            'provider' => $provider !== null ? $provider->get_name() : null,
            'capabilities' => $provider !== null ? $provider->get_capabilities() : array(),
            'chat_available' => $this->is_chat_available(),
            'tts_available' => $this->is_tts_available(),
            'stt_available' => $this->is_stt_available(),
            'vision_available' => $this->is_vision_available(),
        );
    }

    /**
     * Get all registered providers.
     *
     * @return array Array of provider info.
     */
    public function get_providers(): array {
        $providers = array();

        if ($this->provider !== null) {
            $providers[] = array(
                'name' => $this->provider->get_name(),
                'type' => 'primary',
                'connected' => $this->provider->is_connected(),
                'capabilities' => $this->provider->get_capabilities(),
            );
        }

        foreach ($this->fallback_providers as $provider) {
            $providers[] = array(
                'name' => $provider->get_name(),
                'type' => 'fallback',
                'connected' => $provider->is_connected(),
                'capabilities' => $provider->get_capabilities(),
            );
        }

        return $providers;
    }
}
