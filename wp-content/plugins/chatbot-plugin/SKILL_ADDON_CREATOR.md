# AI Agent Skill: Creating & Updating Chatbot Addons

This guide explains how to deploy custom capabilities to this WordPress chatbot as local PHP addons.

## 1. Class Structure Requirements
Your addon must be a valid PHP class structure that:
1. Extends the base class `Chatbot_Addon`.
2. Matches the filename class style. If the addon ID is `my-weather`, the file name must be `class-chatbot-my-weather-addon.php` and the class name must be `Chatbot_My_Weather_Addon`.
3. Starts with the WordPress direct-access guard: `if (!defined('WPINC')) { die; }`.

### Example Code Blueprint:
```php
<?php
if (!defined('WPINC')) {
    die;
}

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
    "code": "<?php\nif (!defined('WPINC')) { die; }\nclass Chatbot_My_Weather_Addon extends Chatbot_Addon { ... }"
}
```

### Execution Safeguards:
The server will validate the PHP code using class compilation tests before finalizing the deploy. Addon files without the direct-access guard or with syntax errors will fail with a 400 error description. Uploaded addon files are stored in a protected uploads subdirectory and are loaded only by WordPress.

## 3. Read Diagnostic Logs
Use the same API key to inspect redacted plugin diagnostics after uploading or testing an addon.

### Logs Endpoint:
* **Target URL**: `{site_url}/wp-json/chatbot-plugin/v1/agent/logs`
* **HTTP Method**: `GET`
* **HTTP Header**: `X-Chatbot-Addon-API-Key: {api_key}`
* **Optional Query Parameter**: `max_bytes=300000` (maximum 1048576)

### Example:
```bash
curl -H "X-Chatbot-Addon-API-Key: {api_key}" "{site_url}/wp-json/chatbot-plugin/v1/agent/logs?max_bytes=300000"
```

The response contains `diagnostics` and `logs`. Token-like values are redacted before logs are saved.

### Clear Logs:
To clear diagnostic logs after a completed debugging session, send a POST request to `{site_url}/wp-json/chatbot-plugin/v1/agent/logs/clear` with the same `X-Chatbot-Addon-API-Key` header.
