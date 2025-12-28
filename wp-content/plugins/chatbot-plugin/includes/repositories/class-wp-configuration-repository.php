<?php
/**
 * WordPress Configuration Repository Implementation
 *
 * Implements chatbot configuration persistence using WordPress database.
 *
 * @package Chatbot_Plugin
 * @since 1.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Class Chatbot_WP_Configuration_Repository
 *
 * WordPress-specific implementation of the Configuration Repository.
 */
class Chatbot_WP_Configuration_Repository implements Chatbot_Configuration_Repository {

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Configurations table name.
     *
     * @var string
     */
    private $table;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'chatbot_configurations';
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $data) {
        $insert_data = array(
            'name' => sanitize_text_field($data['name'] ?? 'Default Chatbot'),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );
        $formats = array('%s', '%s', '%s');

        // Optional fields
        if (isset($data['persona'])) {
            $insert_data['persona'] = sanitize_textarea_field($data['persona']);
            $formats[] = '%s';
        }

        if (isset($data['knowledge'])) {
            $insert_data['knowledge'] = sanitize_textarea_field($data['knowledge']);
            $formats[] = '%s';
        }

        if (isset($data['knowledge_sources'])) {
            $insert_data['knowledge_sources'] = is_string($data['knowledge_sources'])
                ? $data['knowledge_sources']
                : wp_json_encode($data['knowledge_sources']);
            $formats[] = '%s';
        }

        if (isset($data['n8n_settings'])) {
            $insert_data['n8n_settings'] = is_string($data['n8n_settings'])
                ? $data['n8n_settings']
                : wp_json_encode($data['n8n_settings']);
            $formats[] = '%s';
        }

        if (isset($data['telegram_bot_token'])) {
            $insert_data['telegram_bot_token'] = sanitize_text_field($data['telegram_bot_token']);
            $formats[] = '%s';
        }

        // Legacy system_prompt support
        if (isset($data['system_prompt'])) {
            $insert_data['system_prompt'] = sanitize_textarea_field($data['system_prompt']);
            $formats[] = '%s';
        }

        $result = $this->wpdb->insert($this->table, $insert_data, $formats);

        if ($result === false) {
            if (function_exists('chatbot_log')) {
                chatbot_log('ERROR', 'configuration_repository', 'Failed to create configuration', array(
                    'error' => $this->wpdb->last_error
                ));
            }
            return null;
        }

        return $this->wpdb->insert_id;
    }

    /**
     * {@inheritdoc}
     */
    public function find($id) {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        );
        return $this->wpdb->get_row($query);
    }

    /**
     * {@inheritdoc}
     */
    public function find_by_name($name) {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE name = %s",
            $name
        );
        return $this->wpdb->get_row($query);
    }

    /**
     * {@inheritdoc}
     */
    public function update($id, array $data) {
        $update_data = array();
        $formats = array();

        $allowed_fields = array(
            'name' => '%s',
            'persona' => '%s',
            'knowledge' => '%s',
            'system_prompt' => '%s',
            'telegram_bot_token' => '%s',
        );

        foreach ($allowed_fields as $field => $format) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
                $formats[] = $format;
            }
        }

        // Handle JSON fields
        if (isset($data['knowledge_sources'])) {
            $update_data['knowledge_sources'] = is_string($data['knowledge_sources'])
                ? $data['knowledge_sources']
                : wp_json_encode($data['knowledge_sources']);
            $formats[] = '%s';
        }

        if (isset($data['n8n_settings'])) {
            $update_data['n8n_settings'] = is_string($data['n8n_settings'])
                ? $data['n8n_settings']
                : wp_json_encode($data['n8n_settings']);
            $formats[] = '%s';
        }

        if (empty($update_data)) {
            return false;
        }

        // Always update updated_at
        $update_data['updated_at'] = current_time('mysql');
        $formats[] = '%s';

        $result = $this->wpdb->update(
            $this->table,
            $update_data,
            array('id' => $id),
            $formats,
            array('%d')
        );

        return $result !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id) {
        $result = $this->wpdb->delete(
            $this->table,
            array('id' => $id),
            array('%d')
        );
        return $result !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function get_all() {
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY name ASC"
        );
    }

    /**
     * {@inheritdoc}
     */
    public function name_exists($name, $exclude_id = 0) {
        if ($exclude_id > 0) {
            $query = $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE name = %s AND id != %d",
                $name,
                $exclude_id
            );
        } else {
            $query = $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE name = %s",
                $name
            );
        }

        return (int) $this->wpdb->get_var($query) > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function get_default() {
        // Return the first configuration
        $query = "SELECT * FROM {$this->table} ORDER BY id ASC LIMIT 1";
        return $this->wpdb->get_row($query);
    }

    /**
     * {@inheritdoc}
     */
    public function count() {
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }

    /**
     * {@inheritdoc}
     */
    public function get_with_telegram() {
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->table}
             WHERE telegram_bot_token IS NOT NULL
             AND telegram_bot_token != ''
             ORDER BY name ASC"
        );
    }

    /**
     * {@inheritdoc}
     */
    public function get_with_whatsapp() {
        // WhatsApp credentials are stored in options table, not config
        // This returns configs that might have WhatsApp enabled
        // The actual check is done via options
        $configs = $this->get_all();
        $result = array();

        foreach ($configs as $config) {
            $phone_id = get_option('chatbot_whatsapp_phone_number_id_' . $config->id, '');
            if (!empty($phone_id)) {
                $result[] = $config;
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function get_with_n8n() {
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->table}
             WHERE n8n_settings IS NOT NULL
             AND n8n_settings != ''
             AND n8n_settings != '[]'
             AND n8n_settings != '{}'
             ORDER BY name ASC"
        );
    }
}
