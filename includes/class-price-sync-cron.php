<?php
/**
 * Cron job management for scheduled price syncs
 */

if (!defined('WPINC')) {
    die;
}

class Price_Sync_Cron {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /**
     * Get instance
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
    private function __construct() {
        // Register custom cron schedules
        add_filter('cron_schedules', array($this, 'add_custom_schedules'));

        // Hook into the cron event
        add_action('price_sync_cron_event', array($this, 'execute_scheduled_sync'));

        // Update cron schedule when option changes
        add_action('update_option_price_sync_cron_schedule', array($this, 'reschedule_event'), 10, 2);
    }

    /**
     * Add custom cron schedules
     */
    public function add_custom_schedules($schedules) {
        // Weekly schedule
        $schedules['weekly'] = array(
            'interval' => 604800, // 7 days in seconds
            'display' => __('Once Weekly', 'price-sync')
        );

        return $schedules;
    }

    /**
     * Execute scheduled sync
     */
    public function execute_scheduled_sync() {
        Price_Sync_Logger::log('Executing scheduled price sync (cron)');

        $results = Price_Sync_Sync_Engine::execute_sync();

        if ($results['success']) {
            Price_Sync_Logger::log('Scheduled sync completed: ' . $results['message']);
        } else {
            Price_Sync_Logger::error('Scheduled sync failed: ' . $results['message']);
        }
    }

    /**
     * Schedule the cron event
     */
    public static function schedule_event($schedule = 'daily') {
        // Clear existing scheduled event
        self::unschedule_event();

        if ($schedule === 'disabled') {
            Price_Sync_Logger::log('Cron job disabled');
            return;
        }

        // Validate schedule
        $allowed_schedules = array('daily', 'weekly', 'custom');
        if (!in_array($schedule, $allowed_schedules)) {
            $schedule = 'daily';
        }

        // Schedule new event
        if (!wp_next_scheduled('price_sync_cron_event')) {
            $timestamp = strtotime('tomorrow 2:00 AM'); // Run at 2 AM

            if ($schedule === 'custom') {
                // Get custom time from settings
                $custom_time = get_option('price_sync_cron_custom_time', '02:00');
                $timestamp = strtotime('tomorrow ' . $custom_time);
            }

            wp_schedule_event($timestamp, $schedule, 'price_sync_cron_event');
            Price_Sync_Logger::log("Cron job scheduled: $schedule");
        }
    }

    /**
     * Unschedule the cron event
     */
    public static function unschedule_event() {
        $timestamp = wp_next_scheduled('price_sync_cron_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'price_sync_cron_event');
            Price_Sync_Logger::log('Cron job unscheduled');
        }
    }

    /**
     * Reschedule event when option changes
     */
    public function reschedule_event($old_value, $new_value) {
        self::schedule_event($new_value);
    }

    /**
     * Get next scheduled run time
     */
    public static function get_next_run() {
        $timestamp = wp_next_scheduled('price_sync_cron_event');
        if ($timestamp) {
            return $timestamp;
        }
        return false;
    }

    /**
     * Get current schedule
     */
    public static function get_current_schedule() {
        return get_option('price_sync_cron_schedule', 'disabled');
    }
}
