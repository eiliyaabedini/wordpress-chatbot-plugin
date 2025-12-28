<?php
/**
 * Embeddings Capability Interface
 *
 * Defines the contract for AI text embedding capabilities.
 * This is a placeholder for future implementation.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Interface Chatbot_Embeddings_Capability
 *
 * Contract for AI providers that support text embeddings.
 * Future capability - interface defined for extensibility.
 */
interface Chatbot_Embeddings_Capability {

    /**
     * Generate embeddings for text.
     *
     * @param string|array $input   Text or array of texts to embed.
     * @param array        $options Optional parameters:
     *                              - model: string The embeddings model to use
     *                              - dimensions: int Embedding dimensions (if supported)
     * @return array Result array with keys:
     *               - success: bool Whether the request succeeded
     *               - embeddings: array Array of embedding vectors (if success)
     *               - usage: array Token usage statistics
     *               - error: string Error message (if !success)
     */
    public function generate_embeddings($input, array $options = []): array;

    /**
     * Check if embeddings capability is currently available.
     *
     * @return bool True if embeddings can be generated.
     */
    public function is_embeddings_available(): bool;

    /**
     * Get available embeddings models.
     *
     * @return array Array of model identifiers available for embeddings.
     */
    public function get_embeddings_models(): array;

    /**
     * Get the default embeddings model.
     *
     * @return string The default model identifier.
     */
    public function get_default_embeddings_model(): string;

    /**
     * Get the embedding dimensions for a model.
     *
     * @param string $model The model identifier.
     * @return int The number of dimensions in the embedding vector.
     */
    public function get_embedding_dimensions(string $model): int;
}
