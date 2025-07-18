<?php
/**
 * Main Plugin Class
 * File: includes/class-strava-coaching.php
 */

class Strava_Coaching
{

    /**
     * The loader that's responsible for maintaining and registering all hooks
     */
    protected $loader;

    /**
     * The unique identifier of this plugin
     */
    protected $plugin_name;

    /**
     * The current version of the plugin
     */
    protected $version;

    /**
     * Initialize the plugin
     */
    public function __construct()
    {
        $this->plugin_name = 'strava-coaching';
        $this->version = STRAVA_COACHING_VERSION;

        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    /**
     * Load required dependencies
     */
    private function load_dependencies()
    {

        // Core classes (only load what exists for now)
        require_once STRAVA_COACHING_PLUGIN_DIR . 'includes/class-loader.php';

        // Load OAuth endpoint handler (replaces old OAuth handler)
        if (file_exists(STRAVA_COACHING_PLUGIN_DIR . 'includes/class-oauth-endpoint.php')) {
            require_once STRAVA_COACHING_PLUGIN_DIR . 'includes/class-oauth-endpoint.php';
            new Strava_OAuth_Endpoint(); // Initialize immediately
        }

        // Load Strava API class if it exists
        if (file_exists(STRAVA_COACHING_PLUGIN_DIR . 'includes/class-strava-api.php')) {
            require_once STRAVA_COACHING_PLUGIN_DIR . 'includes/class-strava-api.php';
        }

        // Load admin class if it exists
        if (file_exists(STRAVA_COACHING_PLUGIN_DIR . 'admin/class-admin.php')) {
            require_once STRAVA_COACHING_PLUGIN_DIR . 'admin/class-admin.php';
        }

        // Load public class if it exists
        if (file_exists(STRAVA_COACHING_PLUGIN_DIR . 'public/class-public.php')) {
            require_once STRAVA_COACHING_PLUGIN_DIR . 'public/class-public.php';
        }

        $this->loader = new Strava_Coaching_Loader();
    }

    /**
     * Register admin hooks
     */
    private function define_admin_hooks()
    {
        // Only load admin hooks if admin class exists
        if (class_exists('Strava_Coaching_Admin')) {
            $plugin_admin = new Strava_Coaching_Admin($this->get_plugin_name(), $this->get_version());

            // Admin menu and pages
            $this->loader->add_action('admin_menu', $plugin_admin, 'add_admin_menu');
            $this->loader->add_action('admin_init', $plugin_admin, 'init_settings');

            // Enqueue admin scripts and styles
            $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
            $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');

            // Ajax handlers
            $this->loader->add_action('wp_ajax_sync_strava_activities', $plugin_admin, 'ajax_sync_activities');
            $this->loader->add_action('wp_ajax_disconnect_strava', $plugin_admin, 'ajax_disconnect_strava');
            $this->loader->add_action('wp_ajax_add_mentee', $plugin_admin, 'add_mentee');
            $this->loader->add_action('wp_ajax_remove_mentee', $plugin_admin, 'remove_mentee');
            $this->loader->add_action('wp_ajax_score_activity', $plugin_admin, 'score_activity');

            // User profile hooks
            $this->loader->add_action('show_user_profile', $plugin_admin, 'show_strava_profile_fields');
            $this->loader->add_action('edit_user_profile', $plugin_admin, 'show_strava_profile_fields');
        }
    }

    /**
     * Register public hooks
     */
    private function define_public_hooks()
    {
        // Only load public hooks if public class exists
        if (class_exists('Strava_Coaching_Public')) {
            $plugin_public = new Strava_Coaching_Public($this->get_plugin_name(), $this->get_version());

            // Enqueue public scripts and styles
            $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_styles');
            $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_scripts');

            // Shortcodes
            $this->loader->add_shortcode('strava_connect_button', $plugin_public, 'strava_connect_shortcode');
            $this->loader->add_shortcode('strava_progress_chart', $plugin_public, 'progress_chart_shortcode');
            $this->loader->add_shortcode('training_plan_display', $plugin_public, 'training_plan_shortcode');

            // Custom endpoints
            $this->loader->add_action('init', $plugin_public, 'add_rewrite_endpoints');
            $this->loader->add_action('template_redirect', $plugin_public, 'handle_custom_endpoints');

            // Scheduled events
            $this->loader->add_action('strava_daily_sync', $plugin_public, 'sync_all_user_activities');
            // In the define_public_hooks() method, add these shortcode registrations:

            // Shortcodes
            $this->loader->add_shortcode('strava_dashboard', $plugin_public, 'strava_dashboard_shortcode');
            $this->loader->add_shortcode('strava_connect_button', $plugin_public, 'strava_connect_shortcode');
            $this->loader->add_shortcode('strava_progress_chart', $plugin_public, 'strava_progress_chart_shortcode');
            $this->loader->add_shortcode('strava_activity_chart', $plugin_public, 'strava_activity_chart_shortcode');
            $this->loader->add_shortcode('strava_training_plan', $plugin_public, 'training_plan_shortcode');
        }
    }

    /**
     * Run the loader to execute all hooks
     */
    public function run()
    {
        $this->loader->run();

        // Schedule daily sync if not already scheduled
        if (!wp_next_scheduled('strava_daily_sync')) {
            wp_schedule_event(time(), 'daily', 'strava_daily_sync');
        }
    }

    /**
     * Get plugin name
     */
    public function get_plugin_name()
    {
        return $this->plugin_name;
    }

    /**
     * Get plugin version
     */
    public function get_version()
    {
        return $this->version;
    }
}

/**
 * Utility Functions
 */

/**
 * Check if user is a coach
 */
function is_coach($user_id = null)
{
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    $user = get_user_by('id', $user_id);
    return $user && (in_array('coach', $user->roles) || in_array('administrator', $user->roles));
}

/**
 * Check if user is a mentee
 */
function is_mentee($user_id = null)
{
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    $user = get_user_by('id', $user_id);
    return $user && in_array('mentee', $user->roles);
}

/**
 * Get user's Strava connection status
 */
function is_strava_connected($user_id = null)
{
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'strava_tokens';

    $token = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d AND expires_at > NOW()",
        $user_id
    ));

    return !empty($token);
}

/**
 * Format pace (seconds per km to min:sec format)
 */
function format_pace($seconds_per_km)
{
    if (!$seconds_per_km)
        return '--:--';

    $minutes = floor($seconds_per_km / 60);
    $seconds = $seconds_per_km % 60;

    return sprintf('%d:%02d', $minutes, $seconds);
}

/**
 * Format distance (meters to km)
 */
function format_distance($meters)
{
    if (!$meters)
        return '0.0';

    return number_format($meters / 1000, 2);
}

/**
 * Format duration (seconds to h:mm:ss)
 */
function format_duration($seconds)
{
    if (!$seconds)
        return '0:00';

    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    } else {
        return sprintf('%d:%02d', $minutes, $secs);
    }
}

/**
 * Get activity type icon
 */
function get_activity_icon($activity_type)
{
    $icons = [
        'Run' => '🏃‍♂️',
        'Ride' => '🚴‍♂️',
        'Swim' => '🏊‍♂️',
        'Walk' => '🚶‍♂️',
        'Hike' => '🥾',
        'default' => '🏃‍♂️'
    ];

    return isset($icons[$activity_type]) ? $icons[$activity_type] : $icons['default'];
}

/**
 * Calculate pace from speed (m/s to min/km)
 */
function speed_to_pace($speed_ms)
{
    if (!$speed_ms || $speed_ms == 0)
        return 0;

    return 1000 / $speed_ms; // seconds per km
}

/**
 * Get score color class
 */
function get_score_color_class($score)
{
    if ($score >= 8)
        return 'score-excellent';
    if ($score >= 6)
        return 'score-good';
    if ($score >= 4)
        return 'score-average';
    return 'score-poor';
}

/**
 * Get coach's mentees
 */
function get_coach_mentees($coach_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'coach_mentee_relationships';

    $mentees = $wpdb->get_results($wpdb->prepare(
        "SELECT mentee_id FROM $table_name WHERE coach_id = %d AND status = 'active'",
        $coach_id
    ));

    $mentee_ids = array_column($mentees, 'mentee_id');

    if (empty($mentee_ids)) {
        return [];
    }

    return get_users([
        'include' => $mentee_ids,
        'fields' => 'all'
    ]);
}

/**
 * Get mentee's coach
 */
function get_mentee_coach($mentee_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'coach_mentee_relationships';

    $coach_id = $wpdb->get_var($wpdb->prepare(
        "SELECT coach_id FROM $table_name WHERE mentee_id = %d AND status = 'active'",
        $mentee_id
    ));

    return $coach_id ? get_user_by('id', $coach_id) : null;
}