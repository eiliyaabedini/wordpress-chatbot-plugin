/**
 * Chatbot Analytics Dashboard JavaScript
 * Enhanced version with interactive chat interface and quick question buttons
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Set up event handler for refresh button
        $('#refresh-analytics-summary').on('click', function() {
            refreshAnalyticsSummary();
        });
        
        // Set up event handler for AI overview button
        $('#generate-ai-overview').on('click', function() {
            generateAISummary();
        });
        
        // Set up event delegation for question suggestion buttons
        $(document).on('click', '.ai-chat-question-btn', function() {
            sendPredefinedQuestion($(this).text());
        });
        
        // Refresh analytics data periodically (every 5 minutes)
        setInterval(function() {
            refreshAnalyticsSummary();
        }, 300000); // 5 minutes
    });
    
    // Store conversation data globally to use in follow-up questions
    let conversationContext = null;
    let chatHistory = [];
    let isGeneratingResponse = false;
    
    /**
     * Send a predefined question when a suggestion button is clicked
     * 
     * @param {string} questionText The text of the predefined question
     */
    window.sendPredefinedQuestion = function(questionText) {
        // Ensure input field exists and isn't disabled
        const $chatInput = $('#ai-chat-input');
        if ($chatInput.length === 0 || $chatInput.prop('disabled') || isGeneratingResponse) {
            return;
        }
        
        // Set the question in the input field
        $chatInput.val(questionText);
        
        // Send the question
        sendChatMessage();
    };

    /**
     * Generate AI Summary of conversations and initialize chat
     */
    function generateAISummary() {
        // Hide the welcome message and show loading indicator
        $('.ai-chat-welcome').hide();
        $('.ai-summary-loading').show();
        
        // Clear any existing messages
        $('#ai-chat-messages-container').empty();
        
        // Disable the button to prevent multiple requests
        const $button = $('#generate-ai-overview');
        $button.prop('disabled', true).text('Analyzing...');
        
        // Reset chat history
        chatHistory = [];
        
        // Make AJAX request to get AI summary and conversation data
        $.ajax({
            url: chatbotAnalyticsVars.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatbot_generate_conversation_summary',
                nonce: chatbotAnalyticsVars.nonce
            },
            success: function(response) {
                // Hide loading indicator
                $('.ai-summary-loading').hide();
                
                if (response.success) {
                    // Store conversation context for follow-up questions
                    if (response.data.conversation_data) {
                        conversationContext = response.data.conversation_data;
                    }
                    
                    // Add AI message to chat
                    addAIMessage('AI Assistant', response.data.summary);
                    
                    // Add first message to chat history
                    chatHistory.push({
                        role: 'assistant',
                        content: response.data.summary
                    });
                    
                    // Add a helpful prompt for the user explaining new features
                    setTimeout(function() {
                        addSystemMessage('Here\'s your conversation summary. Ask follow-up questions or simply click any of the suggested buttons below for instant insights.');
                    }, 500);
                    
                    // Enable input after summary is received
                    $('#ai-chat-input').prop('disabled', false).focus();
                    $('#ai-chat-send').prop('disabled', false);
                } else {
                    // Show error message and reset welcome view
                    addSystemMessage('Error: ' + (response.data.message || 'Could not generate summary.'));
                    setTimeout(function() {
                        $('#ai-chat-messages-container').empty();
                        $('.ai-chat-welcome').show();
                    }, 3000);
                }
            },
            error: function() {
                // Hide loading indicator and show error
                $('.ai-summary-loading').hide();
                addSystemMessage('Error: Could not connect to the server.');
                
                // After showing error, reset to welcome view
                setTimeout(function() {
                    $('#ai-chat-messages-container').empty();
                    $('.ai-chat-welcome').show();
                }, 3000);
            },
            complete: function() {
                // Re-enable the button
                $button.prop('disabled', false).text('Start Conversation Analysis');
            }
        });
        
        // Set up chat input handlers if not already set
        setupChatInputHandlers();
    }
    
    /**
     * Add message from AI to the chat
     * 
     * Safely handles HTML button elements for suggested questions
     */
    function addAIMessage(sender, message) {
        // Create element structure
        const messageElement = $(`
            <div class="ai-chat-message ai">
                <div class="ai-chat-message-header">${sender}</div>
                <div class="ai-chat-message-content"></div>
            </div>
        `);
        
        // Process the message content - this allows HTML buttons to work
        // We set the HTML directly for the content div to preserve the button functionality
        const contentDiv = messageElement.find('.ai-chat-message-content');
        
        // Replace regular line breaks with <br> tags to maintain formatting
        let formattedMessage = message.replace(/\n/g, '<br>');
        
        // Set the HTML content safely
        contentDiv.html(formattedMessage);
        
        // Add the element to the chat container
        $('#ai-chat-messages-container').append(messageElement);
        scrollChatToBottom();
    }
    
    /**
     * Add message from user to the chat
     */
    function addUserMessage(message) {
        const messageElement = $(`
            <div class="ai-chat-message user">
                <div class="ai-chat-message-header">You</div>
                <div class="ai-chat-message-content">${message}</div>
            </div>
        `);
        
        $('#ai-chat-messages-container').append(messageElement);
        scrollChatToBottom();
    }
    
    /**
     * Add system message to the chat
     */
    function addSystemMessage(message) {
        const messageElement = $(`
            <div class="ai-chat-message system">
                <div class="ai-chat-message-content">${message}</div>
            </div>
        `);
        
        $('#ai-chat-messages-container').append(messageElement);
        scrollChatToBottom();
    }
    
    /**
     * Add typing indicator to the chat
     */
    function addTypingIndicator() {
        const typingElement = $(`
            <div class="ai-chat-message system typing-indicator">
                <div class="ai-chat-typing-indicator">AI Assistant is thinking...</div>
            </div>
        `);
        
        $('#ai-chat-messages-container').append(typingElement);
        scrollChatToBottom();
        
        return typingElement;
    }
    
    /**
     * Scroll chat to the bottom
     */
    function scrollChatToBottom() {
        const chatContainer = $('.ai-chat-messages');
        chatContainer.scrollTop(chatContainer[0].scrollHeight);
    }
    
    /**
     * Set up input handlers for the chat
     */
    function setupChatInputHandlers() {
        // Handle send button click
        $('#ai-chat-send').off('click').on('click', function() {
            if (!isGeneratingResponse) {
                sendChatMessage();
            }
        });
        
        // Handle enter key in input field
        $('#ai-chat-input').off('keypress').on('keypress', function(e) {
            if (e.which === 13 && !isGeneratingResponse) { // Enter key
                sendChatMessage();
                e.preventDefault();
            }
        });
        
        // Add placeholder text based on whether it's a new conversation
        if (chatHistory.length > 0) {
            $('#ai-chat-input').attr('placeholder', 'Ask a follow-up question about the data...');
        } else {
            $('#ai-chat-input').attr('placeholder', 'Generate an AI overview first to begin asking questions...');
        }
    }
    
    /**
     * Send a chat message and get AI response
     */
    function sendChatMessage() {
        const message = $('#ai-chat-input').val().trim();
        
        if (!message) {
            return;
        }
        
        // Guard against multiple sends
        if (isGeneratingResponse) {
            return;
        }
        
        isGeneratingResponse = true;
        
        // Add user message to chat
        addUserMessage(message);
        
        // Clear input field
        $('#ai-chat-input').val('');
        
        // Add message to chat history
        chatHistory.push({
            role: 'user',
            content: message
        });
        
        // Disable input while waiting for response
        $('#ai-chat-input').prop('disabled', true);
        $('#ai-chat-send').prop('disabled', true);
        
        // Show typing indicator
        const typingIndicator = addTypingIndicator();
        
        // Send message to server
        $.ajax({
            url: chatbotAnalyticsVars.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatbot_analytics_follow_up',
                nonce: chatbotAnalyticsVars.nonce,
                question: message,
                chat_history: JSON.stringify(chatHistory)
            },
            success: function(response) {
                // Remove typing indicator
                typingIndicator.remove();
                
                if (response.success) {
                    // Add AI response to chat
                    addAIMessage('AI Assistant', response.data.response);
                    
                    // Add to chat history
                    chatHistory.push({
                        role: 'assistant',
                        content: response.data.response
                    });
                    
                    // If this was the first follow-up question, encourage more
                    if (chatHistory.length === 4) { // Initial AI + User Q + AI response = 3 messages
                        setTimeout(function() {
                            addSystemMessage('Feel free to ask more detailed questions about specific aspects of the data.');
                        }, 1000);
                    }
                } else {
                    addSystemMessage('Error: ' + (response.data.message || 'Could not get response.'));
                }
            },
            error: function() {
                // Remove typing indicator
                typingIndicator.remove();
                addSystemMessage('Error: Could not connect to the server.');
            },
            complete: function() {
                // Re-enable input
                $('#ai-chat-input').prop('disabled', false).focus();
                $('#ai-chat-send').prop('disabled', false);
                isGeneratingResponse = false;
            }
        });
    }
    
    /**
     * Refresh the analytics summary
     */
    function refreshAnalyticsSummary() {
        // Show loading indicator
        const summaryContainer = $('.chatbot-analytics-summary');
        summaryContainer.html('<div class="chatbot-analytics-loading"><span class="spinner is-active"></span><p>Loading analytics data...</p></div>');
        
        // Make AJAX request to get latest data
        $.ajax({
            url: chatbotAnalyticsVars.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatbot_get_analytics_summary',
                nonce: chatbotAnalyticsVars.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update the summary container with the new content
                    summaryContainer.html(response.data.html);
                } else {
                    summaryContainer.html('<p class="error">Error: ' + (response.data.message || 'Could not load analytics data.') + '</p>');
                }
            },
            error: function() {
                summaryContainer.html('<p class="error">Error: Could not connect to the server.</p>');
            }
        });
    }
    
})(jQuery);