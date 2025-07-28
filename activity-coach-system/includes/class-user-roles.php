<?php
class ACS_User_Roles {
    /**
     * Register custom roles and capabilities
     */
    public static function init() {
        self::add_roles();
        // Optionally: add capability checks or filters here
    }

    /**
     * Add Coach and Mentee roles
     */
    public static function add_roles() {
        add_role(
            'acs_coach',
            'Coach',
            [
                'read' => true,
                'manage_mentees' => true,
                'view_coach_dashboard' => true,
                'create_weekly_plans' => true,
                'score_activities' => true,
            ]
        );
        add_role(
            'acs_mentee',
            'Mentee',
            [
                'read' => true,
                'view_mentee_dashboard' => true,
                'connect_fitness_account' => true,
                'view_weekly_plans' => true,
            ]
        );
    }

    /**
     * Remove custom roles (optional, on deactivation)
     */
    public static function remove_roles() {
        remove_role('acs_coach');
        remove_role('acs_mentee');
    }
}

// Remove roles on plugin deactivation
register_deactivation_hook(
    dirname(__FILE__, 2) . '/activity-coach-system.php',
    ['ACS_User_Roles', 'remove_roles']
);
