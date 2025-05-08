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

// Helper function to darken/lighten hex colors
if (!function_exists('adjustBrightness')) {
    function adjustBrightness($hex, $steps) {
        // Remove # if present
        $hex = ltrim($hex, '#');
        
        // Parse the hex code
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        // Adjust the brightness
        $r = max(0, min(255, $r + $steps));
        $g = max(0, min(255, $g + $steps));
        $b = max(0, min(255, $b + $steps));
        
        // Convert back to hex
        return '#' . sprintf('%02x%02x%02x', $r, $g, $b);
    }
}

// Default primary color if not set
$default_primary_color = '#4a6cf7';
$primary_color = get_option('chatbot_primary_color', $default_primary_color);

// If empty, use the default color
if (empty($primary_color)) {
    $primary_color = $default_primary_color;
}

$primary_color_dark = adjustBrightness($primary_color, -20);
?>
<style>
/* Inject primary color as CSS variable to be used in the stylesheet */
:root {
    --chatbot-primary-color: <?php echo esc_attr($primary_color); ?>;
    --chatbot-primary-color-light: <?php echo esc_attr($primary_color); ?>20; /* 20% opacity */
    --chatbot-primary-color-dark: <?php echo esc_attr($primary_color_dark); ?>; /* Darker shade */
    --chatbot-danger-color: #f44336;
    --chatbot-danger-color-light: #f4433620; /* 20% opacity */
    --chatbot-danger-color-dark: #d32f2f; /* Darker shade */
}
</style>

<!-- Chatbot button -->
<div class="chat-button" id="chatButton">
    <div class="chat-icon">
        <?php 
        // Get button icon type and render appropriate icon
        $button_icon_type = get_option('chatbot_button_icon_type', 'default');
        
        if ($button_icon_type === 'custom') {
            // Custom SVG icon
            $custom_icon = get_option('chatbot_button_icon', '');
            if (!empty($custom_icon)) {
                echo wp_kses($custom_icon, array(
                    'svg' => array(
                        'xmlns' => true,
                        'width' => true,
                        'height' => true,
                        'viewBox' => true,
                        'fill' => true,
                        'stroke' => true,
                        'stroke-width' => true,
                        'stroke-linecap' => true,
                        'stroke-linejoin' => true,
                        'class' => true,
                    ),
                    'path' => array(
                        'd' => true,
                        'fill' => true,
                        'stroke' => true,
                    ),
                    'circle' => array(
                        'cx' => true,
                        'cy' => true,
                        'r' => true,
                        'fill' => true,
                        'stroke' => true,
                    ),
                    'rect' => array(
                        'x' => true,
                        'y' => true,
                        'width' => true,
                        'height' => true,
                        'fill' => true,
                        'stroke' => true,
                        'rx' => true,
                        'ry' => true,
                    ),
                ));
            } else {
                // Fallback to default if empty
                echo '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
            }
        } else if ($button_icon_type === 'upload') {
            // Uploaded image
            $image_url = get_option('chatbot_button_icon_url', '');
            if (!empty($image_url)) {
                echo '<img src="' . esc_url($image_url) . '" alt="Chat" class="chat-icon-image" />';
            } else {
                // Fallback to default if empty
                echo '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
            }
        } else {
            // Default chat icon
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
        }
        ?>
    </div>
</div>

<!-- Chatbot container -->
<div class="chatbot-container <?php echo esc_attr($theme); ?>" id="chatbot-container" <?php if (isset($atts['name']) && !empty($atts['name'])): ?>data-config-name="<?php echo esc_attr($atts['name']); ?>"<?php endif; ?>>
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
        <?php echo esc_html(get_option('chatbot_typing_indicator_text', 'AI Assistant is typing...')); ?>
    </div>
    
    <div class="chatbot-welcome-screen">
        <span class="chatbot-close welcome-close" id="welcome-close" style="position: absolute; top: 15px; right: 15px; cursor: pointer; font-size: 22px; color: #4a6cf7;">✕</span>
        <h3>Welcome to our chat!</h3>
        <p><?php echo esc_html(get_option('chatbot_welcome_message', 'Please enter your name to start chatting with us.')); ?></p>
        <div class="chatbot-name-container">
            <input type="text" class="chatbot-name-input" placeholder="Your name...">
            <button class="chatbot-start-btn">Start Chat</button>
        </div>
    </div>
    
    <div class="chatbot-input-container" style="display:none;">
        <input type="text" class="chatbot-input" placeholder="Type your message...">
        <div class="chatbot-buttons-container">
            <button class="chatbot-send-btn">
                <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
            </button>
            <button class="chatbot-end-btn" title="End this conversation">
                End Chat
            </button>
        </div>
    </div>
</div>