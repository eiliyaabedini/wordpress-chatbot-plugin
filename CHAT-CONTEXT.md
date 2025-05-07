# Chat Context for Chatbot Plugin Development

## Current Development Status
We have created a WordPress plugin that provides chat functionality between website visitors and administrators. The first version of the plugin has been completed with basic functionality.

## Codebase Information

### Important Files and Their Purposes
- **chatbot-plugin.php**: Main plugin file with initialization code
- **class-chatbot-db.php**: Database operations for conversations and messages
- **class-chatbot-handler.php**: Handles AJAX requests and message processing
- **class-chatbot-admin.php**: Admin interface and conversation management
- **chatbot-template.php**: Frontend template for the chat widget

### Frontend Flow
1. User visits page with `[chatbot]` shortcode
2. User enters name to start conversation (stored in database)
3. Messages sent by user are stored in database
4. Auto-responses are generated for messages
5. Polling is used to check for new messages

### Admin Flow
1. Admin sees list of conversations
2. Admin can view individual conversations
3. Admin can reply to messages in conversations
4. Admin interface polls for new messages from users

## Development Environment
- **Environment**: DDEV WordPress setup
- **URL**: https://chatbot-plugin-v2.ddev.site
- **WordPress Admin**: Username: admin, Password: admin
- **Database**: Two custom tables (conversations and messages)

## Last Development Tasks
- Created admin interface for viewing conversations
- Added ability for admins to reply to messages
- Implemented real-time updates via polling
- Styled admin interface for conversation management

## Planned Next Steps
1. Test the plugin with the Playwright MCP to verify frontend functionality
2. Test admin interface for managing conversations
3. Consider future enhancements like:
   - Email notifications
   - Admin assignments
   - Chat analytics
   - More styling options

## Technical Notes
- Messages are stored with sender_type ('user' or 'admin')
- Frontend uses localStorage to remember conversation ID
- Admin interface updates every 10 seconds to show new messages
- Auto-responses are currently simple pattern matching in generate_response() method