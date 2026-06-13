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
            
            // Write a simple index.php to prevent directory listing
            file_put_contents($this->custom_addons_dir . 'index.php', '<?php // Silence is golden');
            
            // Also write a .htaccess to prevent execution of arbitrary files except PHP classes we check
            file_put_contents($this->custom_addons_dir . '.htaccess', "Deny from all\n<Files ~ \"^class-chatbot-.*-addon\\.php$\">\nOrder Allow,Deny\nAllow from all\n</Files>");
        }
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

        if (empty($addon_id) || empty($code)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Missing parameter: addon_id and code are required'), 400);
        }

        // Validate addon_id format (lowercase alphanumeric and dash/underscore)
        if (!preg_match('/^[a-z0-9-_]+$/', $addon_id)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid addon_id format. Lowercase alphanumeric and hyphens/underscores only.'), 400);
        }

        // Validate and format class name
        $class_name = str_replace('-', ' ', $addon_id);
        $class_name = ucwords($class_name);
        $class_name = str_replace(' ', '_', $class_name);
        $class_name = 'Chatbot_' . $class_name . '_Addon';

        // Perform basic syntax check: write to temporary file
        $temp_filename = $this->custom_addons_dir . 'temp-check-' . time() . '.php';
        file_put_contents($temp_filename, $code);

        $is_syntax_valid = false;
        $error_message = '';

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
            // Fallback: only include to check syntax if class does not exist yet to prevent fatal redeclaration error
            if (class_exists($class_name)) {
                $is_syntax_valid = true;
            } else {
                try {
                    include $temp_filename;
                    $is_syntax_valid = true;
                } catch (Throwable $e) {
                    $error_message = $e->getMessage();
                }
            }
        }

        // Delete temp file immediately
        if (file_exists($temp_filename)) {
            unlink($temp_filename);
        }

        if (!$is_syntax_valid) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Compilation error in uploaded PHP code: ' . $error_message
            ), 400);
        }

        // Class existence and base class checks using safe static analysis
        if (!preg_match('/\bclass\s+' . preg_quote($class_name, '/') . '\b/i', $code)) {
            return new WP_REST_Response(array(
                'success' => false, 
                'message' => "Class validation failed. The code must define a class named '{$class_name}'"
            ), 400);
        }

        if (!preg_match('/\bclass\s+' . preg_quote($class_name, '/') . '\s+([^\{]*\s+)?extends\s+Chatbot_Addon\b/i', $code)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => "Class validation failed. The class '{$class_name}' must extend the 'Chatbot_Addon' base class"
            ), 400);
        }

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
}

/**
 * Initialize the addon manager
 */
function chatbot_init_addon_manager() {
    return Chatbot_Addon_Manager::get_instance();
}
add_action('init', 'chatbot_init_addon_manager', 1);
