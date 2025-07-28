<?php
class ACS_Dashboards {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_dashboards']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_shortcode('acs_mentee_dashboard', [__CLASS__, 'mentee_dashboard_shortcode']);
        add_shortcode('acs_coach_dashboard', [__CLASS__, 'coach_dashboard_shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_frontend_assets']);
        add_action('wp_head', [__CLASS__, 'enqueue_frontend_assets_fallback']);
    }

    public static function register_dashboards() {
        // Admin menu for mentee dashboard (legacy/admin use)
        if (current_user_can('acs_mentee')) {
            add_menu_page(
                'Mentee Dashboard',
                'Mentee Dashboard',
                'view_mentee_dashboard',
                'acs-mentee-dashboard',
                [__CLASS__, 'mentee_dashboard_html'],
                'dashicons-heart',
                6
            );
        }
        // Admin menu for coach dashboard (optional)
        if (current_user_can('acs_coach')) {
            add_menu_page(
                'Coach Dashboard',
                'Coach Dashboard',
                'view_coach_dashboard',
                'acs-coach-dashboard',
                [__CLASS__, 'coach_dashboard_html'],
                'dashicons-groups',
                7
            );
        }
    }

    public static function enqueue_assets($hook) {
        // Only load on our dashboard pages
        if (isset($_GET['page']) && (strpos($_GET['page'], 'acs-mentee-dashboard') !== false || strpos($_GET['page'], 'acs-coach-dashboard') !== false)) {
            wp_enqueue_style('acs-dashboard', ACS_ASSETS_URL . 'css/dashboard.css', [], ACS_VERSION);
            
            // Enqueue jQuery first
            wp_enqueue_script('jquery');
            
            // Enqueue Chart.js
            wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', ['jquery'], '4.4.0', true);
            
            // Enqueue our dashboard script with proper dependencies
            wp_enqueue_script('acs-dashboard', ACS_ASSETS_URL . 'js/dashboard.js', ['jquery', 'chartjs'], ACS_VERSION, true);
            
            // Localize AJAX data
            wp_localize_script('acs-dashboard', 'acsAjaxData', [
                'nonce' => wp_create_nonce('acs_ajax_nonce'),
                'ajaxurl' => admin_url('admin-ajax.php')
            ]);
            // Localize feedback labels
            $feedback_labels = [];
            for ($i = 1; $i <= 4; $i++) {
                $feedback_labels[] = get_option('acs_feedback_label_' . $i, 'Feedback Field ' . $i);
            }
            wp_localize_script('acs-dashboard', 'acsFeedbackLabels', $feedback_labels);
        }
    }

    public static function enqueue_frontend_assets() {
        // Always enqueue assets on frontend for better compatibility
        // This ensures the JavaScript functions are available when shortcodes are used
        wp_enqueue_style('acs-dashboard', ACS_ASSETS_URL . 'css/dashboard.css', [], ACS_VERSION);
        
        // Enqueue jQuery first
        wp_enqueue_script('jquery');
        
        // Enqueue Chart.js
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', ['jquery'], '4.4.0', true);
        
        // Enqueue our dashboard script with proper dependencies
        wp_enqueue_script('acs-dashboard', ACS_ASSETS_URL . 'js/dashboard.js', ['jquery', 'chartjs'], ACS_VERSION, true);
        
        // Localize AJAX data
        wp_localize_script('acs-dashboard', 'acsAjaxData', [
            'nonce' => wp_create_nonce('acs_ajax_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ]);
        // Localize feedback labels
        $feedback_labels = [];
        for ($i = 1; $i <= 4; $i++) {
            $feedback_labels[] = get_option('acs_feedback_label_' . $i, 'Feedback Field ' . $i);
        }
        wp_localize_script('acs-dashboard', 'acsFeedbackLabels', $feedback_labels);
    }

    public static function enqueue_frontend_assets_fallback() {
        // Fallback method to ensure assets are loaded
        // This is called on wp_head to catch any missed enqueues
        if (!wp_script_is('acs-dashboard', 'enqueued')) {
            wp_enqueue_style('acs-dashboard', ACS_ASSETS_URL . 'css/dashboard.css', [], ACS_VERSION);
            wp_enqueue_script('jquery');
            wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', ['jquery'], '4.4.0', true);
            wp_enqueue_script('acs-dashboard', ACS_ASSETS_URL . 'js/dashboard.js', ['jquery', 'chartjs'], ACS_VERSION, true);
            wp_localize_script('acs-dashboard', 'acsAjaxData', [
                'nonce' => wp_create_nonce('acs_ajax_nonce'),
                'ajaxurl' => admin_url('admin-ajax.php')
            ]);
        }
    }

    // === Shortcode callbacks ===
    public static function mentee_dashboard_shortcode($atts) {
        // Force enqueue assets for shortcode
        self::enqueue_frontend_assets();
        
        ob_start();
        self::mentee_dashboard_html(true);
        return ob_get_clean();
    }
    public static function coach_dashboard_shortcode($atts) {
        // Force enqueue assets for shortcode
        self::enqueue_frontend_assets();
        
        ob_start();
        self::coach_dashboard_html(true);
        return ob_get_clean();
    }

    // === Dashboard HTML (shared for admin and frontend) ===
    public static function mentee_dashboard_html($frontend = false) {
        if (!is_user_logged_in() || !current_user_can('acs_mentee')) {
            echo '<div class="acs-card">You do not have permission to view this page.</div>';
            return;
        }
        $user_id = get_current_user_id();
        // Handle OAuth callback in frontend context
        if ($frontend && isset($_GET['acs_strava_oauth']) && isset($_GET['code']) && isset($_GET['state'])) {
            $result = self::handle_strava_oauth_callback($user_id);
            echo '<div class="acs-card">' . esc_html($result) . '</div>';
        }
        $is_connected = self::is_strava_connected($user_id);
        echo '<div class="acs-card">';
        echo '<h2 class="acs-heading">Mentee Dashboard</h2>';
        if ($is_connected) {
            echo '<p><strong>Status:</strong> <span style="color:green">Connected to Strava</span></p>';
            // Analytics section
            self::show_analytics_section($user_id);
            // Training plans section
            self::show_mentee_training_plans($user_id);
            // Sync Now button
            $sync_url = add_query_arg('acs_strava_sync', '1');
            echo '<form method="post" style="margin-bottom:1rem;">';
            wp_nonce_field('acs_strava_sync_' . $user_id, 'acs_strava_sync_nonce');
            echo '<button type="submit" class="acs-btn-primary">Sync Now</button>';
            echo '</form>';
            // Handle sync request
            if (isset($_POST['acs_strava_sync_nonce']) && wp_verify_nonce($_POST['acs_strava_sync_nonce'], 'acs_strava_sync_' . $user_id)) {
                $msg = self::sync_strava_activities($user_id);
                echo '<div style="margin-bottom:1rem;color:#ff6124;">' . esc_html($msg) . '</div>';
            }
            // Show activities table
            self::show_activities_table($user_id);
        } else {
            $oauth_url = self::get_strava_oauth_url($user_id, $frontend);
            echo '<a href="' . esc_url($oauth_url) . '" class="acs-btn-primary">Connect with Strava</a>';
        }
        echo '</div>';
    }

    public static function coach_dashboard_html($frontend = false) {
        if (!is_user_logged_in() || !current_user_can('acs_coach')) {
            echo '<div class="acs-card">You do not have permission to view this page.</div>';
            return;
        }
        $coach_id = get_current_user_id();
        echo '<div class="acs-card">';
        echo '<h2 class="acs-heading">Coach Dashboard</h2>';
        
        // Handle form submissions
        if (isset($_POST['acs_action'])) {
            switch ($_POST['acs_action']) {
                case 'assign_mentee':
                    self::handle_assign_mentee($coach_id);
                    break;
                case 'create_plan':
                    self::handle_create_plan($coach_id);
                    break;
                case 'score_mentee':
                    self::handle_score_mentee($coach_id);
                    break;
            }
        }
        
        // Mentee Analytics Section (in-page)
        global $wpdb;
        $mentees_table = $wpdb->prefix . 'acs_coach_mentees';
        // Updated query to fetch user_email and assigned_at
        $assigned_mentees = $wpdb->get_results($wpdb->prepare(
            "SELECT m.mentee_id, m.assigned_at, u.display_name, u.user_email FROM $mentees_table m JOIN {$wpdb->users} u ON m.mentee_id = u.ID WHERE m.coach_id = %d ORDER BY u.display_name ASC",
            $coach_id
        ));
        echo '<div id="mentee-analytics-section" style="margin-bottom:2rem;">';
        echo '<h3 class="acs-heading" style="font-size:1.3rem;">Mentee Analytics</h3>';
        // Flex row for dropdown and button
        echo '<div style="margin-bottom:1rem; display: flex; gap: 1rem; align-items: flex-end;">';
        echo '<div style="flex:1;">';
        echo '<label for="mentee-analytics-select" class="acs-label" style="margin-right:0.5rem;">Select Mentee:</label>';
        echo '<select id="mentee-analytics-select" style="min-width:200px; width:100%;">';
        echo '<option value="">-- Please select a mentee --</option>';
        foreach ($assigned_mentees as $mentee) {
            echo '<option value="' . esc_attr($mentee->mentee_id) . '">' . esc_html($mentee->display_name) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<button id="mentee-analytics-apply" class="acs-btn-primary acs-apply-analytics-btn" style="margin-left: 0;">Apply</button>';
        echo '</div>';
        echo '<div id="mentee-analytics-content" style="padding:1rem;background:#f8f9fa;border-radius:8px;">';
        echo '<p style="margin:0;">Please select a mentee to display the data.</p>';
        echo '</div>';
        echo '</div>';
        
        // Show mentee management section (remove View Analytics button)
        echo '<div style="margin-bottom:2rem;">';
        echo '<h3 class="acs-heading" style="font-size:1.3rem;">Mentee Management</h3>';
        // Assign new mentee form
        echo '<div style="background:#f8f9fa;padding:1rem;border-radius:8px;margin-bottom:1rem;">';
        echo '<h4 style="margin-top:0;">Assign New Mentee</h4>';
        echo '<form method="post" data-action="assign_mentee" style="display:flex;gap:1rem;align-items:end;">';
        wp_nonce_field('acs_assign_mentee_' . $coach_id, 'acs_assign_nonce');
        echo '<input type="hidden" name="acs_action" value="assign_mentee">';
        
        // Get all users with mentee role
        $mentee_users = get_users(['role' => 'acs_mentee']);
        echo '<select name="mentee_id" required style="flex:1;">';
        echo '<option value="">Select a mentee...</option>';
        foreach ($mentee_users as $user) {
            $is_assigned = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $mentees_table WHERE coach_id = %d AND mentee_id = %d",
                $coach_id, $user->ID
            ));
            if (!$is_assigned) {
                echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</option>';
            }
        }
        echo '</select>';
        echo '<button type="submit" class="acs-btn-primary">Assign Mentee</button>';
        echo '</form>';
        echo '</div>';
        
        // Show assigned mentees (remove View Analytics button)
        if ($assigned_mentees) {
            echo '<table style="width:100%;border-collapse:collapse;">';
            echo '<thead><tr style="background:#fafbfc;"><th>Mentee</th><th>Email</th><th>Assigned</th><th>Actions</th></tr></thead><tbody>';
            foreach ($assigned_mentees as $mentee) {
                echo '<tr style="border-bottom:1px solid #eee;" id="mentee-row-' . esc_attr($mentee->mentee_id) . '">';
                echo '<td>' . esc_html($mentee->display_name) . '</td>';
                echo '<td>' . esc_html($mentee->user_email) . '</td>';
                echo '<td>' . esc_html($mentee->assigned_at ? date('Y-m-d', strtotime($mentee->assigned_at)) : '-') . '</td>';
                echo '<td>';
                echo '<button class="acs-btn-primary acs-remove-mentee-btn" data-mentee-id="' . $mentee->mentee_id . '" style="padding:0.25rem 0.5rem;font-size:0.8rem;background:#dc3545;">Remove Mentee</button>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No mentees assigned yet. Use the form above to assign mentees.</p>';
        }
        echo '</div>';
        
        // Show training plan creation
        self::show_plan_creation($coach_id);
        // Show mentee performance overview
        self::show_mentee_performance($coach_id);
        // After Mentee Performance section
        echo '<div style="margin-bottom:2rem;">';
        echo '<h3 class="acs-heading" style="font-size:1.3rem;">View Mentee Plans</h3>';
        // Get all mentees assigned to this coach
        $mentees_table = $wpdb->prefix . 'acs_coach_mentees';
        $users_table = $wpdb->users;
        $assigned_mentees = $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.display_name FROM $mentees_table m JOIN $users_table u ON m.mentee_id = u.ID WHERE m.coach_id = %d ORDER BY u.display_name ASC",
            $coach_id
        ));
        echo '<form id="view-mentee-plans-form" style="display:flex;gap:1rem;align-items:end;margin-bottom:1rem;">';
        echo '<div><label class="acs-label">Mentee:</label><select id="vmp-mentee" name="mentee_id" required><option value="">Select mentee...</option>';
        foreach ($assigned_mentees as $mentee) {
            echo '<option value="' . esc_attr($mentee->ID) . '">' . esc_html($mentee->display_name) . '</option>';
        }
        echo '</select></div>';
        echo '<div><label class="acs-label">Plan:</label><select id="vmp-plan" name="plan_id" required disabled><option value="">Select plan...</option></select></div>';
        echo '<button type="submit" class="acs-btn-primary">View Plan</button>';
        echo '</form>';
        echo '<div id="vmp-plan-details"></div>';
        echo '</div>';
        // Add inline JS for dynamic plan loading
        echo <<<EOT
<script>
document.getElementById("vmp-mentee").addEventListener("change", function() {
    var menteeId = this.value;
    var planSelect = document.getElementById("vmp-plan");
    planSelect.innerHTML = "<option value=\"\">Select plan...</option>";
    planSelect.disabled = true;
    if (!menteeId) return;
    fetch(acsAjaxData.ajaxurl + "?action=acs_get_mentee_plans&mentee_id=" + menteeId + "&nonce=" + acsAjaxData.nonce)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data.length) {
                data.data.forEach(function(plan) {
                    var opt = document.createElement("option");
                    opt.value = plan.id;
                    opt.textContent = plan.plan_title + " (" + plan.week_start + ")";
                    planSelect.appendChild(opt);
                });
                planSelect.disabled = false;
            }
        });
});
document.getElementById("view-mentee-plans-form").addEventListener("submit", function(e) {
    e.preventDefault();
    var menteeId = document.getElementById("vmp-mentee").value;
    var planId = document.getElementById("vmp-plan").value;
    var detailsDiv = document.getElementById("vmp-plan-details");
    detailsDiv.innerHTML = "";
    if (!menteeId || !planId) return;
    fetch(acsAjaxData.ajaxurl + "?action=acs_get_full_plan&mentee_id=" + menteeId + "&plan_id=" + planId + "&nonce=" + acsAjaxData.nonce)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data) {
                var plan = data.data.plan;
                var activities = data.data.activities;
                var html = `<div style='margin-bottom:1rem;'><strong>Week:</strong> \${plan.week_start} - \${plan.week_end}<br><strong>Title:</strong> \${plan.plan_title}<br><strong>Notes:</strong> \${plan.plan_notes || '-'}<br></div>`;
                if (activities.length) {
                    html += `<table style='width:100%;border-collapse:collapse;'><thead><tr style='background:#fafbfc;'><th>Type</th><th>Distance (km)</th><th>Duration (min)</th><th>Pace (min/km)</th><th>Notes</th></tr></thead><tbody>`;
                    activities.forEach(function(act) {
                        html += `<tr style='border-bottom:1px solid #eee;'><td>\${act.activity_type}</td><td>\${act.target_distance}</td><td>\${act.target_duration}</td><td>\${act.target_pace}</td><td>\${act.notes || '-'}</td></tr>`;
                    });
                    html += '</tbody></table>';
                } else {
                    html += '<p>No activities found for this plan.</p>';
                }
                detailsDiv.innerHTML = html;
            } else {
                detailsDiv.innerHTML = '<p style="color:red;">Plan not found.</p>';
            }
        });
});
</script>
EOT;
        echo '</div>';
    }

    private static function is_strava_connected($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'acs_api_users';
        $provider = 'strava';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d AND provider = %s", $user_id, $provider));
        return $row && !empty($row->access_token);
    }

    private static function get_strava_oauth_url($user_id, $frontend = false) {
        $client_id = get_option('acs_strava_client_id', '');
        if ($frontend) {
            $redirect_uri = add_query_arg('acs_strava_oauth', '1', get_permalink());
        } else {
            $redirect_uri = admin_url('admin.php?page=acs-mentee-dashboard&acs_strava_oauth=1');
        }
        $state = wp_create_nonce('acs_strava_oauth_' . $user_id);
        $scopes = 'read,activity:read_all,profile:read_all';
        $params = [
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => $scopes,
            'state' => $state,
            'approval_prompt' => 'auto',
        ];
        $url = 'https://www.strava.com/oauth/authorize?' . http_build_query($params);
        return $url;
    }

    // === OAuth callback handler ===
    private static function handle_strava_oauth_callback($user_id) {
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            return 'Missing OAuth parameters.';
        }
        if (!wp_verify_nonce($_GET['state'], 'acs_strava_oauth_' . $user_id)) {
            return 'Invalid OAuth state.';
        }
        $client_id = get_option('acs_strava_client_id', '');
        $client_secret = get_option('acs_strava_client_secret', '');
        $code = sanitize_text_field($_GET['code']);
        $token_url = 'https://www.strava.com/oauth/token';
        $response = wp_remote_post($token_url, [
            'body' => [
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'code' => $code,
                'grant_type' => 'authorization_code',
            ],
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            return 'Error connecting to Strava: ' . $response->get_error_message();
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            return 'Failed to retrieve access token from Strava.';
        }
        // Store tokens in acs_api_users
        global $wpdb;
        $table = $wpdb->prefix . 'acs_api_users';
        $provider = 'strava';
        $access_token = sanitize_text_field($body['access_token']);
        $refresh_token = sanitize_text_field($body['refresh_token']);
        $expires_at = isset($body['expires_at']) ? date('Y-m-d H:i:s', $body['expires_at']) : null;
        // Upsert
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d AND provider = %s", $user_id, $provider));
        if ($existing) {
            $wpdb->update($table, [
                'access_token' => $access_token,
                'refresh_token' => $refresh_token,
                'expires_at' => $expires_at,
                'updated_at' => current_time('mysql'),
            ], [
                'id' => $existing
            ]);
        } else {
            $wpdb->insert($table, [
                'user_id' => $user_id,
                'provider' => $provider,
                'access_token' => $access_token,
                'refresh_token' => $refresh_token,
                'expires_at' => $expires_at,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
        }
        return 'Strava account connected successfully! You may now close this page.';
    }

    private static function sync_strava_activities($user_id) {
        global $wpdb;
        $api_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}acs_api_users WHERE user_id = %d AND provider = %s", $user_id, 'strava'));
        if (!$api_row || empty($api_row->access_token)) {
            return 'No Strava connection found.';
        }
        $access_token = $api_row->access_token;
        $refresh_token = $api_row->refresh_token;
        $expires_at = strtotime($api_row->expires_at);
        $client_id = get_option('acs_strava_client_id', '');
        $client_secret = get_option('acs_strava_client_secret', '');
        // Refresh token if expired
        if (time() > $expires_at) {
            $token_url = 'https://www.strava.com/oauth/token';
            $response = wp_remote_post($token_url, [
                'body' => [
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh_token,
                ],
                'timeout' => 15,
            ]);
            if (is_wp_error($response)) {
                return 'Failed to refresh token: ' . $response->get_error_message();
            }
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($body['access_token'])) {
                return 'Failed to refresh access token.';
            }
            $access_token = sanitize_text_field($body['access_token']);
            $refresh_token = sanitize_text_field($body['refresh_token']);
            $expires_at = isset($body['expires_at']) ? date('Y-m-d H:i:s', $body['expires_at']) : null;
            // Update DB
            $wpdb->update($wpdb->prefix . 'acs_api_users', [
                'access_token' => $access_token,
                'refresh_token' => $refresh_token,
                'expires_at' => $expires_at,
                'updated_at' => current_time('mysql'),
            ], [
                'id' => $api_row->id
            ]);
        }
        // Fetch activities (last 30 days)
        $after = strtotime('-30 days');
        $url = add_query_arg([
            'after' => $after,
            'per_page' => 50,
        ], 'https://www.strava.com/api/v3/athlete/activities');
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) {
            return 'Failed to fetch activities: ' . $response->get_error_message();
        }
        $activities = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($activities)) {
            return 'No activities found or error in response.';
        }
        $table = $wpdb->prefix . 'acs_activities_cache';
        $count = 0;
        foreach ($activities as $act) {
            $activity_id = sanitize_text_field($act['id']);
            $activity_type = sanitize_text_field($act['type']);
            $distance = floatval($act['distance']) / 1000; // meters to km
            $duration = floatval($act['elapsed_time']) / 60; // seconds to min
            $elevation = isset($act['total_elevation_gain']) ? floatval($act['total_elevation_gain']) : 0;
            $pace = ($distance > 0) ? $duration / $distance : 0;
            $start_time = isset($act['start_date']) ? date('Y-m-d H:i:s', strtotime($act['start_date'])) : null;
            $raw_data = wp_json_encode($act);
            $synced_at = current_time('mysql');
            // Upsert by activity_id
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d AND activity_id = %s", $user_id, $activity_id));
            if ($existing) {
                $wpdb->update($table, [
                    'activity_type' => $activity_type,
                    'distance' => $distance,
                    'duration' => $duration,
                    'elevation' => $elevation,
                    'pace' => $pace,
                    'start_time' => $start_time,
                    'raw_data' => $raw_data,
                    'synced_at' => $synced_at,
                ], [
                    'id' => $existing
                ]);
            } else {
                $wpdb->insert($table, [
                    'user_id' => $user_id,
                    'activity_id' => $activity_id,
                    'activity_type' => $activity_type,
                    'distance' => $distance,
                    'duration' => $duration,
                    'elevation' => $elevation,
                    'pace' => $pace,
                    'start_time' => $start_time,
                    'raw_data' => $raw_data,
                    'synced_at' => $synced_at,
                ]);
            }
            $count++;
        }
        return $count . ' activities synced.';
    }

    private static function show_activities_table($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'acs_activities_cache';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY start_time DESC LIMIT 20", $user_id));
        if (!$rows) {
            echo '<div>No recent activities found. Click Sync Now to fetch your latest Strava activities.</div>';
            return;
        }
        echo '<table style="width:100%;border-collapse:collapse;margin-top:1rem;">';
        echo '<thead><tr style="background:#fafbfc;"><th>Date</th><th>Type</th><th>Distance (km)</th><th>Duration (min)</th><th>Pace (min/km)</th><th>Elevation (m)</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr style="border-bottom:1px solid #eee;">';
            echo '<td>' . esc_html(date('Y-m-d', strtotime($row->start_time))) . '</td>';
            echo '<td>' . esc_html($row->activity_type) . '</td>';
            echo '<td>' . esc_html(number_format($row->distance, 2)) . '</td>';
            echo '<td>' . esc_html(number_format($row->duration, 1)) . '</td>';
            echo '<td>' . esc_html(number_format($row->pace, 2)) . '</td>';
            echo '<td>' . esc_html(number_format($row->elevation, 1)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function show_analytics_section($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'acs_activities_cache';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY start_time ASC", $user_id));
        if (!$rows) {
            echo '<div>No activity data for analytics. Please sync your Strava activities.</div>';
            return;
        }
        // Prepare data for JS
        $dates = [];
        $distances = [];
        $paces = [];
        $types = [];
        foreach ($rows as $row) {
            $date = date('Y-m-d', strtotime($row->start_time));
            $dates[] = $date;
            $distances[] = floatval($row->distance);
            $paces[] = floatval($row->pace);
            $types[] = $row->activity_type;
        }
        // Activity type breakdown
        $type_counts = array_count_values($types);
        $type_labels = array_keys($type_counts);
        $type_data = array_values($type_counts);
        // Output chart canvases
        echo '<div style="margin-bottom:2rem;">';
        echo '<h3 class="acs-heading" style="font-size:1.3rem;">Analytics</h3>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:2rem;">';
        echo '<div style="flex:1;min-width:300px;"><canvas id="acsDistanceChart"></canvas></div>';
        echo '<div style="flex:1;min-width:300px;"><canvas id="acsTypeChart"></canvas></div>';
        echo '<div style="flex:1;min-width:300px;"><canvas id="acsPaceChart"></canvas></div>';
        echo '</div>';
        echo '</div>';
        // Pass data to JS
        $chart_data = [
            'dates' => $dates,
            'distances' => $distances,
            'paces' => $paces,
            'type_labels' => $type_labels,
            'type_data' => $type_data,
        ];
        wp_localize_script('acs-dashboard', 'acsChartData', $chart_data);
    }

    private static function show_mentee_training_plans($user_id) {
        global $wpdb;
        $plans_table = $wpdb->prefix . 'acs_weekly_plans';
        $activities_table = $wpdb->prefix . 'acs_plan_activities';
        $scores_table = $wpdb->prefix . 'acs_weekly_scores';

        // Get all plans for this mentee, most recent first
        $plans = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $plans_table WHERE mentee_id = %d ORDER BY week_start DESC",
            $user_id
        ));

        echo '<div style="margin-bottom:2rem;">';
        echo '<h3 class="acs-heading" style="font-size:1.3rem;">Your Training Plans</h3>';

        if (!$plans) {
            echo '<p>No training plans assigned yet. Your coach will create plans for you.</p>';
            echo '</div>';
            return;
        }

        // Plan selection dropdown if more than one
        $selected_plan_id = isset($_GET['acs_plan_id']) ? intval($_GET['acs_plan_id']) : $plans[0]->id;
        if (count($plans) > 1) {
            echo '<form method="get" style="margin-bottom:1rem;">';
            echo '<label for="acs-plan-select" class="acs-label" style="margin-right:0.5rem;">Select Plan:</label>';
            echo '<select id="acs-plan-select" name="acs_plan_id" onchange="this.form.submit()" style="min-width:200px;">';
            foreach ($plans as $plan) {
                $label = esc_html($plan->plan_title . ' (' . date('M j', strtotime($plan->week_start)) . ' - ' . date('M j', strtotime($plan->week_end)) . ')');
                $selected = $selected_plan_id == $plan->id ? 'selected' : '';
                echo "<option value='{$plan->id}' $selected>$label</option>";
            }
            echo '</select>';
            echo '</form>';
        }

        // Get selected plan
        $plan = null;
        foreach ($plans as $p) {
            if ($p->id == $selected_plan_id) {
                $plan = $p;
                break;
            }
        }
        if (!$plan) {
            echo '<p>Plan not found.</p>';
            echo '</div>';
            return;
        }

        // Get activities for this plan (order by id, which matches day order)
        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $activities_table WHERE plan_id = %d ORDER BY id ASC",
            $plan->id
        ));

        // Prepare day labels
        $days = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
        $week_start = strtotime($plan->week_start);

        echo '<div style="margin-bottom:1rem;"><strong>Week:</strong> ' . esc_html(date('M j', $week_start)) . ' - ' . esc_html(date('M j', strtotime($plan->week_end))) . '<br><strong>Title:</strong> ' . esc_html($plan->plan_title) . '<br><strong>Notes:</strong> ' . esc_html($plan->plan_notes ?: '-') . '</div>';

        // Table header
        echo '<table style="width:100%;border-collapse:collapse;">';
        echo '<thead><tr style="background:#fafbfc;"><th>Day</th><th>Activity Type</th><th>Distance (km)</th><th>Duration (min)</th><th>Pace (min/km)</th><th>Notes</th></tr></thead><tbody>';
        for ($i = 0; $i < 7; $i++) {
            $label = $days[date('w', strtotime("+{$i} days", $week_start))] . ' (' . date('M j', strtotime("+{$i} days", $week_start)) . ')';
            $activity = isset($activities[$i]) ? $activities[$i] : null;
            echo '<tr style="border-bottom:1px solid #eee;">';
            echo '<td>' . esc_html($label) . '</td>';
            echo '<td>' . esc_html($activity ? $activity->activity_type : '-') . '</td>';
            echo '<td>' . esc_html($activity ? number_format($activity->target_distance, 1) : '-') . '</td>';
            echo '<td>' . esc_html($activity ? number_format($activity->target_duration, 1) : '-') . '</td>';
            echo '<td>' . esc_html($activity ? number_format($activity->target_pace, 1) : '-') . '</td>';
            echo '<td>' . esc_html($activity ? $activity->notes : '-') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Get score/feedback for this plan
        $score = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $scores_table WHERE plan_id = %d",
            $plan->id
        ));
        // Metric labels
        $default_metrics = ['Pace', 'Distance', 'Consistency', 'Elevation'];
        $metric_labels = [];
        for ($i = 1; $i <= 4; $i++) {
            $metric_labels[] = get_option('acs_feedback_label_' . $i, $default_metrics[$i-1]);
        }
        echo '<div style="margin-top:1.5rem;padding:1rem;background:#f8f9fa;border-radius:8px;">';
        echo '<h4 style="margin-top:0;">Coach Feedback & Scores</h4>';
        if ($score) {
            echo '<ul style="list-style:none;padding:0;">';
            echo '<li><strong>' . esc_html($metric_labels[0]) . ':</strong> ' . esc_html($score->pace_score) . ' / 10</li>';
            echo '<li><strong>' . esc_html($metric_labels[1]) . ':</strong> ' . esc_html($score->distance_score) . ' / 10</li>';
            echo '<li><strong>' . esc_html($metric_labels[2]) . ':</strong> ' . esc_html($score->consistency_score) . ' / 10</li>';
            echo '<li><strong>' . esc_html($metric_labels[3]) . ':</strong> ' . esc_html($score->elevation_score) . ' / 10</li>';
            echo '<li><strong>Overall Score:</strong> ' . esc_html($score->score) . ' / 10</li>';
            echo '<li><strong>Coach Feedback:</strong> ' . esc_html($score->feedback ?: '-') . '</li>';
            echo '</ul>';
        } else {
            echo '<p>No score or feedback provided by your coach yet.</p>';
        }
        echo '</div>';
        echo '</div>';
    }

    private static function show_mentee_management($coach_id) {
        global $wpdb;
        $mentees_table = $wpdb->prefix . 'acs_coach_mentees';
        
        // Get assigned mentees
        $assigned_mentees = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, u.display_name, u.user_email 
             FROM $mentees_table m 
             JOIN {$wpdb->users} u ON m.mentee_id = u.ID 
             WHERE m.coach_id = %d 
             ORDER BY m.assigned_at DESC",
            $coach_id
        ));
        
        echo '<div style="margin-bottom:2rem;">';
        echo '<h3 class="acs-heading" style="font-size:1.3rem;">Mentee Management</h3>';
        
        // Assign new mentee form
        echo '<div style="background:#f8f9fa;padding:1rem;border-radius:8px;margin-bottom:1rem;">';
        echo '<h4 style="margin-top:0;">Assign New Mentee</h4>';
        echo '<form method="post" data-action="assign_mentee" style="display:flex;gap:1rem;align-items:end;">';
        wp_nonce_field('acs_assign_mentee_' . $coach_id, 'acs_assign_nonce');
        echo '<input type="hidden" name="acs_action" value="assign_mentee">';
        
        // Get all users with mentee role
        $mentee_users = get_users(['role' => 'acs_mentee']);
        echo '<select name="mentee_id" required style="flex:1;">';
        echo '<option value="">Select a mentee...</option>';
        foreach ($mentee_users as $user) {
            $is_assigned = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $mentees_table WHERE coach_id = %d AND mentee_id = %d",
                $coach_id, $user->ID
            ));
            if (!$is_assigned) {
                echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</option>';
            }
        }
        echo '</select>';
        echo '<button type="submit" class="acs-btn-primary">Assign Mentee</button>';
        echo '</form>';
        echo '</div>';
        
        // Show assigned mentees (remove View Analytics button)
        if ($assigned_mentees) {
            echo '<table style="width:100%;border-collapse:collapse;">';
            echo '<thead><tr style="background:#fafbfc;"><th>Mentee</th><th>Email</th><th>Assigned</th><th>Actions</th></tr></thead><tbody>';
            foreach ($assigned_mentees as $mentee) {
                echo '<tr style="border-bottom:1px solid #eee;" id="mentee-row-' . esc_attr($mentee->mentee_id) . '">';
                echo '<td>' . esc_html($mentee->display_name) . '</td>';
                echo '<td>' . esc_html($mentee->user_email) . '</td>';
                echo '<td>' . esc_html($mentee->assigned_at ? date('Y-m-d', strtotime($mentee->assigned_at)) : '-') . '</td>';
                echo '<td>';
                echo '<button class="acs-btn-primary acs-remove-mentee-btn" data-mentee-id="' . $mentee->mentee_id . '" style="padding:0.25rem 0.5rem;font-size:0.8rem;background:#dc3545;">Remove Mentee</button>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No mentees assigned yet. Use the form above to assign mentees.</p>';
        }
        echo '</div>';
    }

    private static function show_plan_creation($coach_id) {
        echo '<div style="margin-bottom:2rem;">';
        echo '<h3 class="acs-heading" style="font-size:1.3rem;">Create Training Plan</h3>';
        echo '<form method="post" data-action="create_plan" id="acs-create-plan-form" style="background:#f8f9fa;padding:1rem;border-radius:8px;">';
        wp_nonce_field('acs_create_plan_' . $coach_id, 'acs_plan_nonce');
        echo '<input type="hidden" name="acs_action" value="create_plan">';
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">';
        echo '<div>';
        echo '<label class="acs-label">Mentee:</label>';
        echo '<select name="mentee_id" required>';
        echo '<option value="">Select mentee...</option>';
        global $wpdb;
        $mentees = $wpdb->get_results($wpdb->prepare(
            "SELECT m.mentee_id, u.display_name 
             FROM {$wpdb->prefix}acs_coach_mentees m 
             JOIN {$wpdb->users} u ON m.mentee_id = u.ID 
             WHERE m.coach_id = %d",
            $coach_id
        ));
        foreach ($mentees as $mentee) {
            echo '<option value="' . esc_attr($mentee->mentee_id) . '">' . esc_html($mentee->display_name) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<div>';
        echo '<label class="acs-label">Week Starting:</label>';
        echo '<input type="date" name="week_start" id="acs-week-start" required>';
        echo '</div>';
        echo '</div>';
        echo '<div style="margin-bottom:1rem;">';
        echo '<label class="acs-label">Plan Title:</label>';
        echo '<input type="text" name="plan_title" required placeholder="e.g., Week 1 - Building Endurance">';
        echo '</div>';
        echo '<div style="margin-bottom:1rem;">';
        echo '<label class="acs-label">Plan Notes:</label>';
        echo '<textarea name="plan_notes" rows="3" placeholder="General notes for this week..."></textarea>';
        echo '</div>';
        // Activity details for 7 days
        echo '<div style="margin-bottom:1rem;">';
        echo '<h4>Weekly Activities</h4>';
        echo '<div id="acs-activity-days"></div>';
        echo '</div>';
        echo '<button type="submit" class="acs-btn-primary">Create Training Plan</button>';
        echo '</form>';
        // Inline JS to handle dynamic day fields
        echo '<script>';
        echo 'function getWeekDates(startDateStr) {';
        echo '  const days = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
  const result = [];
  if (!startDateStr) return result;
  const start = new Date(startDateStr);
  for (let i = 0; i < 7; i++) {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    result.push({
      date: d.toISOString().slice(0, 10),
      label: days[d.getDay()] + " (" + d.toLocaleDateString(undefined, { month: "short", day: "numeric" }) + ")"
    });
  }
  return result;
}';
        echo 'function renderActivityDays(startDateStr) {';
        echo '  const days = getWeekDates(startDateStr);';
        echo '  let html = "";';
        echo '  days.forEach((day, i) => {';
        echo '    html += `<div>` +';
        echo '      `<label class=\"acs-label\">${day.label}</label>` +';
        echo '      `<input type=\"text\" name=\"activities[${i}][type]\" placeholder=\"Activity type (e.g., Run)\">` +';
        echo '      `<input type=\"number\" step=\"0.1\" name=\"activities[${i}][distance]\" placeholder=\"Distance (km)\">` +';
        echo '      `<input type=\"number\" step=\"0.1\" name=\"activities[${i}][duration]\" placeholder=\"Duration (min)\">` +';
        echo '      `<input type=\"number\" step=\"0.1\" name=\"activities[${i}][pace]\" placeholder=\"Target pace (min/km)\">` +';
        echo '    `</div>`;';
        echo '  });';
        echo '  document.getElementById("acs-activity-days").innerHTML = html;';
        echo '}';
        echo 'document.getElementById("acs-week-start").addEventListener("change", function() {';
        echo '  renderActivityDays(this.value);';
        echo '});';
        echo 'window.addEventListener("DOMContentLoaded", function() {';
        echo '  renderActivityDays(document.getElementById("acs-week-start").value);';
        echo '});';
        echo '</script>';
        echo '</div>';
    }

    private static function show_mentee_performance($coach_id) {
        global $wpdb;
        $plans_table = $wpdb->prefix . 'acs_weekly_plans';
        $scores_table = $wpdb->prefix . 'acs_weekly_scores';
        // Get metric labels
        $default_metrics = ['Pace', 'Distance', 'Consistency', 'Elevation'];
        $metric_labels = [];
        for ($i = 1; $i <= 4; $i++) {
            $metric_labels[] = get_option('acs_feedback_label_' . $i, $default_metrics[$i-1]);
        }
        // Get recent plans and scores (fetch only needed columns)
        $recent_plans = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, u.display_name as mentee_name, s.score, s.pace_score, s.distance_score, s.consistency_score, s.elevation_score, s.feedback
             FROM $plans_table p 
             JOIN {$wpdb->users} u ON p.mentee_id = u.ID 
             LEFT JOIN $scores_table s ON p.id = s.plan_id 
             WHERE p.coach_id = %d 
             ORDER BY p.week_start DESC 
             LIMIT 10",
            $coach_id
        ));
        echo '<div style="margin-bottom:2rem;">';
        echo '<h3 class="acs-heading" style="font-size:1.3rem;">Mentee Performance</h3>';
        if ($recent_plans) {
            echo '<table style="width:100%;border-collapse:collapse;">';
            echo '<thead><tr style="background:#fafbfc;"><th>Mentee</th><th>Week</th><th>Plan</th>';
            foreach ($metric_labels as $label) {
                echo '<th>' . esc_html($label) . '</th>';
            }
            echo '<th>Overall Score</th><th>Coach Notes</th><th>Actions</th></tr></thead><tbody>';
            foreach ($recent_plans as $plan) {
                echo '<tr style="border-bottom:1px solid #eee;">';
                echo '<td>' . esc_html($plan->mentee_name) . '</td>';
                echo '<td>' . esc_html(date('M j', strtotime($plan->week_start))) . '</td>';
                echo '<td>' . esc_html($plan->plan_title) . '</td>';
                echo '<td>' . (isset($plan->pace_score) ? esc_html($plan->pace_score) : '-') . '</td>';
                echo '<td>' . (isset($plan->distance_score) ? esc_html($plan->distance_score) : '-') . '</td>';
                echo '<td>' . (isset($plan->consistency_score) ? esc_html($plan->consistency_score) : '-') . '</td>';
                echo '<td>' . (isset($plan->elevation_score) ? esc_html($plan->elevation_score) : '-') . '</td>';
                echo '<td>' . (isset($plan->score) ? esc_html($plan->score) : 'Not scored') . '</td>';
                echo '<td>' . esc_html($plan->feedback ?: '-') . '</td>';
                echo '<td>';
                if (isset($plan->score)) {
                    echo '<a href="#" class="acs-btn-primary acs-score-btn" data-plan-id="' . $plan->id . '" data-score="' . esc_attr($plan->score) . '" data-pace="' . esc_attr($plan->pace_score) . '" data-distance="' . esc_attr($plan->distance_score) . '" data-consistency="' . esc_attr($plan->consistency_score) . '" data-elevation="' . esc_attr($plan->elevation_score) . '" data-feedback="' . esc_attr($plan->feedback) . '" style="padding:0.25rem 0.5rem;font-size:0.8rem;">Edit Score</a>';
                } else {
                    echo '<a href="#" class="acs-btn-primary acs-score-btn" data-plan-id="' . $plan->id . '" style="padding:0.25rem 0.5rem;font-size:0.8rem;">Score</a>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No training plans created yet. Create a plan to start tracking performance.</p>';
        }
        echo '</div>';
    }

    private static function handle_assign_mentee($coach_id) {
        if (!wp_verify_nonce($_POST['acs_assign_nonce'], 'acs_assign_mentee_' . $coach_id)) {
            return;
        }
        
        $mentee_id = intval($_POST['mentee_id']);
        if (!$mentee_id) return;
        
        global $wpdb;
        $table = $wpdb->prefix . 'acs_coach_mentees';
        
        // Check if already assigned
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE coach_id = %d AND mentee_id = %d",
            $coach_id, $mentee_id
        ));
        
        if (!$existing) {
            $wpdb->insert($table, [
                'coach_id' => $coach_id,
                'mentee_id' => $mentee_id,
                'assigned_at' => current_time('mysql')
            ]);
            echo '<div style="color:green;margin-bottom:1rem;">Mentee assigned successfully!</div>';
        }
    }

    private static function handle_create_plan($coach_id) {
        if (!wp_verify_nonce($_POST['acs_plan_nonce'], 'acs_create_plan_' . $coach_id)) {
            return;
        }
        
        $mentee_id = intval($_POST['mentee_id']);
        $week_start = sanitize_text_field($_POST['week_start']);
        $plan_title = sanitize_text_field($_POST['plan_title']);
        $plan_notes = sanitize_textarea_field($_POST['plan_notes']);
        $activities = isset($_POST['activities']) ? $_POST['activities'] : [];
        
        if (!$mentee_id || !$week_start || !$plan_title) return;
        
        global $wpdb;
        $plans_table = $wpdb->prefix . 'acs_weekly_plans';
        $activities_table = $wpdb->prefix . 'acs_plan_activities';
        
        // Calculate week end
        $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
        
        // Insert plan
        $plan_id = $wpdb->insert($plans_table, [
            'mentee_id' => $mentee_id,
            'coach_id' => $coach_id,
            'week_start' => $week_start,
            'week_end' => $week_end,
            'plan_title' => $plan_title,
            'plan_notes' => $plan_notes,
            'created_at' => current_time('mysql')
        ]);
        
        if ($plan_id) {
            // Insert activities
            foreach ($activities as $activity) {
                if (!empty($activity['type'])) {
                    $wpdb->insert($activities_table, [
                        'plan_id' => $plan_id,
                        'activity_type' => sanitize_text_field($activity['type']),
                        'target_distance' => floatval($activity['distance']),
                        'target_duration' => floatval($activity['duration']),
                        'target_pace' => floatval($activity['pace']),
                        'notes' => ''
                    ]);
                }
            }
            echo '<div style="color:green;margin-bottom:1rem;">Training plan created successfully!</div>';
        }
    }

    private static function handle_score_mentee($coach_id) {
        // This will be implemented with AJAX for better UX
        return;
    }
}

// Add AJAX handler for removing mentee
add_action('wp_ajax_acs_remove_mentee', function() {
    if (!wp_verify_nonce($_POST['nonce'], 'acs_ajax_nonce') || !current_user_can('acs_coach')) {
        wp_send_json_error('Unauthorized');
    }
    $coach_id = get_current_user_id();
    $mentee_id = intval($_POST['mentee_id']);
    if (!$mentee_id) {
        wp_send_json_error('Invalid mentee ID');
    }
    global $wpdb;
    $table = $wpdb->prefix . 'acs_coach_mentees';
    $deleted = $wpdb->delete($table, [
        'coach_id' => $coach_id,
        'mentee_id' => $mentee_id
    ]);
    if ($deleted) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to remove mentee');
    }
});
