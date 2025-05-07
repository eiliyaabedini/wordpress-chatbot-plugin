=== Chatbot Plugin ===
Contributors: yourname
Tags: chatbot, ai, chat, support
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin for integrating chatbot functionality into your website.

== Description ==

Chatbot Plugin allows you to easily add an interactive chatbot to your WordPress website. The chatbot can be customized to match your site's design and configured to provide helpful responses to your visitors.

**Features:**

* Easy installation and setup
* OpenAI integration for intelligent AI-powered responses
* Customizable appearance with light and dark themes
* Responsive design that works on all devices
* Shortcode integration for easy placement
* AJAX-powered for smooth user experience
* Typing indicators for a natural conversation feel
* Conversation history storage

== Installation ==

1. Upload the `chatbot-plugin` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Place the `[chatbot]` shortcode in your posts or pages

== Frequently Asked Questions ==

= How do I add the chatbot to my website? =

Simply add the `[chatbot]` shortcode to any post or page where you want the chatbot to appear.

= Can I customize the appearance of the chatbot? =

Yes, you can use the theme attribute in the shortcode: `[chatbot theme="dark"]` or `[chatbot theme="light"]`.

= How do I set up the OpenAI integration? =

1. Go to the Chatbot Settings page in your WordPress admin area
2. Click on the "OpenAI Integration" tab
3. Enter your OpenAI API key
4. Configure the model and other settings
5. Click "Save Changes"
6. You can use the "Test Connection" button to verify your API key works

= What OpenAI models are supported? =

The plugin supports GPT-3.5 Turbo, GPT-4, and GPT-4o models. GPT-3.5 Turbo is recommended for most use cases as it's cost-effective and fast.

= Do I need an OpenAI account? =

Yes, you need to create an account at [openai.com](https://openai.com) and obtain an API key to use the AI capabilities of the plugin. Without an API key, the chatbot will fall back to basic predefined responses.

== Screenshots ==

1. Chatbot in light theme
2. Chatbot in dark theme

== Changelog ==

= 1.1.0 =
* Added OpenAI integration for AI-powered responses
* Added typing indicators for better user experience
* Added settings page with OpenAI configuration options
* Added test connection functionality for OpenAI API
* Added support for custom welcome messages
* Added temperature and max tokens customization
* Added system prompt with site context

= 1.0.0 =
* Initial release