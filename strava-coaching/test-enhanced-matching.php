<?php
/**
 * Enhanced Matching Test Script
 * File: test-enhanced-matching.php (CREATE THIS FILE IN YOUR PLUGIN ROOT)
 * 
 * Upload this file temporarily to test the enhanced matching
 * Access via: yoursite.com/wp-content/plugins/strava-coaching/test-enhanced-matching.php
 */

// WordPress environment
require_once('../../../wp-config.php');

// Only allow admins to run this test
if (!current_user_can('manage_options')) {
    die('Access denied. Admin access required.');
}

echo "<h1>🧪 Enhanced Matching Test</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 40px; }
    .success { color: green; background: #e8f5e8; padding: 10px; margin: 10px 0; }
    .error { color: red; background: #ffeaea; padding: 10px; margin: 10px 0; }
    .info { color: blue; background: #e8f4fd; padding: 10px; margin: 10px 0; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
</style>";

// Test 1: Check if API class exists
echo "<h2>1. API Class Check</h2>";
if (class_exists('Strava_Coaching_API')) {
    echo "<div class='success'>✅ Strava_Coaching_API class found</div>";
    $api = new Strava_Coaching_API();
} else {
    echo "<div class='error'>❌ Strava_Coaching_API class not found</div>";
    exit;
}

// Test 2: Check database tables
echo "<h2>2. Database Tables Check</h2>";
global $wpdb;

$tables_to_check = array(
    'strava_activities',
    'strava_training_plans',
    'activity_plan_matches'
);

foreach ($tables_to_check as $table) {
    $table_name = $wpdb->prefix . $table;
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;

    if ($exists) {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        echo "<div class='success'>✅ $table: $count records</div>";
    } else {
        echo "<div class='error'>❌ $table: Table missing</div>";
    }
}

// Test 3: Check for users with data
echo "<h2>3. Test Data Check</h2>";

$users_with_plans = $wpdb->get_results("
    SELECT u.ID, u.display_name, COUNT(tp.id) as plan_count
    FROM {$wpdb->users} u
    INNER JOIN {$wpdb->prefix}strava_training_plans tp ON u.ID = tp.mentee_id
    WHERE tp.status = 'active'
    GROUP BY u.ID
    LIMIT 5
");

if (empty($users_with_plans)) {
    echo "<div class='error'>❌ No users with active training plans found</div>";
    echo "<div class='info'>💡 Create a training plan first to test matching</div>";
} else {
    echo "<div class='success'>✅ Found " . count($users_with_plans) . " users with active plans</div>";

    echo "<table>";
    echo "<tr><th>User</th><th>Plans</th><th>Activities</th><th>Matches</th><th>Test Match</th></tr>";

    foreach ($users_with_plans as $user) {
        $activity_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}strava_activities WHERE user_id = %d",
            $user->ID
        ));

        $match_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}activity_plan_matches apm
             INNER JOIN {$wpdb->prefix}strava_training_plans tp ON apm.plan_id = tp.id
             WHERE tp.mentee_id = %d",
            $user->ID
        ));

        echo "<tr>";
        echo "<td>{$user->display_name}</td>";
        echo "<td>{$user->plan_count}</td>";
        echo "<td>$activity_count</td>";
        echo "<td>$match_count</td>";
        echo "<td><a href='?test_user={$user->ID}'>Test Match</a></td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Test 4: Run matching test
if (isset($_GET['test_user'])) {
    $test_user_id = intval($_GET['test_user']);

    echo "<h2>4. Running Enhanced Matching Test for User ID: $test_user_id</h2>";

    $start_time = microtime(true);

    try {
        $matched_count = $api->match_activities_to_plans($test_user_id);

        $end_time = microtime(true);
        $execution_time = round(($end_time - $start_time) * 1000, 2);

        echo "<div class='success'>✅ Matching completed successfully!</div>";
        echo "<div class='info'>📊 Results: $matched_count new matches found</div>";
        echo "<div class='info'>⏱️ Execution time: {$execution_time}ms</div>";

        // Show detailed results
        echo "<h3>Detailed Matching Results</h3>";

        $matches = $wpdb->get_results($wpdb->prepare("
            SELECT 
                apm.*,
                sa.name as activity_name,
                sa.activity_type,
                sa.distance,
                sa.start_date,
                tp.plan_name,
                tp.week_start
            FROM {$wpdb->prefix}activity_plan_matches apm
            INNER JOIN {$wpdb->prefix}strava_activities sa ON apm.activity_id = sa.id
            INNER JOIN {$wpdb->prefix}strava_training_plans tp ON apm.plan_id = tp.id
            WHERE tp.mentee_id = %d
            ORDER BY apm.matched_at DESC
            LIMIT 10
        ", $test_user_id));

        if ($matches) {
            echo "<table>";
            echo "<tr><th>Activity</th><th>Type</th><th>Distance</th><th>Plan Day</th><th>Confidence</th><th>Date</th></tr>";

            foreach ($matches as $match) {
                $distance = $match->distance ? round($match->distance / 1000, 2) . ' km' : 'N/A';
                $confidence_class = $match->match_confidence >= 80 ? 'success' :
                    ($match->match_confidence >= 60 ? 'info' : 'error');

                echo "<tr>";
                echo "<td>{$match->activity_name}</td>";
                echo "<td>{$match->activity_type}</td>";
                echo "<td>$distance</td>";
                echo "<td>" . ucfirst($match->workout_day) . "</td>";
                echo "<td><span class='$confidence_class'>{$match->match_confidence}%</span></td>";
                echo "<td>" . date('M j', strtotime($match->start_date)) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<div class='info'>No matches found for this user</div>";
        }

    } catch (Exception $e) {
        echo "<div class='error'>❌ Error during matching: " . $e->getMessage() . "</div>";
    }
}

// Test 5: Performance test
echo "<h2>5. Algorithm Performance Test</h2>";

if (isset($_GET['performance_test'])) {
    echo "<div class='info'>Running performance test with sample data...</div>";

    // Create sample activity and workout for testing
    $sample_activity = (object) array(
        'activity_type' => 'Run',
        'distance' => 5000, // 5km in meters
        'moving_time' => 1500, // 25 minutes
        'average_speed' => 3.33, // ~5:00/km pace
        'start_date' => '2024-01-15 07:30:00'
    );

    $sample_workout = array(
        'type' => 'run',
        'distance' => 5, // 5km
        'duration' => 25, // 25 minutes
        'pace' => '5:00',
        'preferred_time' => 'morning'
    );

    $start_time = microtime(true);

    // Test the confidence calculation 1000 times
    for ($i = 0; $i < 1000; $i++) {
        $reflection = new ReflectionClass($api);
        $method = $reflection->getMethod('calculate_match_confidence_enhanced');
        $method->setAccessible(true);
        $confidence = $method->invoke($api, $sample_activity, $sample_workout, '2024-01-15');
    }

    $end_time = microtime(true);
    $avg_time = round((($end_time - $start_time) / 1000) * 1000000, 2);

    echo "<div class='success'>✅ Performance test completed</div>";
    echo "<div class='info'>📊 Sample confidence score: $confidence%</div>";
    echo "<div class='info'>⏱️ Average time per calculation: {$avg_time} microseconds</div>";
    echo "<div class='info'>🚀 Estimated capacity: " . round(1000000 / $avg_time) . " calculations per second</div>";
}

echo "<p><a href='?performance_test=1'>Run Performance Test</a></p>";

echo "<h2>✅ Testing Complete</h2>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ul>";
echo "<li>✅ Enhanced matching algorithm is ready</li>";
echo "<li>🔄 Test with real user data by clicking 'Test Match' above</li>";
echo "<li>📊 Check the matching confidence scores</li>";
echo "<li>🗑️ Delete this test file when done: <code>test-enhanced-matching.php</code></li>";
echo "</ul>";

echo "<div class='info'>";
echo "<h3>🔧 Debugging Tips:</h3>";
echo "<ul>";
echo "<li><strong>Low confidence scores?</strong> Check if activity types match (Run vs run)</li>";
echo "<li><strong>No matches?</strong> Ensure activities fall within the plan week dates</li>";
echo "<li><strong>Performance issues?</strong> Add database indexes for large datasets</li>";
echo "<li><strong>Missing data?</strong> Sync Strava activities first</li>";
echo "</ul>";
echo "</div>";
?>