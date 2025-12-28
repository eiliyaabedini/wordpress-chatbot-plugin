<?php
/**
 * Message Context
 *
 * Represents the context of an incoming message for processing.
 * Contains all information needed to process a message across any platform.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_Message_Context
 *
 * Immutable context object for message processing.
 */
class Chatbot_Message_Context {

    /**
     * The message content.
     *
     * @var string
     */
    private $message;

    /**
     * The conversation ID.
     *
     * @var int|null
     */
    private $conversation_id;

    /**
     * The chatbot configuration ID.
     *
     * @var int|null
     */
    private $config_id;

    /**
     * The chatbot configuration object.
     *
     * @var object|null
     */
    private $config;

    /**
     * The platform type (web, telegram, whatsapp).
     *
     * @var string
     */
    private $platform;

    /**
     * Platform-specific chat identifier.
     *
     * @var string|null
     */
    private $platform_chat_id;

    /**
     * The visitor/user name.
     *
     * @var string
     */
    private $visitor_name;

    /**
     * Additional metadata.
     *
     * @var array
     */
    private $metadata;

    /**
     * Conversation history (recent messages).
     *
     * @var array
     */
    private $history;

    /**
     * File attachments.
     *
     * Each file is an array with:
     * - type: string (image, pdf, document, spreadsheet)
     * - data: string Base64 encoded data
     * - url: string URL to file (alternative to data)
     * - mime_type: string MIME type
     * - name: string Original filename
     *
     * @var array
     */
    private $files;

    /**
     * Processing start timestamp.
     *
     * @var float
     */
    private $start_time;

    /**
     * Constructor.
     *
     * @param string      $message          The message content.
     * @param string      $platform         The platform type.
     * @param string      $visitor_name     The visitor name.
     * @param int|null    $conversation_id  The conversation ID.
     * @param int|null    $config_id        The configuration ID.
     * @param string|null $platform_chat_id Platform-specific chat ID.
     * @param array       $metadata         Additional metadata.
     * @param array       $files            File attachments.
     */
    public function __construct(
        string $message,
        string $platform = 'web',
        string $visitor_name = 'Visitor',
        ?int $conversation_id = null,
        ?int $config_id = null,
        ?string $platform_chat_id = null,
        array $metadata = [],
        array $files = []
    ) {
        $this->message = $message;
        $this->platform = $platform;
        $this->visitor_name = $visitor_name;
        $this->conversation_id = $conversation_id;
        $this->config_id = $config_id;
        $this->platform_chat_id = $platform_chat_id;
        $this->metadata = $metadata;
        $this->files = $files;
        $this->history = [];
        $this->config = null;
        $this->start_time = microtime(true);
    }

    /**
     * Get the message content.
     *
     * @return string
     */
    public function get_message(): string {
        return $this->message;
    }

    /**
     * Get the conversation ID.
     *
     * @return int|null
     */
    public function get_conversation_id(): ?int {
        return $this->conversation_id;
    }

    /**
     * Set the conversation ID.
     *
     * @param int $id The conversation ID.
     * @return self New instance with updated ID.
     */
    public function with_conversation_id(int $id): self {
        $clone = clone $this;
        $clone->conversation_id = $id;
        return $clone;
    }

    /**
     * Get the configuration ID.
     *
     * @return int|null
     */
    public function get_config_id(): ?int {
        return $this->config_id;
    }

    /**
     * Set the configuration ID.
     *
     * @param int $id The configuration ID.
     * @return self New instance with updated ID.
     */
    public function with_config_id(int $id): self {
        $clone = clone $this;
        $clone->config_id = $id;
        return $clone;
    }

    /**
     * Get the chatbot configuration.
     *
     * @return object|null
     */
    public function get_config(): ?object {
        return $this->config;
    }

    /**
     * Set the chatbot configuration.
     *
     * @param object $config The configuration object.
     * @return self New instance with configuration.
     */
    public function with_config(object $config): self {
        $clone = clone $this;
        $clone->config = $config;
        $clone->config_id = $config->id ?? $clone->config_id;
        return $clone;
    }

    /**
     * Get the platform type.
     *
     * @return string
     */
    public function get_platform(): string {
        return $this->platform;
    }

    /**
     * Get the platform chat ID.
     *
     * @return string|null
     */
    public function get_platform_chat_id(): ?string {
        return $this->platform_chat_id;
    }

    /**
     * Get the visitor name.
     *
     * @return string
     */
    public function get_visitor_name(): string {
        return $this->visitor_name;
    }

    /**
     * Get metadata value.
     *
     * @param string $key     The metadata key.
     * @param mixed  $default Default value if not found.
     * @return mixed
     */
    public function get_metadata(string $key, $default = null) {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Get all metadata.
     *
     * @return array
     */
    public function get_all_metadata(): array {
        return $this->metadata;
    }

    /**
     * Set metadata value.
     *
     * @param string $key   The metadata key.
     * @param mixed  $value The value.
     * @return self New instance with metadata.
     */
    public function with_metadata(string $key, $value): self {
        $clone = clone $this;
        $clone->metadata[$key] = $value;
        return $clone;
    }

    /**
     * Get conversation history.
     *
     * @return array
     */
    public function get_history(): array {
        return $this->history;
    }

    /**
     * Set conversation history.
     *
     * @param array $history The conversation history.
     * @return self New instance with history.
     */
    public function with_history(array $history): self {
        $clone = clone $this;
        $clone->history = $history;
        return $clone;
    }

    /**
     * Get file attachments.
     *
     * @return array
     */
    public function get_files(): array {
        return $this->files;
    }

    /**
     * Check if context has file attachments.
     *
     * @return bool
     */
    public function has_files(): bool {
        return !empty($this->files);
    }

    /**
     * Get file count.
     *
     * @return int
     */
    public function get_file_count(): int {
        return count($this->files);
    }

    /**
     * Set file attachments.
     *
     * @param array $files The file attachments.
     * @return self New instance with files.
     */
    public function with_files(array $files): self {
        $clone = clone $this;
        $clone->files = $files;
        return $clone;
    }

    /**
     * Add a file attachment.
     *
     * @param array $file The file data.
     * @return self New instance with added file.
     */
    public function with_file(array $file): self {
        $clone = clone $this;
        $clone->files[] = $file;
        return $clone;
    }

    /**
     * Get files of a specific type.
     *
     * @param string $type The file type (image, pdf, document, spreadsheet).
     * @return array Files matching the type.
     */
    public function get_files_by_type(string $type): array {
        return array_filter($this->files, function ($file) use ($type) {
            return ($file['type'] ?? '') === $type;
        });
    }

    /**
     * Check if context has files of a specific type.
     *
     * @param string $type The file type.
     * @return bool
     */
    public function has_file_type(string $type): bool {
        return !empty($this->get_files_by_type($type));
    }

    /**
     * Get processing time in milliseconds.
     *
     * @return float
     */
    public function get_processing_time(): float {
        return (microtime(true) - $this->start_time) * 1000;
    }

    /**
     * Check if this is a web platform request.
     *
     * @return bool
     */
    public function is_web(): bool {
        return $this->platform === 'web';
    }

    /**
     * Check if this is a Telegram request.
     *
     * @return bool
     */
    public function is_telegram(): bool {
        return $this->platform === 'telegram';
    }

    /**
     * Check if this is a WhatsApp request.
     *
     * @return bool
     */
    public function is_whatsapp(): bool {
        return $this->platform === 'whatsapp';
    }

    /**
     * Create context from array data.
     *
     * @param array $data The data array.
     * @return self
     */
    public static function from_array(array $data): self {
        return new self(
            $data['message'] ?? '',
            $data['platform'] ?? 'web',
            $data['visitor_name'] ?? 'Visitor',
            $data['conversation_id'] ?? null,
            $data['config_id'] ?? null,
            $data['platform_chat_id'] ?? null,
            $data['metadata'] ?? [],
            $data['files'] ?? []
        );
    }

    /**
     * Convert to array.
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'message' => $this->message,
            'platform' => $this->platform,
            'visitor_name' => $this->visitor_name,
            'conversation_id' => $this->conversation_id,
            'config_id' => $this->config_id,
            'platform_chat_id' => $this->platform_chat_id,
            'metadata' => $this->metadata,
            'files' => $this->files,
        ];
    }
}
