<?php
/**
 * Chatbot display template
 */

// Exit if accessed directly
if (!defined('WPINC')) {
    die;
}

// Get theme from shortcode attributes
$theme = isset($atts['theme']) ? $atts['theme'] : 'light';
?>

<!-- Chatbot button -->
<div class="chat-button" id="chatButton">
    <div class="chat-icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
        </svg>
    </div>
</div>

<!-- Chatbot container -->
<div class="chatbot-container <?php echo esc_attr($theme); ?>" id="chatbot-container">
    <!-- Header only shown in chat mode, not in welcome screen -->
    <div class="chatbot-header" style="display:none;">
        <span>Chat Support</span>
        <span class="chatbot-close" id="chatbot-close">✕</span>
    </div>
    
    <div class="chatbot-messages" id="chatbot-messages">
        <!-- Messages will be dynamically added here -->
        
        <!-- Loading indicator (shown while initializing) -->
        <div class="chatbot-loading" id="chatbot-loading">
            <div class="chatbot-loading-spinner"></div>
            <div class="chatbot-loading-text">Initializing chat...</div>
        </div>
    </div>
    
    <!-- Simple text-based typing indicator at the bottom of chat -->
    <div class="chatbot-simple-typing-indicator" id="chatbot-typing-status" style="display:none;">
        AI Assistant is typing...
    </div>
    
    <div class="chatbot-welcome-screen">
        <span class="chatbot-close welcome-close" id="welcome-close" style="position: absolute; top: 15px; right: 15px; cursor: pointer; font-size: 22px; color: #4a6cf7;">✕</span>
        <h3>Welcome to our chat!</h3>
        <p>Please enter your name to start chatting with us.</p>
        <div class="chatbot-name-container">
            <input type="text" class="chatbot-name-input" placeholder="Your name...">
            <button class="chatbot-start-btn">Start Chat</button>
        </div>
    </div>
    
    <div class="chatbot-input-container" style="display:none;">
        <input type="text" class="chatbot-input" placeholder="Type your message...">
        <button class="chatbot-send-btn">
            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <line x1="22" y1="2" x2="11" y2="13"></line>
                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
            </svg>
        </button>
    </div>
</div>