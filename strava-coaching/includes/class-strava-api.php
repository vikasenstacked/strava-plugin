<?php
/**
 * Strava API Handler Class
 * File: includes/class-strava-api.php
 */

class Strava_Coaching_API
{

    private $client_id;
    private $client_secret;
    private $redirect_uri;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->client_id = defined('STRAVA_CLIENT_ID') ? STRAVA_CLIENT_ID : get_option('strava_coaching_client_id');
        $this->client_secret = defined('STRAVA_CLIENT_SECRET') ? STRAVA_CLIENT_SECRET : get_option('strava_coaching_client_secret');
        $this->redirect_uri = admin_url('admin.php?page=strava-coaching&action=oauth_callback');
    }

    /**
     * Get OAuth authorization URL
     */
    public function get_auth_url($user_id)
    {
        $params = array(
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'response_type' => 'code',
            'scope' => 'read,activity:read_all',
            'state' => wp_create_nonce('strava_oauth_' . $user_id) . '|' . $user_id,
            'approval_prompt' => 'auto' // This helps with cache issues
        );

        $auth_url = 'https://www.strava.com/oauth/authorize?' . http_build_query($params);

        // Log the auth URL for debugging
        error_log('Strava Auth URL generated: ' . $auth_url);
        error_log('Redirect URI: ' . $this->redirect_uri);

        return $auth_url;
    }

    /**
     * Exchange authorization code for tokens
     */
    public function exchange_token($code)
    {
        $response = wp_remote_post('https://www.strava.com/oauth/token', array(
            'timeout' => 30,
            'body' => array(
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'code' => $code,
                'grant_type' => 'authorization_code'
            )
        ));

        if (is_wp_error($response)) {
            error_log('Strava OAuth Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Strava OAuth JSON Error: ' . json_last_error_msg());
            return false;
        }

        return $data;
    }

    /**
     * Refresh access token
     */
    public function refresh_token($refresh_token)
    {
        $response = wp_remote_post('https://www.strava.com/oauth/token', array(
            'timeout' => 30,
            'body' => array(
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $refresh_token,
                'grant_type' => 'refresh_token'
            )
        ));

        if (is_wp_error($response)) {
            error_log('Strava Token Refresh Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Strava Token Refresh JSON Error: ' . json_last_error_msg());
            return false;
        }

        return $data;
    }

    /**
     * Store tokens in database
     */
    public function store_tokens($user_id, $token_data)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'strava_tokens';

        $expires_at = date('Y-m-d H:i:s', $token_data['expires_at']);

        $result = $wpdb->replace(
            $table_name,
            array(
                'user_id' => $user_id,
                'access_token' => $token_data['access_token'],
                'refresh_token' => $token_data['refresh_token'],
                'expires_at' => $expires_at,
                'athlete_id' => $token_data['athlete']['id'],
                'updated_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%d', '%s')
        );

        if ($result === false) {
            error_log('Failed to store Strava tokens for user ' . $user_id);
            return false;
        }

        return true;
    }

    /**
     * Get valid access token for user
     */
    public function get_access_token($user_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'strava_tokens';

        $token_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d",
            $user_id
        ));

        if (!$token_row) {
            return false;
        }

        // Check if token is expired
        $expires_at = strtotime($token_row->expires_at);
        $current_time = current_time('timestamp');

        if ($current_time >= $expires_at) {
            // Token expired, try to refresh
            $new_token_data = $this->refresh_token($token_row->refresh_token);

            if ($new_token_data && isset($new_token_data['access_token'])) {
                // Store new tokens
                $this->store_tokens($user_id, $new_token_data);
                return $new_token_data['access_token'];
            } else {
                // Refresh failed, remove invalid tokens
                $wpdb->delete($table_name, array('user_id' => $user_id));
                return false;
            }
        }

        return $token_row->access_token;
    }

    /**
     * Make authenticated API request
     */
    public function make_request($endpoint, $user_id, $params = array())
    {
        $access_token = $this->get_access_token($user_id);

        if (!$access_token) {
            return false;
        }

        $url = 'https://www.strava.com/api/v3/' . ltrim($endpoint, '/');

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            error_log('Strava API Error: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 401) {
            // Unauthorized, token might be invalid
            global $wpdb;
            $table_name = $wpdb->prefix . 'strava_tokens';
            $wpdb->delete($table_name, array('user_id' => $user_id));
            return false;
        }

        if ($status_code !== 200) {
            error_log('Strava API HTTP Error: ' . $status_code);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Strava API JSON Error: ' . json_last_error_msg());
            return false;
        }

        return $data;
    }

    /**
     * Get athlete profile
     */
    public function get_athlete($user_id)
    {
        return $this->make_request('athlete', $user_id);
    }

    /**
     * Get user activities
     */
    public function get_activities($user_id, $after = null, $before = null, $per_page = 30)
    {
        $params = array(
            'per_page' => min($per_page, 200) // Max 200 per Strava API
        );

        if ($after) {
            $params['after'] = is_numeric($after) ? $after : strtotime($after);
        }

        if ($before) {
            $params['before'] = is_numeric($before) ? $before : strtotime($before);
        }

        return $this->make_request('athlete/activities', $user_id, $params);
    }

    /**
     * Get specific activity details
     */
    public function get_activity($user_id, $activity_id)
    {
        return $this->make_request("activities/{$activity_id}", $user_id);
    }


    /**
     * Update sync_user_activities to include matching
     */
    public function sync_user_activities($user_id, $days_back = 30)
    {
        global $wpdb;

        // Get activities from Strava
        $after_timestamp = strtotime("-{$days_back} days");
        $activities = $this->get_activities($user_id, $after_timestamp, null, 200);

        if (!$activities || !is_array($activities)) {
            error_log('Failed to fetch activities for user ' . $user_id);
            return false;
        }

        $table_name = $wpdb->prefix . 'strava_activities';
        $synced_count = 0;

        foreach ($activities as $activity) {
            // Only sync running, cycling, and swimming activities
            $allowed_types = array('Run', 'Ride', 'Swim', 'VirtualRun', 'VirtualRide', 'TrailRun', 'Walk', 'Hike', 'WeightTraining', 'Workout', 'Yoga');
            if (!in_array($activity['type'], $allowed_types)) {
                continue;
            }

            // Check if activity already exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name WHERE strava_id = %d",
                $activity['id']
            ));

            if ($existing) {
                continue; // Skip if already synced
            }

            // Insert activity
            $result = $wpdb->insert(
                $table_name,
                array(
                    'user_id' => $user_id,
                    'strava_id' => $activity['id'],
                    'activity_type' => $activity['type'],
                    'name' => $activity['name'],
                    'distance' => isset($activity['distance']) ? $activity['distance'] : null,
                    'moving_time' => isset($activity['moving_time']) ? $activity['moving_time'] : null,
                    'elapsed_time' => isset($activity['elapsed_time']) ? $activity['elapsed_time'] : null,
                    'total_elevation_gain' => isset($activity['total_elevation_gain']) ? $activity['total_elevation_gain'] : null,
                    'average_speed' => isset($activity['average_speed']) ? $activity['average_speed'] : null,
                    'max_speed' => isset($activity['max_speed']) ? $activity['max_speed'] : null,
                    'average_heartrate' => isset($activity['average_heartrate']) ? $activity['average_heartrate'] : null,
                    'max_heartrate' => isset($activity['max_heartrate']) ? $activity['max_heartrate'] : null,
                    'start_date' => date('Y-m-d H:i:s', strtotime($activity['start_date_local'])),
                    'kudos_count' => isset($activity['kudos_count']) ? $activity['kudos_count'] : 0,
                    'achievement_count' => isset($activity['achievement_count']) ? $activity['achievement_count'] : 0
                ),
                array('%d', '%d', '%s', '%s', '%f', '%d', '%d', '%f', '%f', '%f', '%f', '%d', '%s', '%d', '%d')
            );

            if ($result) {
                $synced_count++;
            } else {
                error_log('Failed to insert activity ' . $activity['id'] . ' for user ' . $user_id);
            }
        }

        // Match activities to training plans
        $this->match_activities_to_plans($user_id, date('Y-m-d', $after_timestamp));

        return $synced_count;
    }

    /**
     * Get activity matches for a plan
     */
    public function get_plan_matches($plan_id)
    {
        global $wpdb;

        $query = "
        SELECT 
            m.*,
            a.name as activity_name,
            a.activity_type,
            a.distance,
            a.moving_time,
            a.average_speed,
            a.average_heartrate,
            a.start_date
        FROM {$wpdb->prefix}activity_plan_matches m
        INNER JOIN {$wpdb->prefix}strava_activities a ON m.activity_id = a.id
        WHERE m.plan_id = %d
        ORDER BY m.workout_day
    ";

        return $wpdb->get_results($wpdb->prepare($query, $plan_id), ARRAY_A);
    }

    /**
     * Calculate plan completion percentage
     */
    public function calculate_plan_completion($plan_id)
    {
        global $wpdb;

        // Get plan details
        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}strava_training_plans WHERE id = %d",
            $plan_id
        ));

        if (!$plan) {
            return 0;
        }

        $plan_data = json_decode($plan->plan_data, true);
        $workouts = isset($plan_data['workouts']) ? $plan_data['workouts'] : array();

        // Count planned workouts
        $planned_count = 0;
        foreach ($workouts as $workout) {
            if (!empty($workout['type'])) {
                $planned_count++;
            }
        }

        if ($planned_count === 0) {
            return 100; // No workouts planned
        }

        // Count matched activities
        $matched_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}activity_plan_matches WHERE plan_id = %d",
            $plan_id
        ));

        return round(($matched_count / $planned_count) * 100);
    }


    /**
     * Remove activity match
     */
    public function remove_activity_match($activity_id, $plan_id)
    {
        global $wpdb;

        return $wpdb->delete(
            $wpdb->prefix . 'activity_plan_matches',
            array(
                'activity_id' => $activity_id,
                'plan_id' => $plan_id
            ),
            array('%d', '%d')
        );
    }

    /**
     * Manually match an activity to a workout
     */
    public function manual_match_activity($activity_id, $plan_id, $workout_day)
    {
        global $wpdb;

        // Remove any existing match for this activity
        $wpdb->delete(
            $wpdb->prefix . 'activity_plan_matches',
            array('activity_id' => $activity_id),
            array('%d')
        );

        // Create new match with 100% confidence (manual match)
        return $wpdb->insert(
            $wpdb->prefix . 'activity_plan_matches',
            array(
                'activity_id' => $activity_id,
                'plan_id' => $plan_id,
                'workout_day' => $workout_day,
                'match_confidence' => 100
            ),
            array('%d', '%d', '%s', '%d')
        );
    }
    /**
     * Disconnect user from Strava
     */
    public function disconnect_user($user_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'strava_tokens';

        return $wpdb->delete($table_name, array('user_id' => $user_id));
    }

    /**
     * Clean up old activities (based on retention period)
     */
    public function cleanup_old_activities()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'strava_activities';

        $retention_days = get_option('strava_coaching_data_retention', 90);
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE start_date < %s",
            $cutoff_date
        ));
    }
    /**
     * Enhanced match activities to training plans
     */
    public function match_activities_to_plans($user_id, $start_date = null)
    {
        global $wpdb;

        // Get date range for matching (default: last 30 days)
        if (!$start_date) {
            $start_date = date('Y-m-d', strtotime('-30 days'));
        }

        // Get user's training plans within date range
        $plans_query = "
        SELECT * FROM {$wpdb->prefix}strava_training_plans
        WHERE mentee_id = %d
        AND status = 'active'
        AND week_start >= %s
        ORDER BY week_start ASC
    ";

        $plans = $wpdb->get_results($wpdb->prepare($plans_query, $user_id, $start_date));

        if (empty($plans)) {
            return 0;
        }

        $total_matched = 0;

        foreach ($plans as $plan) {
            $matched = $this->match_activities_to_single_plan_enhanced($user_id, $plan);
            $total_matched += $matched;
        }

        // Update plan completion statistics
        $this->update_plan_completion_stats($user_id, $plans);

        return $total_matched;
    }

    /**
     * Enhanced match activities to a single training plan
     */
    private function match_activities_to_single_plan_enhanced($user_id, $plan)
    {
        global $wpdb;

        $plan_data = json_decode($plan->plan_data, true);
        $workouts = isset($plan_data['workouts']) ? $plan_data['workouts'] : array();

        $matched_count = 0;

        // Get all activities for the week
        $week_end = date('Y-m-d', strtotime($plan->week_start . ' +6 days'));
        $week_activities = $this->get_week_activities_for_matching($user_id, $plan->week_start, $week_end, $plan->id);

        // Group activities by date
        $activities_by_date = array();
        foreach ($week_activities as $activity) {
            $date = date('Y-m-d', strtotime($activity->start_date));
            if (!isset($activities_by_date[$date])) {
                $activities_by_date[$date] = array();
            }
            $activities_by_date[$date][] = $activity;
        }

        // Process each day of the plan
        $days = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');

        for ($i = 0; $i < 7; $i++) {
            $day = $days[$i];
            $workout = isset($workouts[$day]) ? $workouts[$day] : null;

            // Skip rest days
            if (!$workout || empty($workout['type'])) {
                continue;
            }

            // Calculate the actual date for this workout
            $workout_date = date('Y-m-d', strtotime($plan->week_start . ' +' . $i . ' days'));
            $day_activities = isset($activities_by_date[$workout_date]) ? $activities_by_date[$workout_date] : array();

            // Find best match for this workout
            $best_match = $this->find_best_activity_match($day_activities, $workout, $workout_date);

            if ($best_match) {
                $result = $this->create_activity_match($best_match['activity'], $plan->id, $day, $best_match['confidence']);
                if ($result) {
                    $matched_count++;
                    // Remove matched activity from the pool
                    $activities_by_date[$workout_date] = array_filter(
                        $activities_by_date[$workout_date],
                        function ($activity) use ($best_match) {
                            return $activity->id !== $best_match['activity']->id;
                        }
                    );
                }
            }
        }

        return $matched_count;
    }
    /**
     * Enhanced match confidence calculation
     */
    private function calculate_match_confidence_enhanced($activity, $workout, $workout_date)
    {
        $confidence = 0;

        // 1. Activity type matching (35 points)
        $confidence += $this->match_activity_types($activity->activity_type, $workout['type']);

        // 2. Distance matching (25 points)
        $confidence += $this->match_distance($activity->distance, $workout['distance'] ?? null);

        // 3. Duration matching (20 points)
        $confidence += $this->match_duration($activity->moving_time, $workout['duration'] ?? null);

        // 4. Time of day matching (10 points)
        $confidence += $this->match_workout_time($activity->start_date, $workout, $workout_date);

        // 5. Pace matching for runs (10 points)
        $confidence += $this->match_pace($activity, $workout);

        return min($confidence, 100);
    }
    /**
     * Enhanced activity type matching
     */
    private function match_activity_types($activity_type, $planned_type)
    {
        // Direct match gets full points
        $type_map = array(
            'run' => array('Run', 'VirtualRun', 'TrailRun'),
            'bike' => array('Ride', 'VirtualRide', 'EBikeRide'),
            'swim' => array('Swim', 'VirtualSwim'),
            'strength' => array('WeightTraining', 'Workout', 'Crossfit'),
            'cross_training' => array('Workout', 'WeightTraining', 'Yoga', 'Crossfit', 'Elliptical'),
            'walk' => array('Walk', 'Hike')
        );

        // Find planned type in map
        $planned_activities = array();
        foreach ($type_map as $key => $types) {
            if ($key === strtolower($planned_type)) {
                $planned_activities = $types;
                break;
            }
        }

        // Direct match
        if (in_array($activity_type, $planned_activities)) {
            return 35; // Full points
        }

        // Check for similar activities
        $similar_matches = array(
            'Run' => array('Walk' => 20, 'Hike' => 15),
            'Ride' => array('EBikeRide' => 30, 'VirtualRide' => 35),
            'Workout' => array('WeightTraining' => 30, 'Yoga' => 20, 'Crossfit' => 35),
            'Swim' => array('VirtualSwim' => 35)
        );

        if (isset($similar_matches[$activity_type])) {
            foreach ($planned_activities as $planned_activity) {
                if (isset($similar_matches[$activity_type][$planned_activity])) {
                    return $similar_matches[$activity_type][$planned_activity];
                }
            }
        }

        return 0; // No match
    }
    /**
     * Calculate match confidence between activity and planned workout
     */
    /**private function calculate_match_confidence($activity, $workout)
    {
        $confidence = 0;

        // Activity type matching (40 points)
        $type_map = array(
            'run' => 'Run',
            'bike' => 'Ride',
            'swim' => 'Swim',
            'strength' => 'WeightTraining',
            'cross_training' => 'Workout'
        );

        $planned_type = isset($type_map[$workout['type']]) ? $type_map[$workout['type']] : $workout['type'];
        if (strcasecmp($activity->activity_type, $planned_type) === 0) {
            $confidence += 40;
        } elseif ($this->is_similar_activity_type($activity->activity_type, $planned_type)) {
            $confidence += 20; // Partial match for similar activities
        }

        // Distance matching (30 points)
        if (!empty($workout['distance']) && $activity->distance > 0) {
            $planned_distance = $workout['distance'] * 1000; // Convert km to meters
            $distance_diff_percent = abs($activity->distance - $planned_distance) / $planned_distance * 100;

            if ($distance_diff_percent <= 10) {
                $confidence += 30; // Within 10%
            } elseif ($distance_diff_percent <= 20) {
                $confidence += 20; // Within 20%
            } elseif ($distance_diff_percent <= 30) {
                $confidence += 10; // Within 30%
            }
        } elseif (empty($workout['distance'])) {
            // No distance specified in plan, give partial credit
            $confidence += 15;
        }

        // Duration matching (20 points)
        if (!empty($workout['duration']) && $activity->moving_time > 0) {
            $planned_duration = $workout['duration'] * 60; // Convert minutes to seconds
            $duration_diff_percent = abs($activity->moving_time - $planned_duration) / $planned_duration * 100;

            if ($duration_diff_percent <= 10) {
                $confidence += 20; // Within 10%
            } elseif ($duration_diff_percent <= 20) {
                $confidence += 15; // Within 20%
            } elseif ($duration_diff_percent <= 30) {
                $confidence += 10; // Within 30%
            }
        } elseif (empty($workout['duration'])) {
            // No duration specified, give partial credit
            $confidence += 10;
        }

        // Pace matching for runs (10 points)
        if ($activity->activity_type === 'Run' && !empty($workout['pace']) && $activity->average_speed > 0) {
            $target_pace = $this->parse_pace_to_seconds($workout['pace']);
            if ($target_pace > 0) {
                $actual_pace = 1000 / $activity->average_speed; // seconds per km
                $pace_diff_percent = abs($actual_pace - $target_pace) / $target_pace * 100;

                if ($pace_diff_percent <= 10) {
                    $confidence += 10;
                } elseif ($pace_diff_percent <= 20) {
                    $confidence += 5;
                }
            }
        }

        return min($confidence, 100); // Cap at 100%
    }
/**
 * Enhanced distance matching
 */
    private function match_distance($actual_distance, $planned_distance)
    {
        if (!$planned_distance || !$actual_distance) {
            return 15; // Partial credit when no distance specified
        }

        $planned_meters = $planned_distance * 1000; // Convert km to meters
        $distance_diff_percent = abs($actual_distance - $planned_meters) / $planned_meters * 100;

        if ($distance_diff_percent <= 5) {
            return 25; // Within 5% - excellent
        } elseif ($distance_diff_percent <= 10) {
            return 22; // Within 10% - very good
        } elseif ($distance_diff_percent <= 15) {
            return 18; // Within 15% - good
        } elseif ($distance_diff_percent <= 25) {
            return 12; // Within 25% - acceptable
        } elseif ($distance_diff_percent <= 40) {
            return 8; // Within 40% - poor but possible
        }

        return 0; // Too far off
    }
    /**
     * Enhanced duration matching
     */
    private function match_duration($actual_duration, $planned_duration)
    {
        if (!$planned_duration || !$actual_duration) {
            return 10; // Partial credit when no duration specified
        }

        $planned_seconds = $planned_duration * 60; // Convert minutes to seconds
        $duration_diff_percent = abs($actual_duration - $planned_seconds) / $planned_seconds * 100;

        if ($duration_diff_percent <= 10) {
            return 20; // Within 10% - excellent
        } elseif ($duration_diff_percent <= 20) {
            return 16; // Within 20% - good
        } elseif ($duration_diff_percent <= 30) {
            return 12; // Within 30% - acceptable
        } elseif ($duration_diff_percent <= 50) {
            return 8; // Within 50% - poor but possible
        }

        return 0; // Too far off
    }
    /**
     * Time of day matching
     */
    private function match_workout_time($activity_start_date, $workout, $workout_date)
    {
        $activity_hour = intval(date('H', strtotime($activity_start_date)));

        // Check if workout has preferred time
        $preferred_time = isset($workout['preferred_time']) ? $workout['preferred_time'] : null;

        if (!$preferred_time) {
            // No preferred time specified, use general time matching
            if ($activity_hour >= 5 && $activity_hour <= 11) {
                return 8; // Morning workout - generally good
            } elseif ($activity_hour >= 17 && $activity_hour <= 20) {
                return 8; // Evening workout - generally good
            } elseif ($activity_hour >= 12 && $activity_hour <= 16) {
                return 6; // Afternoon - okay
            } else {
                return 3; // Late night/very early - less common but possible
            }
        }

        // Match against preferred time
        switch (strtolower($preferred_time)) {
            case 'morning':
                return ($activity_hour >= 5 && $activity_hour <= 11) ? 10 : 2;
            case 'afternoon':
                return ($activity_hour >= 12 && $activity_hour <= 16) ? 10 : 2;
            case 'evening':
                return ($activity_hour >= 17 && $activity_hour <= 21) ? 10 : 2;
            default:
                return 5; // Unknown preference
        }
    }

    /**
     * Enhanced pace matching
     */
    private function match_pace($activity, $workout)
    {
        // Only apply to running activities
        if (!in_array($activity->activity_type, array('Run', 'VirtualRun', 'TrailRun'))) {
            return 5; // Small bonus for non-running activities
        }

        if (!isset($workout['pace']) || !$activity->average_speed || $activity->average_speed <= 0) {
            return 5; // Partial credit when no pace data
        }

        $target_pace = $this->parse_pace_to_seconds($workout['pace']);
        if ($target_pace <= 0) {
            return 5; // Invalid pace format
        }

        $actual_pace = 1000 / $activity->average_speed; // seconds per km
        $pace_diff_percent = abs($actual_pace - $target_pace) / $target_pace * 100;

        if ($pace_diff_percent <= 5) {
            return 10; // Within 5% - excellent pace matching
        } elseif ($pace_diff_percent <= 10) {
            return 8; // Within 10% - very good
        } elseif ($pace_diff_percent <= 20) {
            return 6; // Within 20% - acceptable
        } elseif ($pace_diff_percent <= 30) {
            return 3; // Within 30% - poor but possible
        }

        return 0; // Too far off target pace
    }

    /**
     * Get week activities for matching (excluding already matched)
     */
    private function get_week_activities_for_matching($user_id, $start_date, $end_date, $plan_id)
    {
        global $wpdb;

        $query = "
        SELECT sa.* FROM {$wpdb->prefix}strava_activities sa
        WHERE sa.user_id = %d 
        AND DATE(sa.start_date) >= %s 
        AND DATE(sa.start_date) <= %s
        AND sa.id NOT IN (
            SELECT activity_id FROM {$wpdb->prefix}activity_plan_matches
            WHERE plan_id = %d
        )
        ORDER BY sa.start_date ASC
    ";

        return $wpdb->get_results($wpdb->prepare($query, $user_id, $start_date, $end_date, $plan_id));
    }

    /**
     * Create activity match record
     */
    private function create_activity_match($activity, $plan_id, $workout_day, $confidence)
    {
        global $wpdb;

        // Remove any existing match for this activity (in case of re-matching)
        $wpdb->delete(
            $wpdb->prefix . 'activity_plan_matches',
            array('activity_id' => $activity->id),
            array('%d')
        );

        return $wpdb->insert(
            $wpdb->prefix . 'activity_plan_matches',
            array(
                'activity_id' => $activity->id,
                'plan_id' => $plan_id,
                'workout_day' => $workout_day,
                'match_confidence' => $confidence,
                'match_type' => 'automatic',
                'matched_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%d', '%s', '%s')
        );
    }

    /**
     * Update plan completion statistics
     */
    private function update_plan_completion_stats($user_id, $plans)
    {
        global $wpdb;

        foreach ($plans as $plan) {
            $completion_data = $this->calculate_plan_completion_enhanced($plan->id);

            // Update plan with completion stats
            $wpdb->update(
                $wpdb->prefix . 'strava_training_plans',
                array(
                    'completion_percentage' => $completion_data['percentage'],
                    'workouts_completed' => $completion_data['completed'],
                    'workouts_planned' => $completion_data['planned'],
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $plan->id),
                array('%f', '%d', '%d', '%s'),
                array('%d')
            );
        }
    }

    /**
     * Enhanced plan completion calculation
     */
    public function calculate_plan_completion_enhanced($plan_id)
    {
        global $wpdb;

        // Get plan details
        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}strava_training_plans WHERE id = %d",
            $plan_id
        ));

        if (!$plan) {
            return array('percentage' => 0, 'completed' => 0, 'planned' => 0);
        }

        $plan_data = json_decode($plan->plan_data, true);
        $workouts = isset($plan_data['workouts']) ? $plan_data['workouts'] : array();

        // Count planned workouts
        $planned_count = 0;
        foreach ($workouts as $workout) {
            if (!empty($workout['type'])) {
                $planned_count++;
            }
        }

        if ($planned_count === 0) {
            return array('percentage' => 100, 'completed' => 0, 'planned' => 0);
        }

        // Count matched activities with good confidence
        $matched_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}activity_plan_matches 
         WHERE plan_id = %d AND match_confidence >= 50",
            $plan_id
        ));

        $percentage = round(($matched_count / $planned_count) * 100, 1);

        return array(
            'percentage' => $percentage,
            'completed' => $matched_count,
            'planned' => $planned_count
        );
    }

    /**
     * Enhanced pace parsing with more formats
     */
    private function parse_pace_to_seconds($pace_string)
    {
        // Remove common suffixes
        $pace_string = str_replace(array('/km', '/mi', 'min/km', 'min/mi'), '', trim($pace_string));

        // Parse formats like "5:30", "5.5", "5:30.5"
        if (preg_match('/^(\d+):(\d+)(?:\.(\d+))?$/', $pace_string, $matches)) {
            $minutes = intval($matches[1]);
            $seconds = intval($matches[2]);
            $decimal = isset($matches[3]) ? floatval('0.' . $matches[3]) : 0;
            return ($minutes * 60) + $seconds + ($decimal * 60);
        }

        // Parse decimal format like "5.5" (5.5 minutes)
        if (is_numeric($pace_string)) {
            return floatval($pace_string) * 60;
        }

        return 0;
    }

    /**
     * Get plan matching statistics for display
     */
    public function get_plan_matching_stats($plan_id)
    {
        global $wpdb;

        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT 
            workout_day,
            match_confidence,
            match_type,
            COUNT(*) as match_count,
            AVG(match_confidence) as avg_confidence
         FROM {$wpdb->prefix}activity_plan_matches 
         WHERE plan_id = %d 
         GROUP BY workout_day, match_type
         ORDER BY 
            FIELD(workout_day, 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday')",
            $plan_id
        ));

        return $stats;
    }

    /**
     * Re-match activities for a specific plan (useful for manual re-processing)
     */
    public function rematch_plan_activities($plan_id)
    {
        global $wpdb;

        // Get plan details
        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}strava_training_plans WHERE id = %d",
            $plan_id
        ));

        if (!$plan) {
            return false;
        }

        // Clear existing automatic matches
        $wpdb->delete(
            $wpdb->prefix . 'activity_plan_matches',
            array(
                'plan_id' => $plan_id,
                'match_type' => 'automatic'
            ),
            array('%d', '%s')
        );

        // Re-run matching
        return $this->match_activities_to_single_plan_enhanced($plan->mentee_id, $plan);
    }
    /**
     * Check if activity types are similar
     */
    private function is_similar_activity_type($actual, $planned)
    {
        $similar_types = array(
            'Run' => array('VirtualRun', 'TrailRun'),
            'Ride' => array('VirtualRide', 'EBikeRide'),
            'Swim' => array('VirtualSwim'),
            'Walk' => array('Hike'),
            'Workout' => array('WeightTraining', 'Yoga', 'Crossfit')
        );

        foreach ($similar_types as $main_type => $variations) {
            if ($main_type === $actual && in_array($planned, $variations)) {
                return true;
            }
            if ($main_type === $planned && in_array($actual, $variations)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse pace string to seconds per km
     */
    /**private function parse_pace_to_seconds($pace_string)
    {
        // Parse formats like "5:30/km" or "5:30"
        if (preg_match('/(\d+):(\d+)/', $pace_string, $matches)) {
            return intval($matches[1]) * 60 + intval($matches[2]);
        }
        return 0;
    }*/
}