<?php
/**
 * Logger class for Price Sync
 */

if (!defined('WPINC')) {
    die;
}

class Price_Sync_Logger {

    /**
     * Log a message
     */
    public static function log($message, $level = 'info') {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        $logger = wc_get_logger();
        $context = array('source' => 'price-sync');

        switch ($level) {
            case 'error':
                $logger->error($message, $context);
                break;
            case 'warning':
                $logger->warning($message, $context);
                break;
            case 'info':
            default:
                $logger->info($message, $context);
                break;
        }
    }

    /**
     * Log an error
     */
    public static function error($message) {
        self::log($message, 'error');
    }

    /**
     * Log a warning
     */
    public static function warning($message) {
        self::log($message, 'warning');
    }

    /**
     * Get recent logs
     */
    public static function get_recent_logs($limit = 50) {
        if (!function_exists('wc_get_logger')) {
            return array();
        }

        // This is a simplified version
        // In a production environment, you might want to read from the log files
        return array();
    }
}
