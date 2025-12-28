<?php
/**
 * AIPass Provider
 *
 * Implements AI capabilities using the AIPass service.
 * Supports Chat, TTS, and STT capabilities.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_AIPass_Provider
 *
 * AIPass implementation of AI Provider with Chat, TTS, STT, and Vision capabilities.
 */
class Chatbot_AIPass_Provider implements
    Chatbot_AI_Provider,
    Chatbot_Chat_Capability,
    Chatbot_TTS_Capability,
    Chatbot_STT_Capability,
    Chatbot_Vision_Capability {

    /**
     * Provider name.
     */
    private const PROVIDER_NAME = 'aipass';

    /**
     * Default model for chat completions.
     */
    private const DEFAULT_MODEL = 'gemini/gemini-2.5-flash-lite';

    /**
     * Token manager instance.
     *
     * @var Chatbot_Token_Manager
     */
    private $token_manager;

    /**
     * API client instance.
     *
     * @var Chatbot_API_Client
     */
    private $api_client;

    /**
     * Last error message.
     *
     * @var string|null
     */
    private $last_error = null;

    /**
     * Cached models list.
     *
     * @var array|null
     */
    private $models_cache = null;

    /**
     * Constructor.
     *
     * @param Chatbot_Token_Manager $token_manager The token manager.
     * @param Chatbot_API_Client    $api_client    The API client.
     */
    public function __construct(Chatbot_Token_Manager $token_manager, Chatbot_API_Client $api_client) {
        $this->token_manager = $token_manager;
        $this->api_client = $api_client;
    }

    // =========================================================================
    // Chatbot_AI_Provider Implementation
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return self::PROVIDER_NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function is_configured(): bool {
        return true; // AIPass is always configured (hardcoded credentials)
    }

    /**
     * {@inheritdoc}
     */
    public function is_connected(): bool {
        return $this->token_manager->is_connected();
    }

    /**
     * {@inheritdoc}
     */
    public function get_capabilities(): array {
        return array(
            'Chatbot_Chat_Capability',
            'Chatbot_TTS_Capability',
            'Chatbot_STT_Capability',
            'Chatbot_Vision_Capability',
        );
    }

    /**
     * {@inheritdoc}
     */
    public function has_capability(string $capability): bool {
        return in_array($capability, $this->get_capabilities(), true);
    }

    /**
     * {@inheritdoc}
     */
    public function get_models_for_capability(string $capability): array {
        switch ($capability) {
            case 'Chatbot_Chat_Capability':
                return $this->get_chat_models();
            case 'Chatbot_TTS_Capability':
                return array('tts-1', 'tts-1-hd');
            case 'Chatbot_STT_Capability':
                return array('whisper-1');
            case 'Chatbot_Vision_Capability':
                return $this->get_vision_models();
            default:
                return array();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get_last_error(): ?string {
        return $this->last_error;
    }

    // =========================================================================
    // Chatbot_Chat_Capability Implementation
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function generate_completion(array $messages, string $model, array $options = []): array {
        $this->last_error = null;

        if (!$this->is_connected()) {
            $this->last_error = 'AIPass not connected';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        // Use default model if not specified
        if (empty($model)) {
            $model = $this->get_default_chat_model();
        }

        // Build request body
        $request_body = array(
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        );

        // Add optional parameters
        if (isset($options['max_tokens']) && $options['max_tokens'] > 0) {
            $request_body['max_tokens'] = (int) $options['max_tokens'];
        }

        if (isset($options['temperature'])) {
            $request_body['temperature'] = (float) $options['temperature'];
        }

        if (!empty($options['tools'])) {
            $request_body['tools'] = $options['tools'];
            $request_body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        if (function_exists('chatbot_log')) {
            // Check if any message has array content (indicates vision/files)
            $has_array_content = false;
            foreach ($messages as $msg) {
                if (is_array($msg['content'] ?? null)) {
                    $has_array_content = true;
                    break;
                }
            }

            chatbot_log('INFO', 'aipass_provider', 'Making completion request', array(
                'model' => $model,
                'message_count' => count($messages),
                'has_tools' => !empty($options['tools']),
                'has_vision_content' => $has_array_content,
            ));

            // Log first user message content structure for debugging
            foreach ($messages as $msg) {
                if ($msg['role'] === 'user' && is_array($msg['content'] ?? null)) {
                    $content_types = array_map(function($c) {
                        if ($c['type'] === 'image_url') {
                            $url = $c['image_url']['url'] ?? '';
                            $url_preview = strlen($url) > 50 ? substr($url, 0, 50) . '...' : $url;
                            return 'image_url(' . $url_preview . ')';
                        }
                        return $c['type'] ?? 'unknown';
                    }, $msg['content']);
                    chatbot_log('DEBUG', 'aipass_provider', 'User message content types: ' . implode(', ', $content_types));
                    break;
                }
            }
        }

        // Log request structure for debugging (without full base64 data)
        if (function_exists('chatbot_log')) {
            $debug_body = $request_body;
            // Truncate base64 data in logs for readability
            if (!empty($debug_body['messages'])) {
                foreach ($debug_body['messages'] as &$msg) {
                    if (is_array($msg['content'] ?? null)) {
                        foreach ($msg['content'] as &$content) {
                            if (($content['type'] ?? '') === 'image_url') {
                                $url = $content['image_url']['url'] ?? '';
                                if (strpos($url, 'data:') === 0 && strlen($url) > 100) {
                                    $content['image_url']['url'] = substr($url, 0, 80) . '...[truncated, total ' . strlen($url) . ' chars]';
                                }
                            }
                        }
                    }
                }
            }
            chatbot_log('DEBUG', 'aipass_provider', 'API request body structure', $debug_body);
        }

        // Make API request
        $response = $this->api_client->authenticated_post(
            '/oauth2/v1/chat/completions',
            $request_body,
            $this->token_manager->get_access_token(),
            array(),
            300 // 5 minute timeout for AI requests
        );

        // Handle 401 with retry
        if ($this->api_client->was_unauthorized()) {
            if (function_exists('chatbot_log')) {
                chatbot_log('WARN', 'aipass_provider', 'Got 401, attempting token refresh');
            }

            $refresh_result = $this->token_manager->refresh_access_token();
            if ($refresh_result['success']) {
                // Retry with new token
                $response = $this->api_client->authenticated_post(
                    '/oauth2/v1/chat/completions',
                    $request_body,
                    $this->token_manager->get_access_token(),
                    array(),
                    300
                );
            } else {
                $this->last_error = 'Authentication failed: ' . $refresh_result['error'];
                return array(
                    'success' => false,
                    'error' => $this->last_error,
                );
            }
        }

        if ($response === null) {
            $this->last_error = $this->api_client->get_last_error() ?? 'Connection error';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        if (!$this->api_client->was_successful()) {
            $this->last_error = $this->extract_error_message($response);
            return array(
                'success' => false,
                'error' => $this->last_error,
                'error_type' => $this->extract_error_type($response),
            );
        }

        // Parse response
        $message = $response['choices'][0]['message'] ?? null;
        $finish_reason = $response['choices'][0]['finish_reason'] ?? 'stop';

        if (!$message) {
            $this->last_error = 'Invalid response format - no message';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        // Check for tool calls
        if ($finish_reason === 'tool_calls' || !empty($message['tool_calls'])) {
            return array(
                'success' => true,
                'content' => $message['content'] ?? null,
                'tool_calls' => $message['tool_calls'],
                'usage' => $response['usage'] ?? null,
                'model' => $response['model'] ?? $model,
                'finish_reason' => $finish_reason,
            );
        }

        // Regular response
        if (!isset($message['content'])) {
            $this->last_error = 'Invalid response format - no content';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        return array(
            'success' => true,
            'content' => $message['content'],
            'usage' => $response['usage'] ?? null,
            'model' => $response['model'] ?? $model,
            'finish_reason' => $finish_reason,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function is_chat_available(): bool {
        return $this->is_connected();
    }

    /**
     * {@inheritdoc}
     */
    public function get_chat_models(): array {
        if ($this->models_cache !== null) {
            return $this->models_cache;
        }

        if (!$this->is_connected()) {
            return array(self::DEFAULT_MODEL);
        }

        $response = $this->api_client->authenticated_get(
            '/api/v1/usage/models',
            $this->token_manager->get_access_token()
        );

        if ($response === null || !isset($response['success']) || !$response['success']) {
            return array(self::DEFAULT_MODEL);
        }

        $this->models_cache = $response['data'] ?? array(self::DEFAULT_MODEL);
        return $this->models_cache;
    }

    /**
     * {@inheritdoc}
     */
    public function get_default_chat_model(): string {
        return defined('CHATBOT_DEFAULT_MODEL') ? CHATBOT_DEFAULT_MODEL : self::DEFAULT_MODEL;
    }

    /**
     * {@inheritdoc}
     */
    public function validate_chat_model(string $model): bool {
        // AIPass supports all models routed through it
        return !empty($model);
    }

    // =========================================================================
    // Chatbot_TTS_Capability Implementation
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function text_to_speech(string $text, string $voice, array $options = []): array {
        $this->last_error = null;

        if (!$this->is_connected()) {
            $this->last_error = 'AIPass not connected';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        $model = $options['model'] ?? 'tts-1';
        $speed = $options['speed'] ?? 1.0;
        $response_format = $options['format'] ?? 'mp3';

        $request_body = array(
            'model' => $model,
            'input' => $text,
            'voice' => $voice,
            'speed' => (float) $speed,
            'response_format' => $response_format,
        );

        $response = $this->api_client->authenticated_post(
            '/oauth2/v1/audio/speech',
            $request_body,
            $this->token_manager->get_access_token(),
            array(),
            60
        );

        if ($response === null || !$this->api_client->was_successful()) {
            $this->last_error = $this->api_client->get_last_error() ?? 'TTS request failed';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        // Response should contain audio data
        return array(
            'success' => true,
            'audio_data' => $response['audio'] ?? '',
            'content_type' => 'audio/' . $response_format,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function is_tts_available(): bool {
        return $this->is_connected();
    }

    /**
     * {@inheritdoc}
     */
    public function get_tts_voices(): array {
        return array(
            array('id' => 'alloy', 'name' => 'Alloy', 'gender' => 'neutral'),
            array('id' => 'echo', 'name' => 'Echo', 'gender' => 'male'),
            array('id' => 'fable', 'name' => 'Fable', 'gender' => 'neutral'),
            array('id' => 'onyx', 'name' => 'Onyx', 'gender' => 'male'),
            array('id' => 'nova', 'name' => 'Nova', 'gender' => 'female'),
            array('id' => 'shimmer', 'name' => 'Shimmer', 'gender' => 'female'),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function get_default_voice(): string {
        return 'alloy';
    }

    // =========================================================================
    // Chatbot_STT_Capability Implementation
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function speech_to_text(string $audio_data, array $options = []): array {
        $this->last_error = null;

        if (!$this->is_connected()) {
            $this->last_error = 'AIPass not connected';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        $model = $options['model'] ?? 'whisper-1';
        $language = $options['language'] ?? null;

        $request_body = array(
            'model' => $model,
            'file' => $audio_data,
        );

        if ($language) {
            $request_body['language'] = $language;
        }

        $response = $this->api_client->authenticated_post(
            '/oauth2/v1/audio/transcriptions',
            $request_body,
            $this->token_manager->get_access_token(),
            array(),
            60
        );

        if ($response === null || !$this->api_client->was_successful()) {
            $this->last_error = $this->api_client->get_last_error() ?? 'STT request failed';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        return array(
            'success' => true,
            'text' => $response['text'] ?? '',
            'language' => $response['language'] ?? $language,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function is_stt_available(): bool {
        return $this->is_connected();
    }

    /**
     * {@inheritdoc}
     */
    public function get_supported_audio_formats(): array {
        return array('mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'wav', 'webm');
    }

    /**
     * {@inheritdoc}
     */
    public function get_supported_languages(): array {
        // Whisper supports many languages
        return array(
            'en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'ru', 'zh', 'ja', 'ko',
            'ar', 'hi', 'tr', 'pl', 'sv', 'da', 'no', 'fi', 'he', 'th', 'vi',
        );
    }

    // =========================================================================
    // Chatbot_Vision_Capability Implementation
    // =========================================================================

    /**
     * Supported file types and their MIME types.
     *
     * @var array
     */
    private static $supported_file_types = array(
        Chatbot_Vision_Capability::FILE_TYPE_IMAGE => array(
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
        ),
        Chatbot_Vision_Capability::FILE_TYPE_PDF => array(
            'application/pdf',
        ),
        Chatbot_Vision_Capability::FILE_TYPE_DOCUMENT => array(
            'text/plain',
            'text/csv',
            'text/html',
            'text/markdown',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ),
        Chatbot_Vision_Capability::FILE_TYPE_SPREADSHEET => array(
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
        ),
    );

    /**
     * Models that support vision/files.
     *
     * @var array
     */
    private static $vision_models = array(
        'openai/gpt-4o',
        'openai/gpt-4o-mini',
        'openai/gpt-4-turbo',
        'openai/gpt-4-vision-preview',
        'gemini/gemini-2.5-flash',
        'gemini/gemini-2.5-flash-lite',
        'gemini/gemini-2.5-pro',
        'gemini/gemini-3-flash-preview',
        'anthropic/claude-3-5-sonnet',
        'anthropic/claude-3-5-sonnet-latest',
        'anthropic/claude-3-opus',
        'anthropic/claude-3-sonnet',
        'anthropic/claude-3-haiku',
    );

    /**
     * {@inheritdoc}
     */
    public function analyze_file(array $file, string $prompt, array $options = []): array {
        return $this->analyze_files(array($file), $prompt, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function analyze_files(array $files, string $prompt, array $options = []): array {
        $messages = array(
            array(
                'role' => 'user',
                'content' => $prompt,
            ),
        );

        $model = $options['model'] ?? $this->get_default_vision_model();

        return $this->generate_completion_with_files($messages, $files, $model, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function generate_completion_with_files(
        array $messages,
        array $files,
        string $model,
        array $options = []
    ): array {
        $this->last_error = null;

        if (!$this->is_connected()) {
            $this->last_error = 'AIPass not connected';
            return array(
                'success' => false,
                'error' => $this->last_error,
            );
        }

        if (empty($files)) {
            // No files, just do regular completion
            return $this->generate_completion($messages, $model, $options);
        }

        // Validate files
        foreach ($files as $file) {
            if (!$this->validate_file($file)) {
                return array(
                    'success' => false,
                    'error' => $this->last_error ?? 'Invalid file',
                );
            }
        }

        // Build messages with file content
        $processed_messages = $this->build_messages_with_files($messages, $files);

        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'aipass_provider_vision', 'Processing files', array(
                'file_count' => count($files),
                'model' => $model,
                'message_count' => count($processed_messages),
                'file_types' => array_map(function($f) { return $f['mime_type'] ?? 'unknown'; }, $files),
            ));

            // Log the structure of processed messages for debugging
            foreach ($processed_messages as $idx => $msg) {
                if (is_array($msg['content'])) {
                    $content_types = array_map(function($c) { return $c['type'] ?? 'unknown'; }, $msg['content']);
                    chatbot_log('DEBUG', 'aipass_provider_vision', "Message {$idx} content types: " . implode(', ', $content_types));
                }
            }
        }

        // Use the regular completion endpoint - most vision models use the same endpoint
        return $this->generate_completion($processed_messages, $model, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function is_vision_available(): bool {
        return $this->is_connected();
    }

    /**
     * {@inheritdoc}
     */
    public function get_vision_models(): array {
        // Return intersection of known vision models and available models
        $available = $this->get_chat_models();

        // If we can't get available models, return our known vision models
        if (count($available) <= 1) {
            return self::$vision_models;
        }

        return array_values(array_intersect($available, self::$vision_models));
    }

    /**
     * {@inheritdoc}
     */
    public function get_supported_file_types(): array {
        return self::$supported_file_types;
    }

    /**
     * {@inheritdoc}
     */
    public function get_max_file_size(string $file_type): int {
        switch ($file_type) {
            case Chatbot_Vision_Capability::FILE_TYPE_IMAGE:
                return 20 * 1024 * 1024; // 20MB
            case Chatbot_Vision_Capability::FILE_TYPE_PDF:
                return 50 * 1024 * 1024; // 50MB
            case Chatbot_Vision_Capability::FILE_TYPE_DOCUMENT:
            case Chatbot_Vision_Capability::FILE_TYPE_SPREADSHEET:
                return 25 * 1024 * 1024; // 25MB
            default:
                return 10 * 1024 * 1024; // 10MB default
        }
    }

    /**
     * {@inheritdoc}
     */
    public function is_file_type_supported(string $mime_type): bool {
        return $this->get_file_type_from_mime($mime_type) !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function get_file_type_from_mime(string $mime_type): ?string {
        $mime_type = strtolower($mime_type);

        foreach (self::$supported_file_types as $type => $mimes) {
            if (in_array($mime_type, $mimes, true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Get the default model for vision tasks.
     *
     * @return string The default vision model.
     */
    private function get_default_vision_model(): string {
        // Prefer Gemini Flash for speed and cost, or GPT-4o-mini as fallback
        return 'gemini/gemini-2.5-flash';
    }

    /**
     * Validate a file before processing.
     *
     * @param array $file The file data.
     * @return bool True if valid.
     */
    private function validate_file(array $file): bool {
        // Check required fields
        if (empty($file['data']) && empty($file['url'])) {
            $this->last_error = 'File must have either data or url';
            return false;
        }

        if (empty($file['mime_type'])) {
            $this->last_error = 'File must have mime_type';
            return false;
        }

        // Check if file type is supported
        if (!$this->is_file_type_supported($file['mime_type'])) {
            $this->last_error = 'Unsupported file type: ' . $file['mime_type'];
            return false;
        }

        // Check file size if data is provided
        if (!empty($file['data'])) {
            $file_type = $this->get_file_type_from_mime($file['mime_type']);
            $max_size = $this->get_max_file_size($file_type);
            $actual_size = strlen(base64_decode($file['data']));

            if ($actual_size > $max_size) {
                $this->last_error = sprintf(
                    'File too large: %s bytes (max: %s bytes)',
                    number_format($actual_size),
                    number_format($max_size)
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Build messages array with files embedded.
     *
     * Files are added to the LAST user message (the current message),
     * not the first user message (which could be from conversation history).
     *
     * @param array $messages Original messages.
     * @param array $files    Files to embed.
     * @return array Processed messages with files.
     */
    private function build_messages_with_files(array $messages, array $files): array {
        // Find the index of the last user message
        $last_user_index = -1;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i]['role'] === 'user') {
                $last_user_index = $i;
                break;
            }
        }

        // If no user message found, append one with files
        if ($last_user_index === -1) {
            $messages[] = array(
                'role' => 'user',
                'content' => $this->build_content_with_files('Please analyze the attached file(s).', $files),
            );
            return $messages;
        }

        // Build result with files added to the last user message
        $result = array();
        foreach ($messages as $idx => $message) {
            if ($idx === $last_user_index) {
                // Add files to the last user message
                $content = $this->build_content_with_files($message['content'], $files);
                $result[] = array(
                    'role' => 'user',
                    'content' => $content,
                );

                if (function_exists('chatbot_log')) {
                    chatbot_log('DEBUG', 'aipass_provider', 'Added files to user message at index ' . $idx, [
                        'content_parts' => count($content),
                    ]);
                }
            } else {
                $result[] = $message;
            }
        }

        return $result;
    }

    /**
     * Build content array with files for a message.
     *
     * @param string|array $text_content The text content.
     * @param array        $files        The files to add.
     * @return array Content array in OpenAI format.
     */
    private function build_content_with_files($text_content, array $files): array {
        $content = array();

        // Add text content first
        if (is_string($text_content)) {
            $content[] = array(
                'type' => 'text',
                'text' => $text_content,
            );
        } elseif (is_array($text_content)) {
            // Already in content array format
            $content = $text_content;
        }

        // Add each file
        foreach ($files as $file) {
            $file_type = $this->get_file_type_from_mime($file['mime_type']);

            switch ($file_type) {
                case Chatbot_Vision_Capability::FILE_TYPE_IMAGE:
                    $content[] = $this->build_image_content($file);
                    break;

                case Chatbot_Vision_Capability::FILE_TYPE_PDF:
                    $content = $this->add_pdf_content($content, $file);
                    break;

                case Chatbot_Vision_Capability::FILE_TYPE_DOCUMENT:
                case Chatbot_Vision_Capability::FILE_TYPE_SPREADSHEET:
                    $content = $this->add_document_content($content, $file);
                    break;
            }
        }

        return $content;
    }

    /**
     * Build image content for OpenAI/LiteLLM format.
     *
     * @param array $file The file data.
     * @return array Image content block.
     */
    private function build_image_content(array $file): array {
        if (!empty($file['url'])) {
            $image_url = array('url' => $file['url']);
            // Only add detail if explicitly provided (OpenAI-specific, not all models support it)
            if (!empty($file['detail'])) {
                $image_url['detail'] = $file['detail'];
            }
            return array(
                'type' => 'image_url',
                'image_url' => $image_url,
            );
        }

        // Use base64 data URL - standard LiteLLM/OpenAI format
        $data_url = 'data:' . $file['mime_type'] . ';base64,' . $file['data'];

        $image_url = array('url' => $data_url);
        // Only add detail if explicitly provided
        if (!empty($file['detail'])) {
            $image_url['detail'] = $file['detail'];
        }

        return array(
            'type' => 'image_url',
            'image_url' => $image_url,
        );
    }

    /**
     * Add PDF content to the content array.
     *
     * Currently, PDFs are handled by informing the AI about the attached file.
     * For text extraction, consider using a dedicated PDF parsing library.
     *
     * @param array $content Existing content array.
     * @param array $file    The PDF file data.
     * @return array Updated content array.
     */
    private function add_pdf_content(array $content, array $file): array {
        $filename = $file['name'] ?? 'document.pdf';

        // Add a text description about the PDF
        // Note: Full PDF parsing would require additional libraries
        array_unshift($content, array(
            'type' => 'text',
            'text' => "[Attached PDF file: {$filename}]\n\nNote: PDF content analysis is limited. For best results, please copy and paste the relevant text from the PDF, or attach an image/screenshot of the content you want analyzed.",
        ));

        return $content;
    }

    /**
     * Add document/spreadsheet content to the content array.
     *
     * @param array $content Existing content array.
     * @param array $file    The document file data.
     * @return array Updated content array.
     */
    private function add_document_content(array $content, array $file): array {
        $filename = $file['name'] ?? 'document';
        $file_type = $this->get_file_type_from_mime($file['mime_type']);

        // For text-based files, try to decode and include as text
        if (in_array($file['mime_type'], array('text/plain', 'text/csv', 'text/html', 'text/markdown'), true)) {
            if (!empty($file['data'])) {
                $text_content = base64_decode($file['data']);
                if ($text_content !== false) {
                    // Truncate if too long
                    if (strlen($text_content) > 100000) {
                        $text_content = substr($text_content, 0, 100000) . "\n\n[Content truncated...]";
                    }

                    array_unshift($content, array(
                        'type' => 'text',
                        'text' => "--- Content of {$filename} ---\n\n{$text_content}\n\n--- End of {$filename} ---",
                    ));
                    return $content;
                }
            }
        }

        // For binary documents (Word, Excel), add informational text
        // Note: Full binary document parsing would require additional libraries
        $type_label = $file_type === Chatbot_Vision_Capability::FILE_TYPE_SPREADSHEET ? 'Spreadsheet' : 'Document';
        array_unshift($content, array(
            'type' => 'text',
            'text' => "[Attached {$type_label}: {$filename}]\n\nNote: Binary document analysis is limited. For best results with Word or Excel files, please copy and paste the relevant content as text, or save as CSV/TXT and attach that instead.",
        ));

        return $content;
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Extract error message from API response.
     *
     * @param array $response The API response.
     * @return string The error message.
     */
    private function extract_error_message(array $response): string {
        if (isset($response['error'])) {
            if (is_array($response['error'])) {
                return $response['error']['message'] ?? wp_json_encode($response['error']);
            }
            return $response['error'];
        }
        return 'Unknown error';
    }

    /**
     * Extract error type from API response.
     *
     * @param array $response The API response.
     * @return string|null The error type or null.
     */
    private function extract_error_type(array $response): ?string {
        if (isset($response['error']) && is_array($response['error'])) {
            return $response['error']['type'] ?? null;
        }
        return null;
    }

    /**
     * Clear the models cache.
     *
     * @return void
     */
    public function clear_models_cache(): void {
        $this->models_cache = null;
    }

    /**
     * Get balance information from AIPass.
     *
     * @return array Balance info with remainingBudget, totalCost, maxBudget.
     */
    public function get_balance(): array {
        if (!$this->is_connected()) {
            return array(
                'success' => false,
                'error' => 'Not connected',
            );
        }

        $response = $this->api_client->authenticated_get(
            '/api/v1/usage/me/summary',
            $this->token_manager->get_access_token()
        );

        if ($response === null || !$this->api_client->was_successful()) {
            return array(
                'success' => false,
                'error' => $this->api_client->get_last_error() ?? 'Failed to fetch balance',
            );
        }

        return array(
            'success' => true,
            'remainingBudget' => $response['remainingBudget'] ?? 0,
            'totalCost' => $response['totalCost'] ?? 0,
            'maxBudget' => $response['maxBudget'] ?? 0,
        );
    }
}
