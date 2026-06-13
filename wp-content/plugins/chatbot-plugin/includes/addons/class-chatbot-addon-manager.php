<?php
/**
 * Chatbot Native Addon Manager
 *
 * Handles discovery, registration, global settings, REST API endpoints,
 * and execution of native addons.
 *
 * @package Chatbot_Plugin
 * @since 1.9.0
 */

if (!defined('WPINC')) {
    die;
}

class Chatbot_Addon_Manager {

    private static $instance = null;
    private $addons = array();
    private $custom_addons_dir;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        // Define directory for custom addons (inside uploads so they persist updates)
        $upload_dir = wp_upload_dir();
        $this->custom_addons_dir = $upload_dir['basedir'] . '/chatbot-addons/';

        // Initialize directories
        $this->ensure_addons_directory();

        // Load all addons
        $this->load_addons();

        // Register REST API endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /**
     * Ensure the custom addons directory exists in uploads
     */
    private function ensure_addons_directory() {
        if (!file_exists($this->custom_addons_dir)) {
            wp_mkdir_p($this->custom_addons_dir);
        }

        // Prevent directory listing and direct browser execution. WordPress loads
        // addon files with include_once; browsers should not execute them.
        file_put_contents($this->custom_addons_dir . 'index.php', '<?php // Silence is golden');
        file_put_contents($this->custom_addons_dir . '.htaccess', "Require all denied\nDeny from all\n");

        // IIS fallback for hosts that honor web.config instead of .htaccess.
        file_put_contents(
            $this->custom_addons_dir . 'web.config',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
            "<configuration><system.webServer><security><requestFiltering>" .
            "<hiddenSegments><add segment=\"chatbot-addons\" /></hiddenSegments>" .
            "</requestFiltering></security></system.webServer></configuration>\n"
        );
    }

    /**
     * Load all native addon classes
     */
    private function load_addons() {
        // 1. Scan custom uploads addons directory first (so custom overrides take precedence)
        $this->load_addons_from_dir($this->custom_addons_dir);

        // 2. Scan built-in addons directory
        $builtin_dir = CHATBOT_PLUGIN_PATH . 'includes/addons/';
        $this->load_addons_from_dir($builtin_dir);
    }

    /**
     * Load addon classes from a directory
     */
    private function load_addons_from_dir($dir) {
        if (!file_exists($dir) || !is_dir($dir)) {
            return;
        }

        $files = glob($dir . 'class-chatbot-*-addon.php');
        if (empty($files)) {
            return;
        }

        foreach ($files as $file) {
            $filename = basename($file);
            
            // Determine class name
            // class-chatbot-local-calendar-addon.php -> Chatbot_Local_Calendar_Addon
            $class_name = str_replace('.php', '', $filename);
            $class_name = str_replace('class-', '', $class_name);
            $class_name = str_replace('-', ' ', $class_name);
            $class_name = ucwords($class_name);
            $class_name = str_replace(' ', '_', $class_name);

            // Skip if this class has already been declared (e.g. by custom override loaded first)
            if (class_exists($class_name, false)) {
                continue;
            }

            try {
                // Include file to load class
                include_once $file;

                if (class_exists($class_name)) {
                    $addon_instance = new $class_name();
                    if (is_subclass_of($addon_instance, 'Chatbot_Addon')) {
                        $this->addons[$addon_instance->get_id()] = $addon_instance;
                    }
                }
            } catch (Throwable $e) {
                // Log compilation/load failures but do not crash the site
                if (function_exists('chatbot_log')) {
                    chatbot_log('ERROR', 'addon_manager_load', "Failed to load addon file: {$filename}. Error: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Get all registered addons
     */
    public function get_addons() {
        return $this->addons;
    }

    /**
     * Get a specific addon by ID
     */
    public function get_addon($id) {
        return isset($this->addons[$id]) ? $this->addons[$id] : null;
    }

    /**
     * Get global settings for an addon
     */
    public function get_addon_global_settings($addon_id) {
        $settings = get_option("chatbot_addon_settings_{$addon_id}", array());
        return is_array($settings) ? $settings : array();
    }

    /**
     * Save global settings for an addon
     */
    public function save_addon_global_settings($addon_id, array $settings) {
        return update_option("chatbot_addon_settings_{$addon_id}", $settings);
    }

    /**
     * Get the protected directory where custom addon files are stored.
     */
    public function get_custom_addons_dir() {
        return $this->custom_addons_dir;
    }

    /**
     * Delete an addon file from the uploads directory
     */
    public function delete_addon($id) {
        $addon = $this->get_addon($id);
        if (!$addon) {
            return false;
        }

        // Custom addons are stored in custom addons dir
        $file = $this->custom_addons_dir . 'class-chatbot-' . $id . '-addon.php';
        if (file_exists($file)) {
            unlink($file);
            unset($this->addons[$id]);
            delete_option("chatbot_addon_settings_{$id}");
            return true;
        }

        return false;
    }

    /**
     * Get active addons for a specific chatbot configuration
     *
     * @param int $chatbot_id The chatbot configuration ID
     * @return Chatbot_Addon[] Array of active addons
     */
    public function get_active_addons_for_chatbot($chatbot_id) {
        $db = Chatbot_DB::get_instance();
        $config = $db->get_configuration($chatbot_id);

        if (!$config) {
            return array();
        }

        $addon_settings_json = isset($config->addon_settings) ? $config->addon_settings : '';
        $addon_status = !empty($addon_settings_json) ? json_decode($addon_settings_json, true) : array();

        $active_addons = array();
        foreach ($this->addons as $id => $addon) {
            // Check if globally active first
            if (!$this->is_addon_globally_active($id)) {
                continue;
            }

            $is_enabled = isset($addon_status[$id]['enabled']) ? (bool) $addon_status[$id]['enabled'] : false;
            if ($is_enabled) {
                // Fetch global configurations for this addon and set it
                $global_settings = $this->get_addon_global_settings($id);
                $addon->set_settings($global_settings);
                $active_addons[$id] = $addon;
            }
        }

        return $active_addons;
    }
    /**
     * Validate addon code and extract addon ID/slug from the class name.
     *
     * @param string $code The PHP code to validate
     * @param string $error_message Output parameter for error message if validation fails
     * @param string $addon_id Output parameter for the extracted addon ID
     * @return bool True if validation succeeds, false otherwise
     */
    public function validate_addon_code($code, &$error_message, &$addon_id) {
        if (!$this->has_wordpress_guard($code)) {
            $error_message = __("Security validation failed. Addon code must start with a WordPress guard: if (!defined('WPINC')) { die; }", 'chatbot-plugin');
            return false;
        }

        // Parse class name: Chatbot_[Name]_Addon
        if (!preg_match('/\bclass\s+(Chatbot_([A-Za-z0-9_]+)_Addon)\b/i', $code, $matches)) {
            $error_message = __("Class validation failed. The code must define a class named like 'Chatbot_[Name]_Addon' (e.g. Chatbot_My_Custom_Addon).", 'chatbot-plugin');
            return false;
        }

        $class_name = $matches[1];
        $inner_name = $matches[2];
        $addon_id = strtolower(str_replace('_', '-', $inner_name));

        // Validate slug/ID format
        if (!preg_match('/^[a-z0-9-_]+$/', $addon_id)) {
            $error_message = __("Invalid addon ID generated from class name. The class name must only contain alphanumeric characters and underscores.", 'chatbot-plugin');
            return false;
        }

        // Validate inheritance from Chatbot_Addon
        if (!preg_match('/\bclass\s+' . preg_quote($class_name, '/') . '\s+([^\{]*\s+)?extends\s+Chatbot_Addon\b/i', $code)) {
            $error_message = sprintf(__("Class validation failed. The class '%s' must extend the 'Chatbot_Addon' base class.", 'chatbot-plugin'), $class_name);
            return false;
        }

        // Perform basic syntax check: write to temporary file
        $temp_filename = $this->custom_addons_dir . 'temp-check-' . time() . '-' . rand(1000, 9999) . '.php';
        if (file_put_contents($temp_filename, $code) === false) {
            $error_message = __("Failed to write to temporary file for syntax check.", 'chatbot-plugin');
            return false;
        }

        $is_syntax_valid = false;
        if (function_exists('exec')) {
            $output = array();
            $return_var = 1;
            exec('php -l ' . escapeshellarg($temp_filename), $output, $return_var);
            if ($return_var === 0) {
                $is_syntax_valid = true;
            } else {
                $error_message = implode("\n", $output);
            }
        } else {
            $error_message = __("PHP syntax validation is unavailable because exec() is disabled on this server. Refusing to save executable addon code without lint validation.", 'chatbot-plugin');
        }

        // Delete temp file immediately
        if (file_exists($temp_filename)) {
            unlink($temp_filename);
        }

        return $is_syntax_valid;
    }

    /**
     * Check that addon code starts with a guard that blocks direct web execution.
     */
    private function has_wordpress_guard($code) {
        return (bool) preg_match(
            '/^\s*<\?php\s*(?:\/\*.*?\*\/\s*|\/\/[^\r\n]*(?:\r?\n)\s*)*if\s*\(\s*!\s*defined\s*\(\s*[\'"]WPINC[\'"]\s*\)\s*\)\s*\{\s*(?:die|exit)\s*\(?\s*\)?\s*;\s*\}/s',
            $code
        );
    }

    /**
     * Register REST API routes for external AI agents
     */
    public function register_rest_routes() {
        register_rest_route('chatbot-plugin/v1', '/addons/update', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_update_addon'),
            'permission_callback' => '__return_true', // Authentication done via API key
        ));
    }

    /**
     * REST Callback: Update or upload an addon
     */
    public function rest_update_addon($request) {
        // Authenticate via API key
        $api_key = $request->get_header('X-Chatbot-Addon-API-Key');
        if (empty($api_key)) {
            $api_key = $request->get_param('api_key');
        }

        $stored_key = get_option('chatbot_addons_api_key');
        if (empty($stored_key) || $api_key !== $stored_key) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Unauthorized: Invalid API Key'), 401);
        }

        // Get params
        $addon_id = $request->get_param('addon_id');
        $code = $request->get_param('code');

        if (empty($code)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Missing parameter: code is required'), 400);
        }

        // Validate and extract using centralized validation helper
        $error_message = '';
        $extracted_addon_id = '';
        if (!$this->validate_addon_code($code, $error_message, $extracted_addon_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Validation error: ' . $error_message
            ), 400);
        }

        // Verify that the addon_id parameter, if provided, matches the class name
        if (!empty($addon_id) && $addon_id !== $extracted_addon_id) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => sprintf("Mismatch between class name and addon ID. The class defines addon ID '%s', but the request specified '%s'.", $extracted_addon_id, $addon_id)
            ), 400);
        }

        $addon_id = $extracted_addon_id;

        // Save file securely to the uploads folder
        $final_filename = $this->custom_addons_dir . 'class-chatbot-' . $addon_id . '-addon.php';
        $write_result = file_put_contents($final_filename, $code);

        if ($write_result === false) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Failed to write addon file to disk. Check uploads directory permissions.'), 500);
        }

        // Log the successful upload/update
        if (function_exists('chatbot_log')) {
            chatbot_log('INFO', 'addon_rest_update', "Addon '{$addon_id}' updated successfully via API");
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => "Addon '{$addon_id}' has been uploaded and validated successfully!"
        ), 200);
    }

    /**
     * Check if an addon is globally active/enabled.
     */
    public function is_addon_globally_active($id) {
        $status = get_option('chatbot_addons_status', array());
        // By default, addons are active (unless explicitly set to 'inactive')
        return !isset($status[$id]) || $status[$id] !== 'inactive';
    }

    /**
     * Set global status for an addon.
     */
    public function set_addon_globally_active($id, $active) {
        $status = get_option('chatbot_addons_status', array());
        $status[$id] = $active ? 'active' : 'inactive';
        return update_option('chatbot_addons_status', $status);
    }
}

/**
 * Initialize the addon manager
 */
function chatbot_init_addon_manager() {
    return Chatbot_Addon_Manager::get_instance();
}
add_action('init', 'chatbot_init_addon_manager', 1);
