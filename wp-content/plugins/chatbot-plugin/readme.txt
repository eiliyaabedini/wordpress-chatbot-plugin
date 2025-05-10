=== AI Chat Bot ===
Contributors: eiliyaabedini
Tags: chatbot, ai, chat, support, openai, gpt, assistant, artificial intelligence
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A powerful AI chatbot plugin for WordPress that enhances customer engagement with OpenAI integration.

== Description ==

AI Chat Bot allows you to easily add an intelligent, AI-powered chatbot to your WordPress website. The chatbot can be customized to match your site's design and configured to provide helpful responses to your visitors using OpenAI's advanced language models.

**Features:**

* Easy installation and setup
* OpenAI integration for intelligent AI-powered responses
* Multiple AI models support (GPT-3.5, GPT-4, GPT-4o)
* Customizable appearance with light and dark themes
* Responsive design that works on all devices
* Shortcode integration for easy placement
* AJAX-powered for smooth user experience
* Typing indicators for a natural conversation feel
* Conversation history storage and management
* Custom knowledge base configuration
* Persona customization to match your brand voice

== Installation ==

1. Upload the `ai-chat-bot` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure your OpenAI API key in the plugin settings
4. Place the `[chatbot]` shortcode in your posts or pages
5. Optional: Customize the appearance and behavior settings

== Frequently Asked Questions ==

= How do I add the chatbot to my website? =

Simply add the `[chatbot]` shortcode to any post or page where you want the chatbot to appear.

= Can I customize the appearance of the chatbot? =

Yes, you can use the theme attribute in the shortcode: `[chatbot theme="dark"]` or `[chatbot theme="light"]`. You can also customize colors and other settings in the plugin admin panel.

= How do I set up the OpenAI integration? =

1. Go to the AI Chat Bot Settings page in your WordPress admin area
2. Click on the "OpenAI Integration" tab
3. Enter your OpenAI API key
4. Configure the model and other settings
5. Click "Save Changes"
6. You can use the "Test Connection" button to verify your API key works

= What OpenAI models are supported? =

The plugin supports GPT-3.5 Turbo, GPT-4, and GPT-4o models. GPT-3.5 Turbo is recommended for most use cases as it's cost-effective and fast.

= Do I need an OpenAI account? =

Yes, you need to create an account at [openai.com](https://openai.com) and obtain an API key to use the AI capabilities of the plugin. Without an API key, the chatbot will fall back to basic predefined responses.

= Can I train the chatbot with specific information about my business? =

Yes, the plugin includes a knowledge base feature where you can provide specific information that the AI should use when answering questions. This allows you to customize the chatbot to better represent your business, products, or services.

= Does the plugin support multiple languages? =

Yes, the AI models can respond in multiple languages. The plugin interface is prepared for translation, and the AI will automatically detect and respond in the language used by the visitor.

== Screenshots ==

1. Chatbot in light theme
2. Chatbot in dark theme
3. Admin settings panel
4. OpenAI integration configuration
5. Conversation management dashboard

== Changelog ==

= 1.0.0 =
* Initial release
* OpenAI integration for AI-powered responses
* Multiple AI models support
* Customizable themes and appearance
* Typing indicators for better user experience
* Settings page with OpenAI configuration options
* Test connection functionality for OpenAI API
* Support for custom welcome messages
* Temperature and max tokens customization
* System prompt with site context
* Conversation history storage and management
* Knowledge base customization

== Upgrade Notice ==

= 1.0.0 =
Initial release of the AI Chat Bot plugin. Includes OpenAI integration, conversation management, and customizable appearance.

== Developer ==
Developed by [Eiliya Abedini](https://iact.ir). For support or feature requests, please visit the [GitHub repository](https://github.com/eiliyaabedini/wordpress-chatbot-plugin).