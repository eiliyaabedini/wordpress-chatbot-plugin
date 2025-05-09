# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This project is a WordPress plugin called "Chatbot Plugin" that adds an interactive chatbot to WordPress websites. The plugin provides a simple interface for users to interact with a chatbot via a shortcode.

## Development Environment

This project uses DDEV for local WordPress development. Key commands:

- `ddev start`: Start the development environment
- `ddev stop`: Stop the development environment
- `ddev ssh`: SSH into the web container
- `ddev wp`: Run WP-CLI commands (e.g., `ddev wp plugin list`)
- `ddev launch`: Open the WordPress site in your browser
- `ddev describe`: Show project details and URLs

## Plugin Structure

- `/chatbot-plugin.php`: Main plugin file
- `/includes/class-chatbot-handler.php`: Handles chatbot message processing
- `/assets/css/chatbot.css`: Stylesheets for the chatbot
- `/assets/js/chatbot.js`: JavaScript for the chatbot functionality
- `/templates/chatbot-template.php`: Template for the chatbot interface
- `/languages/`: Directory for translation files

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

To modify how the chatbot responds to messages, edit the `generate_response()` method in `/includes/class-chatbot-handler.php`.

### Styling the Chatbot

To modify the appearance of the chatbot, edit the CSS in `/assets/css/chatbot.css`.

### Adding New Features

1. Implement any new functionality in appropriate PHP classes
2. Update JavaScript in `/assets/js/chatbot.js` for front-end functionality
3. Update templates as needed
4. Ensure all user-facing strings are internationalized using WordPress i18n functions

### Testing

Use the WP-CLI tool via DDEV to test:
```
ddev wp plugin activate chatbot-plugin
ddev wp plugin deactivate chatbot-plugin
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

## Development Reminder

- Don't try to commit yourself, Commit just when I ask you!