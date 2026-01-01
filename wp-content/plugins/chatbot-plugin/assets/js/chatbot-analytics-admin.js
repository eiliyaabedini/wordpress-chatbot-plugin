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
     * Uses Showdown.js to convert Markdown to HTML
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
        
        let formattedMessage = '';
        
        // CRITICAL FIX: Use a try/catch block to handle Showdown issues
        try {
            // First, make sure we have a showdown object
            if (typeof showdown === 'undefined') {
                throw new Error('Showdown not available');
            }
            
            // Initialize Showdown converter with options
            const converter = new showdown.Converter({
                tables: true,
                strikethrough: true,
                tasklists: true,
                simpleLineBreaks: true
            });
            
            // Convert markdown to HTML using Showdown
            formattedMessage = converter.makeHtml(message);
            
            // Process the HTML to convert suggested questions into interactive buttons
            formattedMessage = processQuestionSuggestions(formattedMessage);
            
            // Process the HTML to render charts based on special syntax
            formattedMessage = processChartPlaceholders(formattedMessage);
        } catch (error) {
            // Fallback if Showdown fails
            
            // Fallback: Apply basic formatting for Markdown
            formattedMessage = message
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;')
                .replace(/\n\n/g, '</p><p>')
                .replace(/\n/g, '<br />')
                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.*?)\*/g, '<em>$1</em>')
                .replace(/```(.*?)```/gs, '<pre><code>$1</code></pre>')
                .replace(/`(.*?)`/g, '<code>$1</code>');
                
            formattedMessage = '<p>' + formattedMessage + '</p>';
            
            // Process suggested questions in the plain text version as well
            formattedMessage = processQuestionSuggestions(formattedMessage);
            
            // Process chart placeholders even in fallback mode
            formattedMessage = processChartPlaceholders(formattedMessage);
            
            // Try to load Showdown again for future messages
            if (typeof chatbotAnalyticsVars !== 'undefined' && chatbotAnalyticsVars.pluginUrl) {
                // Load Showdown from plugin URL
                const script = document.createElement('script');
                script.src = chatbotAnalyticsVars.pluginUrl + 'assets/js/showdown.min.js';
                document.head.appendChild(script);
            } else {
                // Load Showdown from CDN as fallback
                const script = document.createElement('script');
                script.src = 'https://cdnjs.cloudflare.com/ajax/libs/showdown/2.1.0/showdown.min.js';
                document.head.appendChild(script);
            }
        }
        
        // Set the HTML content safely
        // Create a DOMPurify config to only allow safe tags
        if (typeof DOMPurify !== 'undefined') {
            // If DOMPurify is available, use it to sanitize the HTML
            // IMPORTANT: Include 'button' for question suggestions and 'canvas' for charts
            contentDiv.html(DOMPurify.sanitize(formattedMessage, {
                ALLOWED_TAGS: ['b', 'i', 'em', 'strong', 'a', 'p', 'ul', 'ol', 'li', 'br', 'span', 'div', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'code', 'button', 'canvas'],
                ALLOWED_ATTR: ['href', 'target', 'class', 'id', 'style', 'type', 'width', 'height']
            }));
        } else {
            // If DOMPurify is not available, use a more restrictive approach
            // Convert the formatted message to plain text and then replace only specific markdown-like patterns
            const textContent = $('<div/>').text(formattedMessage).text();
            // Only handle basic formatting like line breaks
            contentDiv.html(textContent.replace(/\n/g, '<br>'));
        }

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
     * Process HTML content to convert suggested questions into interactive buttons
     * 
     * @param {string} htmlContent The HTML content to process
     * @return {string} The processed HTML with question suggestions as buttons
     */
    function processQuestionSuggestions(htmlContent) {
        // Make a copy of the original content to work with
        let modifiedContent = htmlContent;
        
        // Pattern 1: Standard question pattern - sentences ending with question marks
        const questionPattern = /([^.!?><]*?\?)/gi;
        
        // Pattern 2: Lines following suggestion keywords like "suggested questions", "example questions", etc.
        const suggestionKeywords = [
            'suggested question', 
            'sample question', 
            'example question', 
            'you can ask', 
            'try asking',
            'questions:'
        ];
        
        // Function to convert a question to a button
        const convertToButton = (question) => {
            // Clean up the question - remove any HTML tags and trim whitespace
            let cleanQuestion = question.replace(/<[^>]*>/g, '').trim();
            
            // Skip if it's too short to be a real question (less than 10 chars) or doesn't end with ?
            if (cleanQuestion.length < 10 || !cleanQuestion.endsWith('?')) {
                return question;
            }
            
            // Create the button
            return `<button type="button" class="ai-chat-question-btn">${cleanQuestion}</button>`;
        };
        
        // First look for sections that might contain suggested questions based on keywords
        suggestionKeywords.forEach(keyword => {
            // Create a pattern to find paragraphs containing the keyword
            const keywordPattern = new RegExp(`(<p[^>]*>[^<]*${keyword}[^<]*<\/p>)([\\s\\S]*?)(<p|<div|<h|$)`, 'i');
            const match = modifiedContent.match(keywordPattern);
            
            if (match) {
                // Found a paragraph with our keyword - process the content that follows
                const sectionStart = match.index + match[1].length;
                const sectionContent = match[2];
                
                // Replace questions with buttons in this section
                const processedSection = sectionContent.replace(questionPattern, (q) => convertToButton(q));
                
                // Update the content
                modifiedContent = modifiedContent.substring(0, sectionStart) + 
                                 processedSection + 
                                 modifiedContent.substring(sectionStart + sectionContent.length);
            }
        });
        
        // Now look for specific patterns in the text
        
        // 1. Look for lists with questions (often used in summaries)
        modifiedContent = modifiedContent.replace(/<li>([^.!?<>]*?\?)<\/li>/gi, (match, question) => {
            return `<li>${convertToButton(question)}</li>`;
        });
        
        // 2. Look for standalone questions at the end of messages (after a blank line or paragraph break)
        modifiedContent = modifiedContent.replace(/(<\/p>[\s\r\n]*<p>|<br\s*\/*>[\s\r\n]*|<\/ul>[\s\r\n]*<p>)([^.!?<>]*?How|What|Why|When|Where|Which|Who|Can|Could|Should|Would|Will|Is|Are|Do|Does|Did|Has|Have|Had)([^.!?<>]*?\?)/gi, 
            (match, prefix, questionStart, questionEnd) => {
                return `${prefix}${convertToButton(questionStart + questionEnd)}`;
            }
        );
        
        // 3. Check specifically for "Suggested questions:" pattern
        const suggestedQuestionsMatch = modifiedContent.match(/Suggested questions:([^<]*)<\/p>/i);
        if (suggestedQuestionsMatch) {
            const questionsText = suggestedQuestionsMatch[1];
            
            // Split by question marks and convert each to a button
            let processedQuestions = questionsText;
            const questions = questionsText.split('?');
            
            questions.forEach((q, i) => {
                if (i < questions.length - 1) { // Skip the last item as it's empty or after the last question mark
                    const question = q.trim() + '?';
                    if (question.length > 10) { // Only convert questions of reasonable length
                        processedQuestions = processedQuestions.replace(
                            question, 
                            `</p><p>${convertToButton(question)}</p><p>`
                        );
                    }
                }
            });
            
            // Replace the original content with our processed version
            modifiedContent = modifiedContent.replace(
                `Suggested questions:${questionsText}</p>`,
                `Suggested questions:</p>${processedQuestions.replace(/^<\/p><p>/, '').replace(/<p>$/, '')}`
            );
        }
        
        return modifiedContent;
    }
    
    /**
     * Process HTML content to render charts based on special syntax
     * 
     * @param {string} htmlContent The HTML content to process
     * @return {string} The processed HTML with charts rendered
     */
    function processChartPlaceholders(htmlContent) {
        // Make a copy of the original content to work with
        let modifiedContent = htmlContent;
        
        // Chart counter to ensure unique IDs
        let chartCounter = 0;
        
        // Pattern 1: Match Chart JSON in code blocks with "chart:" prefix
        const chartCodePattern = /<pre><code>(chart:[\s\S]*?)<\/code><\/pre>/gi;
        
        // Replace chart code blocks with canvas elements
        modifiedContent = modifiedContent.replace(chartCodePattern, (match, chartData) => {
            // Extract the chart JSON data
            let chartJson = chartData.replace(/^chart:/i, '').trim();
            
            try {
                // Generate a unique ID for this chart
                const chartId = 'ai-chat-chart-' + (++chartCounter);
                
                // Create canvas element
                const canvasHtml = `<div class="ai-chat-chart-container"><canvas id="${chartId}" width="400" height="200"></canvas></div>`;
                
                // Schedule chart rendering after the DOM is updated
                setTimeout(() => renderChart(chartId, chartJson), 100);
                
                return canvasHtml;
            } catch (e) {
                // Silent error handling - keep original content
                return match;
            }
        });
        
        // Pattern 2: Look for specific chart format for simple charts - support space after chart: prefix
        // Format: ```chart: type|title|labels|data``` or ```chart:type|title|labels|data```
        const simpleChartPattern = /<pre><code>chart:\s*(bar|line|pie|doughnut)\|(.*?)\|(.*?)\|(.*?)<\/code><\/pre>/gi;
        
        modifiedContent = modifiedContent.replace(simpleChartPattern, (match, chartType, title, labelsStr, dataStr) => {
            // Generate a unique ID for this chart
            const chartId = 'ai-chat-chart-' + (++chartCounter);
            
            try {
                // Parse the labels and data
                const labels = labelsStr.split(',').map(label => label.trim());
                const data = dataStr.split(',').map(value => parseFloat(value.trim()));
                
                // Create the chart configuration
                const chartConfig = {
                    type: chartType,
                    data: {
                        labels: labels,
                        datasets: [{
                            label: title.trim(),
                            data: data,
                            backgroundColor: [
                                'rgba(75, 192, 192, 0.5)',
                                'rgba(54, 162, 235, 0.5)',
                                'rgba(255, 206, 86, 0.5)',
                                'rgba(255, 99, 132, 0.5)',
                                'rgba(153, 102, 255, 0.5)',
                                'rgba(255, 159, 64, 0.5)'
                            ],
                            borderColor: [
                                'rgba(75, 192, 192, 1)',
                                'rgba(54, 162, 235, 1)',
                                'rgba(255, 206, 86, 1)',
                                'rgba(255, 99, 132, 1)',
                                'rgba(153, 102, 255, 1)',
                                'rgba(255, 159, 64, 1)'
                            ],
                            borderWidth: 1
                        }]
                    }
                };
                
                // Create canvas element
                const canvasHtml = `<div class="ai-chat-chart-container"><canvas id="${chartId}" width="400" height="240"></canvas></div>`;
                
                // Schedule chart rendering after the DOM is updated
                setTimeout(() => renderChart(chartId, JSON.stringify(chartConfig)), 100);
                
                return canvasHtml;
            } catch (e) {
                // Silent error handling - keep original content
                return match;
            }
        });
        
        // Pattern 3: Support plain text chart format without code blocks
        // This is more likely how the AI outputs them in plain text conversation
        const plainTextChartPattern = /chart:\s*(bar|line|pie|doughnut)\|(.*?)\|(.*?)\|(.*?)(?=[\n\r]|$)/gi;
        
        modifiedContent = modifiedContent.replace(plainTextChartPattern, (match, chartType, title, labelsStr, dataStr) => {
            // Generate a unique ID for this chart
            const chartId = 'ai-chat-chart-' + (++chartCounter);
            
            try {
                // Parse the labels and data
                const labels = labelsStr.split(',').map(label => label.trim());
                const data = dataStr.split(',').map(value => parseFloat(value.trim()));
                
                // Create the chart configuration
                const chartConfig = {
                    type: chartType,
                    data: {
                        labels: labels,
                        datasets: [{
                            label: title.trim(),
                            data: data,
                            backgroundColor: [
                                'rgba(75, 192, 192, 0.5)',
                                'rgba(54, 162, 235, 0.5)',
                                'rgba(255, 206, 86, 0.5)',
                                'rgba(255, 99, 132, 0.5)',
                                'rgba(153, 102, 255, 0.5)',
                                'rgba(255, 159, 64, 0.5)'
                            ],
                            borderColor: [
                                'rgba(75, 192, 192, 1)',
                                'rgba(54, 162, 235, 1)',
                                'rgba(255, 206, 86, 1)',
                                'rgba(255, 99, 132, 1)',
                                'rgba(153, 102, 255, 1)',
                                'rgba(255, 159, 64, 1)'
                            ],
                            borderWidth: 1
                        }]
                    }
                };
                
                // Create canvas element
                const canvasHtml = `<div class="ai-chat-chart-container"><canvas id="${chartId}" width="400" height="240"></canvas></div>`;
                
                // Schedule chart rendering after the DOM is updated
                setTimeout(() => renderChart(chartId, JSON.stringify(chartConfig)), 100);
                
                return canvasHtml;
            } catch (e) {
                // Silent error handling - keep original content
                return match;
            }
        });
        
        return modifiedContent;
    }
    
    /**
     * Render a chart on the specified canvas element
     * 
     * @param {string} canvasId The ID of the canvas element
     * @param {string} chartDataJson The chart configuration in JSON format
     */
    function renderChart(canvasId, chartDataJson) {
        try {
            // Get the canvas element
            const canvas = document.getElementById(canvasId);
            if (!canvas) {
                return;
            }
            
            // Parse the chart data JSON
            let chartData;
            try {
                chartData = JSON.parse(chartDataJson);
            } catch (e) {
                // Try to sanitize and fix common issues with the JSON
                try {
                    // Sometimes the AI might generate invalid JSON with unquoted property names
                    // or missing commas. This is a simple attempt to fix that.
                    const fixedJson = chartDataJson
                        .replace(/([{,])\s*(\w+)\s*:/g, '$1"$2":') // Quote unquoted property names
                        .replace(/(["\w\d\]])\s*([{\[])/g, '$1,$2') // Add missing commas
                        .replace(/,\s*([}\]])/g, '$1'); // Remove trailing commas

                    chartData = JSON.parse(fixedJson);
                } catch (fixError) {
                    // Could not fix the JSON
                    return;
                }
            }
            
            // Check if Chart.js is available
            if (typeof Chart === 'undefined') {
                // Display error message on canvas
                const ctx = canvas.getContext('2d');
                ctx.font = '14px Arial';
                ctx.fillStyle = 'red';
                ctx.fillText('Error: Chart.js library not loaded', 10, 50);

                // Try to load Chart.js dynamically
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js';
                script.onload = function() {
                    // Retry rendering after Chart.js is loaded
                    setTimeout(() => {
                        try {
                            new Chart(canvas, chartData);
                        } catch (retryError) {
                            // Silent error handling
                        }
                    }, 500);
                };
                document.head.appendChild(script);
                return;
            }
            
            // Create and render the chart
            new Chart(canvas, chartData);
        } catch (error) {
            // Silent error handling
        }
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