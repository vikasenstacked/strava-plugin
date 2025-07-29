<?php
class ACS_Ajax {
    public static function init() {
        // Coach actions
        add_action('wp_ajax_acs_assign_mentee', [__CLASS__, 'assign_mentee']);
        add_action('wp_ajax_acs_create_plan', [__CLASS__, 'create_plan']);
        add_action('wp_ajax_acs_score_plan', [__CLASS__, 'score_plan']);
        add_action('wp_ajax_acs_get_mentee_analytics', [__CLASS__, 'get_mentee_analytics']);
        
        // Mentee actions
        add_action('wp_ajax_acs_track_progress', [__CLASS__, 'track_progress']);
        add_action('wp_ajax_acs_get_plan_details', [__CLASS__, 'get_plan_details']);
        add_action('wp_ajax_acs_get_mentee_plans', [__CLASS__, 'get_mentee_plans']);
        add_action('wp_ajax_acs_get_full_plan', [__CLASS__, 'get_full_plan']);
    }

    public static function assign_mentee() {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_POST['nonce'], 'acs_ajax_nonce') || !current_user_can('acs_coach')) {
            wp_die('Unauthorized');
        }

        $mentee_id = intval($_POST['mentee_id']);
        $coach_id = get_current_user_id();

        if (!$mentee_id) {
            wp_send_json_error('Invalid mentee ID');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'acs_coach_mentees';
        
        // Check if already assigned
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE coach_id = %d AND mentee_id = %d",
            $coach_id, $mentee_id
        ));
        
        if ($existing) {
            wp_send_json_error('Mentee already assigned');
        }

        // Insert assignment
        $result = $wpdb->insert($table, [
            'coach_id' => $coach_id,
            'mentee_id' => $mentee_id,
            'assigned_at' => current_time('mysql')
        ]);

        if ($result) {
            $mentee = get_user_by('id', $mentee_id);
            wp_send_json_success([
                'message' => 'Mentee assigned successfully!',
                'mentee_name' => $mentee->display_name,
                'mentee_email' => $mentee->user_email
            ]);
        } else {
            wp_send_json_error('Failed to assign mentee');
        }
    }

    public static function create_plan() {
        if (!wp_verify_nonce($_POST['nonce'], 'acs_ajax_nonce') || !current_user_can('acs_coach')) {
            wp_die('Unauthorized');
        }

        $mentee_id = intval($_POST['mentee_id']);
        $week_start = sanitize_text_field($_POST['week_start']);
        $plan_title = sanitize_text_field($_POST['plan_title']);
        $plan_notes = sanitize_textarea_field($_POST['plan_notes']);
        $activities = isset($_POST['activities']) ? $_POST['activities'] : [];
        
        if (!$mentee_id || !$week_start || !$plan_title) {
            wp_send_json_error('Missing required fields');
        }

        global $wpdb;
        $plans_table = $wpdb->prefix . 'acs_weekly_plans';
        $activities_table = $wpdb->prefix . 'acs_plan_activities';
        
        // Calculate week end
        $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
        
        // Insert plan
        $result = $wpdb->insert($plans_table, [
            'mentee_id' => $mentee_id,
            'coach_id' => get_current_user_id(),
            'week_start' => $week_start,
            'week_end' => $week_end,
            'plan_title' => $plan_title,
            'plan_notes' => $plan_notes,
            'created_at' => current_time('mysql')
        ]);
        
        if ($result) {
            $plan_id = $wpdb->insert_id;
            
            // Insert activities with day index and notes
            $activity_count = 0;
            foreach ($activities as $activity) {
                if (!empty($activity['type'])) {
                    $wpdb->insert($activities_table, [
                        'plan_id' => $plan_id,
                        'activity_type' => sanitize_text_field($activity['type']),
                        'target_distance' => floatval($activity['distance'] ?? 0),
                        'target_duration' => floatval($activity['duration'] ?? 0),
                        'target_pace' => floatval($activity['pace'] ?? 0),
                        'day_index' => intval($activity['day_index'] ?? 0),
                        'notes' => sanitize_textarea_field($activity['notes'] ?? '')
                    ]);
                    $activity_count++;
                }
            }
            
            $mentee = get_user_by('id', $mentee_id);
            wp_send_json_success([
                'message' => 'Training plan created successfully with ' . $activity_count . ' activities!',
                'plan_id' => $plan_id,
                'mentee_name' => $mentee->display_name,
                'activity_count' => $activity_count
            ]);
        } else {
            wp_send_json_error('Failed to create plan');
        }
    }

    public static function score_plan() {
        if (!wp_verify_nonce($_POST['nonce'], 'acs_ajax_nonce') || !current_user_can('acs_coach')) {
            wp_die('Unauthorized');
        }

        $plan_id = intval($_POST['plan_id']);
        $score = intval($_POST['score']);
        $pace_score = isset($_POST['pace_score']) ? intval($_POST['pace_score']) : null;
        $distance_score = isset($_POST['distance_score']) ? intval($_POST['distance_score']) : null;
        $consistency_score = isset($_POST['consistency_score']) ? intval($_POST['consistency_score']) : null;
        $elevation_score = isset($_POST['elevation_score']) ? intval($_POST['elevation_score']) : null;
        $feedback = sanitize_textarea_field($_POST['feedback']);
        $custom_field_1 = isset($_POST['custom_field_1']) ? sanitize_text_field($_POST['custom_field_1']) : '';
        $custom_field_2 = isset($_POST['custom_field_2']) ? sanitize_text_field($_POST['custom_field_2']) : '';
        $custom_field_3 = isset($_POST['custom_field_3']) ? sanitize_text_field($_POST['custom_field_3']) : '';
        $custom_field_4 = isset($_POST['custom_field_4']) ? sanitize_text_field($_POST['custom_field_4']) : '';
        // Validate all scores are 0-10
        $all_scores = [$score, $pace_score, $distance_score, $consistency_score, $elevation_score];
        foreach ($all_scores as $s) {
            if (!is_numeric($s) || $s < 0 || $s > 10) {
                wp_send_json_error('All scores must be between 0 and 10');
            }
        }
        if (!$plan_id) {
            wp_send_json_error('Invalid plan ID');
        }

        global $wpdb;
        $plans_table = $wpdb->prefix . 'acs_weekly_plans';
        $scores_table = $wpdb->prefix . 'acs_weekly_scores';
        // Get plan details
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $plans_table WHERE id = %d", $plan_id));
        if (!$plan) {
            wp_send_json_error('Plan not found');
        }
        // Insert or update score
        $existing_score = $wpdb->get_var($wpdb->prepare("SELECT id FROM $scores_table WHERE plan_id = %d", $plan_id));
        $data = [
            'score' => $score,
            'pace_score' => $pace_score,
            'distance_score' => $distance_score,
            'consistency_score' => $consistency_score,
            'elevation_score' => $elevation_score,
            'custom_field_1' => $custom_field_1,
            'custom_field_2' => $custom_field_2,
            'custom_field_3' => $custom_field_3,
            'custom_field_4' => $custom_field_4,
            'feedback' => $feedback,
            'scored_at' => current_time('mysql')
        ];
        if ($existing_score) {
            $wpdb->update($scores_table, $data, ['id' => $existing_score]);
        } else {
            $data['plan_id'] = $plan_id;
            $data['mentee_id'] = $plan->mentee_id;
            $wpdb->insert($scores_table, $data);
        }
        wp_send_json_success([
            'message' => 'Score submitted successfully!',
            'score' => $score,
            'feedback' => $feedback
        ]);
    }

    public static function get_mentee_analytics() {
        if (!wp_verify_nonce($_POST['nonce'], 'acs_ajax_nonce') || !current_user_can('acs_coach')) {
            wp_die('Unauthorized');
        }

        $mentee_id = intval($_POST['mentee_id']);
        
        global $wpdb;
        $table = $wpdb->prefix . 'acs_activities_cache';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY start_time ASC", $mentee_id));
        
        $activities = [];
        $chart_data = [
            'dates' => [],
            'distances' => [],
            'paces' => [],
            'types' => []
        ];
        
        foreach ($rows as $row) {
            $date = date('Y-m-d', strtotime($row->start_time));
            $activities[] = [
                'date' => $date,
                'type' => $row->activity_type,
                'distance' => number_format($row->distance, 2),
                'duration' => number_format($row->duration, 1),
                'pace' => number_format($row->pace, 2),
                'elevation' => number_format($row->elevation, 1)
            ];
            
            // Prepare chart data
            $chart_data['dates'][] = $date;
            $chart_data['distances'][] = floatval($row->distance);
            $chart_data['paces'][] = floatval($row->pace);
            $chart_data['types'][] = $row->activity_type;
        }
        
        // Activity type breakdown for chart
        $type_counts = array_count_values($chart_data['types']);
        $chart_data['type_labels'] = array_keys($type_counts);
        $chart_data['type_data'] = array_values($type_counts);
        
        // Get recent activities for table (last 10)
        $recent_activities = array_slice(array_reverse($activities), 0, 10);

        wp_send_json_success([
            'activities' => $recent_activities,
            'total_activities' => count($activities),
            'chart_data' => $chart_data
        ]);
    }

    public static function track_progress() {
        if (!wp_verify_nonce($_POST['nonce'], 'acs_ajax_nonce') || !current_user_can('acs_mentee')) {
            wp_die('Unauthorized');
        }

        $plan_id = intval($_POST['plan_id']);
        $user_id = get_current_user_id();
        
        global $wpdb;
        $plans_table = $wpdb->prefix . 'acs_weekly_plans';
        $activities_table = $wpdb->prefix . 'acs_plan_activities';
        $cache_table = $wpdb->prefix . 'acs_activities_cache';
        
        // Get plan details
        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $plans_table WHERE id = %d AND mentee_id = %d",
            $plan_id, $user_id
        ));
        
        if (!$plan) {
            wp_send_json_error('Plan not found');
        }

        // Get plan activities
        $plan_activities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $activities_table WHERE plan_id = %d",
            $plan_id
        ));

        // Get actual activities during plan period
        $actual_activities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $cache_table WHERE user_id = %d AND start_time BETWEEN %s AND %s",
            $user_id, $plan->week_start, $plan->week_end
        ));

        // Calculate progress
        $total_target_distance = 0;
        $total_target_duration = 0;
        $total_actual_distance = 0;
        $total_actual_duration = 0;

        foreach ($plan_activities as $activity) {
            $total_target_distance += $activity->target_distance;
            $total_target_duration += $activity->target_duration;
        }

        foreach ($actual_activities as $activity) {
            $total_actual_distance += $activity->distance;
            $total_actual_duration += $activity->duration;
        }

        $distance_progress = $total_target_distance > 0 ? ($total_actual_distance / $total_target_distance) * 100 : 0;
        $duration_progress = $total_target_duration > 0 ? ($total_actual_duration / $total_target_duration) * 100 : 0;

        wp_send_json_success([
            'plan_title' => $plan->plan_title,
            'week_period' => date('M j', strtotime($plan->week_start)) . ' - ' . date('M j', strtotime($plan->week_end)),
            'target_distance' => number_format($total_target_distance, 1),
            'actual_distance' => number_format($total_actual_distance, 1),
            'distance_progress' => round($distance_progress, 1),
            'target_duration' => number_format($total_target_duration, 1),
            'actual_duration' => number_format($total_actual_duration, 1),
            'duration_progress' => round($duration_progress, 1),
            'activities_completed' => count($actual_activities)
        ]);
    }

    public static function get_plan_details() {
        if (!wp_verify_nonce($_POST['nonce'], 'acs_ajax_nonce') || !current_user_can('acs_mentee')) {
            wp_die('Unauthorized');
        }

        $plan_id = intval($_POST['plan_id']);
        $user_id = get_current_user_id();
        
        global $wpdb;
        $plans_table = $wpdb->prefix . 'acs_weekly_plans';
        $activities_table = $wpdb->prefix . 'acs_plan_activities';
        
        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $plans_table WHERE id = %d AND mentee_id = %d",
            $plan_id, $user_id
        ));
        
        if (!$plan) {
            wp_send_json_error('Plan not found');
        }

        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $activities_table WHERE plan_id = %d",
            $plan_id
        ));

        wp_send_json_success([
            'plan' => $plan,
            'activities' => $activities
        ]);
    }

    public static function get_mentee_plans() {
        if (!wp_verify_nonce($_GET['nonce'], 'acs_ajax_nonce') || !current_user_can('acs_coach')) {
            wp_send_json_error('Unauthorized');
        }
        $mentee_id = intval($_GET['mentee_id']);
        $coach_id = get_current_user_id();
        global $wpdb;
        $plans_table = $wpdb->prefix . 'acs_weekly_plans';
        $plans = $wpdb->get_results($wpdb->prepare(
            "SELECT id, plan_title, week_start FROM $plans_table WHERE mentee_id = %d AND coach_id = %d ORDER BY week_start DESC",
            $mentee_id, $coach_id
        ));
        wp_send_json_success($plans);
    }
    public static function get_full_plan() {
         if (!wp_verify_nonce($_GET['nonce'], 'acs_ajax_nonce') || !current_user_can('acs_coach')) {
            wp_send_json_error('Unauthorized');
        }
        
        $mentee_id = intval($_GET['mentee_id']);
        $plan_id = intval($_GET['plan_id']);
        $coach_id = get_current_user_id();
        
        global $wpdb;
        $plans_table = $wpdb->prefix . 'acs_weekly_plans';
        $activities_table = $wpdb->prefix . 'acs_plan_activities';
        
        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $plans_table WHERE id = %d AND mentee_id = %d AND coach_id = %d",
            $plan_id, $mentee_id, $coach_id
        ));
        
        if (!$plan) {
            wp_send_json_error('Plan not found');
        }
        
        // Get all activities for this plan, ordered by day and creation order
        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $activities_table WHERE plan_id = %d ORDER BY day_index ASC, id ASC",
            $plan_id
        ));
        
        // If day_index doesn't exist in old data, add it based on array index
        foreach ($activities as $index => $activity) {
            if (!isset($activity->day_index) || $activity->day_index === null) {
                $activity->day_index = $index < 7 ? $index : 0;
            }
        }
        
        wp_send_json_success([
            'plan' => $plan,
            'activities' => $activities
        ]);
    }
}
