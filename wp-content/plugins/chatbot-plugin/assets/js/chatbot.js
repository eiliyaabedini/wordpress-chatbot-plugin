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

        // File Upload Variables - simplified to one file
        let selectedFile = null;
        const maxFileSize = 20 * 1024 * 1024; // 20MB max
        const supportedFileTypes = {
            'image/jpeg': 'image',
            'image/jpg': 'image',
            'image/png': 'image',
            'image/gif': 'image',
            'image/webp': 'image',
            'application/pdf': 'pdf',
            'text/plain': 'document',
            'text/csv': 'document',
            'application/msword': 'document',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'document',
            'application/vnd.ms-excel': 'spreadsheet',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'spreadsheet'
        };

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

        // File Upload UI and Handlers - Simplified for single file
        const fileInput = chatbotInputContainer.find('.chatbot-file-input');
        const attachBtn = chatbotInputContainer.find('.chatbot-attach-btn');
        const filePreviewContainer = chatbotInputContainer.find('.chatbot-file-preview-container');

        // Remove multiple attribute - only allow one file
        fileInput.removeAttr('multiple');

        // Attach button click handler
        attachBtn.off('click').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            // Only open file picker if no file selected
            if (!selectedFile) {
                fileInput[0].click();
            }
            return false;
        });

        // File input change handler
        fileInput.off('change').on('change', function(e) {
            e.stopPropagation();
            if (this.files && this.files.length > 0) {
                const file = this.files[0];
                handleSingleFile(file);
            }
            // Reset input so same file can be selected again
            this.value = '';
        });

        // Handle single file selection
        function handleSingleFile(file) {
            // Check file type
            if (!supportedFileTypes[file.type]) {
                alert('File type not supported: ' + file.name + '\nSupported: Images, PDF, Word, Excel, Text files');
                return;
            }

            // Check file size
            if (file.size > maxFileSize) {
                alert('File too large: ' + file.name + '\nMaximum size: 20MB');
                return;
            }

            // Set selected file
            selectedFile = file;
            showFilePreview(file);
        }

        // Show file preview
        function showFilePreview(file) {
            const fileType = supportedFileTypes[file.type] || 'document';

            // Clear previous preview
            filePreviewContainer.empty();

            // Create preview element
            const preview = $('<div class="chatbot-file-preview"></div>');

            // Icon based on file type
            let iconHtml = '';
            if (fileType === 'image') {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.find('.chatbot-file-icon').html('<img src="' + e.target.result + '" alt="preview">');
                };
                reader.readAsDataURL(file);
                iconHtml = '<div class="chatbot-file-icon"></div>';
            } else if (fileType === 'pdf') {
                iconHtml = '<div class="chatbot-file-icon pdf"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg></div>';
            } else {
                iconHtml = '<div class="chatbot-file-icon document"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg></div>';
            }

            // File name (truncated)
            const displayName = file.name.length > 20 ? file.name.substring(0, 17) + '...' : file.name;

            preview.html(iconHtml + '<span class="chatbot-file-name" title="' + file.name + '">' + displayName + '</span><button type="button" class="chatbot-file-remove" title="Remove">&times;</button>');

            // Remove button handler
            preview.find('.chatbot-file-remove').off('click').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                clearSelectedFile();
                return false;
            });

            filePreviewContainer.append(preview);
            filePreviewContainer.css('display', 'flex');
            attachBtn.addClass('has-files');
        }

        // Clear selected file
        function clearSelectedFile() {
            selectedFile = null;
            filePreviewContainer.empty();
            filePreviewContainer.hide();
            attachBtn.removeClass('has-files');
        }

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

                    // Show welcome message if no conversation
                    if (!conversationId) {
                        showWelcomeMessage();
                    }

                    chatbotInput.focus();
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
        function addMessage(message, senderType, preventScroll, messageId, isPending) {
            // Remove loading animation if it exists
            $('#chatbot-loading').hide();

            // Only hide typing indicator for AI responses, keep it for user messages
            if (senderType === 'ai' || senderType === 'admin') {
                hideTypingIndicator();
            }

            const messageElement = $('<div class="chatbot-message ' + senderType + '"></div>');

            // Add message ID as data attribute for tracking (prevents duplicate additions)
            if (messageId) {
                messageElement.attr('data-message-id', messageId);
            }

            // Mark as pending if we're waiting for the server to return the ID
            if (isPending) {
                messageElement.attr('data-pending', 'true');
            }

            // Create wrapper for message content (for flex layout with speaker button)
            const messageWrapper = $('<div class="chatbot-message-wrapper"></div>');

            // Add sender label for AI and admin messages
            if (senderType === 'ai' || senderType === 'admin') {
                const senderLabel = $('<div class="chatbot-message-sender"></div>');
                const chatbotName = chatbotContainer.data('chatbot-name') || 'AI Assistant';
                senderLabel.text(chatbotName);
                messageWrapper.append(senderLabel);
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

            // Clear any existing status rotation
            if (window.typingStatusInterval) {
                clearInterval(window.typingStatusInterval);
            }

            const botName = chatbotContainer.data('chatbot-name') || 'Assistant';

            // Status messages that rotate
            const statusMessages = [
                'Thinking',
                'Processing',
                'Analyzing',
                'Working on it'
            ];

            // Extended messages for longer waits
            const extendedMessages = [
                'Still working',
                'Almost there',
                'Just a moment',
                'Complex request'
            ];

            // Create typing bubble with dots and status text
            const typingBubble = $(`
                <div class="chatbot-typing-bubble">
                    <div class="chatbot-typing-content">
                        <div class="chatbot-typing-dots">
                            <span class="dot"></span>
                            <span class="dot"></span>
                            <span class="dot"></span>
                        </div>
                        <span class="chatbot-typing-status">${statusMessages[0]}</span>
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

            // Rotate status messages every 3 seconds
            let messageIndex = 0;
            let useExtended = false;
            window.typingStatusInterval = setInterval(function() {
                if (!window.typingIndicatorShown) {
                    clearInterval(window.typingStatusInterval);
                    return;
                }

                messageIndex++;
                const messages = useExtended ? extendedMessages : statusMessages;

                if (messageIndex >= messages.length) {
                    messageIndex = 0;
                    if (!useExtended) {
                        useExtended = true; // Switch to extended messages after first cycle
                    }
                }

                const newStatus = messages[messageIndex];
                $('.chatbot-typing-status').fadeOut(150, function() {
                    $(this).text(newStatus).fadeIn(150);
                });
            }, 3000);
        }

        // Function to hide typing indicator
        function hideTypingIndicator() {
            window.typingIndicatorShown = false;

            // Clear status rotation interval
            if (window.typingStatusInterval) {
                clearInterval(window.typingStatusInterval);
                window.typingStatusInterval = null;
            }

            $('.chatbot-typing-bubble').remove();

            // Re-enable input
            chatbotInput.prop('disabled', false);
            chatbotSendBtn.prop('disabled', false);
            $('.chatbot-mic-btn').prop('disabled', false);
            chatbotInput.focus();
        }
        
        // Flag to track if we're waiting for name
        let waitingForName = true;

        // Function to show welcome message asking for name
        function showWelcomeMessage() {
            if (conversationId) return; // Already have a conversation

            // Clear messages and show welcome
            chatbotMessages.find('.chatbot-message').remove();
            chatbotMessages.find('.chatbot-system-message').remove();

            const welcomeText = chatbotPluginVars.welcomeMessage || 'Hi there! What\'s your name?';
            addMessage(welcomeText, 'admin', false);

            // Update placeholder
            chatbotInput.attr('placeholder', 'Enter your name...');
            waitingForName = true;
        }

        // Function to start a new conversation
        function startConversation(name) {
            if (name.trim() === '') {
                return;
            }

            visitorName = name.trim();
            waitingForName = false;

            // Update placeholder to normal
            chatbotInput.attr('placeholder', 'Type your message...');

            // Get config name from data attribute if available
            const configName = $('.chatbot-container').attr('data-config-name') || 'Default';

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

                        // Save to localStorage
                        localStorage.setItem('chatbot_conversation_id', conversationId);
                        localStorage.setItem('chatbot_visitor_name', visitorName);
                        localStorage.setItem('chatbot_config_name', configName);

                        // Start polling for new messages (greeting is already in DB from PHP)
                        startPolling();

                        // Fetch messages immediately to show the greeting
                        fetchMessages();
                    }
                }
            });
        }
        
        // Function to send a message to the server
        function sendMessage(message, file) {
            if (!conversationId) {
                return;
            }

            // Show typing indicator for AI response
            showTypingIndicator();

            // Check if we should use the new v2 endpoint with file support
            const hasFile = file !== null && file !== undefined;
            const action = hasFile ? 'chatbot_send_message_v2' : 'chatbot_send_message';

            // Build the request
            let ajaxOptions = {
                url: chatbotPluginVars.ajaxUrl,
                type: 'POST',
                success: function(response) {
                    // Clear any error timer since connection succeeded
                    if (window.fetchErrorTimer) {
                        clearTimeout(window.fetchErrorTimer);
                        window.fetchErrorTimer = null;
                    }

                    if (response.success) {
                        // Update the pending user message with its ID (prevents duplicates if polling runs)
                        if (response.data.message_id) {
                            const pendingUserMsg = $('.chatbot-message.user[data-pending="true"]').last();
                            if (pendingUserMsg.length) {
                                pendingUserMsg.attr('data-message-id', response.data.message_id);
                                pendingUserMsg.removeAttr('data-pending');
                            }
                        }

                        // If we got an AI response, display it immediately
                        const aiResponse = response.data.ai_response || response.data.message;
                        if (aiResponse) {
                            hideTypingIndicator();
                            // Pass the AI message ID to prevent duplicates when polling runs
                            addMessage(aiResponse, response.data.ai_sender_type || 'ai', false, response.data.ai_message_id || response.data.message_id);
                        }

                        // Update conversation ID if returned (for new conversations)
                        if (response.data.conversation_id) {
                            conversationId = response.data.conversation_id;
                            localStorage.setItem('chatbot_conversation_id', conversationId);
                        }
                    } else {
                        // Hide typing indicator and show error
                        hideTypingIndicator();

                        var errorMessage = response.data && response.data.message ? response.data.message : '';
                        var errorType = response.data && response.data.error_type ? response.data.error_type : '';

                        // Check for budget exceeded / insufficient balance errors
                        if (errorType === 'budget_exceeded' ||
                            errorMessage.toLowerCase().includes('budget') ||
                            errorMessage.toLowerCase().includes('balance') ||
                            errorMessage.toLowerCase().includes('insufficient')) {
                            chatbotMessages.append(
                                '<div class="chatbot-system-message chatbot-budget-error">' +
                                '<strong>⚠️ Insufficient Balance</strong><br>' +
                                'The AI service balance is too low to continue. Please contact the site administrator or ' +
                                '<a href="https://aipass.one/panel/dashboard.html" target="_blank" style="color: #8A4FFF;">add funds to AIPass →</a>' +
                                '</div>'
                            );
                        } else if (errorMessage === 'Conversation is not active.') {
                            // If conversation is not active, notify the user
                            chatbotMessages.append('<div class="chatbot-system-message">This conversation has been ended. Please start a new conversation.</div>');
                            chatbotInput.prop('disabled', true);
                            chatbotSendBtn.prop('disabled', true);
                            chatbotEndBtn.prop('disabled', true);

                            // Show option to start a new chat
                            chatbotMessages.append('<div class="chatbot-system-message"><button class="chatbot-new-chat-btn">Start New Chat</button></div>');

                            // Add event listener for new chat button
                            $('.chatbot-new-chat-btn').on('click', resetChat);
                        } else {
                            chatbotMessages.append('<div class="chatbot-system-message">Error: ' + (errorMessage || 'Failed to send message') + '</div>');
                        }

                        chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                    }
                },
                error: function(xhr, status, error) {
                    // Hide typing indicator
                    hideTypingIndicator();
                    // Remove pending flag from user message so polling can recover
                    $('.chatbot-message.user[data-pending="true"]').removeAttr('data-pending');
                    chatbotMessages.append('<div class="chatbot-system-message">Network error. Please try again.</div>');
                    chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                    console.error('AJAX Error:', status, error);
                }
            };

            // Use FormData for file uploads, regular data otherwise
            if (hasFile) {
                const formData = new FormData();
                formData.append('action', action);
                formData.append('conversation_id', conversationId);
                formData.append('message', message);
                formData.append('visitor_name', visitorName || 'Visitor');
                formData.append('nonce', chatbotPluginVars.nonce);

                // Add chatbot config ID if available
                const configId = chatbotContainer.data('chatbot-config-id');
                if (configId) {
                    formData.append('chatbot_config_id', configId);
                }

                // Append single file
                formData.append('files[]', file);

                ajaxOptions.data = formData;
                ajaxOptions.processData = false;
                ajaxOptions.contentType = false;
            } else {
                ajaxOptions.data = {
                    action: action,
                    conversation_id: conversationId,
                    message: message,
                    sender_type: 'user',
                    nonce: chatbotPluginVars.nonce
                };
            }

            $.ajax(ajaxOptions);
        }

        // Function to end the current conversation
        function endConversation() {
            if (!conversationId) {
                return;
            }

            // Store conversation ID to end on server
            const convIdToEnd = conversationId;

            // Immediately reset to welcome screen
            resetChat();

            // End conversation on server in background (no need to wait)
            $.ajax({
                url: chatbotPluginVars.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chatbot_end_conversation',
                    conversation_id: convIdToEnd,
                    nonce: chatbotPluginVars.nonce
                }
            });
        }
        
        // Function to reset chat and start a new conversation
        function resetChat() {
            // Reset conversation variables
            conversationId = null;
            visitorName = '';
            waitingForName = true;

            // Clear localStorage
            localStorage.removeItem('chatbot_conversation_id');
            localStorage.removeItem('chatbot_visitor_name');
            localStorage.removeItem('chatbot_config_name');

            // Clear messages
            chatbotMessages.empty();

            // Reset input
            chatbotInput.prop('disabled', false).val('');
            chatbotSendBtn.prop('disabled', false);
            chatbotEndBtn.prop('disabled', false);

            // Stop polling
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }

            // Show welcome message
            showWelcomeMessage();
            chatbotInput.focus();
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
                        
                        // Filter out system and function messages (only show user/admin/ai messages)
                        const displayableMessages = response.data.messages.filter(function(msg) {
                            return msg.sender_type !== 'system' && msg.sender_type !== 'function';
                        });

                        // Get the highest message ID currently displayed
                        let lastDisplayedId = 0;
                        $('.chatbot-message[data-message-id]').each(function() {
                            const msgId = parseInt($(this).attr('data-message-id')) || 0;
                            if (msgId > lastDisplayedId) {
                                lastDisplayedId = msgId;
                            }
                        });

                        // Check if there's a pending user message (we're waiting for AJAX response)
                        const hasPendingUserMessage = $('.chatbot-message.user[data-pending="true"]').length > 0;

                        // Find new messages (messages with ID > lastDisplayedId)
                        // Skip user messages if we have a pending one to prevent race condition duplicates
                        const newMessages = displayableMessages.filter(function(msg) {
                            if (parseInt(msg.id) <= lastDisplayedId) {
                                return false;
                            }
                            // Skip user messages if there's a pending one (prevents duplicates during AJAX)
                            if (hasPendingUserMessage && msg.sender_type === 'user') {
                                return false;
                            }
                            return true;
                        });

                        // Only update if there are new messages
                        if (newMessages.length > 0 || currentMessageCount === 0) {
                            // If first load, add all messages
                            if (currentMessageCount === 0) {
                                displayableMessages.forEach(function(msg) {
                                    let senderType = msg.sender_type;
                                    if (senderType === 'bot') {
                                        senderType = 'ai';
                                    }
                                    addMessage(msg.message, senderType, true, msg.id);
                                });
                            } else {
                                // Only add new messages
                                newMessages.forEach(function(msg, index) {
                                    let senderType = msg.sender_type;
                                    if (senderType === 'bot') {
                                        senderType = 'ai';
                                    }
                                    // Auto-play TTS only for the last new AI message
                                    const isLastNewAiMessage = (index === newMessages.length - 1) &&
                                        (senderType === 'ai' || senderType === 'admin');
                                    addMessage(msg.message, senderType, !isLastNewAiMessage, msg.id);
                                });
                            }

                            // Hide typing indicator if we received an AI message
                            const hasNewAiMessage = newMessages.some(function(msg) {
                                return (msg.sender_type === 'bot' || msg.sender_type === 'ai');
                            });
                            if (hasNewAiMessage) {
                                hideTypingIndicator();
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

        // Handle user input (both send button and enter key)
        function handleUserInput() {
            const message = chatbotInput.val().trim();
            const hasFile = selectedFile !== null;

            // Allow sending if there's a message OR file attached
            if (message === '' && !hasFile) return;

            const maxLength = chatbotPluginVars.maxMessageLength || 500;

            if (message.length > maxLength) {
                chatbotMessages.append('<div class="chatbot-system-message">Your message is too long. Maximum ' + maxLength + ' characters allowed.</div>');
                chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                return;
            }

            chatbotInput.val('');
            updateCharCounter();

            // If waiting for name, start conversation (without file)
            if (waitingForName) {
                addMessage(message, 'user', false);
                startConversation(message);
                clearSelectedFile();
            } else {
                // Build display message including file info
                let displayMessage = message;
                if (hasFile) {
                    if (displayMessage) {
                        displayMessage += ' [Attached: ' + selectedFile.name + ']';
                    } else {
                        displayMessage = '[Attached: ' + selectedFile.name + ']';
                    }
                }

                // Normal message - mark as pending until we get the ID back
                addMessage(displayMessage, 'user', false, null, true);

                // Copy file before clearing
                const fileToSend = selectedFile;

                // Clear selected file from UI
                clearSelectedFile();

                // Send message with file
                sendMessage(message || 'Please analyze this file.', fileToSend);
            }
        }

        // Handle send button click
        chatbotSendBtn.on('click', handleUserInput);

        // Handle end chat button click
        chatbotEndBtn.on('click', function() {
            endConversation();
        });

        // Handle enter key press in input
        chatbotInput.on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                handleUserInput();
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
            waitingForName = false;

            // Update placeholder
            chatbotInput.attr('placeholder', 'Type your message...');

            // Show reconnection message
            chatbotMessages.append('<div class="chatbot-system-message">Reconnecting...</div>');

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
            if (shouldRestore) {
                // Already handled above
            } else if (skipWelcome) {
                // Skip welcome and start with "Guest"
                startConversation('Guest');
            } else {
                // Show welcome message
                showWelcomeMessage();
            }
        }
    });

})(jQuery);