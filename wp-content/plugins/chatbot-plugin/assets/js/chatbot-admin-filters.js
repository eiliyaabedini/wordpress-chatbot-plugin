/**
 * Chatbot Admin Filters JavaScript
 * Handles filtering in the conversations admin page
 */

(function($) {
    'use strict';
    
    // Wait for DOM to be ready
    $(document).ready(function() {
        // Handle filter form submission
        $('#chatbot-filter-form').on('submit', function(e) {
            
            // If "All Chatbots" is selected (empty value), remove the parameter from the form
            var chatbotFilter = $('#chatbot_filter');
            if (chatbotFilter.length && chatbotFilter.val() === '') {
                chatbotFilter.removeAttr('name');
            }
        });
        
        // Fix problematic URLs on page load
        function fixChatbotUrls() {
            // If we have an empty chatbot parameter, remove it from the URL
            if (window.location.href.includes('chatbot=') && !window.location.href.match(/chatbot=\d+/)) {
                
                var currentUrl = window.location.href;
                var fixedUrl = currentUrl;
                
                // Fix URLs with empty chatbot parameter
                if (currentUrl.includes('&chatbot=&')) {
                    fixedUrl = currentUrl.replace('&chatbot=&', '&');
                } else if (currentUrl.includes('?chatbot=&')) {
                    fixedUrl = currentUrl.replace('?chatbot=&', '?');
                } else if (currentUrl.endsWith('&chatbot=')) {
                    fixedUrl = currentUrl.substring(0, currentUrl.length - 9);
                } else if (currentUrl.endsWith('?chatbot=')) {
                    fixedUrl = currentUrl.substring(0, currentUrl.length - 9);
                }
                
                // Cleanup any malformed URL resulting from the above replacements
                if (fixedUrl.endsWith('?')) {
                    fixedUrl = fixedUrl.substring(0, fixedUrl.length - 1);
                }
                if (fixedUrl.includes('?&')) {
                    fixedUrl = fixedUrl.replace('?&', '?');
                }
                
                // Only update if we actually changed something
                if (fixedUrl !== currentUrl) {
                    // Update URL without reloading
                    window.history.replaceState({}, document.title, fixedUrl);
                }
            }
        }
        
        // Run URL fix on page load
        fixChatbotUrls();
    });
    
})(jQuery);