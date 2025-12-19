/**
 * AIPass Integration for WordPress Chatbot Plugin
 * Handles the integration between the official AIPass Web SDK and WordPress admin interface
 *
 * Updated: 2025-11-09 to use official hosted SDK from https://aipass.one/aipass-sdk.js
 */

(function($) {
    'use strict';

    // Initialize AIPass integration
    function initAIPass() {
        if (!window.AiPass || !window.chatbotAIPassVars) {
            console.error('AIPass SDK or initialization variables not available');
            console.error('- AiPass available:', !!window.AiPass);
            console.error('- chatbotAIPassVars available:', !!window.chatbotAIPassVars);
            return;
        }

        // Check if crypto API is available
        if (!window.crypto || !window.crypto.subtle) {
            console.warn('crypto.subtle not available - this may cause issues with PKCE');
            console.warn('Make sure you are accessing the site via HTTPS or localhost');
        }

        const clientId = window.chatbotAIPassVars.clientId;
        const baseUrl = window.chatbotAIPassVars.baseUrl;
        const callbackUrl = window.chatbotAIPassVars.callbackUrl;

        console.log('Initializing AIPass SDK...');
        console.log('- Client ID:', clientId);
        console.log('- Base URL:', baseUrl);
        console.log('- Callback URL:', callbackUrl);
        console.log('- Protocol:', window.location.protocol);
        console.log('- crypto.subtle available:', !!(window.crypto && window.crypto.subtle));

        // Initialize the hosted AIPass SDK with custom redirect URI
        try {
            AiPass.initialize({
                clientId: clientId,
                baseUrl: baseUrl,
                redirectUri: callbackUrl,  // Use our WordPress REST API endpoint
                scopes: ['api:access', 'profile:read']
            });

            console.log('✓ AIPass SDK initialized successfully');

            // Listen for budget exceeded events from the SDK
            if (AiPass.on) {
                AiPass.on('budgetExceeded', function(data) {
                    console.log('Budget exceeded event received:', data);
                    handleBudgetExceeded(data);
                });

                AiPass.on('balanceUpdated', function(data) {
                    console.log('Balance updated event received:', data);
                    updateBalanceInfo();
                });
            }
        } catch (error) {
            console.error('✗ Failed to initialize AIPass SDK:', error);
            console.error('Error details:', error.message, error.stack);
            return;
        }

        // Update connection status on page load
        updateConnectionStatus();
    }

    // Update UI to reflect connection status
    function updateConnectionStatus() {
        if (!window.AiPass) {
            return;
        }

        // Check for URL parameters indicating successful connection
        const urlParams = new URLSearchParams(window.location.search);
        const justConnected = urlParams.has('aipass_connected');

        // Make server-side check for connection status
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'chatbot_aipass_auth_status',
                nonce: window.chatbotAIPassVars.nonce
            },
            success: function(response) {
                if (response.success) {
                    const serverConnected = response.data.connected;
                    const sdkAuthenticated = AiPass.isAuthenticated();

                    console.log('Connection status - Server:', serverConnected, 'SDK:', sdkAuthenticated);

                    // If connected according to server OR SDK, update UI
                    if (serverConnected || sdkAuthenticated || justConnected) {
                        showConnectedUI();
                        // Get balance info
                        updateBalanceInfo();
                    } else {
                        showDisconnectedUI();
                    }
                }
            },
            error: function() {
                console.error('Failed to check AIPass connection status');
            }
        });
    }

    // Helper to show connected UI state
    function showConnectedUI() {
        console.log('Showing connected UI state');
        $('#chatbot_aipass_enabled').prop('checked', true);
        $('.aipass-status').removeClass('not-connected').addClass('connected');
        $('#chatbot-aipass-connection').show();
        $('.aipass-status.not-connected').hide();
        $('.aipass-status.connected').show();
    }

    // Helper to show disconnected UI state
    function showDisconnectedUI() {
        console.log('Showing disconnected UI state');
        $('.aipass-status.connected').hide();
        $('.aipass-status.not-connected').show();
    }

    // Update balance information in the UI
    function updateBalanceInfo() {
        if (!window.AiPass) {
            return;
        }

        const $balanceInfo = $('#aipass-balance-info');
        if (!$balanceInfo.length) {
            return;
        }

        // Show loading state
        $balanceInfo.html('<span class="balance-loading">Loading balance...</span>');

        // Make AJAX call to get balance info (server-side call for security)
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'chatbot_aipass_get_balance',
                nonce: window.chatbotAIPassVars.nonce
            },
            success: function(response) {
                if (response.success && response.data.balance) {
                    const balance = response.data.balance;
                    let html = '<div class="balance-summary">';
                    html += '<span class="balance-label">Remaining Budget:</span> ';
                    html += '<span class="balance-amount">$' + balance.remainingBudget.toFixed(2) + '</span>';
                    html += '<span style="color: #666; font-size: 12px; margin-left: 10px;">';
                    html += '(Used: $' + balance.totalCost.toFixed(2) + ' / Max: $' + balance.maxBudget.toFixed(2) + ')';
                    html += '</span>';
                    html += '</div>';
                    $balanceInfo.html(html);
                } else {
                    $balanceInfo.html('<div class="balance-error">Could not load balance info</div>');
                }
            },
            error: function() {
                $balanceInfo.html('<div class="balance-error">Connection error</div>');
            }
        });
    }

    // Initialize when document is ready
    $(document).ready(function() {
        console.log('AIPass integration: Document ready');
        console.log('chatbotAIPassVars available:', !!window.chatbotAIPassVars);
        console.log('AiPass SDK available:', !!window.AiPass);

        // Only run on admin settings page
        if (window.chatbotAIPassVars) {
            console.log('chatbotAIPassVars:', window.chatbotAIPassVars);

            // Wait for SDK to be fully loaded
            if (window.AiPass) {
                initAIPass();
            } else {
                console.error('AIPass SDK not loaded yet, waiting...');
                // Try again after a short delay
                setTimeout(function() {
                    if (window.AiPass) {
                        initAIPass();
                    } else {
                        console.error('AIPass SDK failed to load');
                    }
                }, 1000);
            }

            // Check URL for connection indicators
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('aipass_connected')) {
                console.log('AIPass connection detected in URL params');
                // Force refresh UI after a short delay
                setTimeout(function() {
                    showConnectedUI();
                    updateBalanceInfo();
                }, 500);
            }

            // Setup event listeners for the buttons (both AI Integration tab and General Settings tab)
            console.log('Looking for connect button:', $('#chatbot-aipass-connect').length);
            console.log('Looking for connect button (general):', $('#chatbot-aipass-connect-general').length);
            console.log('Looking for disconnect button:', $('#chatbot-aipass-disconnect').length);
            console.log('Looking for disconnect button (general):', $('#chatbot-aipass-disconnect-general').length);

            // AI Integration tab buttons
            $('#chatbot-aipass-connect').on('click', handleConnect);
            $('#chatbot-aipass-disconnect').on('click', handleDisconnect);
            $('#chatbot-aipass-refresh-models').on('click', handleRefreshModels);

            // General Settings tab buttons (same handlers)
            $('#chatbot-aipass-connect-general').on('click', handleConnect);
            $('#chatbot-aipass-disconnect-general').on('click', handleDisconnect);

            console.log('Event handlers attached');
        } else {
            console.log('chatbotAIPassVars not available - not on settings page?');
        }
    });

    // Handle AIPass connect button click
    async function handleConnect(e) {
        e.preventDefault();
        console.log('=== AIPass Connect Button Clicked ===');

        if (!window.AiPass) {
            console.error('AIPass SDK not available');
            alert('AIPass SDK not initialized. Please refresh the page.');
            return;
        }

        // Verify crypto is available
        if (!window.crypto || !window.crypto.subtle) {
            console.error('crypto.subtle not available');
            alert('Crypto API not available. Please make sure you are using HTTPS or localhost.');
            return;
        }

        console.log('Pre-flight checks passed');
        console.log('- AiPass SDK:', typeof window.AiPass);
        console.log('- crypto.subtle:', typeof window.crypto.subtle);

        try {
            // Start OAuth2 authorization flow using popup
            // The SDK will handle PKCE, state, and token exchange automatically
            console.log('Calling AiPass.login()...');
            const tokenData = await AiPass.login();

            console.log('✓ AIPass login successful!');
            console.log('Token data received:', tokenData ? 'Yes' : 'No');

            // Get access token from returned tokenData or SDK
            // The login() method now returns tokenData directly with access_token, refresh_token, expires_in
            let accessToken = null;
            let refreshToken = null;
            let expiresIn = null;

            if (tokenData) {
                // Use tokenData returned from login()
                accessToken = tokenData.access_token;
                refreshToken = tokenData.refresh_token || null;
                expiresIn = tokenData.expires_in || null;
            } else {
                // Fallback: get from SDK methods
                accessToken = AiPass.getAccessTokenSync ? AiPass.getAccessTokenSync() : await AiPass.getAccessToken();

                // Get expiry info from getTokenInfo()
                const tokenInfo = AiPass.getTokenInfo ? AiPass.getTokenInfo() : null;
                if (tokenInfo) {
                    expiresIn = tokenInfo.expiresIn || null;
                }
            }

            console.log('Access token retrieved:', accessToken ? 'Yes' : 'No');
            console.log('Refresh token retrieved:', refreshToken ? 'Yes' : 'No');
            console.log('Token expires in:', expiresIn ? expiresIn + ' seconds (' + Math.round(expiresIn / 3600) + ' hours)' : 'Unknown');

            // Try to sync tokens to backend (critical for server-side API calls)
            if (accessToken) {
                console.log('Syncing tokens to backend...');
                try {
                    await syncTokensWithBackend(accessToken, refreshToken, expiresIn);
                    console.log('✓ Tokens synced to backend');
                } catch (syncError) {
                    console.warn('Token sync failed (non-critical):', syncError.message);
                    console.log('Token is still available in SDK, continuing...');
                }
            }

            // Update UI (do this regardless of sync result)
            console.log('Updating UI...');
            showConnectedUI();

            // Update balance info
            setTimeout(function() {
                updateBalanceInfo();
            }, 500);

            // Reload page to ensure all settings are fresh
            console.log('Reloading page to refresh settings...');
            setTimeout(function() {
                window.location.reload();
            }, 1000);

        } catch (error) {
            console.error('✗ AIPass login error:', error);
            console.error('Error type:', error.constructor.name);
            console.error('Error message:', error.message);
            console.error('Error stack:', error.stack);

            // Check if it's a user cancellation
            if (error.message && error.message.includes('cancel')) {
                console.log('User cancelled authorization');
                // Don't show error for user cancellation
            } else {
                alert('Error connecting to AIPass: ' + error.message);
            }
        }
    }

    // Sync tokens from SDK to WordPress backend
    async function syncTokensWithBackend(accessToken, refreshToken, expiresIn) {
        return new Promise((resolve, reject) => {
            const requestData = {
                action: 'chatbot_aipass_sync_token',
                nonce: window.chatbotAIPassVars.nonce,
                access_token: accessToken
            };

            // Include refresh token if available
            if (refreshToken) {
                requestData.refresh_token = refreshToken;
            }

            // Include expires_in if available
            if (expiresIn && expiresIn > 0) {
                requestData.expires_in = expiresIn;
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: requestData,
                timeout: 10000, // 10 second timeout
                success: function(response) {
                    if (response.success) {
                        console.log('Tokens synced with backend successfully');
                        resolve(response.data);
                    } else {
                        console.error('Failed to sync tokens:', response.data);
                        reject(new Error(response.data?.message || 'Token sync failed'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error during token sync:', status, error);
                    reject(new Error('Connection error during token sync: ' + status));
                }
            });
        });
    }

    // Handle AIPass disconnect button click
    function handleDisconnect(e) {
        e.preventDefault();
        console.log('Handling disconnect button click');

        if (confirm('Are you sure you want to disconnect from AIPass? You will need to reconnect or provide an API key to use AI features.')) {
            // Make AJAX call to disconnect on server
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'chatbot_aipass_disconnect',
                    nonce: window.chatbotAIPassVars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Clear SDK tokens
                        if (window.AiPass) {
                            try {
                                AiPass.logout();
                            } catch (e) {
                                console.warn('SDK logout error (non-critical):', e);
                            }
                        }

                        // Reload page to ensure UI reflects current state
                        window.location.reload();
                    } else {
                        alert('Error disconnecting from AIPass: ' + (response.data ? response.data.message : 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Connection error during AIPass disconnect');
                }
            });
        }
    }

    // Handle refresh models button click
    function handleRefreshModels(e) {
        e.preventDefault();
        console.log('Refreshing AIPass models...');

        const $button = $(e.target);
        const originalText = $button.text();

        $button.prop('disabled', true).text('Refreshing...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'chatbot_aipass_get_models',
                nonce: window.chatbotAIPassVars.nonce,
                force_refresh: true
            },
            success: function(response) {
                if (response.success) {
                    alert('Models refreshed successfully! (' + response.data.count + ' models available)');
                    // Reload to show updated model list
                    window.location.reload();
                } else {
                    alert('Error refreshing models: ' + (response.data ? response.data.message : 'Unknown error'));
                }
            },
            error: function() {
                alert('Connection error while refreshing models');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }

    // Handle budget exceeded event from SDK
    function handleBudgetExceeded(data) {
        console.log('Handling budget exceeded:', data);

        // The SDK will show its own modal with payment options
        // We can also update our UI to reflect the low balance
        const $balanceInfo = $('#aipass-balance-info, #aipass-balance-info-general');
        if ($balanceInfo.length) {
            $balanceInfo.html(
                '<div class="balance-error" style="color: #d32f2f;">' +
                '<strong>⚠️ Insufficient Balance</strong> - ' +
                '<a href="https://aipass.one/panel/dashboard.html" target="_blank" style="color: #8A4FFF;">Add funds →</a>' +
                '</div>'
            );
        }

        // Show a notice in admin if we're on a settings page
        if ($('.wrap h1').length) {
            const noticeHtml = '<div class="notice notice-error is-dismissible" id="aipass-budget-notice">' +
                '<p><strong>AIPass Budget Exceeded:</strong> Your AIPass balance is too low to continue. ' +
                '<a href="https://aipass.one/panel/dashboard.html" target="_blank">Add funds to your account →</a></p>' +
                '</div>';

            // Only add if not already present
            if (!$('#aipass-budget-notice').length) {
                $('.wrap h1').after(noticeHtml);
            }
        }
    }

    // Expose functions to global scope
    window.chatbotAIPass = {
        init: initAIPass,
        updateBalanceInfo: updateBalanceInfo,
        connect: handleConnect,
        disconnect: handleDisconnect,
        refreshModels: handleRefreshModels,
        handleBudgetExceeded: handleBudgetExceeded
    };

})(jQuery);
