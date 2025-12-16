/**
 * AIPass SDK Loader for WordPress Chatbot Plugin
 *
 * This file loads the official AIPass Web SDK from https://aipass.one/aipass-sdk.js
 *
 * The hosted SDK provides:
 * - OAuth2 + PKCE authentication with popup flow
 * - Automatic token management and refresh
 * - AI completions, image generation, speech, embeddings
 * - User balance and model management
 *
 * Migration Note: Replaced custom implementation (v1.0) with official hosted SDK
 * Date: 2025-11-09
 */

// This file is intentionally minimal - the actual SDK is loaded via script tag in PHP
// See: includes/class-chatbot-aipass.php -> enqueue_admin_scripts()

// The hosted SDK exposes a global `AiPass` object with methods:
// - AiPass.initialize({ clientId })
// - AiPass.login()
// - AiPass.logout()
// - AiPass.isAuthenticated()
// - AiPass.generateCompletion({ prompt, model, maxTokens })
// - AiPass.getUserBalance()
// - AiPass.getModels()
// - And more...

console.log('AIPass SDK loader: Waiting for hosted SDK to initialize...');
