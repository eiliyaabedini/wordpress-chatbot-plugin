# WordPress Chatbot Plugin with OpenAI Integration

A WordPress plugin that adds an interactive chatbot with OpenAI integration to your website.

## Features

- Easy setup with shortcode integration
- OpenAI API integration with latest models (GPT-4o, GPT-4o Mini, GPT-4.1 Mini, etc.)
- Customizable system prompts to control AI behavior
- Dark and light theme options
- Typing indicators for better user experience
- Secure API key management
- Conversation history support

## Installation

1. Download the latest release zip file from the [Releases](https://github.com/eiliyaabedini/wordpress-chatbot-plugin/releases) page
2. Log in to your WordPress admin dashboard
3. Navigate to Plugins > Add New
4. Click the "Upload Plugin" button at the top of the page
5. Choose the downloaded zip file and click "Install Now"
6. After installation, click "Activate Plugin"

## Configuration

1. After activation, go to Settings > Chatbot Plugin
2. Enter your OpenAI API key (Get one from [OpenAI Platform](https://platform.openai.com/))
3. Select your preferred model (GPT-3.5 Turbo is the default)
4. Customize the system prompt if desired
5. Save changes

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