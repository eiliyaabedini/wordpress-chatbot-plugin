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
        const chatbotWelcomeScreen = $('.chatbot-welcome-screen');
        const chatbotNameInput = $('.chatbot-name-input');
        const chatbotStartBtn = $('.chatbot-start-btn');
        const chatbotInputContainer = $('.chatbot-input-container');
        
        // Toggle chat window
        chatButton.on('click', function() {
            chatbotContainer.toggleClass('active');
            
            if (chatbotContainer.hasClass('active')) {
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
        
        // Close chat window
        chatbotClose.on('click', function() {
            chatbotContainer.removeClass('active');
        });
        
        // Close welcome screen
        $('#welcome-close').on('click', function() {
            chatbotContainer.removeClass('active');
        });
        
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
        
        // Function to add a new message to the chat
        function addMessage(message, senderType, preventScroll) {
            // Remove loading animation if it exists
            $('#chatbot-loading').hide();
            
            // Only hide typing indicator for AI responses, keep it for user messages
            if (senderType === 'ai' || senderType === 'admin') {
                window.typingIndicatorShown = false;
                $('#chatbot-typing-status').fadeOut(100);
            }
            
            const messageElement = $('<div class="chatbot-message ' + senderType + '"></div>');
            
            // Add sender label for AI and admin messages
            if (senderType === 'ai' || senderType === 'admin') {
                const senderLabel = $('<div class="chatbot-message-sender"></div>');
                senderLabel.text(senderType === 'ai' ? 'AI Assistant' : 'Admin');
                messageElement.append(senderLabel);
            }
            
            // Add message text
            const messageText = $('<div></div>').text(message);
            messageElement.append(messageText);
            
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
            
            // Show simple typing status
            $('#chatbot-typing-status').fadeIn(200);
            
            // Set flag to track typing state
            window.typingIndicatorShown = true;
            
            // Set a safety timeout to hide the indicator if it gets stuck
            setTimeout(function() {
                // Only hide if there's been no response yet
                if (window.typingIndicatorShown) {
                    $('#chatbot-typing-status').fadeOut(200);
                    chatbotMessages.append('<div class="chatbot-system-message">Our assistant is still thinking. Please wait a moment...</div>');
                    chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                }
            }, 15000); // Hide after 15 seconds if no response
        }
        
        // Function to start a new conversation
        function startConversation(name) {
            if (name.trim() === '') {
                alert('Please enter your name to start the chat.');
                return;
            }
            
            visitorName = name.trim();
            
            // Hide welcome screen, show header, messages area and chat interface with loading animation
            chatbotWelcomeScreen.hide();
            $('.chatbot-header').show();
            chatbotMessages.show(); // Show messages area
            chatbotInputContainer.show();
            $('#chatbot-loading').show();
            setLoadingTimeout(); // Set timeout to hide loading indicator
            $('.chatbot-typing-indicator').hide();
            
            // Show connection status directly in chat
            chatbotMessages.append('<div class="chatbot-system-message">Connecting to AI assistant...</div>');
            
            $.ajax({
                url: chatbotPluginVars.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chatbot_start_conversation',
                    visitor_name: visitorName,
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
                    $('#chatbot-loading').hide();
                    chatbotMessages.append('<div class="chatbot-system-message">Error connecting to server. Please try again.</div>');
                    chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                }
            });
        }
        
        // Function to send a message to the server
        function sendMessage(message) {
            if (!conversationId) {
                console.error('No active conversation');
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
                        $('.chatbot-typing-indicator').hide();
                        chatbotMessages.append('<div class="chatbot-system-message">Error sending message. Please try again.</div>');
                        chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                    }
                },
                error: function() {
                    // Hide typing indicator and show error
                    $('.chatbot-typing-indicator').hide();
                    chatbotMessages.append('<div class="chatbot-system-message">Connection error. Please try again later.</div>');
                    chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                }
            });
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
                    if (response.success) {
                        // Hide loading indicator and make sure messages area is visible
                        $('#chatbot-loading').hide();
                        chatbotMessages.show();
                        
                        // Get the current message count to detect new messages
                        const currentMessageCount = $('.chatbot-message').length;
                        const hasNewMessages = response.data.messages.length > currentMessageCount;
                        
                        // Only update when there are new messages or on first load
                        if (hasNewMessages || currentMessageCount === 0) {
                            // Clear messages area and repopulate with all messages
                            chatbotMessages.empty();
                            
                            // Add all messages
                            response.data.messages.forEach(function(msg) {
                                // Determine if the message is from AI or admin
                                let senderType = msg.sender_type;
                                if (senderType === 'bot') {
                                    senderType = 'ai';
                                }
                                addMessage(msg.message, senderType, !hasNewMessages); // Pass flag to prevent scrolling
                            });
                            
                            // If there are new messages from the AI, hide the typing indicator
                            if (hasNewMessages) {
                                // Check if we received an AI message
                                const hasNewAiMessage = response.data.messages.some(function(msg) {
                                    return (msg.sender_type === 'bot' || msg.sender_type === 'ai');
                                });
                                
                                if (hasNewAiMessage) {
                                    window.typingIndicatorShown = false;
                                    $('#chatbot-typing-status').fadeOut(200);
                                }
                            }
                        }
                    }
                },
                error: function() {
                    // Hide loading animation on error
                    $('#chatbot-loading').hide();
                    
                    // Only show error message if we can't fetch messages repeatedly
                    if (!window.fetchErrorShown) {
                        chatbotMessages.append('<div class="chatbot-system-message">Unable to retrieve messages. Will retry shortly.</div>');
                        chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
                        window.fetchErrorShown = true;
                        
                        // Reset the error shown flag after 30 seconds
                        setTimeout(function() {
                            window.fetchErrorShown = false;
                        }, 30000);
                    }
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
        
        // Handle send button click
        chatbotSendBtn.on('click', function() {
            const message = chatbotInput.val().trim();
            
            if (message !== '') {
                addMessage(message, 'user', false);
                chatbotInput.val('');
                sendMessage(message);
            }
        });
        
        // Handle enter key press in input
        chatbotInput.on('keypress', function(e) {
            if (e.which === 13) {
                const message = chatbotInput.val().trim();
                
                if (message !== '') {
                    addMessage(message, 'user', false);
                    chatbotInput.val('');
                    sendMessage(message);
                }
                
                e.preventDefault();
            }
        });
        
        // Check if there's a stored conversation in localStorage
        const storedConversationId = localStorage.getItem('chatbot_conversation_id');
        const storedVisitorName = localStorage.getItem('chatbot_visitor_name');
        
        if (storedConversationId && storedVisitorName) {
            // Resume conversation
            conversationId = storedConversationId;
            visitorName = storedVisitorName;
            
            // Hide welcome screen, show header, messages and chat interface
            chatbotWelcomeScreen.hide();
            $('.chatbot-header').show();
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
            }
        });
        
        // Check if the chatbot was open in the previous session
        if (localStorage.getItem('chatbot_is_open') === 'true') {
            chatbotContainer.addClass('active');
        }
    });
    
})(jQuery);