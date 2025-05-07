/**
 * Chatbot Plugin Frontend JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        const chatbotContainer = $('.chatbot-container');
        const chatbotMessages = $('.chatbot-messages');
        const chatbotInput = $('.chatbot-input');
        const chatbotSendBtn = $('.chatbot-send-btn');
        const chatbotWelcomeScreen = $('.chatbot-welcome-screen');
        const chatbotNameInput = $('.chatbot-name-input');
        const chatbotStartBtn = $('.chatbot-start-btn');
        const chatbotInputContainer = $('.chatbot-input-container');
        
        let conversationId = null;
        let visitorName = '';
        let pollInterval = null;
        
        // Function to add a new message to the chat
        function addMessage(message, senderType) {
            // Remove typing indicator if it exists
            $('.chatbot-typing-indicator').remove();
            
            const messageElement = $('<div class="chatbot-message ' + senderType + '"></div>');
            messageElement.text(message);
            chatbotMessages.append(messageElement);
            
            // Scroll to bottom
            chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
        }
        
        // Function to show typing indicator
        function showTypingIndicator() {
            // Remove existing typing indicator if any
            $('.chatbot-typing-indicator').remove();
            
            const typingIndicator = $(
                '<div class="chatbot-typing-indicator">' +
                    '<div class="chatbot-typing-animation">' +
                        '<div class="chatbot-typing-dot"></div>' +
                        '<div class="chatbot-typing-dot"></div>' +
                        '<div class="chatbot-typing-dot"></div>' +
                    '</div>' +
                '</div>'
            );
            
            chatbotMessages.append(typingIndicator);
            
            // Scroll to bottom
            chatbotMessages.scrollTop(chatbotMessages[0].scrollHeight);
        }
        
        // Function to start a new conversation
        function startConversation(name) {
            if (name.trim() === '') {
                alert('Please enter your name to start the chat.');
                return;
            }
            
            visitorName = name.trim();
            
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
                        
                        // Hide welcome screen, show chat interface
                        chatbotWelcomeScreen.hide();
                        chatbotInputContainer.show();
                        
                        // Add status indicator
                        if (!$('.chatbot-status').length) {
                            chatbotContainer.append('<div class="chatbot-status">Connected</div>');
                        }
                        
                        // Add welcome message
                        addMessage('Hello ' + visitorName + '! How can I help you today?', 'admin');
                        
                        // Start polling for new messages
                        startPolling();
                    } else {
                        alert('Error starting conversation. Please try again.');
                    }
                },
                error: function() {
                    alert('Error connecting to server. Please try again.');
                }
            });
        }
        
        // Function to send a message to the server
        function sendMessage(message) {
            if (!conversationId) {
                console.error('No active conversation');
                return;
            }
            
            // Show sending status and typing indicator
            $('.chatbot-status').text('Sending...');
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
                        // Reset status
                        $('.chatbot-status').text('Connected');
                        
                        // Check if we need to add the message (it might already be added by polling)
                        if (!response.data.message_already_displayed) {
                            // The message might be already added by the user,
                            // but we're ensuring it's in the chat
                            addMessage(message, 'user');
                        }
                    } else {
                        $('.chatbot-status').text('Error sending message');
                    }
                },
                error: function() {
                    $('.chatbot-status').text('Connection error');
                }
            });
        }
        
        // Function to poll for new messages
        function startPolling() {
            // Clear any existing interval
            if (pollInterval) {
                clearInterval(pollInterval);
            }
            
            // Set up polling interval (every 5 seconds)
            pollInterval = setInterval(function() {
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
                            // Clear messages area and repopulate with all messages
                            chatbotMessages.empty();
                            
                            // Add all messages
                            response.data.messages.forEach(function(msg) {
                                addMessage(msg.message, msg.sender_type);
                            });
                            
                            // Update status based on activity
                            if (response.data.messages.length > 0) {
                                $('.chatbot-status').text('Connected');
                            }
                        }
                    },
                    error: function() {
                        $('.chatbot-status').text('Connection error');
                    }
                });
            }, 5000); // Poll every 5 seconds
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
                addMessage(message, 'user');
                chatbotInput.val('');
                sendMessage(message);
            }
        });
        
        // Handle enter key press in input
        chatbotInput.on('keypress', function(e) {
            if (e.which === 13) {
                const message = chatbotInput.val().trim();
                
                if (message !== '') {
                    addMessage(message, 'user');
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
            
            // Hide welcome screen, show chat interface
            chatbotWelcomeScreen.hide();
            chatbotInputContainer.show();
            
            // Add status indicator
            if (!$('.chatbot-status').length) {
                chatbotContainer.append('<div class="chatbot-status">Reconnecting...</div>');
            }
            
            // Start polling for new messages
            startPolling();
        }
        
        // Handle window close/reload to save conversation state
        $(window).on('beforeunload', function() {
            if (conversationId) {
                localStorage.setItem('chatbot_conversation_id', conversationId);
                localStorage.setItem('chatbot_visitor_name', visitorName);
            }
        });
    });
    
})(jQuery);