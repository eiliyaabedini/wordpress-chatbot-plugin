<?php
/**
 * Chatbot Local Booking Calendar Addon
 *
 * Implements a native calendar booking system using WordPress Custom Post Types.
 *
 * @package Chatbot_Plugin
 * @since 1.9.0
 */

if (!defined('WPINC')) {
    die;
}

class Chatbot_Local_Calendar_Addon extends Chatbot_Addon {

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'local-calendar';
        $this->name = 'Local Booking Calendar';
        $this->description = 'Provides local appointment scheduling and event listing natively within WordPress.';
        $this->icon = 'dashicons-calendar-alt';
        
        // Register Custom Post Type on init
        add_action('init', array($this, 'register_post_type'));
    }

    /**
     * Register the Custom Post Type for bookings
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => _x('Chat Bookings', 'Post Type General Name', 'chatbot-plugin'),
            'singular_name'         => _x('Chat Booking', 'Post Type Singular Name', 'chatbot-plugin'),
            'menu_name'             => __('Chat Bookings', 'chatbot-plugin'),
            'name_admin_bar'        => __('Chat Booking', 'chatbot-plugin'),
            'all_items'             => __('All Bookings', 'chatbot-plugin'),
            'add_new_item'          => __('Add New Booking', 'chatbot-plugin'),
            'add_new'               => __('Add New', 'chatbot-plugin'),
            'new_item'              => __('New Booking', 'chatbot-plugin'),
            'edit_item'             => __('Edit Booking', 'chatbot-plugin'),
            'update_item'           => __('Update Booking', 'chatbot-plugin'),
            'view_item'             => __('View Booking', 'chatbot-plugin'),
            'search_items'          => __('Search Bookings', 'chatbot-plugin'),
            'not_found'             => __('No bookings found', 'chatbot-plugin'),
            'not_found_in_trash'    => __('No bookings found in Trash', 'chatbot-plugin'),
        );
        
        $args = array(
            'label'                 => __('Chat Booking', 'chatbot-plugin'),
            'description'           => __('Bookings created by AI Chatbot', 'chatbot-plugin'),
            'labels'                => $labels,
            'supports'              => array('title', 'custom-fields'),
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            'show_in_menu'          => 'chatbot-plugin', // Show as submenu under Chatbot main menu
            'menu_position'         => 20,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'post',
        );
        
        register_post_type('chatbot_booking', $args);
    }

    /**
     * Define tool schemas for AI model function calling
     */
    public function get_tool_definitions() {
        return array(
            array(
                'type' => 'function',
                'function' => array(
                    'name' => 'list_bookings',
                    'description' => 'List all bookings for a specific date to check availability. Always list bookings for a date before scheduling a new booking to prevent conflicts.',
                    'parameters' => array(
                        'type' => 'object',
                        'properties' => array(
                            'date' => array(
                                'type' => 'string',
                                'description' => 'The date to check in YYYY-MM-DD format (e.g., "2026-06-15").'
                            )
                        ),
                        'required' => array('date')
                    )
                )
            ),
            array(
                'type' => 'function',
                'function' => array(
                    'name' => 'create_booking',
                    'description' => 'Schedule a new appointment/booking slot. First check availability using list_bookings to ensure the slot is free.',
                    'parameters' => array(
                        'type' => 'object',
                        'properties' => array(
                            'title' => array(
                                'type' => 'string',
                                'description' => 'The name or purpose of the appointment (e.g., "Consultation with Alice").'
                            ),
                            'date' => array(
                                'type' => 'string',
                                'description' => 'The date of the appointment in YYYY-MM-DD format.'
                            ),
                            'start_time' => array(
                                'type' => 'string',
                                'description' => 'The start time of the appointment in HH:MM format (24-hour style, e.g., "14:30").'
                            ),
                            'duration' => array(
                                'type' => 'integer',
                                'description' => 'The duration of the appointment in minutes. Default is 30.'
                            ),
                            'email' => array(
                                'type' => 'string',
                                'description' => 'The user\'s email address for confirmation.'
                            ),
                            'description' => array(
                                'type' => 'string',
                                'description' => 'Optional notes or details about the meeting.'
                            )
                        ),
                        'required' => array('title', 'date', 'start_time', 'email')
                    )
                )
            )
        );
    }

    /**
     * Execute tool calls triggered by the AI
     */
    public function execute_tool($tool_name, array $args, array $context = array()) {
        if ($tool_name === 'list_bookings') {
            $date = sanitize_text_field($args['date'] ?? '');
            if (empty($date)) {
                return new WP_Error('invalid_param', 'Missing date parameter');
            }

            // Query posts of type chatbot_booking with meta_key _booking_date = $date
            $bookings = get_posts(array(
                'post_type'      => 'chatbot_booking',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    array(
                        'key'     => '_booking_date',
                        'value'   => $date,
                        'compare' => '='
                    )
                )
            ));

            $result = array();
            foreach ($bookings as $b) {
                $result[] = array(
                    'id'          => $b->ID,
                    'title'       => $b->post_title,
                    'start_time'  => get_post_meta($b->ID, '_booking_time', true),
                    'duration'    => (int) get_post_meta($b->ID, '_booking_duration', true),
                    'email'       => get_post_meta($b->ID, '_booking_email', true),
                    'description' => get_post_meta($b->ID, '_booking_description', true),
                );
            }

            // Sort by start_time
            usort($result, function($a, $b) {
                return strcmp($a['start_time'], $b['start_time']);
            });

            return array(
                'date'     => $date,
                'bookings' => $result
            );
        }

        if ($tool_name === 'create_booking') {
            $title = sanitize_text_field($args['title'] ?? '');
            $date = sanitize_text_field($args['date'] ?? '');
            $start_time = sanitize_text_field($args['start_time'] ?? '');
            $duration = intval($args['duration'] ?? 30);
            $email = sanitize_email($args['email'] ?? '');
            $description = sanitize_textarea_field($args['description'] ?? '');

            if (empty($title) || empty($date) || empty($start_time) || empty($email)) {
                return new WP_Error('invalid_param', 'Missing required parameters. Title, date, start_time, and email are required.');
            }

            // Simple conflict checker: check if there's an overlapping booking
            $new_start_minutes = $this->time_to_minutes($start_time);
            if ($new_start_minutes === false) {
                return new WP_Error('invalid_time', 'Invalid start time format. Use HH:MM in 24h format.');
            }
            $new_end_minutes = $new_start_minutes + $duration;

            // Fetch existing bookings on that date
            $existing_bookings = get_posts(array(
                'post_type'      => 'chatbot_booking',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    array(
                        'key'     => '_booking_date',
                        'value'   => $date,
                        'compare' => '='
                    )
                )
            ));

            foreach ($existing_bookings as $b) {
                $b_time = get_post_meta($b->ID, '_booking_time', true);
                $b_duration = intval(get_post_meta($b->ID, '_booking_duration', true));
                
                $b_start_minutes = $this->time_to_minutes($b_time);
                if ($b_start_minutes === false) {
                    continue;
                }
                $b_end_minutes = $b_start_minutes + $b_duration;

                // Overlap condition: max(start1, start2) < min(end1, end2)
                if (max($new_start_minutes, $b_start_minutes) < min($new_end_minutes, $b_end_minutes)) {
                    return array(
                        'success' => false,
                        'message' => "Overlapping booking detected. The slot at {$start_time} (duration {$duration}m) conflicts with an existing booking: '{$b->post_title}' at {$b_time} ({$b_duration}m)."
                    );
                }
            }

            // Create new booking post
            $post_id = wp_insert_post(array(
                'post_title'  => $title,
                'post_status' => 'publish',
                'post_type'   => 'chatbot_booking'
            ));

            if (is_wp_error($post_id)) {
                return $post_id;
            }

            // Save metadata
            update_post_meta($post_id, '_booking_date', $date);
            update_post_meta($post_id, '_booking_time', $start_time);
            update_post_meta($post_id, '_booking_duration', $duration);
            update_post_meta($post_id, '_booking_email', $email);
            update_post_meta($post_id, '_booking_description', $description);
            if (isset($context['conversation_id'])) {
                update_post_meta($post_id, '_conversation_id', $context['conversation_id']);
            }

            return array(
                'success' => true,
                'message' => "Booking successfully created!",
                'booking' => array(
                    'id'         => $post_id,
                    'title'      => $title,
                    'date'       => $date,
                    'start_time' => $start_time,
                    'duration'   => $duration,
                    'email'      => $email
                )
            );
        }

        return new WP_Error('invalid_tool', 'Unknown tool: ' . $tool_name);
    }

    /**
     * Render global configuration settings
     */
    public function render_settings_fields($chatbot_id) {
        $notification_email = $this->settings['notification_email'] ?? get_option('admin_email');
        $working_hours_start = $this->settings['working_hours_start'] ?? '09:00';
        $working_hours_end = $this->settings['working_hours_end'] ?? '17:00';
        ?>
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                <?php _e('Notification Email Address', 'chatbot-plugin'); ?>
            </label>
            <input type="email" name="chatbot_addon_local_calendar[notification_email]" class="regular-text" value="<?php echo esc_attr($notification_email); ?>">
            <p class="description"><?php _e('Bookings confirmation will be sent here.', 'chatbot-plugin'); ?></p>
        </div>
        <div style="display: flex; gap: 15px; margin-bottom: 15px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                    <?php _e('Working Hours Start', 'chatbot-plugin'); ?>
                </label>
                <input type="time" name="chatbot_addon_local_calendar[working_hours_start]" value="<?php echo esc_attr($working_hours_start); ?>">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: 500;">
                    <?php _e('Working Hours End', 'chatbot-plugin'); ?>
                </label>
                <input type="time" name="chatbot_addon_local_calendar[working_hours_end]" value="<?php echo esc_attr($working_hours_end); ?>">
            </div>
        </div>
        <?php
    }

    /**
     * Sanitize settings inputs
     */
    public function sanitize_settings(array $input) {
        return array(
            'notification_email'  => sanitize_email($input['notification_email'] ?? ''),
            'working_hours_start' => sanitize_text_field($input['working_hours_start'] ?? '09:00'),
            'working_hours_end'   => sanitize_text_field($input['working_hours_end'] ?? '17:00'),
        );
    }

    /**
     * Convert HH:MM time string to total minutes
     */
    private function time_to_minutes($time) {
        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return false;
        }
        return intval($parts[0]) * 60 + intval($parts[1]);
    }
}
