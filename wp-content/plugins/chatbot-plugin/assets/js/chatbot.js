/**
 * Chatbot Plugin Frontend JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        const chatButton = $('#chatButton');
        const chatbotContainer = $('.chatbot-container');
        const chatbotClose = $('#chatbot-close');
        const chatbotMessages = $('.chatbot-messages');
        const chatbotInput = $('.chatbot-input');
        const chatbotSendBtn = $('.chatbot-send-btn');
        const chatbotEndBtn = $('.chatbot-end-btn');
        const chatbotWelcomeScreen = $('.chatbot-welcome-screen');
        const chatbotNameInput = $('.chatbot-name-input');
        const chatbotStartBtn = $('.chatbot-start-btn');
        const chatbotInputContainer = $('.chatbot-input-container');

        // Detect inline mode from data attribute
        const isInlineMode = chatbotContainer.data('mode') === 'inline';
        const skipWelcome = chatbotContainer.data('skip-welcome') === true || chatbotContainer.data('skip-welcome') === 'true';

        // TTS/STT Variables
        let ttsAutoPlay = localStorage.getItem('chatbot_tts_autoplay') === 'true';
        let currentAudio = null;
        let mediaRecorder = null;
        let audioChunks = [];
        let ttsQueue = []; // Queue for TTS requests
        let ttsProcessing = false; // Flag to prevent concurrent TTS
        const chatbotMicBtn = $('.chatbot-mic-btn');
        const chatbotTtsToggle = $('#chatbot-tts-autoplay');

        // Initialize TTS toggle from localStorage
        chatbotTtsToggle.prop('checked', ttsAutoPlay);

        // TTS Toggle event handler
        chatbotTtsToggle.on('change', function() {
            ttsAutoPlay = $(this).is(':checked');
            localStorage.setItem('chatbot_tts_autoplay', ttsAutoPlay);
            console.log('TTS Auto-play:', ttsAutoPlay ? 'enabled' : 'disabled');
        });

        // Speaker icon SVG for messages
        const speakerIconSVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>';

        // Process TTS queue one at a time
        async function processTTSQueue() {
            if (ttsProcessing || ttsQueue.length === 0) {
                return;
            }

            ttsProcessing = true;
            const { text, button, retryCount } = ttsQueue.shift();

            try {
                await executeTTS(text, button, retryCount);
            } finally {
                ttsProcessing = false;
                // Process next in queue after a small delay
                if (ttsQueue.length > 0) {
                    setTimeout(processTTSQueue, 500);
                }
            }
        }

        // Add TTS request to queue
        function playTTS(text, button) {
            // Stop any currently playing audio first
            if (currentAudio) {
                currentAudio.pause();
                currentAudio = null;
                $('.chatbot-speaker-btn').removeClass('playing');
            }

            // Add to queue
            ttsQueue.push({ text, button, retryCount: 0 });

            // Start processing if not already
            processTTSQueue();
        }

        // Execute TTS for a message via AJAX (server-side API call)
        async function executeTTS(text, button, retryCount = 0) {
            // Show loading state
            button.addClass('loading').removeClass('playing');

            try {
                console.log('Generating TTS for:', text.substring(0, 50) + '...');

                // Make AJAX call to server for TTS
                const response = await $.ajax({
                    url: chatbotPluginVars.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'chatbot_generate_speech',
                        text: text,
                        voice: 'alloy',
                        nonce: chatbotPluginVars.nonce
                    },
                    timeout: 30000 // 30 second timeout
                });

                if (!response.success) {
                    throw new Error(response.data?.message || 'TTS failed');
                }

                // Convert base64 audio to blob
                const audioData = response.data.audio_data;
                const byteCharacters = atob(audioData);
                const byteNumbers = new Array(byteCharacters.length);
                for (let i = 0; i < byteCharacters.length; i++) {
                    byteNumbers[i] = byteCharacters.charCodeAt(i);
                }
                const byteArray = new Uint8Array(byteNumbers);
                const audioBlob = new Blob([byteArray], { type: 'audio/mpeg' });

                // Create audio and play
                const audioUrl = URL.createObjectURL(audioBlob);
                currentAudio = new Audio(audioUrl);

                return new Promise((resolve, reject) => {
                    currentAudio.onplay = function() {
                        button.removeClass('loading').addClass('playing');
                    };

                    currentAudio.onended = function() {
                        button.removeClass('playing');
                        URL.revokeObjectURL(audioUrl);
                        currentAudio = null;
                        resolve();
                    };

                    currentAudio.onerror = function(e) {
                        console.error('Audio playback error:', e);
                        button.removeClass('loading playing');
                        URL.revokeObjectURL(audioUrl);
                        currentAudio = null;
                        reject(e);
                    };

                    currentAudio.play().then(() => {
                        console.log('TTS playback started');
                    }).catch(reject);
                });

            } catch (error) {
                console.error('TTS error:', error);
                button.removeClass('loading playing');

                // Retry on server errors (up to 2 retries)
                if (retryCount < 2 && error.message && error.message.includes('Internal Server Error')) {
                    console.log('Retrying TTS after error, attempt', retryCount + 1);
                    await new Promise(resolve => setTimeout(resolve, 1000 * (retryCount + 1)));
                    return executeTTS(text, button, retryCount + 1);
                }
            }
        }

        // Stop current audio playback
        function stopAudio() {
            if (currentAudio) {
                currentAudio.pause();
                currentAudio = null;
                $('.chatbot-speaker-btn').removeClass('playing loading');
            }
        }

        // Start voice recording for STT
        async function startRecording() {
            try {
                console.log('Requesting microphone access...');
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });

                // Determine best audio format
                let mimeType = 'audio/webm';
                if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
                    mimeType = 'audio/webm;codecs=opus';
                } else if (MediaRecorder.isTypeSupported('audio/mp4')) {
                    mimeType = 'audio/mp4';
                }

                mediaRecorder = new MediaRecorder(stream, { mimeType: mimeType });
                audioChunks = [];

                mediaRecorder.ondataavailable = function(e) {
                    if (e.data.size > 0) {
                        audioChunks.push(e.data);
                    }
                };

                mediaRecorder.onstop = async function() {
                    console.log('Recording stopped, processing audio...');
                    chatbotMicBtn.removeClass('recording').addClass('processing');

                    const audioBlob = new Blob(audioChunks, { type: mimeType });
                    console.log('Audio blob created:', audioBlob.size, 'bytes');

                    // Stop all tracks
                    stream.getTracks().forEach(track => track.stop());

                    // Transcribe the audio via AJAX
                    await transcribeAudio(audioBlob);

                    chatbotMicBtn.removeClass('processing');
                };

                mediaRecorder.onerror = function(e) {
                    console.error('MediaRecorder error:', e);
                    chatbotMicBtn.removeClass('recording processing');
                    stream.getTracks().forEach(track => track.stop());
                };

                mediaRecorder.start();
                chatbotMicBtn.addClass('recording');
                console.log('Recording started');

            } catch (error) {
                console.error('Microphone error:', error);
                chatbotMicBtn.removeClass('recording processing');

                if (error.name === 'NotAllowedError') {
                    alert('Microphone access was denied. Please allow microphone access in your browser settings.');
                } else if (error.name === 'NotFoundError') {
                    alert('No microphone found. Please connect a microphone and try again.');
                } else {
                    alert('Could not access microphone. Please check your permissions.');
                }
            }
        }

        // Stop recording
        function stopRecording() {
            if (mediaRecorder && mediaRecorder.state === 'recording') {
                mediaRecorder.stop();
                console.log('Stopping recording...');
            }
        }

        // Transcribe audio via AJAX (server-side API call)
        async function transcribeAudio(audioBlob) {
            try {
                console.log('Transcribing audio...');

                // Convert blob to base64
                const reader = new FileReader();
                const base64Promise = new Promise((resolve, reject) => {
                    reader.onloadend = () => {
                        // Remove data URL prefix (e.g., "data:audio/webm;base64,")
                        const base64 = reader.result.split(',')[1];
                        resolve(base64);
                    };
                    reader.onerror = reject;
                });
                reader.readAsDataURL(audioBlob);
                const audioBase64 = await base64Promise;

                // Make AJAX call to server for STT
                const response = await $.ajax({
                    url: chatbotPluginVars.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'chatbot_transcribe_audio',
                        audio_data: audioBase64,
                        nonce: chatbotPluginVars.nonce
                    }
                });

                console.log('Transcription response:', response);

                if (response.success && response.data.text) {
                    // Insert transcribed text into input field
                    const currentText = chatbotInput.val();
                    const newText = currentText ? currentText + ' ' + response.data.text : response.data.text;
                    chatbotInput.val(newText);
                    chatbotInput.focus();

                    // Update character counter
                    chatbotInput.trigger('input');

                    console.log('Transcription inserted:', response.data.text);
                } else {
                    console.warn('No text in transcription result');
                    if (response.data?.message) {
                        alert('Transcription failed: ' + response.data.message);
                    }
                }

            } catch (error) {
                console.error('STT error:', error);
                alert('Could not transcribe audio. Please try again.');
            }
        }

        // Microphone button click handler (toggle recording)
        chatbotMicBtn.on('click', function() {
            if (mediaRecorder && mediaRecorder.state === 'recording') {
                stopRecording();
            } else {
                startRecording();
            }
        });

        // Detect if we're on mobile
        let isMobile = window.innerWidth <= 768;

        // Update mobile detection on window resize
        $(window).on('resize', function() {
            isMobile = window.innerWidth <= 768;
        });

        // Toggle chat window (only for floating mode)
        if (!isInlineMode) {
            chatButton.on('click', function() {
                chatbotContainer.toggleClass('active');

                if (chatbotContainer.hasClass('active')) {
                    // If on mobile, hide the chat button when chat is open
                    if (isMobile) {
                        chatButton.addClass('hidden-mobile');
                    }

                    // If conversation already exists, focus on input field, otherwise focus on name input
                    if (conversationId) {
                        chatbotInput.focus();
                        chatbotWelcomeScreen.hide();
                        $('.chatbot-header').show();
                        chatbotMessages.show();
                        chatbotInputContainer.show();
                    } else {
                        // Show welcome screen and focus on name input
                        chatbotWelcomeScreen.show();
                        $('.chatbot-header').hide();
                        chatbotMessages.hide();
                        chatbotNameInput.focus();
                    }
                }
            });
        }

        // Close chat window (only for floating mode)
        if (!isInlineMode) {
            chatbotClose.on('click', function() {
                chatbotContainer.removeClass('active');
                // If on mobile, show the chat button again when chat is closed
                if (isMobile) {
                    chatButton.removeClass('hidden-mobile');
                }
            });

            // Close welcome screen
            $('#welcome-close').on('click', function() {
                chatbotContainer.removeClass('active');
                // If on mobile, show the chat button again when welcome screen is closed
                if (isMobile) {
                    chatButton.removeClass('hidden-mobile');
                }
            });
        } else {
            // In inline mode, hide the close buttons
            chatbotClose.hide();
            $('#welcome-close').hide();
        }
        
        let conversationId = null;
        let visitorName = '';
        let pollInterval = null;
        
        // Hide loading animation initially
        $('#chatbot-loading').hide();
        
        // Loading timeout to ensure it doesn't stay visible forever
        function setLoadingTimeout() {
            // Hide loading indicator after 10 seconds in case of issues
            setTimeout(function() {
                $('#chatbot-loading').hide();
                
                // Update status if it shows "connecting" or similar
                if ($('.chatbot-status').text().indexOf('onnect') > -1) {
                    $('.chatbot-status').text('Connected');
                }
            }, 10000);
        }
        
        // Configure marked.js for safe rendering
        if (typeof marked !== 'undefined') {
            marked.setOptions({
                breaks: true,      // Convert \n to <br>
                gfm: true,         // GitHub Flavored Markdown
                headerIds: false,  // Don't add IDs to headers (security)
                mangle: false      // Don't mangle email addresses
            });
        }

        // Function to safely render markdown (only for AI/bot messages)
        function renderMarkdown(text) {
            if (typeof marked === 'undefined') {
                // Fallback: escape HTML and convert newlines to <br>
                return $('<div>').text(text).html().replace(/\n/g, '<br>');
            }

            try {
                // Parse markdown
                let html = marked.parse(text);

                // Make links open in new tab and add security attributes
                html = html.replace(/<a /g, '<a target="_blank" rel="noopener noreferrer" ');

                return html;
            } catch (e) {
                console.error('Markdown parsing error:', e);
                // Fallback to plain text
                return $('<div>').text(text).html().replace(/\n/g, '<br>');
            }
        }

        // Track message IDs that have already had TTS played
        let playedTTSMessages = new Set();

        // Function to add a new message to the chat
        function addMessage(message, senderType, preventScroll, messageId) {
            // Remove loading animation if it exists
            $('#chatbot-loading').hide();

            // Only hide typing indicator for AI responses, keep it for user messages
            if (senderType === 'ai' || senderType === 'admin') {
                hideTypingIndicator();
            }

            const messageElement = $('<div class="chatbot-message ' + senderType + '"></div>');

            // Create wrapper for message content (for flex layout with speaker button)
            const messageWrapper = $('<div class="chatbot-message-wrapper"></div>');

            // Add sender label for AI and admin messages
            if (senderType === 'ai' || senderType === 'admin') {
                const senderLabel = $('<div class="chatbot-message-sender"></div>');
                senderLabel.text(senderType === 'ai' ? 'AI Assistant' : 'Admin');
                messageWrapper.append(senderLabel);
            } else if (senderType === 'system') {
                const senderLabel = $('<div class="chatbot-message-sender"></div>');
                senderLabel.text('System');
                messageElement.append(senderLabel);
            }

            // Add message text - render markdown for AI/admin messages, plain text for user
            const messageText = $('<div class="chatbot-message-content"></div>');
            if (senderType === 'ai' || senderType === 'admin') {
                // Render markdown for AI/admin messages
                messageText.html(renderMarkdown(message));
                messageWrapper.append(messageText);
                messageElement.append(messageWrapper);

                // Add speaker button for TTS
                const speakerBtn = $('<button class="chatbot-speaker-btn" title="Play audio"></button>');
                speakerBtn.html(speakerIconSVG);

                // Store the message text for TTS
                speakerBtn.data('message', message);

                // Click handler for speaker button
                speakerBtn.on('click', function(e) {
                    e.stopPropagation();
                    const btn = $(this);
                    const text = btn.data('message');

                    // If currently playing, stop
                    if (btn.hasClass('playing')) {
                        stopAudio();
                    } else {
                        playTTS(text, btn);
                    }
                });

                messageElement.append(speakerBtn);

                // Auto-play TTS if enabled and this is a new message (not from history)
                // Use messageId to avoid playing TTS multiple times for the same message
                const msgKey = messageId || message.substring(0, 50);
                if (ttsAutoPlay && !preventScroll && !playedTTSMessages.has(msgKey)) {
                    playedTTSMessages.add(msgKey);
                    // Delay slightly to ensure message is rendered
                    setTimeout(function() {
                        playTTS(message, speakerBtn);
                    }, 100);
                }
            } else {
                // Plain text for user messages (security: prevent XSS)
                messageText.text(message);
                messageElement.append(messageText);
            }

            chatbotMessages.append(messageElement);

            // Only scroll to bottom if not prevented (new messages)
            if (!preventScroll) {
                chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
            }
        }
        
        // Function to show typing indicator
        function showTypingIndicator() {
            // Hide loading animation
            $('#chatbot-loading').hide();

            // Remove any existing typing bubble
            $('.chatbot-typing-bubble').remove();

            // Create typing bubble with dots animation
            const typingBubble = $(`
                <div class="chatbot-typing-bubble">
                    <div class="chatbot-typing-dots">
                        <span class="dot"></span>
                        <span class="dot"></span>
                        <span class="dot"></span>
                    </div>
                </div>
            `);

            // Add to messages area
            chatbotMessages.append(typingBubble);
            chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);

            // Disable input while waiting for response
            chatbotInput.prop('disabled', true);
            chatbotSendBtn.prop('disabled', true);
            $('.chatbot-mic-btn').prop('disabled', true);

            // Set flag to track typing state
            window.typingIndicatorShown = true;

            // Set a safety timeout to show message if it takes too long
            setTimeout(function() {
                // Only show message if still waiting
                if (window.typingIndicatorShown) {
                    chatbotMessages.append('<div class="chatbot-system-message">Our assistant is still thinking. Please wait a moment...</div>');
                    chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                }
            }, 15000); // Show message after 15 seconds if no response
        }

        // Function to hide typing indicator
        function hideTypingIndicator() {
            window.typingIndicatorShown = false;
            $('.chatbot-typing-bubble').remove();

            // Re-enable input
            chatbotInput.prop('disabled', false);
            chatbotSendBtn.prop('disabled', false);
            $('.chatbot-mic-btn').prop('disabled', false);
            chatbotInput.focus();
        }
        
        // Function to start a new conversation
        function startConversation(name) {
            if (name.trim() === '') {
                alert('Please enter your name to start the chat.');
                return;
            }

            visitorName = name.trim();

            // Hide welcome screen, show messages area and chat interface with loading animation
            chatbotWelcomeScreen.hide();
            // Only show header in floating mode (inline mode hides it via CSS)
            if (!isInlineMode) {
                $('.chatbot-header').show();
            }
            chatbotMessages.show(); // Show messages area
            chatbotInputContainer.show();
            $('#chatbot-loading').show();
            setLoadingTimeout(); // Set timeout to hide loading indicator

            // Show connection status directly in chat
            chatbotMessages.append('<div class="chatbot-system-message">Connecting to AI assistant...</div>');
            
            // Get config name from data attribute if available
            const configName = $('.chatbot-container').attr('data-config-name') || 'Default';
            
            // Starting conversation with configuration
            
            $.ajax({
                url: chatbotPluginVars.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chatbot_start_conversation',
                    visitor_name: visitorName,
                    config_name: configName,
                    nonce: chatbotPluginVars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        conversationId = response.data.conversation_id;
                        
                        // Hide loading animation
                        $('#chatbot-loading').hide();
                        
                        // Clear any existing messages and add welcome message from admin
                        chatbotMessages.empty().show();
                        
                        // This is explicitly an admin message, not an AI response
                        // Use the chat greeting from settings with visitor name
                        const defaultGreeting = 'Hello %s! How can I help you today?';
                        let greeting = chatbotPluginVars.chatGreeting || defaultGreeting;
                        greeting = greeting.replace('%s', visitorName);
                        addMessage(greeting, 'admin', false);
                        
                        // Start polling for new messages
                        startPolling();
                    } else {
                        chatbotMessages.append('<div class="chatbot-system-message">Error starting conversation. Please try again.</div>');
                        chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                        $('#chatbot-loading').hide();
                    }
                },
                error: function() {
                    // Just hide loading animation without showing error message
                    $('#chatbot-loading').hide();
                }
            });
        }
        
        // Function to send a message to the server
        function sendMessage(message) {
            if (!conversationId) {
                return;
            }
            
            // Show typing indicator for AI response
            showTypingIndicator();
            
            $.ajax({
                url: chatbotPluginVars.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chatbot_send_message',
                    conversation_id: conversationId,
                    message: message,
                    sender_type: 'user',
                    nonce: chatbotPluginVars.nonce
                },
                success: function(response) {
                    // Clear any error timer since connection succeeded
                    if (window.fetchErrorTimer) {
                        clearTimeout(window.fetchErrorTimer);
                        window.fetchErrorTimer = null;
                    }
                    
                    if (response.success) {
                        // Check if we need to add the message (it might already be added by polling)
                        if (!response.data.message_already_displayed) {
                            // The message might be already added by the user,
                            // but we're ensuring it's in the chat
                            addMessage(message, 'user', false);
                            
                            // Show typing indicator again after adding the user message
                            showTypingIndicator();
                        }
                    } else {
                        // Hide typing indicator and show error
                        hideTypingIndicator();
                        chatbotMessages.append('<div class="chatbot-system-message">Error sending message. Please try again.</div>');
                        chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                        
                        // If conversation is not active, notify the user
                        if (response.data && response.data.message === 'Conversation is not active.') {
                            chatbotMessages.append('<div class="chatbot-system-message">This conversation has been ended. Please start a new conversation.</div>');
                            chatbotInput.prop('disabled', true);
                            chatbotSendBtn.prop('disabled', true);
                            chatbotEndBtn.prop('disabled', true);
                            
                            // Show option to start a new chat
                            chatbotMessages.append('<div class="chatbot-system-message"><button class="chatbot-new-chat-btn">Start New Chat</button></div>');
                            
                            // Add event listener for new chat button
                            $('.chatbot-new-chat-btn').on('click', resetChat);
                        }
                    }
                },
                error: function() {
                    // Just hide typing indicator without showing error message
                    hideTypingIndicator();
                }
            });
        }

        // Function to end the current conversation
        function endConversation() {
            if (!conversationId) {
                return;
            }
            
            // Confirm before ending the chat
            if (!confirm('Are you sure you want to end this conversation? You won\'t be able to continue it later.')) {
                return;
            }
            
            // Show end chat message
            chatbotMessages.append('<div class="chatbot-system-message">Ending conversation...</div>');
            chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
            
            $.ajax({
                url: chatbotPluginVars.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chatbot_end_conversation',
                    conversation_id: conversationId,
                    nonce: chatbotPluginVars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Clear conversation data from localStorage
                        localStorage.removeItem('chatbot_conversation_id');
                        localStorage.removeItem('chatbot_visitor_name');
                        localStorage.removeItem('chatbot_config_name');
                        
                        // Show success message and disable input
                        chatbotMessages.append('<div class="chatbot-system-message">This conversation has ended. Thank you for chatting with us!</div>');
                        
                        // Add button to start a new chat
                        chatbotMessages.append('<div class="chatbot-system-message"><button class="chatbot-new-chat-btn">Start New Chat</button></div>');
                        
                        // Disable input and buttons
                        chatbotInput.prop('disabled', true);
                        chatbotSendBtn.prop('disabled', true);
                        chatbotEndBtn.prop('disabled', true);
                        
                        // Add event listener for new chat button
                        $('.chatbot-new-chat-btn').on('click', resetChat);
                        
                        // Scroll to bottom
                        chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                        
                        // Stop polling for new messages
                        if (pollInterval) {
                            clearInterval(pollInterval);
                            pollInterval = null;
                        }
                    } else {
                        chatbotMessages.append('<div class="chatbot-system-message">Error ending conversation. Please try again.</div>');
                        chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                    }
                },
                error: function() {
                    // Silent error handling without showing any message
                }
            });
        }
        
        // Function to reset chat and start a new conversation
        function resetChat() {
            // Reset conversation variables
            conversationId = null;
            visitorName = '';
            
            // Clear localStorage
            localStorage.removeItem('chatbot_conversation_id');
            localStorage.removeItem('chatbot_visitor_name');
            localStorage.removeItem('chatbot_config_name');
            
            // Reset UI
            chatbotWelcomeScreen.show();
            $('.chatbot-header').hide();
            chatbotMessages.hide().empty();
            chatbotInputContainer.hide();
            
            // Reset input and buttons
            chatbotInput.prop('disabled', false);
            chatbotSendBtn.prop('disabled', false);
            chatbotEndBtn.prop('disabled', false);
            chatbotInput.val('');
            chatbotNameInput.val('').focus();
            
            // Stop polling
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }
        
        // Function to poll for new messages
        function startPolling() {
            // Clear any existing interval
            if (pollInterval) {
                clearInterval(pollInterval);
            }
            
            // Immediately fetch messages instead of waiting for the first interval
            fetchMessages();
            
            // Set up polling interval (every 5 seconds)
            pollInterval = setInterval(function() {
                if (!conversationId) {
                    return;
                }
                
                fetchMessages();
            }, 5000); // Poll every 5 seconds
        }
        
        // Function to fetch messages from the server
        function fetchMessages() {
            if (!conversationId) {
                return;
            }
                
            $.ajax({
                url: chatbotPluginVars.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chatbot_get_messages',
                    conversation_id: conversationId,
                    nonce: chatbotPluginVars.nonce
                },
                success: function(response) {
                    // Clear any error timer since connection succeeded
                    if (window.fetchErrorTimer) {
                        clearTimeout(window.fetchErrorTimer);
                        window.fetchErrorTimer = null;
                    }
                    
                    if (response.success) {
                        // Hide loading indicator and make sure messages area is visible
                        $('#chatbot-loading').hide();
                        chatbotMessages.show();
                        
                        // Get the current message count to detect new messages
                        const currentMessageCount = $('.chatbot-message').length;
                        const hasNewMessages = response.data.messages.length > currentMessageCount;
                        
                        // Check if conversation is no longer active
                        if (response.data.conversation_status !== 'active' && 
                            chatbotInput.prop('disabled') === false) {
                            
                            // Disable input if conversation is not active
                            chatbotInput.prop('disabled', true);
                            chatbotSendBtn.prop('disabled', true);
                            chatbotEndBtn.prop('disabled', true);
                            
                            // Show message about conversation status
                            let statusMessage = '';
                            if (response.data.conversation_status === 'ended') {
                                statusMessage = 'This conversation has been ended.';
                            } else if (response.data.conversation_status === 'archived') {
                                statusMessage = 'This conversation has been archived.';
                            } else {
                                statusMessage = 'This conversation is no longer active.';
                            }
                            
                            // Add status message to chat
                            chatbotMessages.append('<div class="chatbot-system-message">' + statusMessage + '</div>');
                            
                            // Add button to start a new chat
                            chatbotMessages.append('<div class="chatbot-system-message"><button class="chatbot-new-chat-btn">Start New Chat</button></div>');
                            
                            // Scroll to bottom
                            chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                            
                            // Add event listener for new chat button
                            $('.chatbot-new-chat-btn').on('click', resetChat);
                            
                            // Clear localStorage to prevent auto-resuming this conversation
                            localStorage.removeItem('chatbot_conversation_id');
                            localStorage.removeItem('chatbot_visitor_name');
                            localStorage.removeItem('chatbot_config_name');
                            
                            // Stop polling
                            if (pollInterval) {
                                clearInterval(pollInterval);
                                pollInterval = null;
                            }
                            
                            return;
                        }
                        
                        // Only update when there are new messages or on first load
                        if (hasNewMessages || currentMessageCount === 0) {
                            // Clear messages area and repopulate with all messages
                            chatbotMessages.empty();

                            // Find the last AI message index (for auto-play TTS)
                            let lastAiMessageIndex = -1;
                            if (hasNewMessages) {
                                for (let i = response.data.messages.length - 1; i >= 0; i--) {
                                    const sType = response.data.messages[i].sender_type;
                                    if (sType === 'bot' || sType === 'ai' || sType === 'admin') {
                                        lastAiMessageIndex = i;
                                        break;
                                    }
                                }
                            }

                            // Add all messages
                            response.data.messages.forEach(function(msg, index) {
                                // Determine if the message is from AI or admin
                                let senderType = msg.sender_type;
                                if (senderType === 'bot') {
                                    senderType = 'ai';
                                }
                                // Only allow TTS auto-play for the last AI message when there are new messages
                                const isLastAiMessage = hasNewMessages && index === lastAiMessageIndex;
                                const preventTTS = !isLastAiMessage;
                                addMessage(msg.message, senderType, preventTTS, msg.id);
                            });

                            // If there are new messages from the AI, hide the typing indicator
                            if (hasNewMessages) {
                                // Check if we received an AI message
                                const hasNewAiMessage = response.data.messages.some(function(msg) {
                                    return (msg.sender_type === 'bot' || msg.sender_type === 'ai');
                                });

                                if (hasNewAiMessage) {
                                    hideTypingIndicator();
                                }
                            }
                        }
                    }
                },
                error: function() {
                    // Just hide loading animation without showing error message
                    $('#chatbot-loading').hide();
                }
            });
        }
        
        // Handle start button click
        chatbotStartBtn.on('click', function() {
            const name = chatbotNameInput.val().trim();
            startConversation(name);
        });
        
        // Handle name input enter key
        chatbotNameInput.on('keypress', function(e) {
            if (e.which === 13) {
                const name = chatbotNameInput.val().trim();
                startConversation(name);
                e.preventDefault();
            }
        });
        
        // Add character counter to input options row
        const charCounter = $('<div class="chatbot-char-counter"></div>');
        chatbotInputContainer.find('.chatbot-input-options').prepend(charCounter);

        // Function to update character counter
        function updateCharCounter() {
            const message = chatbotInput.val();
            const maxLength = chatbotPluginVars.maxMessageLength || 500;
            const currentLength = message.length;
            const remainingChars = maxLength - currentLength;

            // Update counter text
            charCounter.text(currentLength + '/' + maxLength);

            // Add warning if approaching limit
            if (remainingChars < 50) {
                charCounter.addClass('warning');
            } else {
                charCounter.removeClass('warning');
            }

            // Add error if over limit
            if (remainingChars < 0) {
                charCounter.addClass('error');
                chatbotSendBtn.prop('disabled', true);
            } else {
                charCounter.removeClass('error');
                chatbotSendBtn.prop('disabled', false);
            }
        }

        // Update counter on input
        chatbotInput.on('input', updateCharCounter);

        // Initialize counter
        updateCharCounter();

        // Handle send button click
        chatbotSendBtn.on('click', function() {
            const message = chatbotInput.val().trim();
            const maxLength = chatbotPluginVars.maxMessageLength || 500;

            if (message !== '' && message.length <= maxLength) {
                addMessage(message, 'user', false);
                chatbotInput.val('');
                sendMessage(message);
                updateCharCounter();
            } else if (message.length > maxLength) {
                // Show error message if too long
                chatbotMessages.append('<div class="chatbot-system-message">Your message is too long. Maximum ' + maxLength + ' characters allowed.</div>');
                chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
            }
        });

        // Handle end chat button click
        chatbotEndBtn.on('click', function() {
            endConversation();
        });

        // Handle enter key press in input
        chatbotInput.on('keypress', function(e) {
            if (e.which === 13) {
                const message = chatbotInput.val().trim();
                const maxLength = chatbotPluginVars.maxMessageLength || 500;

                if (message !== '' && message.length <= maxLength) {
                    addMessage(message, 'user', false);
                    chatbotInput.val('');
                    sendMessage(message);
                    updateCharCounter();
                } else if (message.length > maxLength) {
                    // Show error message if too long
                    chatbotMessages.append('<div class="chatbot-system-message">Your message is too long. Maximum ' + maxLength + ' characters allowed.</div>');
                    chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                }

                e.preventDefault();
            }
        });
        
        // Check if there's a stored conversation in localStorage
        const storedConversationId = localStorage.getItem('chatbot_conversation_id');
        const storedVisitorName = localStorage.getItem('chatbot_visitor_name');
        const storedConfigName = localStorage.getItem('chatbot_config_name');
        const currentConfigName = $('.chatbot-container').attr('data-config-name') || '';
        
        // Only restore if conversation exists and either there's no config name specified
        // or the stored config name matches the current config name
        const shouldRestore = storedConversationId && storedVisitorName && 
                             (currentConfigName === '' || storedConfigName === currentConfigName);
        
        if (shouldRestore) {
            // Resume conversation
            conversationId = storedConversationId;
            visitorName = storedVisitorName;

            // Hide welcome screen, show messages and chat interface
            chatbotWelcomeScreen.hide();
            // Only show header in floating mode (inline mode hides it via CSS)
            if (!isInlineMode) {
                $('.chatbot-header').show();
            }
            chatbotMessages.show(); // Show messages area
            chatbotInputContainer.show();

            // Show loading animation and set a timeout to hide it
            $('#chatbot-loading').show();
            setLoadingTimeout();

            // Show reconnection message
            chatbotMessages.append('<div class="chatbot-system-message">Reconnecting to previous session...</div>');
            chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);

            // Start polling for new messages
            startPolling();
        }
        
        // Handle window close/reload to save conversation state
        $(window).on('beforeunload', function() {
            if (conversationId) {
                localStorage.setItem('chatbot_conversation_id', conversationId);
                localStorage.setItem('chatbot_visitor_name', visitorName);
                localStorage.setItem('chatbot_is_open', chatbotContainer.hasClass('active') ? 'true' : 'false');
                
                // Store config name if available
                const configName = $('.chatbot-container').attr('data-config-name') || '';
                if (configName) {
                    localStorage.setItem('chatbot_config_name', configName);
                }
            }
        });
        
        // Check if the chatbot was open in the previous session (floating mode only)
        if (!isInlineMode && localStorage.getItem('chatbot_is_open') === 'true') {
            chatbotContainer.addClass('active');
        }

        // Inline mode initialization
        if (isInlineMode) {
            // Container is already visible (has .active class from PHP)
            // Check if we should restore a previous conversation
            if (shouldRestore) {
                // Already handled above - conversation restored
                console.log('Inline mode: Restored previous conversation');
            } else if (skipWelcome) {
                // Skip welcome screen and start conversation immediately with "Guest"
                console.log('Inline mode: Skipping welcome, auto-starting conversation');
                chatbotWelcomeScreen.hide();
                chatbotMessages.show();
                chatbotInputContainer.show();

                // Auto-start conversation with "Guest" name
                startConversation('Guest');
            } else {
                // Show welcome screen as usual
                console.log('Inline mode: Showing welcome screen');
                chatbotWelcomeScreen.show();
                chatbotMessages.hide();
                chatbotInputContainer.hide();
                chatbotNameInput.focus();
            }
        }
    });

})(jQuery);