/**
 * Chatbot Plugin Admin JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Only run on conversation view page
        if ($('#chatbot-admin-reply-text').length === 0) {
            return;
        }
        
        const replyText = $('#chatbot-admin-reply-text');
        const sendButton = $('#chatbot-admin-send');
        const statusText = $('#chatbot-admin-status');
        const messagesContainer = $('.chatbot-admin-messages');
        
        // Scroll to bottom of messages
        function scrollToBottom() {
            messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
        }
        
        // Initial scroll
        scrollToBottom();
        
        // Handle send button click
        sendButton.on('click', function() {
            const message = replyText.val().trim();
            const conversationId = $(this).data('conversation-id');
            
            if (message === '') {
                return;
            }
            
            // Disable button and show status
            sendButton.prop('disabled', true);
            statusText.text(chatbotAdminVars.sendingText);
            
            $.ajax({
                url: chatbotAdminVars.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chatbot_admin_send_message',
                    conversation_id: conversationId,
                    message: message,
                    nonce: chatbotAdminVars.nonce
                },
                success: function(response) {
                    sendButton.prop('disabled', false);
                    
                    if (response.success) {
                        // Clear the text area
                        replyText.val('');
                        
                        // Show status
                        statusText.text(chatbotAdminVars.sentText);
                        setTimeout(function() {
                            statusText.text('');
                        }, 3000);
                        
                        // Add message to the chat
                        const messageHtml = `
                            <div class="chatbot-admin-message chatbot-admin-message-admin">
                                <div class="chatbot-admin-message-meta">
                                    <span class="chatbot-admin-message-sender">Admin</span>
                                    <span class="chatbot-admin-message-time">${response.data.formatted_time}</span>
                                </div>
                                <div class="chatbot-admin-message-content">
                                    ${message.replace(/\n/g, '<br>')}
                                </div>
                            </div>
                        `;
                        
                        messagesContainer.append(messageHtml);
                        scrollToBottom();
                    } else {
                        statusText.text(chatbotAdminVars.errorText);
                    }
                },
                error: function() {
                    sendButton.prop('disabled', false);
                    statusText.text(chatbotAdminVars.errorText);
                }
            });
        });
        
        // Handle enter key in textarea (Ctrl+Enter to send)
        replyText.on('keydown', function(e) {
            if (e.ctrlKey && e.keyCode === 13) {
                sendButton.click();
                e.preventDefault();
            }
        });
        
        // Set up polling for new messages (every 10 seconds)
        function pollForNewMessages() {
            const conversationId = sendButton.data('conversation-id');
            
            // Only poll if on a conversation page
            if (!conversationId) {
                return;
            }
            
            $.ajax({
                url: chatbotAdminVars.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chatbot_get_messages',
                    conversation_id: conversationId,
                    nonce: chatbotAdminVars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Clear messages area and repopulate with all messages
                        messagesContainer.empty();
                        
                        // Add all messages
                        response.data.messages.forEach(function(msg) {
                            let senderName = msg.sender_type === 'admin' ? 'Admin' : $('.chatbot-admin-message-sender').first().text();
                            
                            const messageHtml = `
                                <div class="chatbot-admin-message chatbot-admin-message-${msg.sender_type}">
                                    <div class="chatbot-admin-message-meta">
                                        <span class="chatbot-admin-message-sender">${senderName}</span>
                                        <span class="chatbot-admin-message-time">${new Date(msg.timestamp).toLocaleString()}</span>
                                    </div>
                                    <div class="chatbot-admin-message-content">
                                        ${msg.message.replace(/\n/g, '<br>')}
                                    </div>
                                </div>
                            `;
                            
                            messagesContainer.append(messageHtml);
                        });
                        
                        scrollToBottom();
                    }
                }
            });
        }
        
        // Poll every 10 seconds
        setInterval(pollForNewMessages, 10000);
    });
    
})(jQuery);