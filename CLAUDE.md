# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This project is a WordPress plugin called "AI Chat Bot" that adds an AI-powered interactive chatbot to WordPress websites. The plugin provides a simple interface for users to interact with a chatbot via a shortcode.

### Key Features

- **AIPass Integration**: OAuth2-based managed API service - no API keys needed
- **Multiple AI Models**: Access to 161+ models including OpenAI GPT-4, O-series, and Google Gemini
- **Smart Configuration**: Dynamic model loading from AIPass server
- **User-Friendly**: End users just login with AIPass - one-click connection
- **Conversation Management**: Full conversation history and analytics
- **Rate Limiting**: Built-in abuse prevention
- **Security**: XSS/SQL injection protection, PKCE-based OAuth2
- **Data Retention**: Automatic cleanup of old conversations

## Development Environment

This project uses DDEV for local WordPress development. Key commands:

- `ddev start`: Start the development environment
- `ddev stop`: Stop the development environment
- `ddev ssh`: SSH into the web container
- `ddev wp`: Run WP-CLI commands (e.g., `ddev wp plugin list`)
- `ddev launch`: Open the WordPress site in your browser
- `ddev describe`: Show project details and URLs

## Plugin Structure

### Core Files
- `wp-content/plugins/chatbot-plugin/chatbot-plugin.php`: Main plugin file
- `wp-content/plugins/chatbot-plugin/includes/class-chatbot-handler.php`: Message processing and routing
- `wp-content/plugins/chatbot-plugin/includes/class-chatbot-db.php`: Database operations
- `wp-content/plugins/chatbot-plugin/includes/class-chatbot-admin.php`: Admin interface
- `wp-content/plugins/chatbot-plugin/includes/class-chatbot-settings.php`: Settings management

### AI Integration
- `wp-content/plugins/chatbot-plugin/includes/class-chatbot-aipass.php`: AIPass OAuth2 integration (core AI service)
- `wp-content/plugins/chatbot-plugin/includes/class-chatbot-aipass-proxy.php`: AIPass proxy handlers
- `wp-content/plugins/chatbot-plugin/includes/class-chatbot-ai.php`: AI facade class (thin wrapper for AIPass)

### Frontend Assets
- `wp-content/plugins/chatbot-plugin/assets/js/aipass-sdk.js`: AIPass SDK loader (loads hosted SDK from https://aipass.one/aipass-sdk.js)
- `wp-content/plugins/chatbot-plugin/assets/js/aipass-integration.js`: WordPress AIPass UI integration
- `wp-content/plugins/chatbot-plugin/assets/js/chatbot.js`: Chatbot frontend functionality
- `wp-content/plugins/chatbot-plugin/assets/css/chatbot.css`: Chatbot styles

**Note**: As of 2025-11-09, the plugin uses the official hosted AIPass Web SDK instead of a custom implementation.

### Additional Components
- `wp-content/plugins/chatbot-plugin/includes/class-chatbot-analytics.php`: Usage analytics
- `wp-content/plugins/chatbot-plugin/includes/class-chatbot-notifications.php`: Admin notifications
- `wp-content/plugins/chatbot-plugin/includes/class-chatbot-rate-limiter.php`: Rate limiting
- `wp-content/plugins/chatbot-plugin/includes/class-chatbot-data-retention.php`: Data cleanup
- `wp-content/plugins/chatbot-plugin/templates/chatbot-template.php`: Chatbot UI template
- `wp-content/plugins/chatbot-plugin/languages/`: Translation files

## AIPass Integration (Production)

### Overview

AIPass is an OAuth2-based managed API service that allows users to access AI models without their own API keys. The plugin uses the production AIPass backend at **https://aipass.one**.

### Hardcoded Credentials (Set by Plugin Developer)

These credentials are hardcoded in `includes/class-chatbot-aipass.php:20-21`:

```php
private $base_url = 'https://aipass.one';
private $client_id = 'client_B44Woc2V6Jc_ywmlbIKLEA';
```

**IMPORTANT**: End users should NOT see these credentials. They only need to click "Connect with AIPass" and login.

### OAuth2 + PKCE Flow

**Updated 2025-11-09**: Now uses official hosted AIPass SDK with popup-based OAuth flow.

The implementation follows RFC 6749 (OAuth 2.0) and RFC 7636 (PKCE):

1. User clicks "Connect with AIPass"
2. **Hosted SDK** opens OAuth popup window
3. SDK generates PKCE code verifier and challenge (SHA-256)
4. User redirected to `https://aipass.one/oauth2/authorize` in popup
5. User logs in and approves
6. AIPass redirects popup with authorization code
7. **Client-side** SDK exchanges code for access token automatically
8. SDK returns access token via postMessage to parent window
9. JavaScript syncs token to WordPress backend via AJAX
10. Tokens stored in WordPress options for server-side API calls

**Key Change**: OAuth now happens in popup (better UX, no page reload) instead of full-page redirect.

### AIPass API Endpoints

All endpoints use `Authorization: Bearer {access_token}` header:

- **Token Exchange**: `POST /oauth2/token` - Exchange authorization code for tokens
- **Token Refresh**: `POST /oauth2/token` (grant_type: refresh_token)
- **Token Revocation**: `POST /oauth2/revoke`
- **Chat Completions**: `POST /oauth2/v1/chat/completions` - OpenAI-compatible format
- **Available Models**: `GET /api/v1/usage/models` - Returns list of available models
- **User Balance**: `GET /api/v1/usage/me/summary` - Returns totalCost, maxBudget, remainingBudget

### Redirect URI Configuration

**Updated 2025-11-09**: Now uses WordPress REST API endpoint with popup flow support.

The redirect URI is dynamic based on each WordPress installation:

```
{wordpress-site-url}/wp-json/chatbot-plugin/v1/aipass-callback
```

**Examples**:
- DDEV: `http://127.0.0.1:54323/wp-json/chatbot-plugin/v1/aipass-callback`
- DDEV Domain: `http://chatbot-plugin-v2.ddev.site/wp-json/chatbot-plugin/v1/aipass-callback`
- Production: `https://example.com/wp-json/chatbot-plugin/v1/aipass-callback`

**IMPORTANT**: This redirect URI must be registered in your AIPass OAuth2 client configuration.

**How It Works**:
1. Hosted SDK opens popup to AIPass authorization page
2. User approves, AIPass redirects popup to callback URL
3. Callback page uses `postMessage` to send code to opener window
4. SDK receives code and exchanges it for tokens
5. Popup closes automatically

### Dynamic Model Loading

When AIPass is connected:
- Models are fetched from `/api/v1/usage/models`
- 161+ models available (OpenAI GPT, O-series, Gemini)
- Models cached for 1 hour in WordPress transients
- "Refresh Models" button to update list manually

Models include:
- **Gemini**: `gemini/gemini-2.5-flash` (fastest & cheapest), `gemini/gemini-2.5-flash-lite`, `gemini/gemini-2.5-pro`
- **OpenAI GPT**: `openai/gpt-4o-mini`, `openai/gpt-4.1-mini`, `openai/gpt-4`
- **OpenAI O-series**: `openai/o4-mini`, `openai/o3-mini`, `openai/o1`

### Testing AIPass Integration

**In WordPress Admin**:
1. Go to: Chat Bots → Settings → AI tab
2. AIPass section shows connection status
3. Click "Test Connection" - sends real AI completion request
4. Expected: `✓ Connection successful! AI responded: "CONNECTED" | Balance: $XX.XX`

**In Live Chatbot**:
1. Add `[chatbot]` shortcode to any page
2. Start conversation
3. Messages should be responded to by AI (no debug messages)
4. Check logs for: `Chatbot: INFO - generate_response - Using AIPass for API request`

### AI Provider

The plugin uses AIPass exclusively for all AI operations:
- If AIPass connected → Use AIPass for AI responses
- If not connected → Fallback to simple pattern-matching responses

This is controlled in `includes/class-chatbot-ai.php` which delegates to AIPass.

### Token Refresh Mechanism (CRITICAL)

**Automatic Token Refresh** (Implemented 2025-11-11):

The plugin now implements automatic token refresh to maintain persistent connections without requiring users to manually reconnect.

**How It Works**:
1. **Token Storage**: Both access_token and refresh_token are stored in WordPress options
   - `chatbot_aipass_access_token` - Valid for duration specified by AIPass API (typically ~1 week)
   - `chatbot_aipass_refresh_token` - Valid for longer period (used to get new access tokens)
   - `chatbot_aipass_token_expiry` - Unix timestamp when access token expires (from `expires_in` in API response)

2. **Proactive Refresh**: `is_connected()` checks if token expires within 5 minutes
   - If expiring soon, automatically calls `refresh_access_token()`
   - New tokens are fetched from `/oauth2/token` endpoint
   - WordPress options are updated with new tokens
   - No user intervention required

3. **Token Expiry Detection**:
   - AIPass API returns `expires_in` (in seconds) with every token response
   - JavaScript SDK: `AiPass.getExpiresIn()` returns expiry duration
   - OAuth callback: Token exchange response includes `expires_in` field
   - Token refresh: Refresh response includes new `expires_in` for new token
   - Fallback: If `expires_in` not available, defaults to 30 days (2592000 seconds)
   - Token expiry is stored as Unix timestamp: `time() + expires_in`
   - Comprehensive logging added to track if API is returning `expires_in` or using fallback

4. **Refresh Token Lifecycle**:
   - When user connects via SDK, both access and refresh tokens are synced to backend
   - JavaScript calls `AiPass.getRefreshToken()` and `AiPass.getExpiresIn()`, sends to PHP via AJAX
   - PHP stores both tokens and calculates expiry timestamp
   - When access token expires, PHP uses refresh token to get new access token
   - New token comes with new `expires_in` value from API
   - If refresh token is invalid/expired, user must reconnect (tokens are cleared)

5. **API Call Protection**: All AIPass API methods call `is_connected()` first
   - `generate_completion()` - Line 1455
   - `get_available_models()` - Line 1365
   - `get_balance_info()` - Line 1198
   - Each automatically refreshes token before making API call

**Why This Matters**:
- Access tokens typically last 7 days (as returned by AIPass API)
- Fallback is set to 30 days if API doesn't return `expires_in` (for maximum safety)
- Without token refresh: Connection expires after token lifetime, chatbot stops working
- With token refresh: Connection persists indefinitely (until refresh token expires, typically 30 days)
- Proactive refresh (5 minutes before expiry) prevents mid-conversation interruptions
- Users connect once and never need to reconnect (unless refresh token expires)
- Comprehensive logging helps diagnose if API is not returning `expires_in` correctly

**Files Modified**:
- `includes/class-chatbot-aipass.php:981-1089` - Added `refresh_access_token()` method
- `includes/class-chatbot-aipass.php:954-979` - Updated `is_connected()` to auto-refresh
- `includes/class-chatbot-aipass.php:1534-1620` - Updated `sync_token_from_sdk()` to accept `expires_in` from SDK/API
- `includes/class-chatbot-aipass.php:1544-1575` - Added failsafe: auto-refresh on 401 Unauthorized
- `assets/js/aipass-integration.js:238-307` - Get access token, refresh token, and `expires_in` from SDK, sync to backend

**Failsafe Mechanism** (Added 2025-11-12):
- If API returns 401 Unauthorized, automatically refresh token and retry ONCE
- Prevents issues when stored expiry time is incorrect (e.g., from old fallback code)
- Logs warning when this happens to help diagnose expiry mismatch issues

### Important Notes

- **PKCE Security**: Uses SHA-256 when HTTPS available, falls back to "plain" for HTTP contexts
- **Token Storage**: Both access and refresh tokens stored in WordPress options (NOT in JavaScript)
- **Token Refresh**: Automatic refresh happens 5 minutes before expiry (proactive, not reactive)
- **CORS Avoidance**: Token exchange happens server-side in PHP (not client-side JavaScript)
- **Cache Management**: Models cached for 1 hour, balance fetched on page load

## Key Development Tasks

### Adding the Chatbot to a Page

The chatbot can be added to any page or post using the shortcode:
```
[chatbot]
```

Or with a dark theme:
```
[chatbot theme="dark"]
```

### Modifying Response Logic

The chatbot's response generation flow:

1. **Message received**: `class-chatbot-handler.php:133` - `send_message()`
2. **Response generated**: `class-chatbot-handler.php:346` - `generate_response()`
3. **AI integration**: Calls `Chatbot_AI::generate_response()` which delegates to AIPass
4. **AIPass API call**: `class-chatbot-aipass.php` - Uses `/oauth2/v1/chat/completions`
5. **Conversation history**: Last 10 messages included for context
6. **System prompt**: Combines persona + knowledge base from chatbot configuration

### Styling the Chatbot

To modify the appearance of the chatbot, edit the CSS in `/assets/css/chatbot.css`.

### Adding New Features

1. Implement any new functionality in appropriate PHP classes
2. Update JavaScript in `/assets/js/chatbot.js` for front-end functionality
3. Update templates as needed
4. Ensure all user-facing strings are internationalized using WordPress i18n functions

### Testing

**Plugin Activation/Deactivation**:
```bash
ddev wp plugin activate chatbot-plugin
ddev wp plugin deactivate chatbot-plugin
```

**Create Test Page**:
```bash
ddev wp post create --post_type=page --post_title="Test Chatbot" --post_content="[chatbot]" --post_status=publish
ddev wp post list --post_type=page --title="Test Chatbot" --field=url
```

**Test AIPass Connection**:
```bash
# Check if AIPass is connected
ddev wp eval '$aipass = Chatbot_AIPass::get_instance(); echo "Connected: " . ($aipass->is_connected() ? "YES" : "NO");'

# Test model loading
ddev wp eval '$aipass = Chatbot_AIPass::get_instance(); $result = $aipass->get_available_models(); echo "Models: " . count($result["models"]);'

# Check if AI is configured
ddev wp eval '$ai = Chatbot_AI::get_instance(); echo "AI Configured: " . ($ai->is_configured() ? "YES" : "NO");'
```

**Clear AIPass Connection** (for testing):
```bash
ddev wp option delete chatbot_aipass_access_token
ddev wp option delete chatbot_aipass_refresh_token
ddev wp option delete chatbot_aipass_token_expiry
ddev wp option delete chatbot_aipass_enabled
ddev wp transient delete chatbot_aipass_models_cache
```

**View Logs**:
```bash
tail -f wp-content/debug.log | grep Chatbot
```

### Deployment

To create a deployable zip file:
```
ddev exec "cd wp-content/plugins && zip -r chatbot-plugin.zip chatbot-plugin"
```

Then download from the `wp-content/plugins` directory.

## Development Guidelines

- Always use Claude 3.7 Sonnet model dont go on 3.5
- Always use git commit history to read commit messages and learn about project history
- Implement proper logging throughout the codebase 

## Logging Standards

1. **Comprehensive Logging**: Implement detailed logging for all critical operations, user actions, and error states. This is MANDATORY for all new code and updates to existing code.

2. **When to Use Logging**:
   - At the start and end of important functions
   - When handling user input/requests
   - During API calls and responses
   - For all database operations
   - When errors or exceptions occur
   - During plugin initialization and deactivation
   - For any state changes or important events

3. **Log Structure**:
   - All logs should use the prefix 'Chatbot:' for easy filtering
   - Include context information (function name, operation being performed)
   - For errors, include the specific error message and relevant variables
   - Include values of key variables (sanitized if containing sensitive data)

4. **Logging Levels**:
   - Use `error_log()` for standard logging (WordPress default)
   - For errors: `error_log('Chatbot: ERROR - [function/context] - [error description]');`
   - For info: `error_log('Chatbot: INFO - [function/context] - [information]');`
   - For debug: `error_log('Chatbot: DEBUG - [function/context] - [debug information]');`

5. **Debugging Process**:
   - When debugging issues, ALWAYS check logs first
   - If logs are insufficient, add additional temporary logging to relevant code sections
   - Include browser console logging for front-end issues
   - Log both input and output values of problematic functions

6. **Sensitive Data**:
   - Never log API keys, passwords, or personal information
   - For sensitive fields, log only presence/absence (e.g., "API key exists: Yes/No")
   - Truncate potentially large values in logs (e.g., system prompts)

By maintaining comprehensive logging, we create a reliable audit trail for troubleshooting issues, understanding system behavior, and improving the plugin over time.

## Recent Updates

### Architecture Simplification (December 2024)

**Change**: Removed direct OpenAI API support - now AIPass only
- ✅ Renamed `class-chatbot-openai.php` to `class-chatbot-ai.php`
- ✅ Renamed `Chatbot_OpenAI` class to `Chatbot_AI`
- ✅ Removed all `chatbot_openai_*` option names (now `chatbot_ai_*`)
- ✅ Simplified architecture - AIPass is the only AI provider
- ✅ Reduced codebase complexity significantly

### AIPass SDK Migration (2025-11-09)

**Migrated to Official Hosted SDK**:

- ✅ **Replaced custom SDK implementation** with official hosted SDK from `https://aipass.one/aipass-sdk.js`
- ✅ **Updated OAuth flow** from full-page redirect to popup-based authentication
- ✅ **New API pattern**: Changed from class instantiation (`new AIPass()`) to static methods (`AiPass.initialize()`, `AiPass.login()`)
- ✅ **Token sync mechanism**: Client-side SDK obtains tokens, syncs to WordPress backend via AJAX
- ✅ **Backward compatibility**: PHP backend continues to work for server-side API calls

**Benefits**:
- Auto-updates from AIPass team
- Better UX with popup OAuth (no page reload)
- Access to new features: image generation, speech, embeddings
- Simplified maintenance

### AIPass Integration Completed

**What We Implemented**:

1. ✅ **Production OAuth2 + PKCE Implementation**
   - Integrated with https://aipass.one production backend
   - Follows RFC 6749 (OAuth 2.0) and RFC 7636 (PKCE)
   - ~~Server-side token exchange to avoid CORS issues~~ **Updated**: Now client-side via hosted SDK
   - Automatic token refresh support

2. ✅ **AIPass SDK (JavaScript)** - **Updated 2025-11-09**
   - Files: `assets/js/aipass-sdk.js` (loader only), `assets/js/aipass-integration.js`
   - Uses official hosted SDK from `https://aipass.one/aipass-sdk.js`
   - Popup-based OAuth with automatic PKCE handling
   - Methods: `AiPass.login()`, `AiPass.generateCompletion()`, `AiPass.getUserBalance()`, `AiPass.getModels()`

3. ✅ **PHP Backend Integration**
   - File: `includes/class-chatbot-aipass.php`
   - Hardcoded credentials (transparent to end users)
   - Token exchange, refresh, and revocation
   - Chat completion API integration
   - Balance tracking
   - Dynamic model fetching

4. ✅ **Dynamic Model Loading**
   - Fetches 161+ models from `/api/v1/usage/models`
   - Includes OpenAI (GPT-4, O-series), Gemini models
   - 1-hour caching to reduce API calls
   - Manual refresh button in settings

5. ✅ **User Experience**
   - Simple "Connect with AIPass" button
   - No visible client credentials or technical configuration
   - Balance display in admin (Remaining / Used / Max)
   - Test Connection button with real AI completion

6. ✅ **Security Features**
   - PKCE (Proof Key for Code Exchange) prevents authorization code interception
   - State parameter for CSRF protection
   - Tokens stored securely in WordPress options
   - Nonce verification on all AJAX requests
   - Server-side validation

### Architecture Decisions

**Why Use Hosted SDK Instead of Custom Implementation?** (Updated 2025-11-09)
- Auto-updates and bug fixes from AIPass team
- Access to new features without code changes
- Better OAuth UX with popup flow
- Reduced maintenance burden

**Why Hybrid Approach (Client SDK + Server Backend)?**
- **Client SDK**: Handles OAuth authentication (popup UX)
- **Server Backend**: Makes AI API calls (more secure, no CORS)
- **Token sync**: Best of both worlds - modern auth + secure API calls

**Why Hardcoded Client Credentials?**
- Commercial plugin distribution model
- End users shouldn't configure OAuth2 technical details
- One-click "Connect with AIPass" experience

### Known Issues & Solutions

**Issue**: Empty model dropdown after connection
**Solution**: Hard refresh browser (Ctrl+Shift+R) to clear cache

**Issue**: Settings reset on save
**Solution**: Added hidden inputs to preserve tokens during form submission

**Issue**: crypto.subtle not available (HTTP context)
**Solution**: Access via http://127.0.0.1 instead of .ddev.site domain

## Versioning and Builds

### Version Numbering
- **Patch version** (1.3.0 → 1.3.1): Increment on every build/zip creation (without commit)
- **Minor version** (1.3.6 → 1.4.0): Increment when user asks to COMMIT (commit = minor version bump)
- **Major version** (1.4.0 → 2.0.0): Only increment for breaking changes when explicitly requested

### Version Locations
Update version in TWO places in `chatbot-plugin.php`:
1. Plugin header: `* Version: X.Y.Z`
2. Constant: `define('CHATBOT_PLUGIN_VERSION', 'X.Y.Z');`

### Creating a Build
```bash
cd /Users/eiliya/ai/app/idea/chatbot-plugin/wp-content/plugins
rm -f chatbot-plugin.zip
zip -r chatbot-plugin.zip chatbot-plugin \
  -x "*.DS_Store" \
  -x "*__MACOSX*" \
  -x "chatbot-plugin/vendor/*" \
  -x "chatbot-plugin/tests/*" \
  -x "chatbot-plugin/bin/*" \
  -x "chatbot-plugin/.git/*" \
  -x "chatbot-plugin/docs/*" \
  -x "chatbot-plugin/*.new" \
  -x "chatbot-plugin/includes/*.new"
```

**IMPORTANT**: The zip should only contain the plugin files, NOT the entire WordPress repository.

## Development Reminder

- Don't try to commit yourself, Commit just when I ask you!
- When modifying AIPass integration, test complete OAuth flow
- Always check WordPress debug.log for integration issues
- When creating a zip file for the plugin, always include the version number in the filename (e.g., chatbot-plugin-1.3.15.zip instead of chatbot-plugin.zip)
