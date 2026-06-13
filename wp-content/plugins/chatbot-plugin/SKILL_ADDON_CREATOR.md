# AI Agent Skill: Creating & Updating Chatbot Addons

This guide explains how to deploy custom capabilities to this WordPress chatbot as local PHP addons.

## 1. Class Structure Requirements
Your addon must be a valid PHP class structure that:
1. Extends the base class `Chatbot_Addon`.
2. Matches the filename class style. If the addon ID is `my-weather`, the file name must be `class-chatbot-my-weather-addon.php` and the class name must be `Chatbot_My_Weather_Addon`.

### Example Code Blueprint:
```php
<?php
class Chatbot_My_Weather_Addon extends Chatbot_Addon {
    public function __construct() {
        $this->id = 'my-weather';
        $this->name = 'Local Weather Report';
        $this->description = 'Queries real-time weather forecasts for a city.';
        $this->icon = 'dashicons-cloud';
    }

    public function get_tool_definitions() {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_current_weather',
                    'description' => 'Retrieves the temperature and sky conditions of a city.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'city' => [
                                'type' => 'string',
                                'description' => 'The city to search (e.g., Paris, New York).'
                            ]
                        ],
                        'required' => ['city']
                    ]
                ]
            ]
        ];
    }

    public function execute_tool($tool_name, array $args, array $context = []) {
        if ($tool_name === 'get_current_weather') {
            $city = sanitize_text_field($args['city'] ?? '');
            // perform your custom logic, database query, or remote curl requests here
            return [
                'city' => $city,
                'temperature' => '22°C',
                'condition' => 'Partly Cloudy'
            ];
        }
        return new WP_Error('unknown_tool', 'Weather tool not found.');
    }
}
```

## 2. Deploy via API
Post the payload containing the addon settings and code to the REST target endpoint.

### Connection Parameters:
* **Target URL**: `{site_url}/wp-json/chatbot-plugin/v1/addons/update`
* **HTTP Header**: `X-Chatbot-Addon-API-Key: {api_key}`

### JSON Payload Schema:
```json
{
    "addon_id": "my-weather",
    "code": "<?php\nclass Chatbot_My_Weather_Addon extends Chatbot_Addon { ... }"
}
```

### Execution Safeguards:
The server will validate the PHP code using class compilation tests before finalizing the deploy. If there are syntax errors, the upload will fail with a 400 error description.
