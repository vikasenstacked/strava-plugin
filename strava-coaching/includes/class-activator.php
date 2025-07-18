<?php
/**
 * Plugin Activator Class with Enhanced Matching Support
 * File: includes/class-activator.php
 * 
 * REPLACE YOUR ENTIRE class-activator.php FILE WITH THIS VERSION
 */

class Strava_Coaching_Activator
{

    public static function activate()
    {
        self::create_tables();
        self::create_user_roles();
        self::set_default_options();

        // Add the new enhanced matching updates
        self::update_database_for_enhanced_matching();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Schedule initial matching (will run after activation)
        wp_schedule_single_event(time() + 60, 'strava_initial_matching');
    }

    private static function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Training Plans table
        $table_name = $wpdb->prefix . 'strava_training_plans';
        $sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            coach_id bigint(20) NOT NULL,
            mentee_id bigint(20) NOT NULL,
            plan_name varchar(255) NOT NULL,
            week_start date NOT NULL,
            week_end date NOT NULL,
            plan_data longtext NOT NULL,
            status varchar(20) DEFAULT 'active',
            completion_percentage decimal(5,2) DEFAULT 0.00,
            workouts_completed int(11) DEFAULT 0,
            workouts_planned int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY coach_id (coach_id),
            KEY mentee_id (mentee_id),
            KEY week_start (week_start),
            KEY status (status)
        ) $charset_collate;";

        // Strava Activities table
        $table_name2 = $wpdb->prefix . 'strava_activities';
        $sql2 = "CREATE TABLE $table_name2 (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            strava_id bigint(20) NOT NULL UNIQUE,
            activity_type varchar(50) NOT NULL,
            name varchar(255) NOT NULL,
            distance decimal(8,2) DEFAULT NULL,
            moving_time int(11) DEFAULT NULL,
            elapsed_time int(11) DEFAULT NULL,
            total_elevation_gain decimal(8,2) DEFAULT NULL,
            average_speed decimal(8,4) DEFAULT NULL,
            max_speed decimal(8,4) DEFAULT NULL,
            average_heartrate decimal(5,2) DEFAULT NULL,
            max_heartrate int(11) DEFAULT NULL,
            start_date datetime NOT NULL,
            kudos_count int(11) DEFAULT 0,
            achievement_count int(11) DEFAULT 0,
            synced_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY activity_type (activity_type),
            KEY start_date (start_date),
            KEY user_date (user_id, start_date)
        ) $charset_collate;";

        // Coach-Mentee Relationships table
        $table_name3 = $wpdb->prefix . 'coach_mentee_relationships';
        $sql3 = "CREATE TABLE $table_name3 (
            id int(11) NOT NULL AUTO_INCREMENT,
            coach_id bigint(20) NOT NULL,
            mentee_id bigint(20) NOT NULL,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY coach_mentee (coach_id, mentee_id),
            KEY coach_id (coach_id),
            KEY mentee_id (mentee_id)
        ) $charset_collate;";

        // Activity Scores table
        $table_name4 = $wpdb->prefix . 'activity_scores';
        $sql4 = "CREATE TABLE $table_name4 (
            id int(11) NOT NULL AUTO_INCREMENT,
            activity_id int(11) NOT NULL,
            coach_id bigint(20) NOT NULL,
            pace_score tinyint(2) DEFAULT NULL,
            distance_score tinyint(2) DEFAULT NULL,
            heart_rate_score tinyint(2) DEFAULT NULL,
            overall_score decimal(3,1) DEFAULT NULL,
            comments text,
            scored_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY activity_id (activity_id),
            KEY coach_id (coach_id)
        ) $charset_collate;";

        // Strava Tokens table
        $table_name5 = $wpdb->prefix . 'strava_tokens';
        $sql5 = "CREATE TABLE $table_name5 (
            user_id bigint(20) NOT NULL,
            access_token varchar(255) NOT NULL,
            refresh_token varchar(255) NOT NULL,
            expires_at datetime NOT NULL,
            athlete_id bigint(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            UNIQUE KEY athlete_id (athlete_id)
        ) $charset_collate;";

        // Activity Plan Matches table (for enhanced matching)
        $table_name6 = $wpdb->prefix . 'activity_plan_matches';
        $sql6 = "CREATE TABLE $table_name6 (
            id int(11) NOT NULL AUTO_INCREMENT,
            activity_id int(11) NOT NULL,
            plan_id int(11) NOT NULL,
            workout_day varchar(20) NOT NULL,
            match_confidence int(11) DEFAULT 0,
            match_type varchar(20) DEFAULT 'automatic',
            matched_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_activity_plan (activity_id, plan_id),
            KEY plan_id (plan_id),
            KEY workout_day (workout_day),
            KEY match_confidence (match_confidence),
            KEY match_type (match_type)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql2);
        dbDelta($sql3);
        dbDelta($sql4);
        dbDelta($sql5);
        dbDelta($sql6);
    }

    private static function create_user_roles()
    {
        // Add coach capability to administrator
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('manage_coaching');
            $admin_role->add_cap('view_all_mentees');
        }

        // Create coach role
        add_role('coach', 'Coach', array(
            'read' => true,
            'manage_mentees' => true,
            'create_training_plans' => true,
            'score_activities' => true,
            'view_mentee_progress' => true,
        ));

        // Create mentee role
        add_role('mentee', 'Mentee', array(
            'read' => true,
            'view_own_progress' => true,
            'connect_strava' => true,
        ));
    }

    private static function set_default_options()
    {
        add_option('strava_coaching_client_id', '');
        add_option('strava_coaching_client_secret', '');
        add_option('strava_coaching_sync_interval', 'daily');
        add_option('strava_coaching_data_retention', 90); // 3 months
        add_option('strava_coaching_db_version', '1.1.0'); // Enhanced matching version
    }

    /**
     * Update database schema for enhanced matching
     * Call this after plugin update or add to activation
     */
    public static function update_database_for_enhanced_matching()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // 1. Update activity_plan_matches table
        $table_name = $wpdb->prefix . 'activity_plan_matches';

        // Check if table exists, if not create it
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
                id int(11) NOT NULL AUTO_INCREMENT,
                activity_id int(11) NOT NULL,
                plan_id int(11) NOT NULL,
                workout_day varchar(20) NOT NULL,
                match_confidence int(11) DEFAULT 0,
                match_type varchar(20) DEFAULT 'automatic',
                matched_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_activity_plan (activity_id, plan_id),
                KEY plan_id (plan_id),
                KEY workout_day (workout_day),
                KEY match_confidence (match_confidence)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        } else {
            // Add new columns if they don't exist
            $columns = $wpdb->get_col("DESCRIBE $table_name");

            if (!in_array('match_type', $columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN match_type varchar(20) DEFAULT 'automatic'");
            }

            if (!in_array('matched_at', $columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN matched_at datetime DEFAULT CURRENT_TIMESTAMP");
            }
        }

        // 2. Update training plans table for completion tracking
        $plans_table = $wpdb->prefix . 'strava_training_plans';
        $plan_columns = $wpdb->get_col("DESCRIBE $plans_table");

        if (!in_array('completion_percentage', $plan_columns)) {
            $wpdb->query("ALTER TABLE $plans_table ADD COLUMN completion_percentage decimal(5,2) DEFAULT 0.00");
        }

        if (!in_array('workouts_completed', $plan_columns)) {
            $wpdb->query("ALTER TABLE $plans_table ADD COLUMN workouts_completed int(11) DEFAULT 0");
        }

        if (!in_array('workouts_planned', $plan_columns)) {
            $wpdb->query("ALTER TABLE $plans_table ADD COLUMN workouts_planned int(11) DEFAULT 0");
        }

        // 3. Add indexes for better performance
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_activity_start_date ON {$wpdb->prefix}strava_activities (start_date)");
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_activity_user_date ON {$wpdb->prefix}strava_activities (user_id, start_date)");
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_plan_mentee_week ON {$wpdb->prefix}strava_training_plans (mentee_id, week_start)");
    }

    /**
     * Run matching for all active plans (useful for initial setup)
     */
    public static function rematch_all_active_plans()
    {
        global $wpdb;

        if (!class_exists('Strava_Coaching_API')) {
            return false;
        }

        $strava_api = new Strava_Coaching_API();

        // Get all active plans from last 3 months
        $plans = $wpdb->get_results("
            SELECT DISTINCT mentee_id 
            FROM {$wpdb->prefix}strava_training_plans 
            WHERE status = 'active' 
            AND week_start >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
        ");

        $total_matched = 0;

        foreach ($plans as $plan) {
            $matched = $strava_api->match_activities_to_plans($plan->mentee_id);
            $total_matched += $matched;
        }

        return $total_matched;
    }

    /**
     * Clean up plugin data on deactivation (optional)
     */
    public static function cleanup_plugin_data()
    {
        global $wpdb;

        // Remove custom roles
        remove_role('coach');
        remove_role('mentee');

        // Remove capabilities from admin
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->remove_cap('manage_coaching');
            $admin_role->remove_cap('view_all_mentees');
        }

        // Clear scheduled events
        wp_clear_scheduled_hook('strava_daily_sync');
        wp_clear_scheduled_hook('strava_initial_matching');

        // Note: We don't drop tables here to preserve data
        // Tables will only be dropped on uninstall if uninstall.php is created
    }
}
?>