<?php
/**
 * Vision Capability Interface
 *
 * Defines the contract for AI vision/file understanding capabilities.
 * Supports images, PDFs, and document analysis.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Interface Chatbot_Vision_Capability
 *
 * Contract for AI providers that support vision/file analysis.
 */
interface Chatbot_Vision_Capability {

    /**
     * Supported file type constants.
     */
    public const FILE_TYPE_IMAGE = 'image';
    public const FILE_TYPE_PDF = 'pdf';
    public const FILE_TYPE_DOCUMENT = 'document';
    public const FILE_TYPE_SPREADSHEET = 'spreadsheet';

    /**
     * Analyze a file and generate a response.
     *
     * @param array  $file    File data array with keys:
     *                        - type: string File type (image, pdf, document, spreadsheet)
     *                        - data: string Base64 encoded file data
     *                        - url: string URL to file (alternative to data)
     *                        - mime_type: string MIME type of the file
     *                        - name: string Original filename
     * @param string $prompt  The prompt/question about the file.
     * @param array  $options Optional parameters:
     *                        - model: string The model to use
     *                        - max_tokens: int Maximum tokens in response
     *                        - detail: string Level of detail (low, high, auto)
     * @return array Result array with keys:
     *               - success: bool Whether the request succeeded
     *               - content: string The analysis result (if success)
     *               - error: string Error message (if !success)
     */
    public function analyze_file(array $file, string $prompt, array $options = []): array;

    /**
     * Analyze multiple files together with a prompt.
     *
     * @param array  $files   Array of file data arrays (same structure as analyze_file).
     * @param string $prompt  The prompt/question about the files.
     * @param array  $options Optional parameters.
     * @return array Result array with success, content, or error.
     */
    public function analyze_files(array $files, string $prompt, array $options = []): array;

    /**
     * Generate a chat completion with file attachments.
     *
     * This integrates files into a regular chat completion flow,
     * allowing conversation context with file analysis.
     *
     * @param array  $messages Conversation messages (OpenAI format).
     * @param array  $files    Array of file data arrays to include.
     * @param string $model    The model to use.
     * @param array  $options  Additional options.
     * @return array Result array with success, content, tool_calls, or error.
     */
    public function generate_completion_with_files(
        array $messages,
        array $files,
        string $model,
        array $options = []
    ): array;

    /**
     * Check if vision/file capability is currently available.
     *
     * @return bool True if file analysis can be used.
     */
    public function is_vision_available(): bool;

    /**
     * Get available models that support vision/files.
     *
     * @return array Array of model identifiers available for vision.
     */
    public function get_vision_models(): array;

    /**
     * Get supported file types and their MIME types.
     *
     * @return array Associative array of file types to MIME type arrays:
     *               ['image' => ['image/jpeg', 'image/png', ...], ...]
     */
    public function get_supported_file_types(): array;

    /**
     * Get maximum file size in bytes for a given type.
     *
     * @param string $file_type The file type (image, pdf, document, spreadsheet).
     * @return int Maximum file size in bytes.
     */
    public function get_max_file_size(string $file_type): int;

    /**
     * Check if a specific file type is supported.
     *
     * @param string $mime_type The MIME type to check.
     * @return bool True if the file type is supported.
     */
    public function is_file_type_supported(string $mime_type): bool;

    /**
     * Get the file type category from a MIME type.
     *
     * @param string $mime_type The MIME type.
     * @return string|null The file type category or null if unsupported.
     */
    public function get_file_type_from_mime(string $mime_type): ?string;
}
