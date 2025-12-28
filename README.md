# AI Chat Bot - WordPress Plugin

A powerful WordPress plugin that adds an AI-powered interactive chatbot to your website using AIPass.

## Features

- Easy setup with shortcode integration
- **AIPass Integration**: Access 161+ AI models (OpenAI GPT-4, O-series, Google Gemini, and more)
- No API keys needed - just connect with AIPass
- Customizable system prompts to control AI behavior
- Dark and light theme options
- Typing indicators for better user experience
- Conversation history and analytics
- Multi-platform support (Web, Telegram, WhatsApp)

## Installation

1. Download the latest release zip file from the [Releases](https://github.com/eiliyaabedini/wordpress-chatbot-plugin/releases) page
2. Log in to your WordPress admin dashboard
3. Navigate to Plugins > Add New
4. Click the "Upload Plugin" button at the top of the page
5. Choose the downloaded zip file and click "Install Now"
6. After installation, click "Activate Plugin"

## Configuration

1. After activation, go to Chat Bots → Settings → AI tab
2. Click "Connect with AIPass" button
3. Login or create an AIPass account at [aipass.one](https://aipass.one)
4. Select your preferred AI model from the dropdown
5. Customize the system prompt if desired
6. Save changes

## Usage

Add the chatbot to any page or post using the shortcode:

```
[chatbot]
```

For a dark theme:

```
[chatbot theme="dark"]
```

## Customization

### CSS Styling

You can add custom CSS to your theme to modify the appearance of the chatbot.

### System Prompt

The system prompt defines the behavior and personality of the AI chatbot. Customize it in the plugin settings to:
- Define the chatbot's persona
- Set specific knowledge domains
- Control response style and tone
- Add conversation guardrails

## Development

This project uses DDEV for local WordPress development.

### Setup Development Environment

1. Clone the repository
   ```
   git clone https://github.com/eiliyaabedini/wordpress-chatbot-plugin.git
   ```

2. Start DDEV
   ```
   ddev start
   ```

3. Open WordPress in browser
   ```
   ddev launch
   ```

### Key Commands

- `ddev start`: Start the development environment
- `ddev stop`: Stop the development environment
- `ddev ssh`: SSH into the web container
- `ddev wp`: Run WP-CLI commands (e.g., `ddev wp plugin list`)
- `ddev launch`: Open the WordPress site in your browser
- `ddev describe`: Show project details and URLs

### Creating a Deployable Zip

Either:
1. Download the latest release from GitHub, or
2. Use WP-CLI to create a zip file:
   ```
   ddev exec "cd wp-content/plugins && zip -r chatbot-plugin.zip chatbot-plugin"
   ```

## Support

For issues, feature requests, or contributions, please use the [GitHub issue tracker](https://github.com/eiliyaabedini/wordpress-chatbot-plugin/issues).

## License

This project is licensed under the GPL v2 or later.