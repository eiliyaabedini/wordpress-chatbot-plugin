<?php
/**
 * Message Pipeline
 *
 * Orchestrates message processing through middleware chain.
 * Provides unified message handling for all platforms.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_Message_Pipeline
 *
 * Pipeline for processing messages through middleware chain.
 */
class Chatbot_Message_Pipeline {

    /**
     * Registered middleware.
     *
     * @var array
     */
    private $middleware = [];

    /**
     * Sorted middleware cache.
     *
     * @var array|null
     */
    private $sorted_middleware = null;

    /**
     * AI service for generating responses.
     *
     * @var Chatbot_AI_Service
     */
    private $ai_service;

    /**
     * Message repository.
     *
     * @var Chatbot_Message_Repository
     */
    private $message_repository;

    /**
     * Conversation repository.
     *
     * @var Chatbot_Conversation_Repository
     */
    private $conversation_repository;

    /**
     * Configuration repository.
     *
     * @var Chatbot_Configuration_Repository
     */
    private $config_repository;

    /**
     * Constructor.
     *
     * @param Chatbot_AI_Service               $ai_service             The AI service.
     * @param Chatbot_Message_Repository       $message_repository     Message repository.
     * @param Chatbot_Conversation_Repository  $conversation_repository Conversation repository.
     * @param Chatbot_Configuration_Repository $config_repository      Configuration repository.
     */
    public function __construct(
        Chatbot_AI_Service $ai_service,
        Chatbot_Message_Repository $message_repository,
        Chatbot_Conversation_Repository $conversation_repository,
        Chatbot_Configuration_Repository $config_repository
    ) {
        $this->ai_service = $ai_service;
        $this->message_repository = $message_repository;
        $this->conversation_repository = $conversation_repository;
        $this->config_repository = $config_repository;
    }

    /**
     * Add middleware to the pipeline.
     *
     * @param Chatbot_Message_Middleware $middleware The middleware.
     * @return self
     */
    public function add_middleware(Chatbot_Message_Middleware $middleware): self {
        $this->middleware[] = $middleware;
        $this->sorted_middleware = null; // Clear cache
        return $this;
    }

    /**
     * Process a message through the pipeline.
     *
     * @param Chatbot_Message_Context $context The message context.
     * @return Chatbot_Message_Response The response.
     */
    public function process(Chatbot_Message_Context $context): Chatbot_Message_Response {
        $start_time = microtime(true);

        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'message_pipeline', 'Processing message', [
                'platform' => $context->get_platform(),
                'message_length' => strlen($context->get_message()),
                'conversation_id' => $context->get_conversation_id(),
            ]);
        }

        try {
            // Load configuration if needed
            $context = $this->ensure_configuration($context);

            // Load conversation history
            $context = $this->load_conversation_history($context);

            // Build the middleware chain
            $pipeline = $this->build_pipeline();

            // Execute the pipeline
            $response = $pipeline($context);

            // Add processing time
            $processing_time = (microtime(true) - $start_time) * 1000;
            $response = $response->with_processing_time($processing_time);

            if (function_exists('chatbot_log')) {
                chatbot_log('INFO', 'message_pipeline', 'Message processed', [
                    'success' => $response->is_success(),
                    'processing_time_ms' => round($processing_time, 2),
                ]);
            }

            return $response;

        } catch (Exception $e) {
            if (function_exists('chatbot_log')) {
                chatbot_log('ERROR', 'message_pipeline', 'Pipeline error: ' . $e->getMessage());
            }

            return Chatbot_Message_Response::error(
                'An error occurred while processing your message.',
                'pipeline_error',
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Build the middleware pipeline.
     *
     * @return callable The pipeline function.
     */
    private function build_pipeline(): callable {
        // Sort middleware by priority
        if ($this->sorted_middleware === null) {
            $this->sorted_middleware = $this->middleware;
            usort($this->sorted_middleware, function ($a, $b) {
                return $a->get_priority() - $b->get_priority();
            });
        }

        // Build the chain from inside out (core handler is innermost)
        $pipeline = function (Chatbot_Message_Context $context): Chatbot_Message_Response {
            return $this->core_handler($context);
        };

        // Wrap with middleware (reverse order so first runs first)
        foreach (array_reverse($this->sorted_middleware) as $middleware) {
            $pipeline = function (Chatbot_Message_Context $context) use ($middleware, $pipeline): Chatbot_Message_Response {
                return $middleware->process($context, $pipeline);
            };
        }

        return $pipeline;
    }

    /**
     * Core message handler - generates AI response.
     *
     * @param Chatbot_Message_Context $context The message context.
     * @return Chatbot_Message_Response The response.
     */
    private function core_handler(Chatbot_Message_Context $context): Chatbot_Message_Response {
        // Ensure we have a conversation
        $context = $this->ensure_conversation($context);

        if ($context->get_conversation_id() === null) {
            return Chatbot_Message_Response::error('Failed to create conversation', 'conversation_error');
        }

        // Save user message (with file info if present)
        $message_content = $context->get_message();
        if ($context->has_files()) {
            $file_info = $this->build_file_info_text($context->get_files());
            $message_content .= $file_info;
        }

        $user_message_id = $this->message_repository->save(
            $context->get_conversation_id(),
            'user',
            $message_content
        );

        if ($user_message_id === null) {
            return Chatbot_Message_Response::error('Failed to save message', 'database_error');
        }

        // Build messages array for AI
        $messages = $this->build_ai_messages($context);

        // Check if AI is available
        if (!$this->ai_service->is_available()) {
            return Chatbot_Message_Response::error(
                'AI service is not available. Please try again later.',
                'ai_unavailable'
            );
        }

        // Generate AI response (with files if present)
        if ($context->has_files()) {
            $ai_result = $this->generate_completion_with_files($context, $messages);
        } else {
            $ai_result = $this->ai_service->generate_completion($messages);
        }

        if (!$ai_result['success']) {
            $error_type = $ai_result['error_type'] ?? 'ai_error';
            return Chatbot_Message_Response::error(
                $ai_result['error'] ?? 'Failed to generate response',
                $error_type
            );
        }

        // Handle tool calls if present
        if (!empty($ai_result['tool_calls'])) {
            return Chatbot_Message_Response::with_tool_calls(
                $ai_result['tool_calls'],
                $ai_result['content'] ?? null,
                $context->get_conversation_id(),
                $ai_result['model'] ?? null,
                $ai_result['usage'] ?? null
            );
        }

        // Save AI response
        $ai_message_id = $this->message_repository->save(
            $context->get_conversation_id(),
            'ai',
            $ai_result['content']
        );

        return Chatbot_Message_Response::success(
            $ai_result['content'],
            $context->get_conversation_id(),
            $ai_message_id,
            $ai_result['model'] ?? null,
            $ai_result['usage'] ?? null
        );
    }

    /**
     * Generate completion with file attachments.
     *
     * @param Chatbot_Message_Context $context  The message context.
     * @param array                   $messages The messages array.
     * @return array The AI result.
     */
    private function generate_completion_with_files(Chatbot_Message_Context $context, array $messages): array {
        $files = $context->get_files();

        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'message_pipeline', 'Processing message with files', [
                'file_count' => count($files),
                'file_types' => array_column($files, 'type'),
            ]);
        }

        // Check if vision capability is available
        if (!$this->ai_service->has_capability('Chatbot_Vision_Capability')) {
            if (function_exists('chatbot_log')) {
                chatbot_log('WARN', 'message_pipeline', 'Vision capability not available, falling back to text-only');
            }
            // Fall back to text-only with file descriptions
            return $this->ai_service->generate_completion($messages);
        }

        // Use the vision capability
        return $this->ai_service->generate_completion_with_files($messages, $files);
    }

    /**
     * Build file info text for message storage.
     *
     * @param array $files The files.
     * @return string Text describing the attached files.
     */
    private function build_file_info_text(array $files): string {
        if (empty($files)) {
            return '';
        }

        $parts = [];
        foreach ($files as $file) {
            $name = $file['name'] ?? 'file';
            $type = $file['type'] ?? 'unknown';
            $parts[] = "{$name} ({$type})";
        }

        return "\n\n[Attached files: " . implode(', ', $parts) . "]";
    }

    /**
     * Ensure configuration is loaded.
     *
     * @param Chatbot_Message_Context $context The context.
     * @return Chatbot_Message_Context Updated context.
     */
    private function ensure_configuration(Chatbot_Message_Context $context): Chatbot_Message_Context {
        if ($context->get_config() !== null) {
            return $context;
        }

        $config = null;

        if ($context->get_config_id() !== null) {
            $config = $this->config_repository->find($context->get_config_id());
        }

        if ($config === null) {
            $config = $this->config_repository->get_default();
        }

        if ($config !== null) {
            return $context->with_config($config);
        }

        return $context;
    }

    /**
     * Load conversation history.
     *
     * @param Chatbot_Message_Context $context The context.
     * @return Chatbot_Message_Context Updated context with history.
     */
    private function load_conversation_history(Chatbot_Message_Context $context): Chatbot_Message_Context {
        if ($context->get_conversation_id() === null) {
            return $context;
        }

        $messages = $this->message_repository->get_recent($context->get_conversation_id(), 10);
        return $context->with_history($messages);
    }

    /**
     * Ensure a conversation exists.
     *
     * @param Chatbot_Message_Context $context The context.
     * @return Chatbot_Message_Context Updated context.
     */
    private function ensure_conversation(Chatbot_Message_Context $context): Chatbot_Message_Context {
        if ($context->get_conversation_id() !== null) {
            return $context;
        }

        // Try to find existing conversation for platform
        if ($context->get_platform_chat_id() !== null && $context->get_config_id() !== null) {
            $conversation = $this->conversation_repository->find_active_by_platform(
                $context->get_platform_chat_id(),
                $context->get_platform(),
                $context->get_config_id()
            );

            if ($conversation !== null) {
                return $context->with_conversation_id($conversation->id);
            }
        }

        // Create new conversation
        $config = $context->get_config();
        $conversation_id = $this->conversation_repository->create([
            'visitor_name' => $context->get_visitor_name(),
            'chatbot_config_id' => $context->get_config_id(),
            'chatbot_config_name' => $config ? $config->name : null,
            'platform_type' => $context->get_platform(),
            'platform_chat_id' => $context->get_platform_chat_id(),
        ]);

        if ($conversation_id !== null) {
            return $context->with_conversation_id($conversation_id);
        }

        return $context;
    }

    /**
     * Build messages array for AI.
     *
     * @param Chatbot_Message_Context $context The context.
     * @return array Messages array.
     */
    private function build_ai_messages(Chatbot_Message_Context $context): array {
        $messages = [];

        // Build system prompt from configuration and context
        $config = $context->get_config();
        $system_prompt = $this->build_system_prompt($config, $context);
        if (!empty($system_prompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $system_prompt,
            ];
        }

        // Add conversation history
        foreach ($context->get_history() as $msg) {
            $role = $msg->sender_type === 'user' ? 'user' : 'assistant';
            $messages[] = [
                'role' => $role,
                'content' => $msg->message,
            ];
        }

        // Add current message
        $messages[] = [
            'role' => 'user',
            'content' => $context->get_message(),
        ];

        return $messages;
    }

    /**
     * Build system prompt from configuration and context.
     *
     * @param object|null             $config  The configuration.
     * @param Chatbot_Message_Context $context The message context.
     * @return string The system prompt.
     */
    private function build_system_prompt(?object $config, Chatbot_Message_Context $context): string {
        $parts = [];

        // Add persona
        if ($config !== null && !empty($config->persona)) {
            $parts[] = $config->persona;
        }

        // Add visitor/user context
        $visitor_name = $context->get_visitor_name();
        if (!empty($visitor_name) && $visitor_name !== 'Visitor') {
            $parts[] = "\n\nCurrent User Information:\n- Name: " . $visitor_name;
            $parts[] = "When the user needs to provide their name (e.g., for scheduling, forms, or bookings), use the name \"" . $visitor_name . "\" - do not ask for their name again.";
        }

        // Add knowledge
        if ($config !== null && !empty($config->knowledge)) {
            $parts[] = "\n\nKnowledge Base:\n" . $config->knowledge;
        }

        // Load knowledge from WordPress sources
        if ($config !== null && !empty($config->knowledge_sources)) {
            $db = Chatbot_DB::get_instance();
            $wp_knowledge = $db->get_knowledge_from_sources($config->knowledge_sources);
            if (!empty($wp_knowledge)) {
                $parts[] = "\n\nWebsite Content:\n" . $wp_knowledge;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Get the AI service.
     *
     * @return Chatbot_AI_Service
     */
    public function get_ai_service(): Chatbot_AI_Service {
        return $this->ai_service;
    }

    /**
     * Get registered middleware.
     *
     * @return array
     */
    public function get_middleware(): array {
        return $this->middleware;
    }
}
