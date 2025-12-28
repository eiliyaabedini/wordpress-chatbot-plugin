<?php
/**
 * Web Platform
 *
 * Handles web-based chat as a messaging platform.
 * Unifies web chat handling with other platforms.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_Platform_Web
 *
 * Web platform implementation for chat widget.
 */
class Chatbot_Platform_Web {

    /**
     * Platform identifier.
     */
    public const PLATFORM_ID = 'web';

    /**
     * Message pipeline.
     *
     * @var Chatbot_Message_Pipeline|null
     */
    private $pipeline = null;

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_chatbot_send_message_v2', [$this, 'handle_send_message']);
        add_action('wp_ajax_nopriv_chatbot_send_message_v2', [$this, 'handle_send_message']);
    }

    /**
     * Set the message pipeline.
     *
     * @param Chatbot_Message_Pipeline $pipeline The pipeline.
     * @return void
     */
    public function set_pipeline(Chatbot_Message_Pipeline $pipeline): void {
        $this->pipeline = $pipeline;
    }

    /**
     * Get or create the message pipeline.
     *
     * @return Chatbot_Message_Pipeline|null
     */
    public function get_pipeline(): ?Chatbot_Message_Pipeline {
        if ($this->pipeline === null) {
            $this->pipeline = $this->create_pipeline();
        }
        return $this->pipeline;
    }

    /**
     * Get the pipeline from the container or create a fallback.
     *
     * @return Chatbot_Message_Pipeline|null
     */
    private function create_pipeline(): ?Chatbot_Message_Pipeline {
        try {
            $container = chatbot_container();

            // Try to get from container first (already configured with middleware)
            if ($container->has('Chatbot_Message_Pipeline')) {
                return $container->make('Chatbot_Message_Pipeline');
            }

            // Fallback: create manually if container doesn't have it
            $pipeline = new Chatbot_Message_Pipeline(
                $container->make('Chatbot_AI_Service'),
                $container->make('Chatbot_Message_Repository'),
                $container->make('Chatbot_Conversation_Repository'),
                $container->make('Chatbot_Configuration_Repository')
            );

            // Add default middleware
            $pipeline->add_middleware(new Chatbot_Validation_Middleware());
            $pipeline->add_middleware(new Chatbot_Rate_Limit_Middleware());

            return $pipeline;

        } catch (Exception $e) {
            if (function_exists('chatbot_log')) {
                chatbot_log('ERROR', 'platform_web', 'Failed to create pipeline: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Handle send message AJAX request.
     *
     * @return void
     */
    public function handle_send_message(): void {
        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'platform_web', 'handle_send_message called', [
                'has_files' => !empty($_FILES),
                'files_keys' => array_keys($_FILES),
                'post_keys' => array_keys($_POST),
            ]);
        }

        // Verify nonce
        if (!check_ajax_referer('chatbot-plugin-nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }

        // Get message from request
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : null;
        $config_id = isset($_POST['chatbot_config_id']) ? intval($_POST['chatbot_config_id']) : null;
        $visitor_name = isset($_POST['visitor_name']) ? sanitize_text_field($_POST['visitor_name']) : 'Visitor';

        if ($conversation_id === 0) {
            $conversation_id = null;
        }
        if ($config_id === 0) {
            $config_id = null;
        }

        // Process file uploads
        $files = $this->process_uploaded_files();

        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'platform_web', 'Files processed', [
                'file_count' => count($files),
                'file_types' => array_map(function($f) { return $f['type'] ?? 'unknown'; }, $files),
                'has_data' => array_map(function($f) { return !empty($f['data']); }, $files),
            ]);
        }

        // Create message context with files
        $context = new Chatbot_Message_Context(
            $message,
            self::PLATFORM_ID,
            $visitor_name,
            $conversation_id,
            $config_id,
            null, // No platform chat ID for web
            ['source' => 'ajax'],
            $files
        );

        // Process through pipeline
        $pipeline = $this->get_pipeline();

        if ($pipeline === null) {
            // Fallback to legacy handler if pipeline not available
            wp_send_json_error(['message' => 'Service unavailable']);
            return;
        }

        $response = $pipeline->process($context);

        // Convert response to AJAX format
        if ($response->is_success()) {
            wp_send_json_success([
                'message' => $response->get_message(),
                'conversation_id' => $response->get_conversation_id(),
                'message_id' => $response->get_message_id(),
                'model' => $response->get_model(),
                'usage' => $response->get_usage(),
                'processing_time' => $response->get_processing_time(),
            ]);
        } else {
            wp_send_json_error([
                'message' => $response->get_error(),
                'error_type' => $response->get_error_type(),
            ]);
        }
    }

    /**
     * Process uploaded files from the request.
     *
     * @return array Array of processed file data.
     */
    private function process_uploaded_files(): array {
        $files = [];

        // Check for uploaded files
        if (empty($_FILES['files'])) {
            if (function_exists('chatbot_log')) {
                chatbot_log('DEBUG', 'platform_web', 'No files in $_FILES[files]', [
                    'all_files_keys' => array_keys($_FILES),
                ]);
            }
            return $files;
        }

        $uploaded_files = $_FILES['files'];

        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'platform_web', 'Received file upload', [
                'names' => is_array($uploaded_files['name']) ? $uploaded_files['name'] : [$uploaded_files['name']],
                'types' => is_array($uploaded_files['type']) ? $uploaded_files['type'] : [$uploaded_files['type']],
                'errors' => is_array($uploaded_files['error']) ? $uploaded_files['error'] : [$uploaded_files['error']],
            ]);
        }

        // Handle both single and multiple file uploads
        if (!is_array($uploaded_files['name'])) {
            $uploaded_files = [
                'name' => [$uploaded_files['name']],
                'type' => [$uploaded_files['type']],
                'tmp_name' => [$uploaded_files['tmp_name']],
                'error' => [$uploaded_files['error']],
                'size' => [$uploaded_files['size']],
            ];
        }

        $file_count = count($uploaded_files['name']);

        for ($i = 0; $i < $file_count; $i++) {
            // Skip files with errors
            if ($uploaded_files['error'][$i] !== UPLOAD_ERR_OK) {
                if (function_exists('chatbot_log')) {
                    chatbot_log('WARN', 'platform_web', 'File upload error', [
                        'file' => $uploaded_files['name'][$i],
                        'error' => $uploaded_files['error'][$i],
                    ]);
                }
                continue;
            }

            $file_data = $this->process_single_file(
                $uploaded_files['tmp_name'][$i],
                $uploaded_files['name'][$i],
                $uploaded_files['type'][$i],
                $uploaded_files['size'][$i]
            );

            if ($file_data !== null) {
                $files[] = $file_data;
            }
        }

        if (function_exists('chatbot_log') && !empty($files)) {
            chatbot_log('INFO', 'platform_web', 'Processed file uploads', [
                'file_count' => count($files),
            ]);
        }

        return $files;
    }

    /**
     * Process a single uploaded file.
     *
     * @param string $tmp_path  Temporary file path.
     * @param string $name      Original filename.
     * @param string $mime_type MIME type.
     * @param int    $size      File size in bytes.
     * @return array|null Processed file data or null on error.
     */
    private function process_single_file(string $tmp_path, string $name, string $mime_type, int $size): ?array {
        // Validate file type
        $file_type = $this->get_file_type_from_mime($mime_type);
        if ($file_type === null) {
            if (function_exists('chatbot_log')) {
                chatbot_log('WARN', 'platform_web', 'Unsupported file type', [
                    'mime_type' => $mime_type,
                    'name' => $name,
                ]);
            }
            return null;
        }

        // Check file size (max 50MB)
        $max_size = 50 * 1024 * 1024;
        if ($size > $max_size) {
            if (function_exists('chatbot_log')) {
                chatbot_log('WARN', 'platform_web', 'File too large', [
                    'size' => $size,
                    'max' => $max_size,
                    'name' => $name,
                ]);
            }
            return null;
        }

        // Read and encode file content
        $content = file_get_contents($tmp_path);
        if ($content === false) {
            if (function_exists('chatbot_log')) {
                chatbot_log('ERROR', 'platform_web', 'Failed to read file', [
                    'tmp_path' => $tmp_path,
                    'name' => $name,
                ]);
            }
            return null;
        }

        $base64_data = base64_encode($content);

        return [
            'type' => $file_type,
            'data' => $base64_data,
            'mime_type' => $mime_type,
            'name' => sanitize_file_name($name),
        ];
    }

    /**
     * Get file type from MIME type.
     *
     * @param string $mime_type The MIME type.
     * @return string|null The file type or null if unsupported.
     */
    private function get_file_type_from_mime(string $mime_type): ?string {
        $mime_type = strtolower($mime_type);

        // Image types
        if (strpos($mime_type, 'image/') === 0) {
            $supported = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            return in_array($mime_type, $supported, true) ? 'image' : null;
        }

        // PDF
        if ($mime_type === 'application/pdf') {
            return 'pdf';
        }

        // Documents
        $document_types = [
            'text/plain',
            'text/csv',
            'text/html',
            'text/markdown',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        if (in_array($mime_type, $document_types, true)) {
            return 'document';
        }

        // Spreadsheets
        $spreadsheet_types = [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        if (in_array($mime_type, $spreadsheet_types, true)) {
            return 'spreadsheet';
        }

        return null;
    }

    /**
     * Process a message directly (for internal use).
     *
     * @param string   $message         The message content.
     * @param string   $visitor_name    The visitor name.
     * @param int|null $conversation_id The conversation ID.
     * @param int|null $config_id       The configuration ID.
     * @return Chatbot_Message_Response
     */
    public function process_message(
        string $message,
        string $visitor_name = 'Visitor',
        ?int $conversation_id = null,
        ?int $config_id = null
    ): Chatbot_Message_Response {
        $context = new Chatbot_Message_Context(
            $message,
            self::PLATFORM_ID,
            $visitor_name,
            $conversation_id,
            $config_id
        );

        $pipeline = $this->get_pipeline();

        if ($pipeline === null) {
            return Chatbot_Message_Response::error('Pipeline not available', 'service_error');
        }

        return $pipeline->process($context);
    }

    /**
     * Get platform identifier.
     *
     * @return string
     */
    public function get_platform_id(): string {
        return self::PLATFORM_ID;
    }

    /**
     * Check if the platform is available.
     *
     * @return bool
     */
    public function is_available(): bool {
        $pipeline = $this->get_pipeline();
        return $pipeline !== null && $pipeline->get_ai_service()->is_available();
    }
}
