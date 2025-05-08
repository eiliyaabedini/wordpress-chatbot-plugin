/**
 * Chatbot Plugin Admin JavaScript
 */

(function($) {
    'use strict';
    
    // Debug any other scripts or plugins that might be interfering
    console.log('Chatbot Admin JS loading - checking for conflicts');
    
    // Check if jQuery is properly loaded
    if (typeof jQuery !== 'undefined') {
        console.log('jQuery version:', jQuery.fn.jquery);
    } else {
        console.error('jQuery not found! This will cause issues.');
    }
    
    // Log any potential form validation scripts
    if (typeof jQuery !== 'undefined') {
        var formValidators = [];
        jQuery.each(jQuery.event.global, function(key, value) {
            if (key.indexOf('validate') !== -1 || key.indexOf('form') !== -1) {
                formValidators.push(key);
            }
        });
        if (formValidators.length > 0) {
            console.log('Potential form validators found:', formValidators);
        }
    }
    
    // Helper function to safely set textarea values
    function setTextareaValue(id, value) {
        console.log('Setting textarea value for #' + id);
        
        // Store all approaches we tried
        var approaches = [];
        
        try {
            // Approach 1: jQuery val method
            $('#' + id).val(value);
            approaches.push('jQuery val()');
            
            // Approach 2: Direct DOM access
            var element = document.getElementById(id);
            if (element) {
                element.value = value;
                approaches.push('DOM element.value direct');
                
                // Approach 3: Force input event
                try {
                    element.dispatchEvent(new Event('input', { bubbles: true }));
                    approaches.push('input event');
                } catch (e) {
                    console.error('Error dispatching input event', e);
                }
                
                // Approach 4: Force change event
                try {
                    element.dispatchEvent(new Event('change', { bubbles: true }));
                    approaches.push('change event');
                } catch (e) {
                    console.error('Error dispatching change event', e);
                }
            } else {
                console.error('Element #' + id + ' not found in DOM');
            }
        } catch (e) {
            console.error('Error setting textarea value', e);
        }
        
        // Check final value and log
        var finalValue = $('#' + id).val();
        var domFinalValue = document.getElementById(id) ? document.getElementById(id).value : 'element not found';
        
        console.log('Attempted approaches:', approaches.join(', '));
        console.log('Final jQuery value:', finalValue ? finalValue.substring(0, 50) + '...' : 'empty');
        console.log('Final DOM value:', domFinalValue ? domFinalValue.substring(0, 50) + '...' : 'empty');
        
        return finalValue === value;
    }
    
    $(document).ready(function() {
        // Add debug for Improve with AI button
        if ($('#chatbot_improve_prompt').length > 0) {
            console.log('DEBUG: Improve with AI button found');
            
            $('#chatbot_improve_prompt').on('click', function() {
                var $button = $(this);
                var $status = $('#chatbot_improve_prompt_status');
                var promptText = $('#chatbot_system_prompt').val();
                
                console.log('DEBUG: Improve button clicked');
                console.log('DEBUG: Original prompt text:', promptText);
                
                if (promptText.trim() === '') {
                    console.log('DEBUG: Empty prompt text');
                    $status.html('<span style="color: red;">Please enter some text first.</span>');
                    return;
                }
                
                $button.prop('disabled', true);
                $status.html('<span>Improving prompt...</span>');
                console.log('DEBUG: Button disabled, status updated');
                
                // Redirect to OpenAI settings if API key is not configured
                var handleApiKeyMissing = function() {
                    console.log('DEBUG: API key missing handler called');
                    if (confirm('OpenAI API key is not configured. Would you like to configure it now?')) {
                        window.location.href = ajaxurl.replace('admin-ajax.php', 'admin.php?page=chatbot-settings&tab=openai');
                    } else {
                        $status.html('<span style="color: red;">API key is required. Please configure your OpenAI API key in the OpenAI settings tab.</span>');
                        $button.prop('disabled', false);
                    }
                };
                
                // First, check if OpenAI API key is configured
                console.log('DEBUG: Testing OpenAI API key');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'chatbot_test_openai',
                        validate_key_only: true,
                        nonce: $('#chatbot_improve_prompt').data('nonce-test')
                    },
                    success: function(apiTestResponse) {
                        console.log('DEBUG: API key test response:', apiTestResponse);
                        if (apiTestResponse.success) {
                            // API key is configured, proceed with improving prompt
                            console.log('DEBUG: API key is configured, proceeding with improve prompt');
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'chatbot_improve_prompt',
                                    prompt: promptText,
                                    nonce: $('#chatbot_improve_prompt').data('nonce-improve')
                                },
                                success: function(response) {
                                    console.log('DEBUG: Improve prompt response:', response);
                                    if (response.success) {
                                        console.log('DEBUG: Setting improved prompt to textarea');
                                        
                                        // Detailed verification of response data
                                        if (!response.data) {
                                            console.error('DEBUG: Response data is missing');
                                            $status.html('<span style="color: red;">Error: Response data is missing</span>');
                                            return;
                                        }
                                        
                                        if (!response.data.improved_prompt) {
                                            console.error('DEBUG: improved_prompt is missing in response data', response.data);
                                            $status.html('<span style="color: red;">Error: Improved prompt data is missing</span>');
                                            return;
                                        }
                                        
                                        // Show improved prompt in logs for debugging
                                        console.log('DEBUG: Improved prompt content:', response.data.improved_prompt);
                                        
                                        // Get the current value before setting new value
                                        var beforeValue = $('#chatbot_system_prompt').val();
                                        console.log('DEBUG: Textarea value before setting:', beforeValue);
                                        
                                        // Attempt first direct method
                                        $('#chatbot_system_prompt').val(response.data.improved_prompt);
                                        console.log('DEBUG: Value after first set attempt:', $('#chatbot_system_prompt').val());
                                        
                                        // Try DOM direct access
                                        var textareaElement = document.getElementById('chatbot_system_prompt');
                                        if (textareaElement) {
                                            textareaElement.value = response.data.improved_prompt;
                                            console.log('DEBUG: Value after direct DOM set:', textareaElement.value);
                                            
                                            // Try triggering input/change events
                                            try {
                                                textareaElement.dispatchEvent(new Event('input', { bubbles: true }));
                                                textareaElement.dispatchEvent(new Event('change', { bubbles: true }));
                                                console.log('DEBUG: Events dispatched');
                                            } catch(e) {
                                                console.error('DEBUG: Error dispatching events', e);
                                            }
                                        } else {
                                            console.error('DEBUG: Textarea element not found in DOM');
                                        }
                                        
                                        // Try jQuery attribute method
                                        $('#chatbot_system_prompt').attr('value', response.data.improved_prompt);
                                        console.log('DEBUG: Value after attr() set:', $('#chatbot_system_prompt').val());
                                        
                                        // Use our helper function as backup
                                        var success = setTextareaValue('chatbot_system_prompt', response.data.improved_prompt);
                                        console.log('DEBUG: Helper function success:', success);
                                        
                                        // Try delayed setting with three different intervals
                                        setTimeout(function() {
                                            console.log('DEBUG: Attempting delayed setting (100ms)');
                                            $('#chatbot_system_prompt').val(response.data.improved_prompt);
                                        }, 100);
                                        
                                        setTimeout(function() {
                                            console.log('DEBUG: Attempting delayed setting (500ms)');
                                            textareaElement.value = response.data.improved_prompt;
                                        }, 500);
                                        
                                        setTimeout(function() {
                                            console.log('DEBUG: Attempting delayed setting (1000ms)');
                                            setTextareaValue('chatbot_system_prompt', response.data.improved_prompt);
                                            
                                            // Check for any JS errors that might be preventing the setting
                                            console.log('DEBUG: Checking for any console errors');
                                            if (window.console && console.error) {
                                                var originalError = console.error;
                                                console.error = function() {
                                                    console.log('DEBUG: Console error intercepted:', arguments);
                                                    return originalError.apply(console, arguments);
                                                };
                                            }
                                        }, 1000);
                                        
                                        // Update status with actual content preview
                                        var previewLength = 50;
                                        var improvedPromptPreview = response.data.improved_prompt.length > previewLength ? 
                                            response.data.improved_prompt.substring(0, previewLength) + '...' : 
                                            response.data.improved_prompt;
                                            
                                        $status.html('<span style="color: green;">Prompt improved! Content: <br>"' + 
                                            $('<div/>').text(improvedPromptPreview).html() + '"</span>');
                                            
                                        // Add a button to force update if all other methods fail
                                        $status.append('<br><button type="button" class="button button-small" id="force_update_prompt">Force Update</button>');
                                        
                                        // Handle force update button
                                        $('#force_update_prompt').on('click', function() {
                                            console.log('Force update button clicked');
                                            // Create a copy of the improved prompt to a global variable
                                            window.improvedPromptContent = response.data.improved_prompt;
                                            
                                            // Try direct DOM manipulation with various approaches
                                            var textarea = document.getElementById('chatbot_system_prompt');
                                            if (textarea) {
                                                textarea.value = window.improvedPromptContent;
                                                textarea.innerHTML = window.improvedPromptContent;
                                                $(textarea).trigger('input').trigger('change');
                                                
                                                // Show success message
                                                $status.html('<span style="color: green;">Prompt updated! Please save your changes.</span>');
                                            } else {
                                                $status.html('<span style="color: red;">Could not find the textarea element.</span>');
                                            }
                                        });
                                    } else {
                                        console.log('DEBUG: Error in improve prompt response', response);
                                        if (response.data && response.data.message && response.data.message.includes('API key is not set')) {
                                            handleApiKeyMissing();
                                        } else {
                                            $status.html('<span style="color: red;">' + 
                                                (response.data && response.data.message ? response.data.message : 'Unknown error') + 
                                                '</span>');
                                        }
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.log('DEBUG: AJAX error:', status, error);
                                    console.log('DEBUG: XHR response:', xhr.responseText);
                                    $status.html('<span style="color: red;">Error improving prompt.</span>');
                                },
                                complete: function() {
                                    console.log('DEBUG: AJAX complete');
                                    $button.prop('disabled', false);
                                }
                            });
                        } else {
                            // API key is not configured
                            console.log('DEBUG: API key is not configured');
                            handleApiKeyMissing();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('DEBUG: API key test error:', status, error);
                        console.log('DEBUG: XHR response:', xhr.responseText);
                        handleApiKeyMissing();
                    }
                });
            });
        }
        
        // Add preview update for typing indicator text field
        if ($('#chatbot_typing_indicator_text').length > 0) {
            $('#chatbot_typing_indicator_text').on('input', function() {
                var previewText = $(this).val();
                if (previewText.trim() === '') {
                    previewText = 'AI Assistant is typing...'; // Default value
                }
                
                // Show preview
                if (!$('#typing-indicator-preview').length) {
                    $('<div id="typing-indicator-preview" class="chatbot-preview" style="margin-top: 10px; padding: 10px; border-radius: 4px; background-color: #f0f0f0; display: inline-block;"></div>')
                        .insertAfter('#chatbot_typing_indicator_text');
                }
                $('#typing-indicator-preview').text(previewText);
            }).trigger('input'); // Initialize on page load
        }
        
        // Initialize WordPress media uploader
        if ($('#chatbot_button_icon_upload_button').length > 0) {
            $('#chatbot_button_icon_upload_button').on('click', function(e) {
                e.preventDefault();
                
                // If wp.media is not available, show error
                if (typeof wp === 'undefined' || !wp.media) {
                    alert('The WordPress Media Library is not available. Please try refreshing the page.');
                    return;
                }

                // Create the media frame
                var frame = wp.media({
                    title: 'Select or Upload an Icon',
                    button: {
                        text: 'Use this icon'
                    },
                    multiple: false
                });

                // When an image is selected in the media frame...
                frame.on('select', function() {
                    // Get the selected attachment
                    var attachment = frame.state().get('selection').first().toJSON();
                    
                    // Update the URL field
                    $('#chatbot_button_icon_url').val(attachment.url);
                    
                    // Update preview image
                    $('#chatbot_button_icon_preview_placeholder').hide();
                    $('#chatbot_button_icon_preview').attr('src', attachment.url).show();
                });

                // Open the media frame
                frame.open();
            });
        }
        
        // Handle icon type toggle
        if ($('#chatbot_button_icon_type').length > 0) {
            $('#chatbot_button_icon_type').on('change', function() {
                var iconType = $(this).val();
                if (iconType === 'custom') {
                    $('#chatbot_button_icon_wrapper').show();
                    $('#chatbot_button_icon_upload_wrapper').hide();
                } else if (iconType === 'upload') {
                    $('#chatbot_button_icon_wrapper').hide();
                    $('#chatbot_button_icon_upload_wrapper').show();
                } else {
                    $('#chatbot_button_icon_wrapper').hide();
                    $('#chatbot_button_icon_upload_wrapper').hide();
                }
            }).trigger('change');
        }
        
        // Handle sample SVG icon clicks
        if ($('.svg-sample').length > 0) {
            $('.svg-sample').on('click', function() {
                var svgCode = $(this).find('div').html();
                $('#chatbot_button_icon').val(svgCode);
            });
        }
        
        // Only run conversation-specific code on conversation view page
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