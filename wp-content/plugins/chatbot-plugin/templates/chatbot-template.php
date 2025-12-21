<?php
/**
 * Chatbot display template
 */

// Exit if accessed directly
if (!defined('WPINC')) {
    die;
}

// Get shortcode attributes
$theme = isset($atts['theme']) ? $atts['theme'] : 'light';
$mode = isset($atts['mode']) ? $atts['mode'] : 'floating';
$height = isset($atts['height']) ? $atts['height'] : '600px';
$skip_welcome = isset($atts['skip_welcome']) && ($atts['skip_welcome'] === 'true' || $atts['skip_welcome'] === true);

// Determine if inline mode
$is_inline = ($mode === 'inline');

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

<!-- Chatbot button (hidden in inline mode) -->
<div class="chat-button<?php echo $is_inline ? ' inline-hidden' : ''; ?>" id="chatButton"<?php echo $is_inline ? ' style="display:none;"' : ''; ?>>
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
<?php
// Determine the chatbot display name
$chatbot_display_name = 'AI Assistant'; // Default
if (isset($atts['config']) && isset($atts['config']->name) && !empty($atts['config']->name) && $atts['config']->name !== 'Default') {
    $chatbot_display_name = $atts['config']->name;
}
?>
<div class="chatbot-container <?php echo esc_attr($theme); ?><?php echo $is_inline ? ' inline active' : ''; ?>"
     id="chatbot-container"
     <?php if (isset($atts['name']) && !empty($atts['name'])): ?>data-config-name="<?php echo esc_attr($atts['name']); ?>"<?php endif; ?>
     data-chatbot-name="<?php echo esc_attr($chatbot_display_name); ?>"
     data-mode="<?php echo esc_attr($mode); ?>"
     <?php if ($skip_welcome): ?>data-skip-welcome="true"<?php endif; ?>
     <?php if ($is_inline): ?>style="height: <?php echo esc_attr($height); ?>;"<?php endif; ?>>
    <!-- Header - always visible now (welcome is inside chat) -->
    <div class="chatbot-header">
        <div class="chatbot-header-content">
            <div class="chatbot-header-title"><?php echo esc_html($chatbot_display_name); ?></div>
            <?php if (!$is_inline): ?>
            <div class="chatbot-header-branding">
                <span class="powered-by-text">Powered by</span>
                <a href="https://aipass.one" target="_blank" rel="noopener noreferrer" class="aipass-logo-link">
                    <div class="aipass-logo">
                        <div class="aipass-ai-box">AI</div>
                        <div class="aipass-pass-text">Pass</div>
                    </div>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <div class="chatbot-header-actions">
            <button class="chatbot-end-btn" title="End this conversation">End Chat</button>
            <?php if (!$is_inline): ?>
            <span class="chatbot-close" id="chatbot-close">✕</span>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="chatbot-messages" id="chatbot-messages">
        <!-- Loading indicator (shown while initializing) -->
        <div class="chatbot-loading" id="chatbot-loading" style="display:none;">
            <div class="chatbot-loading-spinner"></div>
            <div class="chatbot-loading-text">Initializing chat...</div>
        </div>
    </div>

    <div class="chatbot-input-container">
        <div class="chatbot-input-row">
            <!-- Input wrapper with send button inside -->
            <div class="chatbot-input-wrapper">
                <input type="text" class="chatbot-input" placeholder="Type your message...">
                <button class="chatbot-send-btn">
                    <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                    </svg>
                </button>
            </div>

            <!-- Microphone Button for Voice Input -->
            <button type="button" class="chatbot-mic-btn" title="Voice input">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path>
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
                    <line x1="12" y1="19" x2="12" y2="23"></line>
                    <line x1="8" y1="23" x2="16" y2="23"></line>
                </svg>
            </button>
        </div>
        <div class="chatbot-input-options">
            <!-- TTS Auto-play Toggle -->
            <label class="chatbot-tts-toggle" title="Automatically play AI responses as audio">
                <span class="tts-toggle-label">Auto play</span>
                <input type="checkbox" id="chatbot-tts-autoplay">
                <span class="tts-toggle-switch"></span>
            </label>
        </div>
    </div>

    <!-- Powered by AIPass footer (shown only in inline mode) -->
    <div class="chatbot-inline-footer">
        <span class="powered-by-text">Powered by</span>
        <a href="https://aipass.one" target="_blank" rel="noopener noreferrer" class="aipass-logo-link">
            <div class="aipass-logo">
                <div class="aipass-ai-box">AI</div>
                <div class="aipass-pass-text">Pass</div>
            </div>
        </a>
    </div>
</div>