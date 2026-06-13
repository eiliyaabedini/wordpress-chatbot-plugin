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
        if (!class_exists('Chatbot_Addon_Manager') || !Chatbot_Addon_Manager::get_instance()->is_addon_globally_active($this->id)) {
            return;
        }
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
            'show_in_menu'          => false, // Hidden from sidebar menu, managed in Addons page
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
     * Register Calendar View submenu under Chatbot main menu
     */
    public function register_calendar_menu() {
        if (!class_exists('Chatbot_Addon_Manager') || !Chatbot_Addon_Manager::get_instance()->is_addon_globally_active($this->id)) {
            return;
        }
        add_submenu_page(
            'chatbot-plugin',
            __('Calendar View', 'chatbot-plugin'),
            __('Calendar View', 'chatbot-plugin'),
            'manage_options',
            'chatbot-booking-calendar',
            array($this, 'render_calendar_page')
        );
    }

    /**
     * Render the interactive bookings calendar dashboard
     */
    public function render_calendar_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'chatbot-plugin'));
        }

        // Fetch all bookings
        $bookings = get_posts(array(
            'post_type'      => 'chatbot_booking',
            'post_status'    => 'any',
            'posts_per_page' => -1,
        ));

        // Group bookings by date
        $bookings_by_date = array();
        foreach ($bookings as $b) {
            $date = get_post_meta($b->ID, '_booking_date', true);
            if (!empty($date)) {
                $bookings_by_date[$date][] = array(
                    'id'          => $b->ID,
                    'title'       => $b->post_title,
                    'start_time'  => get_post_meta($b->ID, '_booking_time', true),
                    'duration'    => (int) get_post_meta($b->ID, '_booking_duration', true),
                    'email'       => get_post_meta($b->ID, '_booking_email', true),
                    'description' => get_post_meta($b->ID, '_booking_description', true),
                    'edit_url'    => get_edit_post_link($b->ID, 'raw'),
                );
            }
        }

        // Sort chronologically by start time on each day
        foreach ($bookings_by_date as $date => &$list) {
            usort($list, function($a, $b) {
                return strcmp($a['start_time'], $b['start_time']);
            });
        }
        unset($list);

        $bookings_json = wp_json_encode($bookings_by_date);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Bookings Calendar Dashboard', 'chatbot-plugin'); ?></h1>
            <hr class="wp-header-end">

            <div class="chatbot-calendar-container">
                <!-- Calendar Card -->
                <div class="chatbot-calendar-card">
                    <div class="calendar-header">
                        <h2 class="calendar-title" id="calendar-title-text">Month Year</h2>
                        <div class="calendar-controls">
                            <button class="calendar-btn calendar-btn-nav" id="btn-prev-month" title="<?php esc_attr_e('Previous Month', 'chatbot-plugin'); ?>">&lsaquo;</button>
                            <button class="calendar-btn" id="btn-today"><?php _e('Today', 'chatbot-plugin'); ?></button>
                            <button class="calendar-btn calendar-btn-nav" id="btn-next-month" title="<?php esc_attr_e('Next Month', 'chatbot-plugin'); ?>">&rsaquo;</button>
                        </div>
                    </div>

                    <div class="calendar-grid" id="calendar-grid-container">
                        <!-- Days and Cells generated dynamically by JS -->
                    </div>
                </div>

                <!-- Side details drawer -->
                <div class="calendar-details-card" id="calendar-details-card">
                    <div class="details-header">
                        <h2 class="details-title" id="details-date-title"><?php _e('Select a Date', 'chatbot-plugin'); ?></h2>
                        <p class="details-subtitle" id="details-date-subtitle"><?php _e('Click a day cell to view appointments.', 'chatbot-plugin'); ?></p>
                    </div>
                    <div id="details-bookings-list">
                        <p style="color: #646970; font-style: italic; text-align: center; padding: 20px 0;">
                            <?php _e('No day selected.', 'chatbot-plugin'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <style type="text/css">
            .chatbot-calendar-container {
                display: grid;
                grid-template-columns: 1fr;
                gap: 20px;
                margin-top: 15px;
                margin-right: 20px;
            }
            @media (min-width: 1100px) {
                .chatbot-calendar-container {
                    grid-template-columns: 3fr 1fr;
                }
            }
            .chatbot-calendar-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 8px;
                padding: 20px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            }
            .calendar-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }
            .calendar-title {
                font-size: 20px;
                font-weight: 700;
                color: #1d2327;
                margin: 0;
            }
            .calendar-controls {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            .calendar-btn {
                background: #fff;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                padding: 6px 12px;
                font-size: 13px;
                cursor: pointer;
                font-weight: 500;
                transition: all 0.2s ease;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                height: 32px;
                box-sizing: border-box;
            }
            .calendar-btn:hover {
                border-color: #8A4FFF;
                color: #8A4FFF;
                background: #fcf9ff;
            }
            .calendar-btn-nav {
                width: 32px;
                height: 32px;
                padding: 0;
                font-size: 20px;
                border-radius: 50%;
                line-height: 28px;
            }
            .calendar-grid {
                display: grid;
                grid-template-columns: repeat(7, 1fr);
                border-left: 1px solid #e3e4e6;
                border-top: 1px solid #e3e4e6;
            }
            .calendar-day-header {
                background: #f6f7f7;
                padding: 10px;
                text-align: center;
                font-weight: 600;
                color: #50575e;
                border-right: 1px solid #e3e4e6;
                border-bottom: 1px solid #e3e4e6;
                font-size: 12px;
                text-transform: uppercase;
            }
            .calendar-day-cell {
                min-height: 110px;
                padding: 6px;
                background: #fff;
                border-right: 1px solid #e3e4e6;
                border-bottom: 1px solid #e3e4e6;
                display: flex;
                flex-direction: column;
                justify-content: flex-start;
                cursor: pointer;
                transition: background 0.15s ease;
                box-sizing: border-box;
            }
            .calendar-day-cell:hover {
                background: #fcf9ff;
            }
            .calendar-day-cell.other-month {
                background: #fafafa;
                color: #a7aaad;
            }
            .calendar-day-cell.other-month:hover {
                background: #f6f7f7;
            }
            .calendar-day-cell.today {
                background: #f6f2ff;
            }
            .calendar-day-cell.today .day-number {
                background: #8A4FFF;
                color: #fff;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
            .calendar-day-cell.active-selected {
                background: #f0f6fc;
                border-color: #0969da;
            }
            .day-number-container {
                display: flex;
                justify-content: flex-end;
                margin-bottom: 4px;
            }
            .day-number {
                font-size: 13px;
                font-weight: 600;
                color: #2c3338;
            }
            .event-list {
                display: flex;
                flex-direction: column;
                gap: 4px;
                overflow: hidden;
            }
            .event-tag {
                font-size: 11px;
                background: #f0ebff;
                color: #5c26d6;
                padding: 3px 6px;
                border-radius: 4px;
                white-space: nowrap;
                text-overflow: ellipsis;
                overflow: hidden;
                font-weight: 500;
                border-left: 3px solid #8A4FFF;
                transition: transform 0.1s ease;
            }
            .event-tag:hover {
                transform: translateX(2px);
            }
            .event-more-tag {
                font-size: 10px;
                color: #646970;
                padding-left: 5px;
                font-weight: 600;
                margin-top: 2px;
            }
            .calendar-details-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 8px;
                padding: 20px;
                height: fit-content;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            }
            .details-header {
                border-bottom: 1px solid #f0f0f1;
                padding-bottom: 12px;
                margin-bottom: 15px;
            }
            .details-title {
                font-size: 16px;
                font-weight: 700;
                color: #1d2327;
                margin: 0;
            }
            .details-subtitle {
                font-size: 12px;
                color: #646970;
                margin: 4px 0 0 0;
            }
            .booking-item-card {
                border: 1px solid #e3e4e6;
                border-radius: 6px;
                padding: 12px;
                margin-bottom: 10px;
                background: #fafafa;
                transition: all 0.2s ease;
            }
            .booking-item-card:hover {
                border-color: #8A4FFF;
                background: #fff;
                box-shadow: 0 2px 4px rgba(138, 79, 255, 0.08);
            }
            .booking-item-time {
                font-size: 12px;
                font-weight: 700;
                color: #8A4FFF;
                margin-bottom: 4px;
            }
            .booking-item-title {
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
                margin: 0 0 6px 0;
            }
            .booking-item-meta {
                font-size: 12px;
                color: #50575e;
                margin-bottom: 4px;
                display: flex;
                align-items: center;
                gap: 4px;
            }
            .booking-item-description {
                font-size: 12px;
                color: #646970;
                font-style: italic;
                margin: 6px 0;
                padding-top: 6px;
                border-top: 1px dashed #e3e4e6;
            }
            .booking-item-actions {
                margin-top: 8px;
                text-align: right;
            }
        </style>

        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                const bookingsData = <?php echo $bookings_json; ?>;
                
                let today = new Date();
                let currentYear = today.getFullYear();
                let currentMonth = today.getMonth(); // 0-indexed
                
                const monthNames = [
                    "<?php _e('January', 'chatbot-plugin'); ?>",
                    "<?php _e('February', 'chatbot-plugin'); ?>",
                    "<?php _e('March', 'chatbot-plugin'); ?>",
                    "<?php _e('April', 'chatbot-plugin'); ?>",
                    "<?php _e('May', 'chatbot-plugin'); ?>",
                    "<?php _e('June', 'chatbot-plugin'); ?>",
                    "<?php _e('July', 'chatbot-plugin'); ?>",
                    "<?php _e('August', 'chatbot-plugin'); ?>",
                    "<?php _e('September', 'chatbot-plugin'); ?>",
                    "<?php _e('October', 'chatbot-plugin'); ?>",
                    "<?php _e('November', 'chatbot-plugin'); ?>",
                    "<?php _e('December', 'chatbot-plugin'); ?>"
                ];

                const dayNames = [
                    "<?php _e('Mon', 'chatbot-plugin'); ?>",
                    "<?php _e('Tue', 'chatbot-plugin'); ?>",
                    "<?php _e('Wed', 'chatbot-plugin'); ?>",
                    "<?php _e('Thu', 'chatbot-plugin'); ?>",
                    "<?php _e('Fri', 'chatbot-plugin'); ?>",
                    "<?php _e('Sat', 'chatbot-plugin'); ?>",
                    "<?php _e('Sun', 'chatbot-plugin'); ?>"
                ];

                const gridContainer = document.getElementById('calendar-grid-container');
                const titleText = document.getElementById('calendar-title-text');
                const detailsDateTitle = document.getElementById('details-date-title');
                const detailsDateSubtitle = document.getElementById('details-date-subtitle');
                const detailsBookingsList = document.getElementById('details-bookings-list');

                function renderCalendar(year, month) {
                    gridContainer.innerHTML = '';
                    titleText.textContent = `${monthNames[month]} ${year}`;

                    // Render day headers (Mon-Sun)
                    dayNames.forEach(name => {
                        const header = document.createElement('div');
                        header.className = 'calendar-day-header';
                        header.textContent = name;
                        gridContainer.appendChild(header);
                    });

                    // First day of active month
                    let firstDay = new Date(year, month, 1);
                    // getDay() is 0 for Sunday, 1 for Monday... convert to Mon=0, Sun=6
                    let startWeekday = firstDay.getDay() - 1;
                    if (startWeekday < 0) startWeekday = 6;

                    // Days in active month
                    let daysInMonth = new Date(year, month + 1, 0).getDate();

                    // Days in previous month
                    let prevMonthDays = new Date(year, month, 0).getDate();

                    // 1. Render overflow days from previous month
                    for (let i = startWeekday - 1; i >= 0; i--) {
                        let dayNum = prevMonthDays - i;
                        let prevDate = new Date(year, month - 1, dayNum);
                        createDayCell(prevDate.getFullYear(), prevDate.getMonth(), dayNum, true);
                    }

                    // 2. Render active month days
                    for (let dayNum = 1; dayNum <= daysInMonth; dayNum++) {
                        createDayCell(year, month, dayNum, false);
                    }

                    // 3. Render overflow days from next month
                    let totalCells = startWeekday + daysInMonth;
                    let remainingCells = (7 - (totalCells % 7)) % 7;
                    for (let dayNum = 1; dayNum <= remainingCells; dayNum++) {
                        let nextDate = new Date(year, month + 1, dayNum);
                        createDayCell(nextDate.getFullYear(), nextDate.getMonth(), dayNum, true);
                    }
                }

                function createDayCell(year, month, dayNum, isOtherMonth) {
                    const cell = document.createElement('div');
                    cell.className = 'calendar-day-cell';
                    if (isOtherMonth) {
                        cell.className += ' other-month';
                    }

                    // Highlight Today
                    if (year === today.getFullYear() && month === today.getMonth() && dayNum === today.getDate() && !isOtherMonth) {
                        cell.className += ' today';
                    }

                    // Format Date key: YYYY-MM-DD
                    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(dayNum).padStart(2, '0')}`;
                    cell.dataset.date = dateStr;

                    // Day Number display
                    const numContainer = document.createElement('div');
                    numContainer.className = 'day-number-container';
                    const numSpan = document.createElement('div');
                    numSpan.className = 'day-number';
                    numSpan.textContent = dayNum;
                    numContainer.appendChild(numSpan);
                    cell.appendChild(numContainer);

                    // Add bookings if present
                    if (bookingsData[dateStr] && bookingsData[dateStr].length > 0) {
                        const eventList = document.createElement('div');
                        eventList.className = 'event-list';
                        
                        const list = bookingsData[dateStr];
                        const displayCount = 3;
                        
                        list.slice(0, displayCount).forEach(b => {
                            const tag = document.createElement('div');
                            tag.className = 'event-tag';
                            tag.textContent = `${b.start_time} ${b.title}`;
                            eventList.appendChild(tag);
                        });

                        if (list.length > displayCount) {
                            const moreTag = document.createElement('div');
                            moreTag.className = 'event-more-tag';
                            moreTag.textContent = `+${list.length - displayCount} <?php _e('more', 'chatbot-plugin'); ?>`;
                            eventList.appendChild(moreTag);
                        }

                        cell.appendChild(eventList);
                    }

                    // Click handler
                    cell.addEventListener('click', function() {
                        document.querySelectorAll('.calendar-day-cell').forEach(c => c.classList.remove('active-selected'));
                        cell.classList.add('active-selected');
                        showDayDetails(dateStr, year, month, dayNum);
                    });

                    gridContainer.appendChild(cell);
                }

                function showDayDetails(dateStr, year, month, dayNum) {
                    // Formatted Human Date
                    const formattedDate = new Date(year, month, dayNum).toLocaleDateString(undefined, {
                        weekday: 'long',
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });

                    detailsDateTitle.textContent = formattedDate;
                    detailsBookingsList.innerHTML = '';

                    const dayBookings = bookingsData[dateStr] || [];
                    detailsDateSubtitle.textContent = `${dayBookings.length} ${dayBookings.length === 1 ? "<?php _e('appointment', 'chatbot-plugin'); ?>" : "<?php _e('appointments', 'chatbot-plugin'); ?>"}`;

                    if (dayBookings.length === 0) {
                        detailsBookingsList.innerHTML = `
                            <p style="color: #646970; font-style: italic; text-align: center; padding: 20px 0;">
                                <?php _e('No bookings scheduled for this date.', 'chatbot-plugin'); ?>
                            </p>
                        `;
                        return;
                    }

                    dayBookings.forEach(b => {
                        // Calculate end time
                        const startParts = b.start_time.split(':');
                        const startMin = parseInt(startParts[0]) * 60 + parseInt(startParts[1]);
                        const endMin = startMin + b.duration;
                        const endHours = String(Math.floor(endMin / 60)).padStart(2, '0');
                        const endMins = String(endMin % 60).padStart(2, '0');
                        const endTime = `${endHours}:${endMins}`;

                        const card = document.createElement('div');
                        card.className = 'booking-item-card';

                        let descriptionHtml = '';
                        if (b.description && b.description.trim() !== '') {
                            descriptionHtml = `<div class="booking-item-description">${escapeHtml(b.description)}</div>`;
                        }

                        card.innerHTML = `
                            <div class="booking-item-time">
                                <span class="dashicons dashicons-clock" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle; margin-right: 2px;"></span>
                                ${b.start_time} - ${endTime} (${b.duration}m)
                            </div>
                            <h3 class="booking-item-title">${escapeHtml(b.title)}</h3>
                            <div class="booking-item-meta">
                                <span class="dashicons dashicons-email" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
                                <a href="mailto:${encodeURIComponent(b.email)}">${escapeHtml(b.email)}</a>
                            </div>
                            ${descriptionHtml}
                            <div class="booking-item-actions">
                                <a href="${b.edit_url}" class="button button-small" target="_blank">
                                    <span class="dashicons dashicons-edit" style="font-size: 14px; width: 14px; height: 14px; margin-top: 3px;"></span>
                                    <?php _e('Edit', 'chatbot-plugin'); ?>
                                </a>
                            </div>
                        `;

                        detailsBookingsList.appendChild(card);
                    });
                }

                function escapeHtml(str) {
                    return str
                        .replace(/&/g, "&amp;")
                        .replace(/</g, "&lt;")
                        .replace(/>/g, "&gt;")
                        .replace(/"/g, "&quot;")
                        .replace(/'/g, "&#039;");
                }

                // Month Nav Event Listeners
                document.getElementById('btn-prev-month').addEventListener('click', function() {
                    currentMonth--;
                    if (currentMonth < 0) {
                        currentMonth = 11;
                        currentYear--;
                    }
                    renderCalendar(currentYear, currentMonth);
                });

                document.getElementById('btn-next-month').addEventListener('click', function() {
                    currentMonth++;
                    if (currentMonth > 11) {
                        currentMonth = 0;
                        currentYear++;
                    }
                    renderCalendar(currentYear, currentMonth);
                });

                document.getElementById('btn-today').addEventListener('click', function() {
                    currentYear = today.getFullYear();
                    currentMonth = today.getMonth();
                    renderCalendar(currentYear, currentMonth);
                    
                    // Automatically click today's cell if it exists in grid
                    const todayStr = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
                    const todayCell = document.querySelector(`.calendar-day-cell[data-date="${todayStr}"]`);
                    if (todayCell) {
                        todayCell.click();
                    }
                });

                // Initial render
                renderCalendar(currentYear, currentMonth);

                // Auto-select today
                const todayStr = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
                const todayCell = document.querySelector(`.calendar-day-cell[data-date="${todayStr}"]`);
                if (todayCell) {
                    todayCell.click();
                }
            });
        </script>
        <?php
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

    /**
     * Get custom admin tabs for this addon when active.
     */
    public function get_admin_tabs() {
        return array(
            'calendar-view' => __('Calendar View', 'chatbot-plugin'),
            'bookings-list' => __('Bookings List', 'chatbot-plugin'),
        );
    }

    /**
     * Render the content for a custom admin tab.
     */
    public function render_admin_tab($tab) {
        if ($tab === 'bookings-list') {
            $this->render_bookings_list_page();
        } else {
            $this->render_calendar_page();
        }
    }

    /**
     * Render a premium bookings list table
     */
    public function render_bookings_list_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'chatbot-plugin'));
        }

        // Handle deletion if requested
        if (isset($_GET['delete_booking_id'])) {
            $booking_id = (int)$_GET['delete_booking_id'];
            if (check_admin_referer('chatbot_delete_booking_' . $booking_id)) {
                wp_delete_post($booking_id, true);
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Booking deleted successfully.', 'chatbot-plugin') . '</p></div>';
            }
        }

        // Fetch bookings with pagination
        $paged = isset($_GET['paged_num']) ? max(1, (int)$_GET['paged_num']) : 1;
        $posts_per_page = 15;
        
        $query = new WP_Query(array(
            'post_type'      => 'chatbot_booking',
            'post_status'    => 'any',
            'posts_per_page' => $posts_per_page,
            'paged'          => $paged,
            'meta_key'       => '_booking_date',
            'orderby'        => 'meta_value',
            'order'          => 'DESC'
        ));

        $bookings = $query->posts;
        $total_pages = $query->max_num_pages;
        ?>
        <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0; font-size: 18px; font-weight: 700; color: #1d2327;">
                    <?php _e('All Appointments & Bookings', 'chatbot-plugin'); ?>
                </h2>
                <a href="<?php echo admin_url('post-new.php?post_type=chatbot_booking'); ?>" class="button button-primary" target="_blank">
                    <?php _e('Add New Booking Manually', 'chatbot-plugin'); ?>
                </a>
            </div>

            <?php if (empty($bookings)): ?>
                <p style="text-align: center; color: #646970; font-style: italic; padding: 30px 0;">
                    <?php _e('No bookings found.', 'chatbot-plugin'); ?>
                </p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped table-view-list" style="border: none; box-shadow: none;">
                    <thead>
                        <tr>
                            <th style="font-weight: 600; padding: 12px 10px;"><?php _e('Client/Title', 'chatbot-plugin'); ?></th>
                            <th style="font-weight: 600; padding: 12px 10px;"><?php _e('Date & Time', 'chatbot-plugin'); ?></th>
                            <th style="font-weight: 600; padding: 12px 10px;"><?php _e('Duration', 'chatbot-plugin'); ?></th>
                            <th style="font-weight: 600; padding: 12px 10px;"><?php _e('Email', 'chatbot-plugin'); ?></th>
                            <th style="font-weight: 600; padding: 12px 10px;"><?php _e('Notes', 'chatbot-plugin'); ?></th>
                            <th style="font-weight: 600; padding: 12px 10px; text-align: right;"><?php _e('Actions', 'chatbot-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $b): 
                            $date = get_post_meta($b->ID, '_booking_date', true);
                            $time = get_post_meta($b->ID, '_booking_time', true);
                            $duration = get_post_meta($b->ID, '_booking_duration', true);
                            $email = get_post_meta($b->ID, '_booking_email', true);
                            $desc = get_post_meta($b->ID, '_booking_description', true);
                            
                            $formatted_date = !empty($date) ? date_i18n(get_option('date_format'), strtotime($date)) : __('N/A', 'chatbot-plugin');
                            ?>
                            <tr>
                                <td style="padding: 12px 10px; font-weight: 500; vertical-align: middle;">
                                    <strong><?php echo esc_html($b->post_title); ?></strong>
                                </td>
                                <td style="padding: 12px 10px; vertical-align: middle;">
                                    <span class="dashicons dashicons-calendar-alt" style="font-size: 16px; width: 16px; height: 16px; margin-right: 4px; vertical-align: text-bottom; color: #8A4FFF;"></span>
                                    <?php echo esc_html($formatted_date); ?>
                                    <span style="color: #646970; margin-left: 5px;">@ <?php echo esc_html($time ?: '00:00'); ?></span>
                                </td>
                                <td style="padding: 12px 10px; vertical-align: middle;">
                                    <?php echo sprintf(__('%s min', 'chatbot-plugin'), esc_html($duration ?: '0')); ?>
                                </td>
                                <td style="padding: 12px 10px; vertical-align: middle;">
                                    <?php if (!empty($email)): ?>
                                        <a href="mailto:<?php echo esc_attr($email); ?>" style="color: #8A4FFF; text-decoration: none;">
                                            <?php echo esc_html($email); ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #a7aaad; font-style: italic;"><?php _e('None', 'chatbot-plugin'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 10px; vertical-align: middle; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo esc_attr($desc); ?>">
                                    <?php echo esc_html($desc ?: '-'); ?>
                                </td>
                                <td style="padding: 12px 10px; text-align: right; vertical-align: middle;">
                                    <a href="<?php echo get_edit_post_link($b->ID); ?>" class="button button-small" target="_blank" style="margin-right: 4px;">
                                        <?php _e('Edit', 'chatbot-plugin'); ?>
                                    </a>
                                    <?php 
                                    $delete_url = wp_nonce_url(
                                        add_query_arg(array('tab' => 'addon-local-calendar', 'subtab' => 'bookings-list', 'delete_booking_id' => $b->ID), admin_url('admin.php?page=chatbot-addons')),
                                        'chatbot_delete_booking_' . $b->ID
                                    );
                                    ?>
                                    <a href="<?php echo esc_url($delete_url); ?>" class="button button-small" style="color: #c62828; border-color: #c62828;" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this booking?', 'chatbot-plugin'); ?>');">
                                        <?php _e('Delete', 'chatbot-plugin'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1): ?>
                    <div class="tablenav" style="margin-top: 15px;">
                        <div class="tablenav-pages">
                            <span class="displaying-num"><?php printf(_n('%s item', '%s items', $query->found_posts, 'chatbot-plugin'), number_format_i18n($query->found_posts)); ?></span>
                            <span class="pagination-links">
                                <?php if ($paged > 1): ?>
                                    <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged_num', $paged - 1)); ?>">&lsaquo;</a>
                                <?php endif; ?>
                                <span class="paging-input">
                                    <span class="current-page"><?php echo $paged; ?></span>
                                    <?php _e('of', 'chatbot-plugin'); ?>
                                    <span class="total-pages"><?php echo $total_pages; ?></span>
                                </span>
                                <?php if ($paged < $total_pages): ?>
                                    <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged_num', $paged + 1)); ?>">&rsaquo;</a>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; wp_reset_postdata(); ?>
        </div>
        <?php
    }
}

