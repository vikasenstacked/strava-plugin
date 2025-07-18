<?php
/**
 * Plugin Name: Strava Coaching
 * Plugin URI: https://yoursite.com
 * Description: Connect WordPress users with Strava for coaching and mentee management with enhanced activity-to-plan matching
 * Version: 1.1.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: strava-coaching
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('STRAVA_COACHING_VERSION', '1.1.0'); // Updated for enhanced matching
define('STRAVA_COACHING_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('STRAVA_COACHING_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Plugin activation hook
 */
function activate_strava_coaching()
{
    require_once STRAVA_COACHING_PLUGIN_DIR . 'includes/class-activator.php';
    Strava_Coaching_Activator::activate();
}

/**
 * Plugin deactivation hook
 */
function deactivate_strava_coaching()
{
    require_once STRAVA_COACHING_PLUGIN_DIR . 'includes/class-deactivator.php';
    Strava_Coaching_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_strava_coaching');
register_deactivation_hook(__FILE__, 'deactivate_strava_coaching');

/**
 * Include the core plugin class
 */
require STRAVA_COACHING_PLUGIN_DIR . 'includes/class-strava-coaching.php';

/**
 * Initialize the plugin
 */
function run_strava_coaching()
{
    $plugin = new Strava_Coaching();
    $plugin->run();
}
run_strava_coaching();

/* ===================================================================
 * ENHANCED MATCHING HOOKS AND FUNCTIONALITY
 * =================================================================== */

/**
 * Handle initial matching after plugin activation
 * This runs 1 minute after activation to allow all tables to be created
 */
add_action('strava_initial_matching', function () {
    if (class_exists('Strava_Coaching_Activator')) {
        $matched_count = Strava_Coaching_Activator::rematch_all_active_plans();

        // Log the result
        if ($matched_count !== false) {
            error_log("Strava Coaching: Initial matching completed. {$matched_count} activities matched.");
        }
    }
});

/**
 * Version check and auto-update database
 * This ensures the database is updated when the plugin is updated
 */
function check_strava_coaching_database_version()
{
    $current_version = get_option('strava_coaching_db_version', '1.0.0');
    $new_version = '1.1.0'; // Enhanced matching version

    if (version_compare($current_version, $new_version, '<')) {
        if (class_exists('Strava_Coaching_Activator')) {
            Strava_Coaching_Activator::update_database_for_enhanced_matching();
            update_option('strava_coaching_db_version', $new_version);

            // Set transient for admin notice
            set_transient('strava_coaching_db_updated', true, 300); // 5 minutes

            // Log the database update
            error_log('Strava Coaching: Database updated to version ' . $new_version);

            // Schedule a re-match of existing activities
            wp_schedule_single_event(time() + 30, 'strava_rematch_after_update');
        }
    }
}

// Run on admin_init to check for database updates
add_action('admin_init', 'check_strava_coaching_database_version');

/**
 * Re-match activities after database update
 */
add_action('strava_rematch_after_update', function () {
    if (class_exists('Strava_Coaching_Activator')) {
        $matched_count = Strava_Coaching_Activator::rematch_all_active_plans();

        // Set transient to show success message
        if ($matched_count !== false) {
            set_transient('strava_coaching_rematch_success', $matched_count, 300);
        }
    }
});

/**
 * Admin notices for database updates and matching results
 */
function strava_coaching_admin_notices()
{
    // Database update notice
    if (get_transient('strava_coaching_db_updated')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>🎉 Strava Coaching Enhanced Matching Activated!</strong></p>
            <p>Your database has been updated with improved activity-to-plan matching capabilities.</p>
        </div>
        <?php
        delete_transient('strava_coaching_db_updated');
    }

    // Rematch success notice
    $rematch_count = get_transient('strava_coaching_rematch_success');
    if ($rematch_count !== false) {
        ?>
        <div class="notice notice-info is-dismissible">
            <p><strong>🔄 Activity Matching Complete!</strong></p>
            <p>Re-processed existing training plans. Found <?php echo intval($rematch_count); ?> new activity matches with
                improved accuracy.</p>
        </div>
        <?php
        delete_transient('strava_coaching_rematch_success');
    }
}
add_action('admin_notices', 'strava_coaching_admin_notices');

/**
 * Daily sync hook for automatic activity matching
 * This runs daily to sync new activities and match them to plans
 */
add_action('strava_daily_sync', function () {
    if (!class_exists('Strava_Coaching_API')) {
        return;
    }

    global $wpdb;
    $strava_api = new Strava_Coaching_API();

    // Get all users with active training plans
    $users_with_plans = $wpdb->get_results("
        SELECT DISTINCT mentee_id, u.display_name
        FROM {$wpdb->prefix}strava_training_plans tp
        INNER JOIN {$wpdb->prefix}users u ON tp.mentee_id = u.ID
        WHERE tp.status = 'active'
        AND tp.week_start >= DATE_SUB(NOW(), INTERVAL 2 WEEK)
        AND tp.week_end <= DATE_ADD(NOW(), INTERVAL 1 WEEK)
    ");

    $total_synced = 0;
    $total_matched = 0;

    foreach ($users_with_plans as $user) {
        // Check if user has valid Strava connection
        if (is_strava_connected($user->mentee_id)) {
            // Sync recent activities
            $synced = $strava_api->sync_user_activities($user->mentee_id, 7); // Last 7 days
            if ($synced !== false) {
                $total_synced += $synced;
            }

            // Match activities to plans
            $matched = $strava_api->match_activities_to_plans($user->mentee_id);
            if ($matched !== false) {
                $total_matched += $matched;
            }
        }
    }

    // Log daily sync results
    if ($total_synced > 0 || $total_matched > 0) {
        error_log("Strava Coaching Daily Sync: {$total_synced} activities synced, {$total_matched} new matches found.");
    }
});

/**
 * Cleanup function for plugin deactivation
 */
function strava_coaching_cleanup_on_deactivate()
{
    // Clear all scheduled events
    wp_clear_scheduled_hook('strava_daily_sync');
    wp_clear_scheduled_hook('strava_initial_matching');
    wp_clear_scheduled_hook('strava_rematch_after_update');

    // Clear transients
    delete_transient('strava_coaching_db_updated');
    delete_transient('strava_coaching_rematch_success');

    // Log cleanup
    error_log('Strava Coaching: Cleanup completed on deactivation.');
}
register_deactivation_hook(__FILE__, 'strava_coaching_cleanup_on_deactivate');

/**
 * Debug function for troubleshooting (remove in production)
 * Add ?strava_debug=1 to any admin page to see plugin status
 */
function strava_coaching_debug_info()
{
    if (isset($_GET['strava_debug']) && $_GET['strava_debug'] === '1' && current_user_can('manage_options')) {
        global $wpdb;

        echo '<div style="background: #f1f1f1; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">';
        echo '<h3>🔧 Strava Coaching Debug Info</h3>';

        // Database version
        $db_version = get_option('strava_coaching_db_version', 'Not set');
        echo '<p><strong>Database Version:</strong> ' . $db_version . '</p>';

        // Table status
        $tables = array(
            'strava_activities',
            'strava_training_plans',
            'activity_plan_matches',
            'coach_mentee_relationships',
            'activity_scores',
            'strava_tokens'
        );

        echo '<p><strong>Database Tables:</strong></p><ul>';
        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
            $count = $exists ? $wpdb->get_var("SELECT COUNT(*) FROM $table_name") : 0;
            $status = $exists ? "✅ {$count} records" : "❌ Missing";
            echo "<li>{$table}: {$status}</li>";
        }
        echo '</ul>';

        // Scheduled events
        $scheduled = wp_get_scheduled_event('strava_daily_sync');
        echo '<p><strong>Daily Sync Scheduled:</strong> ' . ($scheduled ? '✅ Yes' : '❌ No') . '</p>';

        // API Configuration
        $client_id = get_option('strava_coaching_client_id');
        echo '<p><strong>Strava API:</strong> ' . ($client_id ? '✅ Configured' : '❌ Not configured') . '</p>';

        echo '</div>';
    }
}
add_action('admin_footer', 'strava_coaching_debug_info');

/**
 * Schedule daily sync if not already scheduled
 * This ensures the daily sync continues working
 */
function ensure_strava_daily_sync_scheduled()
{
    if (!wp_next_scheduled('strava_daily_sync')) {
        wp_schedule_event(time(), 'daily', 'strava_daily_sync');
        error_log('Strava Coaching: Daily sync scheduled.');
    }
}
add_action('wp_loaded', 'ensure_strava_daily_sync_scheduled');
?>