/**
 * Chatbot Embed Widget
 *
 * Standalone JavaScript widget for embedding chatbot on external websites.
 * Uses Shadow DOM for style isolation.
 *
 * Usage:
 * <script src="https://your-wp.com/wp-content/plugins/chatbot-plugin/assets/js/chatbot-embed.js"
 *   data-chatbot-token="your-64-char-token"
 *   data-chatbot-mode="floating"
 *   data-chatbot-theme="light">
 * </script>
 */
(function() {
    'use strict';

    // Get configuration from script tag
    const script = document.currentScript || document.querySelector('script[data-chatbot-token]');
    if (!script) {
        console.error('Chatbot Embed: Script tag not found');
        return;
    }

    // Build API URL from script src
    // Script src: https://example.com/wp-content/plugins/chatbot-plugin/assets/js/chatbot-embed.js
    // API URL:    https://example.com/wp-json/chatbot-plugin/v1/embed
    function getApiUrl() {
        // Allow explicit URL override via data attribute
        if (script.dataset.chatbotUrl) {
            return script.dataset.chatbotUrl;
        }

        const scriptSrc = script.src;

        // Method 1: Extract base URL by finding /wp-content/plugins/
        const wpContentIndex = scriptSrc.indexOf('/wp-content/plugins/');
        if (wpContentIndex !== -1) {
            return scriptSrc.substring(0, wpContentIndex) + '/wp-json/chatbot-plugin/v1/embed';
        }

        // Method 2: Try to find /wp-content/ (in case plugins folder has different name)
        const wpContentAltIndex = scriptSrc.indexOf('/wp-content/');
        if (wpContentAltIndex !== -1) {
            return scriptSrc.substring(0, wpContentAltIndex) + '/wp-json/chatbot-plugin/v1/embed';
        }

        // Method 3: Use URL origin as fallback
        try {
            const url = new URL(scriptSrc);
            return url.origin + '/wp-json/chatbot-plugin/v1/embed';
        } catch (e) {
            console.error('Chatbot Embed: Failed to parse script URL:', e);
        }

        // Last resort: use current page origin
        return window.location.origin + '/wp-json/chatbot-plugin/v1/embed';
    }

    const CONFIG = {
        token: script.dataset.chatbotToken || '',
        apiUrl: getApiUrl(),
        mode: script.dataset.chatbotMode || 'floating',
        theme: script.dataset.chatbotTheme || 'light',
        height: script.dataset.chatbotHeight || '600px',
        skipWelcome: script.dataset.chatbotSkipWelcome === 'true',
        position: script.dataset.chatbotPosition || 'right',
        // Target mode: inject into existing element without Shadow DOM
        target: script.dataset.chatbotTarget || null,
        // Avatar URL for target mode header
        avatarUrl: script.dataset.chatbotAvatar || null,
        // Default visitor name (skip name prompt entirely)
        visitorName: script.dataset.chatbotVisitorName || 'Visitor',
        // Configurable bot name (overrides server config)
        botName: script.dataset.chatbotBotName || null,
        // Configurable greeting message (overrides server config, which uses per-chatbot setting)
        greeting: script.dataset.chatbotGreeting || null,
    };

    if (!CONFIG.token) {
        console.error('Chatbot Embed: Token is required');
        return;
    }

    /**
     * Chatbot Embed Widget Class
     */
    class ChatbotEmbed {
        constructor(config) {
            this.config = config;
            this.container = null;
            this.shadowRoot = null;
            this.sessionId = localStorage.getItem(`chatbot_embed_session_${config.token.substring(0, 8)}`);
            this.conversationId = null;
            this.visitorName = localStorage.getItem(`chatbot_embed_name_${config.token.substring(0, 8)}`) || '';
            this.chatbotName = 'AI Assistant';
            this.greeting = 'Hello! How can I help you today?';
            this.primaryColor = '#4a6cf7';
            this.isOpen = false;
            this.waitingForName = true;
            this.isLoading = false;
        }

        /**
         * Initialize the widget
         */
        async init() {
            // Fetch chatbot config first
            await this.fetchConfig();

            // Check if target mode (inject into existing element)
            if (this.config.target) {
                await this.initTargetMode();
                return;
            }

            // Standard mode: Create container with Shadow DOM for style isolation
            this.container = document.createElement('div');
            this.container.id = 'chatbot-embed-root';
            this.shadowRoot = this.container.attachShadow({ mode: 'closed' });

            // Inject styles
            this.injectStyles();

            // Create UI
            this.createUI();

            // Append to body
            document.body.appendChild(this.container);

            // If inline mode, auto-start
            if (this.config.mode === 'inline') {
                this.isOpen = true;
                if (this.config.skipWelcome && this.visitorName) {
                    this.waitingForName = false;
                    await this.startConversation();
                }
            }

            // Restore previous state for floating mode
            if (this.config.mode === 'floating') {
                const wasOpen = localStorage.getItem(`chatbot_embed_open_${this.config.token.substring(0, 8)}`);
                if (wasOpen === 'true') {
                    this.toggleChat();
                }
            }
        }

        /**
         * Initialize target mode - inject into existing HTML element
         * Uses the host page's CSS instead of Shadow DOM
         * Shows chat directly - no name prompt, ready to type immediately
         * Restores existing session if available
         */
        async initTargetMode() {

            // Find target element
            this.container = document.querySelector(this.config.target);
            if (!this.container) {
                console.error('Chatbot Embed: Target element not found:', this.config.target);
                return;
            }

            // Clear existing content
            this.container.innerHTML = '';

            // No Shadow DOM - use host page styles
            this.shadowRoot = null;
            this.useTargetMode = true;

            // Use configured visitor name (default: "Visitor")
            this.visitorName = this.config.visitorName;

            // No name prompt in target mode - go straight to chat
            this.waitingForName = false;

            // Create UI for target mode
            this.createTargetUI();

            // Target mode is always "open" and inline
            this.isOpen = true;

            // Check if we have an existing session to restore
            if (this.sessionId) {
                const restored = await this.restoreSession();
                if (restored) {
                    return;
                }
                // Session invalid, clear it
                this.clearSession();
            }

            // Start new conversation
            await this.startConversation();
        }

        /**
         * Restore existing session - load messages from server
         */
        async restoreSession() {
            try {
                const response = await this.apiRequest('/messages', 'GET');

                if (response.success && response.messages && response.messages.length > 0) {
                    // Check if conversation is still active
                    if (response.conversation_status !== 'active') {
                        return false;
                    }

                    // Show greeting first, then restore messages
                    this.addTargetMessage(this.greeting, 'bot');

                    // Restore messages to UI
                    for (const msg of response.messages) {
                        const sender = msg.sender_type === 'user' ? 'user' : 'bot';
                        this.addTargetMessage(msg.message, sender);
                    }

                    return true;
                }

                return false;
            } catch (error) {
                console.error('Chatbot Embed: Failed to restore session', error);
                return false;
            }
        }

        /**
         * Clear session data
         */
        clearSession() {
            this.sessionId = null;
            this.conversationId = null;
            localStorage.removeItem(`chatbot_embed_session_${this.config.token.substring(0, 8)}`);
        }

        /**
         * Create UI for target mode using host page's CSS classes
         * Simple structure: header + messages + input (no name prompt)
         */
        createTargetUI() {
            this.container.innerHTML = `
                <div class="chat-header">
                    <div class="chat-avatar">
                        ${this.config.avatarUrl
                            ? `<img src="${this.escapeHtml(this.config.avatarUrl)}" alt="${this.escapeHtml(this.chatbotName)}">`
                            : `<svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                                <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>
                               </svg>`
                        }
                    </div>
                    <div class="chat-title">
                        <h3>${this.escapeHtml(this.chatbotName)}</h3>
                        <span class="status-dot"></span>
                    </div>
                    <button class="chat-end-btn" id="chatEndBtn" title="End conversation">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
                <div class="chat-history" id="chatHistory"></div>
                <div class="chat-input-area">
                    <input type="text" id="chatInput" placeholder="Type your message..." maxlength="${this.getMaxMessageLength()}">
                    <button id="chatSendBtn" aria-label="Send message">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M22 2L11 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M22 2L15 22L11 13L2 9L22 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
            `;

            // Store reference to history element
            this.historyElement = this.container.querySelector('#chatHistory');

            // Bind events for target mode
            this.bindTargetEvents();
        }

        /**
         * Bind event listeners for target mode
         */
        bindTargetEvents() {
            // Send message
            const sendBtn = this.container.querySelector('#chatSendBtn');
            const messageInput = this.container.querySelector('#chatInput');

            if (sendBtn && messageInput) {
                sendBtn.addEventListener('click', () => this.handleTargetSendMessage());
                messageInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        this.handleTargetSendMessage();
                    }
                });
            }

            // End chat button
            const endBtn = this.container.querySelector('#chatEndBtn');
            if (endBtn) {
                endBtn.addEventListener('click', () => this.handleTargetEndChat());
            }
        }

        /**
         * Handle send message in target mode
         */
        async handleTargetSendMessage() {
            const input = this.container.querySelector('#chatInput');
            const message = input?.value?.trim();

            if (!message || this.isLoading) return;

            input.value = '';
            await this.sendMessage(message);
        }

        /**
         * Handle end chat in target mode
         */
        async handleTargetEndChat() {
            if (!confirm('Are you sure you want to end this chat?')) {
                return;
            }

            try {
                await this.apiRequest('/end', 'POST', {
                    session_id: this.sessionId,
                });
            } catch (error) {
                console.error('Chatbot Embed: Failed to end conversation', error);
            }

            // Clear session
            this.clearSession();

            // Clear messages and restart
            if (this.historyElement) {
                this.historyElement.innerHTML = '';
            }

            // Start new conversation
            await this.startConversation();
        }

        /**
         * Add message to chat for target mode
         */
        addTargetMessage(content, sender) {
            if (!this.historyElement) return;

            const messageDiv = document.createElement('div');
            messageDiv.className = `chat-message ${sender}`;
            messageDiv.innerHTML = `<div class="message-content">${this.formatMessage(content)}</div>`;

            this.historyElement.appendChild(messageDiv);
            this.historyElement.scrollTop = this.historyElement.scrollHeight;
        }

        /**
         * Show typing indicator for target mode
         */
        showTargetTyping() {
            if (!this.historyElement) return;

            const typingDiv = document.createElement('div');
            typingDiv.className = 'chat-message bot typing-indicator';
            typingDiv.id = 'typingIndicator';
            typingDiv.innerHTML = `<div class="message-content"><span class="typing-dots">...</span></div>`;

            this.historyElement.appendChild(typingDiv);
            this.historyElement.scrollTop = this.historyElement.scrollHeight;
        }

        /**
         * Hide typing indicator for target mode
         */
        hideTargetTyping() {
            const typing = this.container?.querySelector('#typingIndicator');
            if (typing) {
                typing.remove();
            }
        }

        /**
         * Get max message length from config or default
         */
        getMaxMessageLength() {
            return 500; // Default max length
        }

        /**
         * Fetch chatbot configuration
         */
        async fetchConfig() {
            try {
                const response = await this.apiRequest('/config', 'GET');
                if (response.success) {
                    // Use data attribute overrides first, then server config, then defaults
                    this.chatbotName = this.config.botName || response.chatbot_name || this.chatbotName;
                    this.greeting = this.config.greeting || response.greeting || this.greeting;
                    this.primaryColor = response.primary_color || this.primaryColor;
                }
            } catch (error) {
                console.error('Chatbot Embed: Failed to fetch config', error);
                // Still apply data attribute overrides even if server fetch fails
                if (this.config.botName) {
                    this.chatbotName = this.config.botName;
                }
                if (this.config.greeting) {
                    this.greeting = this.config.greeting;
                }
            }
        }

        /**
         * Make API request with CORS error handling
         */
        async apiRequest(endpoint, method = 'GET', body = null) {
            let url = `${this.config.apiUrl}/${this.config.token}${endpoint}`;

            // For GET requests, add session_id as query parameter (more reliable than headers)
            if (method === 'GET' && this.sessionId) {
                const separator = url.includes('?') ? '&' : '?';
                url += `${separator}session_id=${encodeURIComponent(this.sessionId)}`;
            }

            const headers = {
                'Content-Type': 'application/json',
            };

            // Also send session_id as header for redundancy
            if (this.sessionId) {
                headers['X-Session-ID'] = this.sessionId;
            }

            const options = {
                method,
                headers,
                mode: 'cors',
            };

            if (body && method !== 'GET') {
                options.body = JSON.stringify(body);
            }

            try {
                const response = await fetch(url, options);

                // Check if response is OK
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error(`Chatbot API Error (${response.status}):`, errorText);
                    return {
                        success: false,
                        error: `Server error: ${response.status}`,
                    };
                }

                return response.json();
            } catch (error) {
                // Check if this is a CORS error
                if (error.name === 'TypeError' && error.message.includes('Failed to fetch')) {
                    console.error('Chatbot Embed: CORS error - The server may not be configured to accept cross-origin requests.');
                    console.error('API URL:', url);
                    console.error('Please ensure the WordPress site has CORS headers enabled for embed endpoints.');

                    // Show user-friendly error in chat
                    return {
                        success: false,
                        error: 'Connection error. Please contact the website administrator.',
                        cors_error: true,
                    };
                }

                console.error('Chatbot Embed: API request failed', error);
                return {
                    success: false,
                    error: error.message,
                };
            }
        }

        /**
         * Inject styles into Shadow DOM
         */
        injectStyles() {
            const styles = document.createElement('style');
            styles.textContent = this.getStyles();
            this.shadowRoot.appendChild(styles);
        }

        /**
         * Get CSS styles
         */
        getStyles() {
            const isDark = this.config.theme === 'dark';
            const isRight = this.config.position === 'right';

            return `
                :host {
                    all: initial;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
                    font-size: 14px;
                    line-height: 1.5;
                }

                * {
                    box-sizing: border-box;
                    margin: 0;
                    padding: 0;
                }

                /* Floating button */
                .chatbot-embed-button {
                    position: fixed;
                    bottom: 20px;
                    ${isRight ? 'right: 20px;' : 'left: 20px;'}
                    width: 60px;
                    height: 60px;
                    border-radius: 50%;
                    background: ${this.primaryColor};
                    border: none;
                    cursor: pointer;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: transform 0.2s, box-shadow 0.2s;
                    z-index: 999999;
                }

                .chatbot-embed-button:hover {
                    transform: scale(1.05);
                    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
                }

                .chatbot-embed-button svg {
                    width: 28px;
                    height: 28px;
                    fill: white;
                }

                .chatbot-embed-button.hidden {
                    display: none;
                }

                /* Chat window */
                .chatbot-embed-window {
                    position: fixed;
                    bottom: 90px;
                    ${isRight ? 'right: 20px;' : 'left: 20px;'}
                    width: 380px;
                    height: 580px;
                    max-height: calc(100vh - 120px);
                    background: ${isDark ? '#1e1e1e' : '#ffffff'};
                    border-radius: 16px;
                    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
                    display: none;
                    flex-direction: column;
                    overflow: hidden;
                    z-index: 999998;
                }

                .chatbot-embed-window.open {
                    display: flex;
                }

                /* Inline mode */
                .chatbot-embed-window.inline {
                    position: relative;
                    bottom: auto;
                    right: auto;
                    left: auto;
                    width: 100%;
                    height: ${this.config.height};
                    max-height: none;
                    display: flex;
                    border-radius: 12px;
                }

                /* Header */
                .chatbot-embed-header {
                    background: ${isDark ? '#2d2d2d' : this.primaryColor};
                    color: white;
                    padding: 16px;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    flex-shrink: 0;
                }

                .chatbot-embed-header-title {
                    font-size: 16px;
                    font-weight: 600;
                }

                .chatbot-embed-header-actions {
                    display: flex;
                    gap: 8px;
                }

                .chatbot-embed-header-btn {
                    background: rgba(255, 255, 255, 0.2);
                    border: none;
                    color: white;
                    padding: 6px 12px;
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 12px;
                    transition: background 0.2s;
                }

                .chatbot-embed-header-btn:hover {
                    background: rgba(255, 255, 255, 0.3);
                }

                .chatbot-embed-close {
                    background: none;
                    border: none;
                    color: white;
                    cursor: pointer;
                    padding: 4px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }

                .chatbot-embed-close svg {
                    width: 20px;
                    height: 20px;
                    fill: white;
                }

                /* Messages area */
                .chatbot-embed-messages {
                    flex: 1;
                    overflow-y: auto;
                    padding: 16px;
                    background: ${isDark ? '#1e1e1e' : '#f5f5f5'};
                }

                .chatbot-embed-message {
                    margin-bottom: 12px;
                    max-width: 85%;
                    padding: 10px 14px;
                    border-radius: 12px;
                    word-wrap: break-word;
                }

                .chatbot-embed-message.user {
                    background: ${this.primaryColor};
                    color: white;
                    margin-left: auto;
                    border-bottom-right-radius: 4px;
                }

                .chatbot-embed-message.ai {
                    background: ${isDark ? '#2d2d2d' : '#ffffff'};
                    color: ${isDark ? '#e0e0e0' : '#333333'};
                    margin-right: auto;
                    border-bottom-left-radius: 4px;
                    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
                }

                .chatbot-embed-message.system {
                    background: ${isDark ? '#3d3d3d' : '#e8e8e8'};
                    color: ${isDark ? '#b0b0b0' : '#666666'};
                    text-align: center;
                    font-size: 12px;
                    max-width: 100%;
                    margin: 8px 0;
                }

                /* Typing indicator */
                .chatbot-embed-typing {
                    display: flex;
                    align-items: center;
                    gap: 4px;
                    padding: 12px 16px;
                    background: ${isDark ? '#2d2d2d' : '#ffffff'};
                    border-radius: 12px;
                    border-bottom-left-radius: 4px;
                    max-width: 80px;
                    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
                }

                .chatbot-embed-typing .dot {
                    width: 8px;
                    height: 8px;
                    background: ${isDark ? '#666' : '#999'};
                    border-radius: 50%;
                    animation: typing-dot 1.4s infinite ease-in-out both;
                }

                .chatbot-embed-typing .dot:nth-child(1) { animation-delay: -0.32s; }
                .chatbot-embed-typing .dot:nth-child(2) { animation-delay: -0.16s; }

                @keyframes typing-dot {
                    0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; }
                    40% { transform: scale(1); opacity: 1; }
                }

                /* Input area */
                .chatbot-embed-input-area {
                    padding: 12px 16px;
                    background: ${isDark ? '#2d2d2d' : '#ffffff'};
                    border-top: 1px solid ${isDark ? '#3d3d3d' : '#e0e0e0'};
                    display: flex;
                    gap: 8px;
                    align-items: flex-end;
                    flex-shrink: 0;
                }

                .chatbot-embed-input {
                    flex: 1;
                    border: 1px solid ${isDark ? '#4d4d4d' : '#ddd'};
                    border-radius: 20px;
                    padding: 10px 16px;
                    font-size: 14px;
                    outline: none;
                    resize: none;
                    min-height: 40px;
                    max-height: 120px;
                    background: ${isDark ? '#1e1e1e' : '#ffffff'};
                    color: ${isDark ? '#e0e0e0' : '#333333'};
                    font-family: inherit;
                    line-height: 1.4;
                }

                .chatbot-embed-input:focus {
                    border-color: ${this.primaryColor};
                }

                .chatbot-embed-input::placeholder {
                    color: ${isDark ? '#666' : '#999'};
                }

                .chatbot-embed-send {
                    width: 40px;
                    height: 40px;
                    border-radius: 50%;
                    background: ${this.primaryColor};
                    border: none;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: background 0.2s, transform 0.2s;
                    flex-shrink: 0;
                }

                .chatbot-embed-send:hover {
                    filter: brightness(1.1);
                    transform: scale(1.05);
                }

                .chatbot-embed-send:disabled {
                    opacity: 0.5;
                    cursor: not-allowed;
                    transform: none;
                }

                .chatbot-embed-send svg {
                    width: 18px;
                    height: 18px;
                    fill: white;
                }

                /* Footer */
                .chatbot-embed-footer {
                    padding: 8px 16px;
                    background: ${isDark ? '#2d2d2d' : '#ffffff'};
                    border-top: 1px solid ${isDark ? '#3d3d3d' : '#e0e0e0'};
                    text-align: center;
                    flex-shrink: 0;
                }

                .chatbot-embed-footer a {
                    color: ${isDark ? '#888' : '#999'};
                    text-decoration: none;
                    font-size: 11px;
                }

                .chatbot-embed-footer a:hover {
                    color: ${this.primaryColor};
                }

                /* Welcome screen */
                .chatbot-embed-welcome {
                    flex: 1;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    padding: 24px;
                    text-align: center;
                    background: ${isDark ? '#1e1e1e' : '#f5f5f5'};
                }

                .chatbot-embed-welcome h3 {
                    color: ${isDark ? '#e0e0e0' : '#333'};
                    margin-bottom: 8px;
                    font-size: 18px;
                }

                .chatbot-embed-welcome p {
                    color: ${isDark ? '#999' : '#666'};
                    margin-bottom: 20px;
                    font-size: 14px;
                }

                .chatbot-embed-welcome input {
                    width: 100%;
                    max-width: 280px;
                    padding: 12px 16px;
                    border: 1px solid ${isDark ? '#4d4d4d' : '#ddd'};
                    border-radius: 8px;
                    font-size: 14px;
                    margin-bottom: 12px;
                    background: ${isDark ? '#2d2d2d' : '#ffffff'};
                    color: ${isDark ? '#e0e0e0' : '#333'};
                    outline: none;
                }

                .chatbot-embed-welcome input:focus {
                    border-color: ${this.primaryColor};
                }

                .chatbot-embed-welcome button {
                    background: ${this.primaryColor};
                    color: white;
                    border: none;
                    padding: 12px 32px;
                    border-radius: 8px;
                    font-size: 14px;
                    cursor: pointer;
                    transition: background 0.2s;
                }

                .chatbot-embed-welcome button:hover {
                    filter: brightness(1.1);
                }

                .chatbot-embed-welcome button:disabled {
                    opacity: 0.5;
                    cursor: not-allowed;
                }

                /* Mobile responsive */
                @media (max-width: 480px) {
                    .chatbot-embed-window {
                        width: calc(100vw - 20px);
                        height: calc(100vh - 100px);
                        bottom: 80px;
                        ${isRight ? 'right: 10px;' : 'left: 10px;'}
                        border-radius: 12px;
                    }

                    .chatbot-embed-button {
                        bottom: 15px;
                        ${isRight ? 'right: 15px;' : 'left: 15px;'}
                        width: 56px;
                        height: 56px;
                    }
                }
            `;
        }

        /**
         * Create the UI
         */
        createUI() {
            const wrapper = document.createElement('div');
            wrapper.className = 'chatbot-embed-wrapper';

            if (this.config.mode === 'floating') {
                // Create floating button
                const button = document.createElement('button');
                button.className = 'chatbot-embed-button';
                button.innerHTML = `
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>
                    </svg>
                `;
                button.addEventListener('click', () => this.toggleChat());
                wrapper.appendChild(button);
                this.buttonElement = button;
            }

            // Create chat window
            const window = document.createElement('div');
            window.className = `chatbot-embed-window ${this.config.mode === 'inline' ? 'inline open' : ''}`;
            window.innerHTML = this.getChatWindowHTML();
            wrapper.appendChild(window);
            this.windowElement = window;

            this.shadowRoot.appendChild(wrapper);

            // Bind events
            this.bindEvents();

            // Show welcome or chat based on state
            this.updateView();
        }

        /**
         * Get chat window HTML
         */
        getChatWindowHTML() {
            return `
                <div class="chatbot-embed-header">
                    <span class="chatbot-embed-header-title">${this.escapeHtml(this.chatbotName)}</span>
                    <div class="chatbot-embed-header-actions">
                        <button class="chatbot-embed-header-btn chatbot-embed-end-btn" style="display: none;">End Chat</button>
                        ${this.config.mode === 'floating' ? `
                            <button class="chatbot-embed-close">
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                                </svg>
                            </button>
                        ` : ''}
                    </div>
                </div>
                <div class="chatbot-embed-welcome">
                    <h3>Welcome!</h3>
                    <p>Please enter your name to start chatting</p>
                    <input type="text" placeholder="Your name" class="chatbot-embed-name-input" maxlength="50">
                    <button class="chatbot-embed-start-btn">Start Chat</button>
                </div>
                <div class="chatbot-embed-messages" style="display: none;"></div>
                <div class="chatbot-embed-input-area" style="display: none;">
                    <textarea class="chatbot-embed-input" placeholder="Type a message..." rows="1"></textarea>
                    <button class="chatbot-embed-send">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                        </svg>
                    </button>
                </div>
                <div class="chatbot-embed-footer">
                    <a href="https://aipass.one" target="_blank" rel="noopener">Powered by AIPass</a>
                </div>
            `;
        }

        /**
         * Bind event listeners
         */
        bindEvents() {
            // Close button
            const closeBtn = this.windowElement.querySelector('.chatbot-embed-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => this.toggleChat());
            }

            // End chat button
            const endBtn = this.windowElement.querySelector('.chatbot-embed-end-btn');
            if (endBtn) {
                endBtn.addEventListener('click', () => this.endChat());
            }

            // Start chat button
            const startBtn = this.windowElement.querySelector('.chatbot-embed-start-btn');
            const nameInput = this.windowElement.querySelector('.chatbot-embed-name-input');

            if (startBtn && nameInput) {
                // Pre-fill name if saved
                if (this.visitorName) {
                    nameInput.value = this.visitorName;
                }

                startBtn.addEventListener('click', () => this.handleStartChat());
                nameInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        this.handleStartChat();
                    }
                });
            }

            // Send message
            const sendBtn = this.windowElement.querySelector('.chatbot-embed-send');
            const messageInput = this.windowElement.querySelector('.chatbot-embed-input');

            if (sendBtn && messageInput) {
                sendBtn.addEventListener('click', () => this.handleSendMessage());
                messageInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        this.handleSendMessage();
                    }
                });

                // Auto-resize textarea
                messageInput.addEventListener('input', () => {
                    messageInput.style.height = 'auto';
                    messageInput.style.height = Math.min(messageInput.scrollHeight, 120) + 'px';
                });
            }
        }

        /**
         * Toggle chat window
         */
        toggleChat() {
            this.isOpen = !this.isOpen;

            if (this.config.mode === 'floating') {
                this.windowElement.classList.toggle('open', this.isOpen);
                localStorage.setItem(`chatbot_embed_open_${this.config.token.substring(0, 8)}`, this.isOpen);
            }
        }

        /**
         * Update view based on state
         */
        updateView() {
            const welcomeEl = this.windowElement.querySelector('.chatbot-embed-welcome');
            const messagesEl = this.windowElement.querySelector('.chatbot-embed-messages');
            const inputEl = this.windowElement.querySelector('.chatbot-embed-input-area');
            const endBtn = this.windowElement.querySelector('.chatbot-embed-end-btn');

            if (this.waitingForName) {
                welcomeEl.style.display = 'flex';
                messagesEl.style.display = 'none';
                inputEl.style.display = 'none';
                endBtn.style.display = 'none';
            } else {
                welcomeEl.style.display = 'none';
                messagesEl.style.display = 'block';
                inputEl.style.display = 'flex';
                endBtn.style.display = 'block';
            }
        }

        /**
         * Handle start chat
         */
        async handleStartChat() {
            const nameInput = this.windowElement.querySelector('.chatbot-embed-name-input');
            const startBtn = this.windowElement.querySelector('.chatbot-embed-start-btn');
            const name = nameInput.value.trim();

            if (!name) {
                nameInput.focus();
                return;
            }

            this.visitorName = name;
            localStorage.setItem(`chatbot_embed_name_${this.config.token.substring(0, 8)}`, name);

            startBtn.disabled = true;
            startBtn.textContent = 'Starting...';

            await this.startConversation();

            startBtn.disabled = false;
            startBtn.textContent = 'Start Chat';
        }

        /**
         * Start conversation - works for both modes
         */
        async startConversation() {
            try {
                const response = await this.apiRequest('/init', 'POST', {
                    visitor_name: this.visitorName,
                });

                if (response.success) {
                    this.sessionId = response.session_id;
                    this.conversationId = response.conversation_id;
                    localStorage.setItem(`chatbot_embed_session_${this.config.token.substring(0, 8)}`, this.sessionId);

                    this.waitingForName = false;

                    // Update view for standard mode only (target mode doesn't need view updates)
                    if (!this.useTargetMode) {
                        this.updateView();
                    }

                    // Add greeting message from server (uses per-chatbot config)
                    this.addMessage('bot', response.greeting || this.greeting);

                    // Focus input
                    setTimeout(() => {
                        this.focusInput();
                    }, 100);
                } else {
                    this.addMessage('system', response.error || 'Failed to start conversation');
                }
            } catch (error) {
                console.error('Chatbot Embed: Failed to start conversation', error);
                this.addMessage('system', 'Failed to connect. Please try again.');
            }
        }

        /**
         * Handle send message (standard mode)
         */
        async handleSendMessage() {
            const input = this.windowElement.querySelector('.chatbot-embed-input');
            const message = input.value.trim();

            if (!message || this.isLoading) {
                return;
            }

            input.value = '';
            input.style.height = 'auto';

            await this.sendMessage(message);
        }

        /**
         * Send message - works for both standard and target modes
         */
        async sendMessage(message) {
            if (!message || this.isLoading) {
                return;
            }

            // Add user message to UI
            this.addMessage('user', message);

            // Show typing indicator
            this.isLoading = true;
            this.disableSendButton(true);
            this.showTypingIndicator();

            try {
                const response = await this.apiRequest('/message', 'POST', {
                    message: message,
                    session_id: this.sessionId,
                });

                this.hideTypingIndicator();

                if (response.success) {
                    this.addMessage('bot', response.response);
                } else {
                    this.addMessage('system', response.error || 'Failed to get response');
                }
            } catch (error) {
                this.hideTypingIndicator();
                console.error('Chatbot Embed: Failed to send message', error);
                this.addMessage('system', 'Failed to send message. Please try again.');
            }

            this.isLoading = false;
            this.disableSendButton(false);
            this.focusInput();
        }

        /**
         * Disable/enable send button
         */
        disableSendButton(disabled) {
            if (this.useTargetMode) {
                const sendBtn = this.container?.querySelector('#chatSendBtn');
                if (sendBtn) sendBtn.disabled = disabled;
            } else {
                const sendBtn = this.windowElement?.querySelector('.chatbot-embed-send');
                if (sendBtn) sendBtn.disabled = disabled;
            }
        }

        /**
         * Focus input field
         */
        focusInput() {
            if (this.useTargetMode) {
                const input = this.container?.querySelector('#chatInput');
                if (input) input.focus();
            } else {
                const input = this.windowElement?.querySelector('.chatbot-embed-input');
                if (input) input.focus();
            }
        }

        /**
         * Add message to chat - works for both modes
         */
        addMessage(type, content) {
            // Map type for consistency (ai -> bot)
            const senderType = type === 'ai' ? 'bot' : type;

            if (this.useTargetMode) {
                this.addTargetMessage(content, senderType);
            } else {
                const messagesEl = this.windowElement.querySelector('.chatbot-embed-messages');
                const messageEl = document.createElement('div');
                messageEl.className = `chatbot-embed-message ${senderType}`;
                messageEl.innerHTML = this.formatMessage(content);
                messagesEl.appendChild(messageEl);
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }
        }

        /**
         * Format message content
         */
        formatMessage(content) {
            // Basic markdown-like formatting
            let formatted = this.escapeHtml(content);

            // Bold: **text**
            formatted = formatted.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');

            // Italic: *text*
            formatted = formatted.replace(/\*(.*?)\*/g, '<em>$1</em>');

            // Code: `code`
            formatted = formatted.replace(/`(.*?)`/g, '<code style="background: rgba(0,0,0,0.1); padding: 2px 4px; border-radius: 3px;">$1</code>');

            // Line breaks
            formatted = formatted.replace(/\n/g, '<br>');

            return formatted;
        }

        /**
         * Show typing indicator - works for both modes
         */
        showTypingIndicator() {
            if (this.useTargetMode) {
                this.showTargetTyping();
            } else {
                const messagesEl = this.windowElement.querySelector('.chatbot-embed-messages');
                const typingEl = document.createElement('div');
                typingEl.className = 'chatbot-embed-typing';
                typingEl.id = 'typing-indicator';
                typingEl.innerHTML = '<div class="dot"></div><div class="dot"></div><div class="dot"></div>';
                messagesEl.appendChild(typingEl);
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }
        }

        /**
         * Hide typing indicator - works for both modes
         */
        hideTypingIndicator() {
            if (this.useTargetMode) {
                this.hideTargetTyping();
            } else {
                const typingEl = this.windowElement.querySelector('#typing-indicator');
                if (typingEl) {
                    typingEl.remove();
                }
            }
        }

        /**
         * End chat
         */
        async endChat() {
            if (!confirm('Are you sure you want to end this chat?')) {
                return;
            }

            try {
                await this.apiRequest('/end', 'POST', {
                    session_id: this.sessionId,
                });
            } catch (error) {
                console.error('Chatbot Embed: Failed to end conversation', error);
            }

            // Clear session
            this.sessionId = null;
            this.conversationId = null;
            localStorage.removeItem(`chatbot_embed_session_${this.config.token.substring(0, 8)}`);

            // Reset to welcome screen
            this.waitingForName = true;
            this.updateView();

            // Clear messages
            const messagesEl = this.windowElement.querySelector('.chatbot-embed-messages');
            messagesEl.innerHTML = '';
        }

        /**
         * Escape HTML
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // Initialize widget when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            const widget = new ChatbotEmbed(CONFIG);
            widget.init();
        });
    } else {
        const widget = new ChatbotEmbed(CONFIG);
        widget.init();
    }
})();
