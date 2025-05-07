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

<div class="chatbot-container <?php echo esc_attr($theme); ?>">
    <div class="chatbot-header">
        Chatbot Assistant
    </div>
    
    <div class="chatbot-messages">
        <!-- Messages will be dynamically added here -->
    </div>
    
    <div class="chatbot-welcome-screen">
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
            <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <line x1="22" y1="2" x2="11" y2="13"></line>
                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
            </svg>
        </button>
    </div>
</div>