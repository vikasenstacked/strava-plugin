<?php
class ACS_Database {
    /**
     * Create custom tables on plugin activation
     */
    public static function activate() {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        $charset_collate = $wpdb->get_charset_collate();

        // Table names
        $coach_mentees     = $wpdb->prefix . 'acs_coach_mentees';
        $api_users         = $wpdb->prefix . 'acs_api_users';
        $activities_cache  = $wpdb->prefix . 'acs_activities_cache';
        $weekly_plans      = $wpdb->prefix . 'acs_weekly_plans';
        $plan_activities   = $wpdb->prefix . 'acs_plan_activities';
        $weekly_scores     = $wpdb->prefix . 'acs_weekly_scores';
        $settings          = $wpdb->prefix . 'acs_settings';

        // SQL for each table
        $sql = [];

        // 1. Coach-Mentee Relationships
        $sql[] = "CREATE TABLE $coach_mentees (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            coach_id bigint(20) unsigned NOT NULL,
            mentee_id bigint(20) unsigned NOT NULL,
            assigned_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY coach_id (coach_id),
            KEY mentee_id (mentee_id)
        ) $charset_collate;";

        // 2. API User Integrations
        $sql[] = "CREATE TABLE $api_users (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            provider varchar(64) NOT NULL,
            access_token text NOT NULL,
            refresh_token text,
            expires_at datetime,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) $charset_collate;";

        // 3. Activities Cache
        $sql[] = "CREATE TABLE $activities_cache (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            activity_id varchar(64) NOT NULL,
            activity_type varchar(64),
            distance float,
            duration float,
            elevation float,
            pace float,
            start_time datetime,
            raw_data longtext,
            synced_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY activity_id (activity_id)
        ) $charset_collate;";

        // 4. Weekly Training Plans
        $sql[] = "CREATE TABLE $weekly_plans (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            mentee_id bigint(20) unsigned NOT NULL,
            coach_id bigint(20) unsigned NOT NULL,
            week_start date NOT NULL,
            week_end date NOT NULL,
            plan_title varchar(255),
            plan_notes text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY mentee_id (mentee_id),
            KEY coach_id (coach_id)
        ) $charset_collate;";

        // 5. Plan Activity Details
        $sql[] = "CREATE TABLE $plan_activities (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            plan_id bigint(20) unsigned NOT NULL,
            activity_type varchar(64),
            target_distance float,
            target_duration float,
            target_pace float,
            notes text,
            PRIMARY KEY  (id),
            KEY plan_id (plan_id)
        ) $charset_collate;";

        // 6. Weekly Performance Scores
        $sql[] = "CREATE TABLE $weekly_scores (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            plan_id bigint(20) unsigned NOT NULL,
            mentee_id bigint(20) unsigned NOT NULL,
            score tinyint(2),
            pace_score tinyint(2),
            distance_score tinyint(2),
            consistency_score tinyint(2),
            elevation_score tinyint(2),
            custom_field_1 varchar(255),
            custom_field_2 varchar(255),
            custom_field_3 varchar(255),
            custom_field_4 varchar(255),
            feedback text,
            scored_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY plan_id (plan_id),
            KEY mentee_id (mentee_id)
        ) $charset_collate;";

        // 7. Plugin Settings
        $sql[] = "CREATE TABLE $settings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            option_name varchar(191) NOT NULL,
            option_value longtext,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY option_name (option_name)
        ) $charset_collate;";

        // Run dbDelta for each table
        foreach ( $sql as $table_sql ) {
            dbDelta( $table_sql );
        }
    }

    public static function deactivate() {
        // Cleanup logic if needed (not dropping tables by default)
    }
    public static function init() {
        // Future: maybe scheduled events, etc.
        self::maybe_upgrade_schema();
    }

    private static function maybe_upgrade_schema() {
        global $wpdb;
        $scores_table = $wpdb->prefix . 'acs_weekly_scores';
        $columns = $wpdb->get_col("DESC $scores_table", 0);
        $alter = [];
        if (!in_array('pace_score', $columns)) {
            $alter[] = 'ADD COLUMN pace_score tinyint(2)';
        }
        if (!in_array('distance_score', $columns)) {
            $alter[] = 'ADD COLUMN distance_score tinyint(2)';
        }
        if (!in_array('consistency_score', $columns)) {
            $alter[] = 'ADD COLUMN consistency_score tinyint(2)';
        }
        if (!in_array('elevation_score', $columns)) {
            $alter[] = 'ADD COLUMN elevation_score tinyint(2)';
        }
        if ($alter) {
            $sql = "ALTER TABLE $scores_table " . implode(', ', $alter) . ";";
            $wpdb->query($sql);
        }
    }
}
