<?php
/**
 * Plugin Deactivator Class
 * File: includes/class-deactivator.php
 */

class Strava_Coaching_Deactivator {
    
    /**
     * Plugin deactivation hook
     */
    public static function deactivate() {
        // Clear scheduled hooks
        wp_clear_scheduled_hook('strava_daily_sync');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        error_log('Strava Coaching Plugin deactivated');
    }
}