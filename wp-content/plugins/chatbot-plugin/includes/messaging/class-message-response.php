<?php
/**
 * Message Response
 *
 * Represents the response from message processing.
 * Contains the AI response and metadata about the processing.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_Message_Response
 *
 * Immutable response object from message processing.
 */
class Chatbot_Message_Response {

    /**
     * Whether the response was successful.
     *
     * @var bool
     */
    private $success;

    /**
     * The response message content.
     *
     * @var string
     */
    private $message;

    /**
     * Error message if not successful.
     *
     * @var string|null
     */
    private $error;

    /**
     * Error type for categorization.
     *
     * @var string|null
     */
    private $error_type;

    /**
     * The conversation ID.
     *
     * @var int|null
     */
    private $conversation_id;

    /**
     * The message ID (saved message).
     *
     * @var int|null
     */
    private $message_id;

    /**
     * The AI model used.
     *
     * @var string|null
     */
    private $model;

    /**
     * Token usage statistics.
     *
     * @var array|null
     */
    private $usage;

    /**
     * Processing time in milliseconds.
     *
     * @var float
     */
    private $processing_time;

    /**
     * Tool calls requested by AI.
     *
     * @var array|null
     */
    private $tool_calls;

    /**
     * Additional metadata.
     *
     * @var array
     */
    private $metadata;

    /**
     * Constructor.
     *
     * @param bool        $success         Whether successful.
     * @param string      $message         Response message or error.
     * @param string|null $error           Error message.
     * @param string|null $error_type      Error type.
     * @param int|null    $conversation_id Conversation ID.
     * @param int|null    $message_id      Message ID.
     * @param string|null $model           AI model used.
     * @param array|null  $usage           Token usage.
     * @param float       $processing_time Processing time in ms.
     * @param array|null  $tool_calls      Tool calls from AI.
     * @param array       $metadata        Additional metadata.
     */
    public function __construct(
        bool $success,
        string $message = '',
        ?string $error = null,
        ?string $error_type = null,
        ?int $conversation_id = null,
        ?int $message_id = null,
        ?string $model = null,
        ?array $usage = null,
        float $processing_time = 0,
        ?array $tool_calls = null,
        array $metadata = []
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->error = $error;
        $this->error_type = $error_type;
        $this->conversation_id = $conversation_id;
        $this->message_id = $message_id;
        $this->model = $model;
        $this->usage = $usage;
        $this->processing_time = $processing_time;
        $this->tool_calls = $tool_calls;
        $this->metadata = $metadata;
    }

    /**
     * Create a successful response.
     *
     * @param string      $message         The response message.
     * @param int|null    $conversation_id Conversation ID.
     * @param int|null    $message_id      Message ID.
     * @param string|null $model           AI model used.
     * @param array|null  $usage           Token usage.
     * @param float       $processing_time Processing time in ms.
     * @return self
     */
    public static function success(
        string $message,
        ?int $conversation_id = null,
        ?int $message_id = null,
        ?string $model = null,
        ?array $usage = null,
        float $processing_time = 0
    ): self {
        return new self(
            true,
            $message,
            null,
            null,
            $conversation_id,
            $message_id,
            $model,
            $usage,
            $processing_time
        );
    }

    /**
     * Create an error response.
     *
     * @param string      $error      The error message.
     * @param string|null $error_type The error type.
     * @param array       $metadata   Additional metadata.
     * @return self
     */
    public static function error(string $error, ?string $error_type = null, array $metadata = []): self {
        return new self(
            false,
            '',
            $error,
            $error_type,
            null,
            null,
            null,
            null,
            0,
            null,
            $metadata
        );
    }

    /**
     * Create a response with tool calls.
     *
     * @param array       $tool_calls      The tool calls.
     * @param string|null $message         Optional message.
     * @param int|null    $conversation_id Conversation ID.
     * @param string|null $model           AI model used.
     * @param array|null  $usage           Token usage.
     * @return self
     */
    public static function with_tool_calls(
        array $tool_calls,
        ?string $message = null,
        ?int $conversation_id = null,
        ?string $model = null,
        ?array $usage = null
    ): self {
        return new self(
            true,
            $message ?? '',
            null,
            null,
            $conversation_id,
            null,
            $model,
            $usage,
            0,
            $tool_calls
        );
    }

    /**
     * Check if response was successful.
     *
     * @return bool
     */
    public function is_success(): bool {
        return $this->success;
    }

    /**
     * Get the response message.
     *
     * @return string
     */
    public function get_message(): string {
        return $this->message;
    }

    /**
     * Get the error message.
     *
     * @return string|null
     */
    public function get_error(): ?string {
        return $this->error;
    }

    /**
     * Get the error type.
     *
     * @return string|null
     */
    public function get_error_type(): ?string {
        return $this->error_type;
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
     * @return self New instance.
     */
    public function with_conversation_id(int $id): self {
        $clone = clone $this;
        $clone->conversation_id = $id;
        return $clone;
    }

    /**
     * Get the message ID.
     *
     * @return int|null
     */
    public function get_message_id(): ?int {
        return $this->message_id;
    }

    /**
     * Set the message ID.
     *
     * @param int $id The message ID.
     * @return self New instance.
     */
    public function with_message_id(int $id): self {
        $clone = clone $this;
        $clone->message_id = $id;
        return $clone;
    }

    /**
     * Get the AI model used.
     *
     * @return string|null
     */
    public function get_model(): ?string {
        return $this->model;
    }

    /**
     * Get token usage statistics.
     *
     * @return array|null
     */
    public function get_usage(): ?array {
        return $this->usage;
    }

    /**
     * Get processing time in milliseconds.
     *
     * @return float
     */
    public function get_processing_time(): float {
        return $this->processing_time;
    }

    /**
     * Set processing time.
     *
     * @param float $time Time in milliseconds.
     * @return self New instance.
     */
    public function with_processing_time(float $time): self {
        $clone = clone $this;
        $clone->processing_time = $time;
        return $clone;
    }

    /**
     * Check if response has tool calls.
     *
     * @return bool
     */
    public function has_tool_calls(): bool {
        return !empty($this->tool_calls);
    }

    /**
     * Get tool calls.
     *
     * @return array|null
     */
    public function get_tool_calls(): ?array {
        return $this->tool_calls;
    }

    /**
     * Get metadata value.
     *
     * @param string $key     The key.
     * @param mixed  $default Default value.
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
     * @param string $key   The key.
     * @param mixed  $value The value.
     * @return self New instance.
     */
    public function with_metadata(string $key, $value): self {
        $clone = clone $this;
        $clone->metadata[$key] = $value;
        return $clone;
    }

    /**
     * Check if this is a rate limit error.
     *
     * @return bool
     */
    public function is_rate_limited(): bool {
        return $this->error_type === 'rate_limit';
    }

    /**
     * Check if this is a budget exceeded error.
     *
     * @return bool
     */
    public function is_budget_exceeded(): bool {
        return $this->error_type === 'budget_exceeded';
    }

    /**
     * Convert to array.
     *
     * @return array
     */
    public function to_array(): array {
        $result = [
            'success' => $this->success,
            'message' => $this->message,
        ];

        if ($this->error !== null) {
            $result['error'] = $this->error;
        }
        if ($this->error_type !== null) {
            $result['error_type'] = $this->error_type;
        }
        if ($this->conversation_id !== null) {
            $result['conversation_id'] = $this->conversation_id;
        }
        if ($this->message_id !== null) {
            $result['message_id'] = $this->message_id;
        }
        if ($this->model !== null) {
            $result['model'] = $this->model;
        }
        if ($this->usage !== null) {
            $result['usage'] = $this->usage;
        }
        if ($this->processing_time > 0) {
            $result['processing_time'] = $this->processing_time;
        }
        if (!empty($this->tool_calls)) {
            $result['tool_calls'] = $this->tool_calls;
        }
        if (!empty($this->metadata)) {
            $result['metadata'] = $this->metadata;
        }

        return $result;
    }
}
