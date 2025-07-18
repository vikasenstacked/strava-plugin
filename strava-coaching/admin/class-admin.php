<?php
/**
 * Complete Admin Class for Strava Coaching Plugin with Chart Integration
 * File: admin/class-admin.php
 * 
 * REPLACE YOUR ENTIRE admin/class-admin.php file with this complete version
 */

class Strava_Coaching_Admin
{

    private $plugin_name;
    private $version;

    /**
     * Constructor
     */
    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        // Existing AJAX handlers
        add_action('wp_ajax_disconnect_strava', array($this, 'ajax_disconnect_strava'));
        add_action('wp_ajax_sync_strava_activities', array($this, 'ajax_sync_activities'));

        // New AJAX handlers for Phase 3
        add_action('wp_ajax_search_users_for_mentee', array($this, 'ajax_search_users'));
        add_action('wp_ajax_add_mentee', array($this, 'ajax_add_mentee'));
        add_action('wp_ajax_remove_mentee', array($this, 'ajax_remove_mentee'));
        add_action('wp_ajax_score_activity', array($this, 'ajax_score_activity'));
        add_action('wp_ajax_get_mentee_progress', array($this, 'ajax_get_mentee_progress'));

        // Chart AJAX handlers
        add_action('wp_ajax_get_activity_chart_data', array($this, 'ajax_get_activity_chart_data'));
        add_action('wp_ajax_get_weekly_summary_data', array($this, 'ajax_get_weekly_summary_data'));
        add_action('wp_ajax_get_heart_rate_data', array($this, 'ajax_get_heart_rate_data'));
        add_action('wp_ajax_get_plan_vs_actual_data', array($this, 'ajax_get_plan_vs_actual_data'));

        // Handle OAuth callback early
        add_action('admin_init', array($this, 'handle_oauth_callback_early'));
    }

    /**
     * Handle OAuth callback early (before any output)
     */
    public function handle_oauth_callback_early()
    {
        // Only handle if we're on the right page with OAuth callback
        if (!isset($_GET['page']) || $_GET['page'] !== 'strava-coaching') {
            return;
        }

        if (!isset($_GET['action']) || $_GET['action'] !== 'oauth_callback') {
            return;
        }

        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            wp_redirect(admin_url('admin.php?page=strava-coaching&error=invalid_callback'));
            exit;
        }

        $state_parts = explode('|', urldecode($_GET['state']));
        if (count($state_parts) !== 2) {
            wp_redirect(admin_url('admin.php?page=strava-coaching&error=invalid_state'));
            exit;
        }

        $nonce = $state_parts[0];
        $user_id = intval($state_parts[1]);

        if (!wp_verify_nonce($nonce, 'strava_oauth_' . $user_id)) {
            wp_redirect(admin_url('admin.php?page=strava-coaching&error=security_failed'));
            exit;
        }

        if (!class_exists('Strava_Coaching_API')) {
            wp_redirect(admin_url('admin.php?page=strava-coaching&error=api_missing'));
            exit;
        }

        $strava_api = new Strava_Coaching_API();
        $token_data = $strava_api->exchange_token($_GET['code']);

        if ($token_data && isset($token_data['access_token'])) {
            // Store tokens
            $success = $strava_api->store_tokens($user_id, $token_data);

            if ($success) {
                // Sync initial activities
                $synced_count = $strava_api->sync_user_activities($user_id, 30);

                // Check if we have a stored redirect URL
                $redirect_url = get_transient('strava_redirect_url_' . $user_id);

                if ($redirect_url) {
                    // Delete the transient
                    delete_transient('strava_redirect_url_' . $user_id);

                    // Add success parameters to the URL
                    $redirect_url = add_query_arg(array(
                        'strava_connected' => '1',
                        'synced' => $synced_count
                    ), $redirect_url);
                } else {
                    // Default to admin page
                    $redirect_url = admin_url('admin.php?page=strava-coaching&connected=1&synced=' . $synced_count . '&t=' . time());
                }

                // Add headers to prevent caching
                if (!headers_sent()) {
                    header('Cache-Control: no-cache, no-store, must-revalidate');
                    header('Pragma: no-cache');
                    header('Expires: 0');
                }

                wp_redirect($redirect_url);
                exit;
            } else {
                wp_redirect(admin_url('admin.php?page=strava-coaching&error=store_failed'));
                exit;
            }
        } else {
            wp_redirect(admin_url('admin.php?page=strava-coaching&error=token_failed'));
            exit;
        }
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_menu_page(
            'Strava Coaching',
            'Strava Coaching',
            'manage_options',
            'strava-coaching',
            array($this, 'display_admin_page'),
            'dashicons-chart-line',
            30
        );

        // Add submenu pages
        add_submenu_page(
            'strava-coaching',
            'Dashboard',
            'Dashboard',
            'read',
            'strava-coaching',
            array($this, 'display_admin_page')
        );

        add_submenu_page(
            'strava-coaching',
            'Settings',
            'Settings',
            'manage_options',
            'strava-coaching-settings',
            array($this, 'display_settings_page')
        );
    }

    /**
     * Initialize settings
     */
    public function init_settings()
    {
        register_setting('strava_coaching_settings', 'strava_coaching_client_id');
        register_setting('strava_coaching_settings', 'strava_coaching_client_secret');

        // Handle role updates
        if (isset($_POST['update_role']) && wp_verify_nonce($_POST['_wpnonce'], 'update_user_role')) {
            $current_user = wp_get_current_user();
            $new_role = sanitize_text_field($_POST['user_role']);

            if (in_array($new_role, array('coach', 'mentee'))) {
                // Remove current custom roles
                $current_user->remove_role('coach');
                $current_user->remove_role('mentee');

                // Add new role
                $current_user->add_role($new_role);

                add_action('admin_notices', function () use ($new_role) {
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<p>Role updated to <strong>' . ucfirst($new_role) . '</strong> successfully!</p>';
                    echo '</div>';
                });
            }
        }

        // Handle training plan creation
        if (isset($_POST['create_training_plan']) && wp_verify_nonce($_POST['_wpnonce'], 'create_training_plan')) {
            $this->handle_create_training_plan($_POST);
        }
    }

    /**
     * Display main admin page
     */
    public function display_admin_page()
    {
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;

        // Check if user is connected to Strava
        $is_connected = is_strava_connected($user_id);

        // Only create API instance if class exists
        if (class_exists('Strava_Coaching_API')) {
            $strava_api = new Strava_Coaching_API();
        } else {
            $strava_api = null;
        }
        ?>
        <div class="wrap strava-coaching-admin">
            <h1>🏃‍♂️ Strava Coaching Dashboard</h1>

            <?php
            // Show success/error messages based on URL parameters
            if (isset($_GET['connected']) && $_GET['connected'] === '1') {
                $synced_count = isset($_GET['synced']) ? intval($_GET['synced']) : 0;
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p><strong>🎉 Successfully connected to Strava!</strong></p>';
                echo '<p>Synced ' . $synced_count . ' activities from the last 30 days.</p>';
                echo '</div>';
            }

            if (isset($_GET['error'])) {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p><strong>❌ Connection Error:</strong> ';
                switch ($_GET['error']) {
                    case 'invalid_callback':
                        echo 'Invalid OAuth callback parameters.';
                        break;
                    case 'invalid_state':
                        echo 'Invalid state parameter.';
                        break;
                    case 'security_failed':
                        echo 'Security verification failed.';
                        break;
                    case 'api_missing':
                        echo 'Strava API configuration missing.';
                        break;
                    case 'store_failed':
                        echo 'Failed to store authentication tokens.';
                        break;
                    case 'token_failed':
                        echo 'Failed to obtain access token from Strava.';
                        break;
                    default:
                        echo 'Unknown error occurred.';
                }
                echo ' Please try again.</p>';
                echo '</div>';
            }

            // Debug information for OAuth issues
            if (current_user_can('manage_options') && isset($_GET['debug'])) {
                echo '<div class="notice notice-info">';
                echo '<h3>Debug Information</h3>';
                echo '<p><strong>Current URL:</strong> ' . esc_html($_SERVER['REQUEST_URI']) . '</p>';
                echo '<p><strong>GET Parameters:</strong> ' . esc_html(print_r($_GET, true)) . '</p>';
                echo '<p><strong>User Connected:</strong> ' . ($is_connected ? 'Yes' : 'No') . '</p>';
                echo '<p><strong>Redirect URI:</strong> ' . esc_html(admin_url('admin.php?page=strava-coaching&action=oauth_callback')) . '</p>';
                if ($strava_api) {
                    echo '<p><strong>Auth URL:</strong> ' . esc_html($strava_api->get_auth_url($user_id)) . '</p>';
                }
                echo '</div>';
            }
            ?>

            <?php if (!$is_connected): ?>
                <div class="notice notice-info">
                    <p><strong>Connect to Strava</strong> to start syncing your activities and using the coaching features.</p>
                </div>
            <?php endif; ?>

            <div class="strava-dashboard">
                <!-- User Status Card -->
                <div class="card">
                    <h2>👤 Your Profile</h2>
                    <p><strong>Name:</strong> <?php echo esc_html($current_user->display_name); ?></p>
                    <p><strong>Role:</strong> <?php echo esc_html(implode(', ', $current_user->roles)); ?></p>
                    <p><strong>Strava Status:</strong>
                        <span
                            class="status-indicator <?php echo $is_connected ? 'status-connected' : 'status-disconnected'; ?>">
                            <?php echo $is_connected ? '🟢 Connected' : '🔴 Disconnected'; ?>
                        </span>
                    </p>

                    <?php if (!$is_connected && $strava_api): ?>
                        <a href="<?php echo esc_url($strava_api->get_auth_url($user_id)); ?>" class="btn-strava">
                            🔗 Connect to Strava
                        </a>
                    <?php elseif ($is_connected): ?>
                        <button onclick="disconnectStrava()" class="btn-strava secondary">
                            🔌 Disconnect Strava
                        </button>
                        <button onclick="syncMyActivities()" class="btn-strava secondary" style="margin-left: 10px;">
                            🔄 Sync Activities
                        </button>
                    <?php else: ?>
                        <p><em>Strava API not configured. Please check settings.</em></p>
                    <?php endif; ?>
                </div>

                <!-- Role Assignment Card -->
                <div class="card">
                    <h2>🎯 Your Role</h2>
                    <p>Select your role to access the appropriate features:</p>

                    <form method="post" action="">
                        <?php wp_nonce_field('update_user_role'); ?>
                        <p>
                            <label>
                                <input type="radio" name="user_role" value="coach" <?php checked(is_coach($user_id)); ?>>
                                🏆 Coach - Manage mentees and create training plans
                            </label>
                        </p>
                        <p>
                            <label>
                                <input type="radio" name="user_role" value="mentee" <?php checked(is_mentee($user_id)); ?>>
                                🎯 Mentee - Follow training plans and track progress
                            </label>
                        </p>
                        <button type="submit" name="update_role" class="btn-strava">Update Role</button>
                    </form>
                </div>
            </div>

            <!-- Dashboard Content Based on Role -->
            <?php if (is_coach($user_id)): ?>
                <?php $this->display_coach_dashboard($user_id); ?>
            <?php elseif (is_mentee($user_id)): ?>
                <?php $this->display_mentee_dashboard($user_id); ?>
            <?php else: ?>
                <div class="card">
                    <h2>🚀 Get Started</h2>
                    <p>Choose your role above to access your personalized dashboard!</p>
                </div>
            <?php endif; ?>

            <!-- System Status -->
            <div class="card">
                <h2>⚙️ System Status</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div>
                        <strong>Database:</strong><br>
                        <span
                            class="status-indicator <?php echo $this->check_database_tables() ? 'status-connected' : 'status-disconnected'; ?>">
                            <?php echo $this->check_database_tables() ? '✅ Ready' : '❌ Error'; ?>
                        </span>
                    </div>
                    <div>
                        <strong>Strava API:</strong><br>
                        <span
                            class="status-indicator <?php echo $this->check_strava_config() ? 'status-connected' : 'status-disconnected'; ?>">
                            <?php echo $this->check_strava_config() ? '✅ Configured' : '❌ Missing'; ?>
                        </span>
                    </div>
                    <div>
                        <strong>Activities Synced:</strong><br>
                        <span class="status-indicator status-pending">
                            <?php echo $this->count_user_activities($user_id); ?> activities
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Handle OAuth callback detection and auto-refresh
            jQuery(document).ready(function ($) {
                // Check if we're on an OAuth callback URL that hasn't been processed
                const urlParams = new URLSearchParams(window.location.search);
                const hasOAuthCallback = urlParams.get('action') === 'oauth_callback' && urlParams.get('code');
                const hasSuccessMessage = urlParams.get('connected') === '1';

                // If we have OAuth callback parameters but no success message, force refresh
                if (hasOAuthCallback && !hasSuccessMessage) {
                    console.log('OAuth callback detected, processing...');
                    // Show loading message
                    $('body').append('<div id="oauth-processing" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);color:white;display:flex;align-items:center;justify-content:center;z-index:9999;font-size:18px;"><div>🔄 Processing Strava connection...</div></div>');

                    // Force a page reload to process the callback
                    setTimeout(function () {
                        window.location.reload();
                    }, 1000);

                    return; // Don't execute other scripts
                }
            });

            function disconnectStrava() {
                if (confirm('Are you sure you want to disconnect from Strava? This will remove all synced data.')) {
                    // Show loading
                    jQuery('body').append('<div id="disconnect-loading" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);color:white;display:flex;align-items:center;justify-content:center;z-index:9999;font-size:18px;"><div>🔌 Disconnecting from Strava...</div></div>');

                    jQuery.post(ajaxurl, {
                        action: 'disconnect_strava',
                        user_id: <?php echo $user_id; ?>,
                        nonce: '<?php echo wp_create_nonce("disconnect_strava"); ?>'
                    }, function (response) {
                        jQuery('#disconnect-loading').remove();
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Failed to disconnect. Please try again.');
                        }
                    }).fail(function () {
                        jQuery('#disconnect-loading').remove();
                        alert('Network error. Please try again.');
                    });
                }
            }

            function syncMyActivities() {
                // Show loading
                jQuery('body').append('<div id="sync-loading" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);color:white;display:flex;align-items:center;justify-content:center;z-index:9999;font-size:18px;"><div>🔄 Syncing activities from Strava...</div></div>');

                jQuery.post(ajaxurl, {
                    action: 'sync_strava_activities'
                }, function (response) {
                    jQuery('#sync-loading').remove();
                    if (response.success) {
                        alert('✅ ' + response.data.message);
                        location.reload();
                    } else {
                        alert('❌ ' + (response.data.message || 'Failed to sync activities'));
                    }
                }).fail(function () {
                    jQuery('#sync-loading').remove();
                    alert('❌ Network error occurred');
                });
            }
        </script>
        <?php
    }

    /**
     * Display settings page
     */
    public function display_settings_page()
    {
        ?>
        <div class="wrap">
            <h1>Strava Coaching Settings</h1>

            <form method="post" action="options.php">
                <?php settings_fields('strava_coaching_settings'); ?>
                <?php do_settings_sections('strava_coaching_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Strava Client ID</th>
                        <td>
                            <input type="text" name="strava_coaching_client_id"
                                value="<?php echo esc_attr(get_option('strava_coaching_client_id')); ?>" class="regular-text" />
                            <p class="description">Your Strava API Client ID</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Strava Client Secret</th>
                        <td>
                            <input type="password" name="strava_coaching_client_secret"
                                value="<?php echo esc_attr(get_option('strava_coaching_client_secret')); ?>"
                                class="regular-text" />
                            <p class="description">Your Strava API Client Secret</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <div class="card">
                <h2>Strava API Setup</h2>
                <p>To get your Strava API credentials:</p>
                <ol>
                    <li>Go to <a href="https://www.strava.com/settings/api" target="_blank">Strava API Settings</a></li>
                    <li>Create a new application</li>
                    <li><strong>Authorization Callback Domain:</strong>
                        <code><?php echo esc_html(parse_url(home_url(), PHP_URL_HOST)); ?></code>
                    </li>
                    <li>Copy Client ID and Client Secret here</li>
                </ol>

                <h3>Current Configuration Status</h3>
                <table class="widefat">
                    <tr>
                        <td><strong>Client ID Configured:</strong></td>
                        <td><?php echo $this->check_strava_config() ? '✅ Yes' : '❌ No'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Redirect URI:</strong></td>
                        <td><code><?php echo esc_html(admin_url('admin.php?page=strava-coaching&action=oauth_callback')); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Domain for Strava:</strong></td>
                        <td><code><?php echo esc_html(parse_url(home_url(), PHP_URL_HOST)); ?></code></td>
                    </tr>
                </table>
            </div>

            <?php if (current_user_can('manage_options')): ?>
                <div class="card">
                    <h2>Debug Tools</h2>
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=strava-coaching&debug=1'); ?>" class="button">
                            🔍 Debug OAuth Flow
                        </a>
                    </p>
                    <p class="description">Shows detailed information about the OAuth configuration and flow.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Enhanced display_coach_dashboard method
     */
    private function display_coach_dashboard($user_id)
    {
        $mentees = get_coach_mentees($user_id);
        ?>
        <div class="card">
            <h2>🏆 Coach Dashboard</h2>

            <!-- Quick Stats -->
            <div class="coach-stats"
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($mentees); ?></div>
                    <div class="stat-label">Active Mentees</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $this->count_active_plans($user_id); ?></div>
                    <div class="stat-label">Active Plans</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $this->count_weekly_activities_all_mentees($user_id); ?></div>
                    <div class="stat-label">This Week's Activities</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $this->count_unscored_activities($user_id); ?></div>
                    <div class="stat-label">Pending Reviews</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <!-- Mentees Section -->
                <div>
                    <h3>👥 Your Mentees (<?php echo count($mentees); ?>)</h3>
                    <?php if (empty($mentees)): ?>
                        <div class="empty-state">
                            <p>🎯 No mentees assigned yet.</p>
                            <p>Start building your coaching team!</p>
                            <button class="btn-strava" onclick="showAddMenteeModal()">➕ Add Your First Mentee</button>
                        </div>
                    <?php else: ?>
                        <div class="mentees-grid">
                            <?php foreach ($mentees as $mentee): ?>
                                <div class="mentee-card">
                                    <button class="remove-mentee-button btn-small btn-primary" data-mentee-id="<?= $mentee->ID ?>" data-mentee-name="<?= $mentee->display_name ?>"> Remove X</button>
                                    <div class="mentee-header" style="margin-top:15px;">
                                        <div class="mentee-avatar">
                                            <?php echo get_avatar($mentee->ID, 40); ?>
                                        </div>
                                        <div class="mentee-info">
                                            <div class="mentee-name"><?php echo esc_html($mentee->display_name); ?></div>
                                            <div class="mentee-email"><?php echo esc_html($mentee->user_email); ?></div>
                                        </div>
                                    </div>

                                    <div class="mentee-stats">
                                        <div class="stat-row">
                                            <span>🏃‍♂️ Last activity:</span>
                                            <span><?php echo $this->get_last_activity_date($mentee->ID); ?></span>
                                        </div>
                                        <div class="stat-row">
                                            <span>📊 This week:</span>
                                            <span><?php echo $this->count_weekly_activities($mentee->ID); ?> activities</span>
                                        </div>
                                        <div class="stat-row">
                                            <span>📋 Current plan:</span>
                                            <span><?php echo $this->get_current_plan_name($mentee->ID); ?></span>
                                        </div>
                                    </div>

                                    <div class="mentee-actions">
                                        <button class="btn-small btn-primary" onclick="viewMenteeProgress(<?php echo $mentee->ID; ?>)">
                                            📊 Progress
                                        </button>
                                        <button class="btn-small btn-secondary"
                                            onclick="createPlanForMentee(<?php echo $mentee->ID; ?>)">
                                            📋 New Plan
                                        </button>
                                        <button class="btn-small btn-outline" onclick="scoreActivities(<?php echo $mentee->ID; ?>)">
                                            ⭐ Score
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="btn-strava" onclick="showAddMenteeModal()" style="margin-top: 15px;">
                            ➕ Add Another Mentee
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions Section -->
                <div>
                    <h3>🚀 Quick Actions</h3>
                    <div class="quick-actions">
                        <button class="action-card" onclick="showTrainingPlanModal()">
                            <div class="action-icon">📋</div>
                            <div class="action-title">Create Training Plan</div>
                            <div class="action-desc">Build custom weekly training plans</div>
                        </button>

                        <button class="action-card" onclick="showPlanTemplates()">
                            <div class="action-icon">📚</div>
                            <div class="action-title">Plan Templates</div>
                            <div class="action-desc">Use pre-built 5K, 10K, Marathon plans</div>
                        </button>

                        <button class="action-card" onclick="viewAllActivities()">
                            <div class="action-icon">📈</div>
                            <div class="action-title">Activity Overview</div>
                            <div class="action-desc">Review all mentee activities</div>
                        </button>

                        <button class="action-card" onclick="generateReports()">
                            <div class="action-icon">📊</div>
                            <div class="action-title">Generate Reports</div>
                            <div class="action-desc">Weekly progress summaries</div>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity Feed -->
        <div class="card">
            <h3>📈 Recent Activity Feed</h3>
            <?php $this->display_recent_activity_feed($user_id); ?>
        </div>

        <!-- Charts Section -->
        <div class="card">
            <h2>📊 Performance Analytics</h2>

            <!-- Chart Controls -->
            <div class="chart-controls">
                <div class="chart-control">
                    <label for="chartMenteeSelector">Select Mentee</label>
                    <select id="chartMenteeSelector">
                        <option value="">All Mentees</option>
                        <?php foreach ($mentees as $mentee): ?>
                            <option value="<?php echo $mentee->ID; ?>">
                                <?php echo esc_html($mentee->display_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="chart-control">
                    <label for="activityTypeFilter">Activity Type</label>
                    <select id="activityTypeFilter">
                        <option value="all">All Activities</option>
                        <option value="Run">Running</option>
                        <option value="Ride">Cycling</option>
                        <option value="Swim">Swimming</option>
                    </select>
                </div>

                <div class="chart-control">
                    <label for="dateRangeFilter">Date Range</label>
                    <select id="dateRangeFilter">
                        <option value="7days">Last 7 Days</option>
                        <option value="30days" selected>Last 30 Days</option>
                        <option value="90days">Last 90 Days</option>
                    </select>
                </div>
            </div>

            <!-- Charts Grid -->
            <div class="charts-grid">
                <!-- Activity Progress Chart -->
                <div class="strava-chart-container chart-full-width">
                    <h3>Activity Progress</h3>
                    <canvas id="activityProgressChart" class="chart-canvas"></canvas>
                </div>

                <!-- Weekly Summary Chart -->
                <div class="strava-chart-container chart-half-width">
                    <h3>Weekly Summary</h3>
                    <canvas id="weeklySummaryChart" class="chart-canvas"></canvas>
                </div>

                <!-- Heart Rate Distribution -->
                <div class="strava-chart-container chart-half-width">
                    <h3>Heart Rate Zones</h3>
                    <canvas id="heartRateChart" class="chart-canvas"></canvas>
                </div>
            </div>
        </div>

        <?php
        // Include modals for training plan creation
        $this->include_training_plan_modals();
    }

    /**
     * Display recent activity feed for coach
     */
    private function display_recent_activity_feed($coach_id)
    {
        $recent_activities = $this->get_recent_mentee_activities($coach_id, 10);

        if (empty($recent_activities)) {
            echo '<div class="empty-state">';
            echo '<p>📱 No recent activities from your mentees.</p>';
            echo '<p>Activities will appear here once your mentees connect Strava and start training!</p>';
            echo '</div>';
            return;
        }

        echo '<div class="activity-feed">';
        foreach ($recent_activities as $activity) {
            $mentee = get_user_by('id', $activity->user_id);
            $time_ago = human_time_diff(strtotime($activity->start_date));

            echo '<div class="activity-item">';
            echo '<div class="activity-avatar">' . get_avatar($activity->user_id, 32) . '</div>';
            echo '<div class="activity-content">';
            echo '<div class="activity-header">';
            echo '<span class="activity-user">' . esc_html($mentee->display_name) . '</span>';
            echo '<span class="activity-time">' . $time_ago . ' ago</span>';
            echo '</div>';
            echo '<div class="activity-details">';
            echo '<span class="activity-icon">' . get_activity_icon($activity->activity_type) . '</span>';
            echo '<span class="activity-name">' . esc_html($activity->name) . '</span>';
            echo '<span class="activity-stats">';
            echo format_distance($activity->distance) . ' km • ';
            echo format_duration($activity->moving_time);
            if ($activity->average_heartrate) {
                echo ' • ❤️ ' . round($activity->average_heartrate) . ' bpm';
            }
            echo '</span>';
            echo '</div>';
            echo '</div>';
            echo '<div class="activity-actions">';
            echo '<button class="btn-mini" onclick="scoreActivity(' . $activity->id . ')">⭐ Score</button>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Include training plan creation modals
     */
    private function include_training_plan_modals()
    {
        ?>
        <!-- Training Plan Creator Modal -->
        <div id="trainingPlanModal" class="strava-modal" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>📋 Create Training Plan</h2>
                    <button class="modal-close" onclick="closeModal('trainingPlanModal')">&times;</button>
                </div>

                <form id="trainingPlanForm" method="post">
                    <?php wp_nonce_field('create_training_plan'); ?>

                    <div class="modal-body">
                        <!-- Plan Details -->
                        <div class="form-section">
                            <h3>Plan Details</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="plan_name">Plan Name</label>
                                    <input type="text" id="plan_name" name="plan_name" placeholder="e.g., 10K Training - Week 1"
                                        required>
                                </div>
                                <div class="form-group">
                                    <label for="mentee_id">Assign to Mentee</label>
                                    <select id="mentee_id" name="mentee_id" required>
                                        <option value="">Select mentee...</option>
                                        <?php foreach (get_coach_mentees(get_current_user_id()) as $mentee): ?>
                                            <option value="<?php echo $mentee->ID; ?>">
                                                <?php echo esc_html($mentee->display_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="week_start">Week Start Date</label>
                                    <input type="date" id="week_start" name="week_start" required>
                                </div>
                                <div class="form-group">
                                    <label for="plan_type">Plan Type</label>
                                    <select id="plan_type" name="plan_type">
                                        <option value="custom">Custom Plan</option>
                                        <option value="5k">5K Training</option>
                                        <option value="10k">10K Training</option>
                                        <option value="half_marathon">Half Marathon</option>
                                        <option value="marathon">Marathon</option>
                                        <option value="maintenance">Maintenance</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Weekly Planner -->
                        <div class="form-section">
                            <h3>Weekly Schedule</h3>
                            <div class="weekly-planner">
                                <?php
                                $days = array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');
                                foreach ($days as $day):
                                    ?>
                                    <div class="day-planner">
                                        <h4><?php echo $day; ?></h4>
                                        <div class="workout-slot" data-day="<?php echo strtolower($day); ?>">
                                            <select name="workouts[<?php echo strtolower($day); ?>][type]" class="workout-type">
                                                <option value="">Rest Day</option>
                                                <option value="run">🏃‍♂️ Run</option>
                                                <option value="bike">🚴‍♂️ Bike</option>
                                                <option value="swim">🏊‍♂️ Swim</option>
                                                <option value="strength">💪 Strength</option>
                                                <option value="cross_training">🏋️‍♂️ Cross Training</option>
                                            </select>

                                            <div class="workout-details" style="display: none;">
                                                <input type="number" name="workouts[<?php echo strtolower($day); ?>][distance]"
                                                    placeholder="Distance (km)" step="0.1" min="0">
                                                <input type="text" name="workouts[<?php echo strtolower($day); ?>][pace]"
                                                    placeholder="Target pace (e.g., 5:30/km)">
                                                <input type="number" name="workouts[<?php echo strtolower($day); ?>][duration]"
                                                    placeholder="Duration (minutes)" min="0">
                                                <textarea name="workouts[<?php echo strtolower($day); ?>][notes]"
                                                    placeholder="Notes and instructions..." rows="2"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Plan Notes -->
                        <div class="form-section">
                            <h3>Plan Notes</h3>
                            <textarea name="plan_notes" rows="3"
                                placeholder="General instructions, goals, or notes for this week's plan..."></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('trainingPlanModal')">Cancel</button>
                        <button type="submit" name="create_training_plan" class="btn-strava">Create Plan</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add Mentee Modal -->
        <div id="addMenteeModal" class="strava-modal" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>👥 Add Mentee</h2>
                    <button class="modal-close" onclick="closeModal('addMenteeModal')">&times;</button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label for="mentee_search">Search for User</label>
                        <input type="text" id="mentee_search" placeholder="Enter name or email...">
                        <div id="mentee_search_results" class="search-results"></div>
                    </div>

                    <div class="mentee-invitation">
                        <h4>Or invite new user</h4>
                        <p>Send an invitation email to someone not yet on your WordPress site.</p>
                        <input type="email" id="invite_email" placeholder="Enter email address...">
                        <button class="btn-secondary" onclick="sendInvitation()">Send Invitation</button>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('addMenteeModal')">Cancel</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Enhanced mentee dashboard
     */
    private function display_mentee_dashboard($user_id)
    {
        $coach = get_mentee_coach($user_id);
        $recent_activities = $this->get_recent_activities($user_id, 5);
        $current_plan = $this->get_current_training_plan($user_id);
        $upcoming_workouts = $this->get_upcoming_workouts($user_id);
        ?>
        <div class="card">
            <h2>🎯 Mentee Dashboard</h2>

            <!-- Quick Stats -->
            <div class="mentee-stats-grid"
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $this->count_weekly_activities($user_id); ?></div>
                    <div class="stat-label">This Week</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo round($this->get_weekly_distance($user_id), 1); ?> km</div>
                    <div class="stat-label">Distance</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $this->get_avg_weekly_score($user_id); ?>/10</div>
                    <div class="stat-label">Avg Score</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo round($this->calculate_weekly_progress($user_id)); ?>%</div>
                    <div class="stat-label">Plan Progress</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <!-- Coach & Plan Section -->
                <div>
                    <h3>👨‍🏫 Your Coach</h3>
                    <?php if ($coach): ?>
                        <div class="coach-info">
                            <?php echo get_avatar($coach->ID, 50); ?>
                            <div class="coach-details">
                                <div class="coach-name"><?php echo esc_html($coach->display_name); ?></div>
                                <div class="coach-email">📧 <?php echo esc_html($coach->user_email); ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>No coach assigned yet.</p>
                            <p>Contact an administrator to get assigned to a coach.</p>
                        </div>
                    <?php endif; ?>

                    <h3>📋 Current Training Plan</h3>
                    <?php if ($current_plan): ?>
                        <div class="current-plan">
                            <div class="plan-header">
                                <div class="plan-name"><?php echo esc_html($current_plan->plan_name); ?></div>
                                <div class="plan-dates">
                                    <?php echo date('M j', strtotime($current_plan->week_start)); ?> -
                                    <?php echo date('M j', strtotime($current_plan->week_end)); ?>
                                </div>
                            </div>

                            <?php if ($current_plan->plan_data): ?>
                                <div class="plan-details">
                                    <?php
                                    $plan_data = json_decode($current_plan->plan_data, true);
                                    if (isset($plan_data['notes']) && $plan_data['notes']):
                                        ?>
                                        <div class="plan-notes">
                                            <strong>Coach Notes:</strong><br>
                                            <?php echo nl2br(esc_html($plan_data['notes'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <button class="btn-strava" onclick="viewFullPlan(<?php echo $current_plan->id; ?>)">
                                📋 View Full Plan
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>📋 No active training plan.</p>
                            <p>Your coach will assign a plan soon!</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Activities Section -->
                <div>
                    <h3>🏃‍♂️ Recent Activities</h3>
                    <?php if (empty($recent_activities)): ?>
                        <div class="empty-state">
                            <p>No activities found.</p>
                            <p>Make sure your Strava account is connected!</p>
                            <button class="btn-strava" onclick="syncMyActivities()">🔄 Sync Now</button>
                        </div>
                    <?php else: ?>
                        <div class="activities-list">
                            <?php foreach ($recent_activities as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-header">
                                        <span class="activity-icon"><?php echo get_activity_icon($activity->activity_type); ?></span>
                                        <span class="activity-name"><?php echo esc_html($activity->name); ?></span>
                                        <span class="activity-date"><?php echo date('M j', strtotime($activity->start_date)); ?></span>
                                    </div>

                                    <div class="activity-stats">
                                        <?php if ($activity->distance): ?>
                                            <span>📏 <?php echo format_distance($activity->distance); ?> km</span>
                                        <?php endif; ?>

                                        <?php if ($activity->moving_time): ?>
                                            <span>⏱️ <?php echo format_duration($activity->moving_time); ?></span>
                                        <?php endif; ?>

                                        <?php if ($activity->average_heartrate): ?>
                                            <span>❤️ <?php echo round($activity->average_heartrate); ?> bpm</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php
                                    $score = $this->get_activity_score($activity->id);
                                    if ($score):
                                        ?>
                                        <div class="activity-score">
                                            <span class="score-badge score-<?php echo $this->get_score_class($score->overall_score); ?>">
                                                ⭐ <?php echo $score->overall_score; ?>/10
                                            </span>
                                            <?php if ($score->comments): ?>
                                                <div class="score-comments">
                                                    <strong>Coach feedback:</strong> <?php echo esc_html($score->comments); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="activity-score">
                                            <span class="score-pending">⏳ Pending review</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="activity-actions">
                            <button class="btn-strava" onclick="syncMyActivities()">🔄 Sync Activities</button>
                            <button class="btn-strava secondary" onclick="viewAllActivities()">📈 View All</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Upcoming Workouts -->
        <?php if ($upcoming_workouts && !empty($upcoming_workouts)): ?>
            <div class="card">
                <h3>📅 This Week's Workouts</h3>
                <?php $this->display_weekly_workouts($upcoming_workouts); ?>
            </div>
        <?php endif; ?>

        <!-- My Progress Charts -->
        <div class="card">
            <h2>📊 My Progress</h2>

            <div class="chart-controls">
                <div class="chart-control">
                    <label for="myActivityFilter">Activity Type</label>
                    <select id="activityTypeFilter">
                        <option value="all">All Activities</option>
                        <option value="Run">Running</option>
                        <option value="Ride">Cycling</option>
                        <option value="Swim">Swimming</option>
                    </select>
                </div>

                <div class="chart-control">
                    <label for="myDateRangeFilter">Date Range</label>
                    <select id="dateRangeFilter">
                        <option value="7days">Last 7 Days</option>
                        <option value="30days" selected>Last 30 Days</option>
                        <option value="90days">Last 90 Days</option>
                    </select>
                </div>
            </div>

            <div class="charts-grid">
                <!-- Activity Progress Chart -->
                <div class="strava-chart-container chart-full-width">
                    <h3>My Activity Progress</h3>
                    <canvas id="activityProgressChart" class="chart-canvas"></canvas>
                </div>

                <!-- Plan vs Actual (if training plan exists) -->
                <?php if ($current_plan): ?>
                    <div class="strava-chart-container chart-full-width">
                        <h3>This Week: Plan vs Actual</h3>
                        <canvas id="planVsActualChart" class="chart-canvas"></canvas>
                    </div>
                    <script>
                        jQuery(document).ready(function () {
                            loadPlanVsActualChart(<?php echo $user_id; ?>, '<?php echo $current_plan->week_start; ?>');
                        });
                    </script>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Display weekly workouts for mentee
     */
    private function display_weekly_workouts($workouts)
    {
        $days = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
        $day_names = array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');

        echo '<div class="weekly-workout-display">';

        for ($i = 0; $i < 7; $i++) {
            $day = $days[$i];
            $day_name = $day_names[$i];
            $workout = isset($workouts[$day]) ? $workouts[$day] : null;

            echo '<div class="workout-day">';
            echo '<h4>' . $day_name . '</h4>';

            if ($workout && !empty($workout['type'])) {
                echo '<div class="workout-details">';
                echo '<div class="workout-type">' . get_activity_icon($workout['type']) . ' ' . ucfirst($workout['type']) . '</div>';

                if (!empty($workout['distance'])) {
                    echo '<div class="workout-target">📏 ' . $workout['distance'] . ' km</div>';
                }

                if (!empty($workout['pace'])) {
                    echo '<div class="workout-target">⏱️ ' . $workout['pace'] . '</div>';
                }

                if (!empty($workout['duration'])) {
                    echo '<div class="workout-target">⏲️ ' . $workout['duration'] . ' min</div>';
                }

                if (!empty($workout['notes'])) {
                    echo '<div class="workout-notes">' . esc_html($workout['notes']) . '</div>';
                }
                echo '</div>';
            } else {
                echo '<div class="rest-day">😴 Rest Day</div>';
            }

            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Check if database tables exist
     */
    private function check_database_tables()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'strava_activities';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        return $table_exists;
    }

    /**
     * Check if Strava API is configured
     */
    private function check_strava_config()
    {
        return (defined('STRAVA_CLIENT_ID') && !empty(STRAVA_CLIENT_ID)) ||
            (!empty(get_option('strava_coaching_client_id')));
    }

    /**
     * Get user's recent activities
     */
    private function get_recent_activities($user_id, $limit = 5)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'strava_activities';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d ORDER BY start_date DESC LIMIT %d",
            $user_id,
            $limit
        ));
    }

    /**
     * Count user activities
     */
    private function count_user_activities($user_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'strava_activities';

        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get last activity date
     */
    private function get_last_activity_date($user_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'strava_activities';

        $last_date = $wpdb->get_var($wpdb->prepare(
            "SELECT start_date FROM $table_name WHERE user_id = %d ORDER BY start_date DESC LIMIT 1",
            $user_id
        ));

        return $last_date ? human_time_diff(strtotime($last_date)) . ' ago' : 'No activities';
    }

    /**
     * Count weekly activities
     */
    private function count_weekly_activities($user_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'strava_activities';

        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND start_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            $user_id
        ));
    }

    /**
     * Calculate weekly progress (placeholder)
     */
    private function calculate_weekly_progress($user_id)
    {
        $weekly_activities = $this->count_weekly_activities($user_id);
        $target_activities = 3; // Default target

        return min(100, ($weekly_activities / $target_activities) * 100);
    }

    /**
     * Helper methods for enhanced dashboard
     */
    private function count_active_plans($coach_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'strava_training_plans';

        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE coach_id = %d AND status = 'active'",
            $coach_id
        ));
    }

    private function count_weekly_activities_all_mentees($coach_id)
    {
        $mentees = get_coach_mentees($coach_id);
        $total = 0;

        foreach ($mentees as $mentee) {
            $total += $this->count_weekly_activities($mentee->ID);
        }

        return $total;
    }

    private function count_unscored_activities($coach_id)
    {
        global $wpdb;

        $query = "
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}strava_activities sa
            INNER JOIN {$wpdb->prefix}coach_mentee_relationships cmr ON sa.user_id = cmr.mentee_id
            LEFT JOIN {$wpdb->prefix}activity_scores acs ON sa.id = acs.activity_id
            WHERE cmr.coach_id = %d 
            AND cmr.status = 'active'
            AND acs.id IS NULL
            AND sa.start_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";

        return $wpdb->get_var($wpdb->prepare($query, $coach_id));
    }

    private function get_current_plan_name($mentee_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'strava_training_plans';

        $plan_name = $wpdb->get_var($wpdb->prepare(
            "SELECT plan_name FROM $table_name 
             WHERE mentee_id = %d AND status = 'active' 
             AND week_start <= CURDATE() AND week_end >= CURDATE()
             ORDER BY created_at DESC LIMIT 1",
            $mentee_id
        ));

        return $plan_name ?: 'No active plan';
    }

    private function get_recent_mentee_activities($coach_id, $limit = 10)
    {
        global $wpdb;

        $query = "
            SELECT sa.*, u.display_name
            FROM {$wpdb->prefix}strava_activities sa
            INNER JOIN {$wpdb->prefix}coach_mentee_relationships cmr ON sa.user_id = cmr.mentee_id
            INNER JOIN {$wpdb->prefix}users u ON sa.user_id = u.ID
            WHERE cmr.coach_id = %d AND cmr.status = 'active'
            ORDER BY sa.start_date DESC
            LIMIT %d
        ";

        return $wpdb->get_results($wpdb->prepare($query, $coach_id, $limit));
    }

    private function get_current_training_plan($mentee_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'strava_training_plans';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name 
             WHERE mentee_id = %d AND status = 'active' 
             AND week_start <= CURDATE() AND week_end >= CURDATE()
             ORDER BY created_at DESC LIMIT 1",
            $mentee_id
        ));
    }

    private function get_upcoming_workouts($mentee_id)
    {
        $current_plan = $this->get_current_training_plan($mentee_id);

        if (!$current_plan || !$current_plan->plan_data) {
            return null;
        }

        $plan_data = json_decode($current_plan->plan_data, true);
        return isset($plan_data['workouts']) ? $plan_data['workouts'] : null;
    }

    private function get_weekly_distance($user_id)
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(distance) FROM {$wpdb->prefix}strava_activities 
             WHERE user_id = %d AND start_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            $user_id
        )) / 1000;
    }

    private function get_avg_weekly_score($user_id)
    {
        global $wpdb;

        $avg_score = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(acs.overall_score) 
             FROM {$wpdb->prefix}activity_scores acs
             INNER JOIN {$wpdb->prefix}strava_activities sa ON acs.activity_id = sa.id
             WHERE sa.user_id = %d AND sa.start_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            $user_id
        ));

        return $avg_score ? round($avg_score, 1) : '--';
    }

    private function get_activity_score($activity_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'activity_scores';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE activity_id = %d",
            $activity_id
        ));
    }

    private function get_score_class($score)
    {
        if ($score >= 8)
            return 'excellent';
        if ($score >= 6)
            return 'good';
        if ($score >= 4)
            return 'average';
        return 'poor';
    }

    private function handle_create_training_plan($post_data)
    {
        global $wpdb;

        $plan_name = sanitize_text_field($post_data['plan_name']);
        $mentee_id = intval($post_data['mentee_id']);
        $week_start = sanitize_text_field($post_data['week_start']);
        $plan_type = sanitize_text_field($post_data['plan_type']);
        $plan_notes = sanitize_textarea_field($post_data['plan_notes']);

        $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));

        $plan_data = array(
            'type' => $plan_type,
            'notes' => $plan_notes,
            'workouts' => array()
        );

        if (isset($post_data['workouts'])) {
            foreach ($post_data['workouts'] as $day => $workout) {
                if (!empty($workout['type'])) {
                    $plan_data['workouts'][$day] = array(
                        'type' => sanitize_text_field($workout['type']),
                        'distance' => floatval($workout['distance']),
                        'pace' => sanitize_text_field($workout['pace']),
                        'duration' => intval($workout['duration']),
                        'notes' => sanitize_textarea_field($workout['notes'])
                    );
                }
            }
        }

        $table_name = $wpdb->prefix . 'strava_training_plans';

        $result = $wpdb->insert(
            $table_name,
            array(
                'coach_id' => get_current_user_id(),
                'mentee_id' => $mentee_id,
                'plan_name' => $plan_name,
                'week_start' => $week_start,
                'week_end' => $week_end,
                'plan_data' => wp_json_encode($plan_data),
                'status' => 'active'
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p><strong>🎉 Training plan created successfully!</strong></p>';
                echo '</div>';
            });
        } else {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p><strong>❌ Failed to create training plan.</strong> Please try again.</p>';
                echo '</div>';
            });
        }
    }

    /**
     * AJAX HANDLERS
     */
    public function ajax_search_users()
    {
        check_ajax_referer('search_users', 'nonce');

        if (!is_coach(get_current_user_id())) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $query = sanitize_text_field($_POST['query']);
        $coach_id = get_current_user_id();

        // Get existing mentees to exclude
        $existing_mentees = get_coach_mentees($coach_id);
        $exclude_ids = array_map(function ($mentee) {
            return $mentee->ID;
        }, $existing_mentees);
        $exclude_ids[] = $coach_id;

        $user_query = new WP_User_Query(array(
            'search' => '*' . $query . '*',
            'search_columns' => array('user_login', 'user_email', 'display_name'),
            'exclude' => $exclude_ids,
            'number' => 10,
            'fields' => array('ID', 'display_name', 'user_email')
        ));

        $users = array();
        foreach ($user_query->get_results() as $user) {
            $users[] = array(
                'ID' => $user->ID,
                'display_name' => $user->display_name,
                'user_email' => $user->user_email,
                'avatar' => get_avatar_url($user->ID, array('size' => 32))
            );
        }

        wp_send_json_success(array('users' => $users));
    }

    public function ajax_add_mentee()
    {
        check_ajax_referer('add_mentee', 'nonce');

        $coach_id = get_current_user_id();
        $mentee_id = intval($_POST['mentee_id']);

        if (!is_coach($coach_id)) {
            wp_send_json_error(array('message' => 'Only coaches can add mentees'));
        }

        if (!$mentee_id || $mentee_id === $coach_id) {
            wp_send_json_error(array('message' => 'Invalid mentee ID'));
        }

        $mentee = get_user_by('id', $mentee_id);
        if (!$mentee) {
            wp_send_json_error(array('message' => 'User not found'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'coach_mentee_relationships';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE coach_id = %d AND mentee_id = %d",
            $coach_id,
            $mentee_id
        ));

        if ($existing) {
            wp_send_json_error(array('message' => 'User is already your mentee'));
        }

        $result = $wpdb->insert(
            $table_name,
            array(
                'coach_id' => $coach_id,
                'mentee_id' => $mentee_id,
                'status' => 'active'
            ),
            array('%d', '%d', '%s')
        );

        if ($result) {
            $mentee->add_role('mentee');
            wp_send_json_success(array('message' => 'Mentee added successfully!'));
        } else {
            wp_send_json_error(array('message' => 'Failed to add mentee'));
        }
    }

    public function ajax_remove_mentee()
    {
        check_ajax_referer('remove_mentee', 'nonce');

        $coach_id = get_current_user_id();
        $mentee_id = intval($_POST['mentee_id']);

        if (!is_coach($coach_id)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'coach_mentee_relationships';

        $result = $wpdb->delete(
            $table_name,
            array(
                'coach_id' => $coach_id,
                'mentee_id' => $mentee_id
            ),
            array('%d', '%d')
        );

        if ($result) {
            wp_send_json_success(array('message' => 'Mentee removed successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to remove mentee'));
        }
    }

    public function ajax_score_activity()
    {
        check_ajax_referer('score_activity', 'nonce');

        $coach_id = get_current_user_id();
        $activity_id = intval($_POST['activity_id']);
        $pace_score = intval($_POST['pace_score']);
        $distance_score = intval($_POST['distance_score']);
        $heart_rate_score = intval($_POST['heart_rate_score']);
        $comments = sanitize_textarea_field($_POST['comments']);

        if (!is_coach($coach_id)) {
            wp_send_json_error(array('message' => 'Only coaches can score activities'));
        }

        if (
            $pace_score < 1 || $pace_score > 10 ||
            $distance_score < 1 || $distance_score > 10 ||
            $heart_rate_score < 1 || $heart_rate_score > 10
        ) {
            wp_send_json_error(array('message' => 'Scores must be between 1 and 10'));
        }

        $overall_score = round(($pace_score + $distance_score + $heart_rate_score) / 3, 1);

        global $wpdb;
        $table_name = $wpdb->prefix . 'activity_scores';

        $result = $wpdb->insert(
            $table_name,
            array(
                'activity_id' => $activity_id,
                'coach_id' => $coach_id,
                'pace_score' => $pace_score,
                'distance_score' => $distance_score,
                'heart_rate_score' => $heart_rate_score,
                'overall_score' => $overall_score,
                'comments' => $comments
            ),
            array('%d', '%d', '%d', '%d', '%d', '%f', '%s')
        );

        if ($result !== false) {
            wp_send_json_success(array(
                'message' => 'Activity scored successfully!',
                'overall_score' => $overall_score
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to save score'));
        }
    }

    public function ajax_get_mentee_progress()
    {
        check_ajax_referer('get_mentee_progress', 'nonce');

        $coach_id = get_current_user_id();
        $mentee_id = intval($_POST['mentee_id']);

        if (!is_coach($coach_id)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        // Verify coach-mentee relationship
        $mentees = get_coach_mentees($coach_id);
        $is_valid_mentee = false;
        foreach ($mentees as $mentee) {
            if ($mentee->ID == $mentee_id) {
                $is_valid_mentee = true;
                break;
            }
        }

        if (!$is_valid_mentee) {
            wp_send_json_error(array('message' => 'Invalid mentee'));
        }

        // Get progress data
        $progress_data = array(
            'mentee_info' => get_user_by('id', $mentee_id),
            'weekly_stats' => $this->calculate_weekly_stats($mentee_id),
            'activities' => $this->get_mentee_activities_with_scores($mentee_id)
        );

        wp_send_json_success($progress_data);
    }

    /**
     * Chart AJAX Handlers
     */
    public function ajax_get_activity_chart_data()
    {
        check_ajax_referer('chart_data', 'nonce');

        $user_id = intval($_POST['user_id']);
        $activity_type = sanitize_text_field($_POST['activity_type']);
        $date_range = sanitize_text_field($_POST['date_range']);

        // Verify permissions
        if (!$this->can_view_user_data($user_id)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $data = $this->get_activity_progress_data($user_id, $activity_type, $date_range);
        wp_send_json_success($data);
    }

    public function ajax_get_weekly_summary_data()
    {
        check_ajax_referer('chart_data', 'nonce');

        $user_id = intval($_POST['user_id']);

        if (!$this->can_view_user_data($user_id)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $data = $this->get_weekly_summary_data($user_id);
        wp_send_json_success($data);
    }

    public function ajax_get_heart_rate_data()
    {
        check_ajax_referer('chart_data', 'nonce');

        $user_id = intval($_POST['user_id']);
        $date_range = sanitize_text_field($_POST['date_range']);

        if (!$this->can_view_user_data($user_id)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $data = $this->get_heart_rate_distribution($user_id, $date_range);
        wp_send_json_success($data);
    }

    public function ajax_get_plan_vs_actual_data()
    {
        check_ajax_referer('chart_data', 'nonce');

        $mentee_id = intval($_POST['mentee_id']);
        $week_start = sanitize_text_field($_POST['week_start']);

        if (!is_coach(get_current_user_id())) {
            wp_send_json_error(array('message' => 'Only coaches can view this data'));
        }

        $data = $this->get_plan_vs_actual_data($mentee_id, $week_start);
        wp_send_json_success($data);
    }

    /**
     * Chart Helper Methods
     */
    private function can_view_user_data($target_user_id)
    {
        $current_user_id = get_current_user_id();

        // User can view their own data
        if ($current_user_id === $target_user_id) {
            return true;
        }

        // Coach can view their mentees' data
        if (is_coach($current_user_id)) {
            $mentees = get_coach_mentees($current_user_id);
            foreach ($mentees as $mentee) {
                if ($mentee->ID == $target_user_id) {
                    return true;
                }
            }
        }

        return false;
    }

    private function get_activity_progress_data($user_id, $activity_type, $date_range)
    {
        global $wpdb;

        // Calculate date range
        $days = $date_range === '7days' ? 7 : ($date_range === '30days' ? 30 : 90);
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        // Build query
        $query = "SELECT 
                    DATE(start_date) as activity_date,
                    AVG(distance) as avg_distance,
                    AVG(average_speed) as avg_speed,
                    activity_type
                  FROM {$wpdb->prefix}strava_activities
                  WHERE user_id = %d
                  AND start_date >= %s";

        $params = array($user_id, $start_date);

        if ($activity_type !== 'all') {
            $query .= " AND activity_type = %s";
            $params[] = $activity_type;
        }

        $query .= " GROUP BY DATE(start_date), activity_type
                    ORDER BY activity_date ASC";

        $results = $wpdb->get_results($wpdb->prepare($query, $params));

        // Process data for chart
        $labels = array();
        $distance = array();
        $pace = array();

        foreach ($results as $row) {
            $labels[] = date('M j', strtotime($row->activity_date));
            $distance[] = round($row->avg_distance / 1000, 2); // Convert to km

            // Calculate pace (min/km) from speed (m/s)
            if ($row->avg_speed > 0) {
                $pace_seconds = 1000 / $row->avg_speed;
                $pace[] = round($pace_seconds / 60, 2); // Minutes per km
            } else {
                $pace[] = 0;
            }
        }

        return array(
            'labels' => $labels,
            'distance' => $distance,
            'pace' => $pace
        );
    }

    private function get_weekly_summary_data($user_id)
    {
        global $wpdb;

        $query = "SELECT 
                    WEEK(start_date) as week_num,
                    YEAR(start_date) as year,
                    SUM(distance) as total_distance,
                    SUM(moving_time) as total_time,
                    COUNT(*) as activity_count
                  FROM {$wpdb->prefix}strava_activities
                  WHERE user_id = %d
                  AND start_date >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
                  GROUP BY YEAR(start_date), WEEK(start_date)
                  ORDER BY year DESC, week_num DESC
                  LIMIT 8";

        $results = $wpdb->get_results($wpdb->prepare($query, $user_id));

        // Reverse to show oldest first
        $results = array_reverse($results);

        $labels = array();
        $distance = array();
        $duration = array();

        foreach ($results as $row) {
            $labels[] = 'Week ' . $row->week_num;
            $distance[] = round($row->total_distance / 1000, 2); // km
            $duration[] = round($row->total_time / 3600, 2); // hours
        }

        return array(
            'labels' => $labels,
            'distance' => $distance,
            'duration' => $duration
        );
    }

    private function get_heart_rate_distribution($user_id, $date_range)
    {
        global $wpdb;

        $days = $date_range === '7days' ? 7 : ($date_range === '30days' ? 30 : 90);
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $query = "SELECT average_heartrate
                  FROM {$wpdb->prefix}strava_activities
                  WHERE user_id = %d
                  AND start_date >= %s
                  AND average_heartrate IS NOT NULL
                  AND average_heartrate > 0";

        $results = $wpdb->get_results($wpdb->prepare($query, $user_id, $start_date));

        $heart_rates = array();
        foreach ($results as $row) {
            $heart_rates[] = $row->average_heartrate;
        }

        return $heart_rates;
    }

    private function get_plan_vs_actual_data($mentee_id, $week_start)
    {
        global $wpdb;

        // Get training plan for the week
        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}strava_training_plans
             WHERE mentee_id = %d
             AND week_start = %s",
            $mentee_id,
            $week_start
        ));

        if (!$plan) {
            return array(
                'labels' => array(),
                'planned' => array(),
                'actual' => array()
            );
        }

        $plan_data = json_decode($plan->plan_data, true);
        $workouts = isset($plan_data['workouts']) ? $plan_data['workouts'] : array();

        // Get actual activities for the week
        $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));

        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                DATE(start_date) as activity_date,
                activity_type,
                SUM(distance) as total_distance
             FROM {$wpdb->prefix}strava_activities
             WHERE user_id = %d
             AND start_date >= %s
             AND start_date <= %s
             GROUP BY DATE(start_date), activity_type",
            $mentee_id,
            $week_start,
            $week_end
        ));

        // Process data for each day
        $days = array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');
        $labels = array();
        $planned = array();
        $actual = array();

        foreach ($days as $index => $day) {
            $labels[] = $day;

            // Get planned distance
            $day_key = strtolower($day);
            $planned_distance = 0;
            if (isset($workouts[$day_key]) && isset($workouts[$day_key]['distance'])) {
                $planned_distance = floatval($workouts[$day_key]['distance']);
            }
            $planned[] = $planned_distance;

            // Get actual distance
            $date = date('Y-m-d', strtotime($week_start . ' +' . $index . ' days'));
            $actual_distance = 0;

            foreach ($activities as $activity) {
                if ($activity->activity_date === $date) {
                    $actual_distance += $activity->total_distance / 1000; // Convert to km
                }
            }
            $actual[] = round($actual_distance, 2);
        }

        return array(
            'labels' => $labels,
            'planned' => $planned,
            'actual' => $actual
        );
    }

    private function calculate_weekly_stats($mentee_id)
    {
        global $wpdb;

        $stats_query = "
            SELECT 
                COUNT(*) as activity_count,
                SUM(distance) as total_distance,
                AVG(distance) as avg_distance,
                SUM(moving_time) as total_time,
                AVG(moving_time) as avg_time,
                AVG(average_speed) as avg_speed,
                AVG(average_heartrate) as avg_heartrate
            FROM {$wpdb->prefix}strava_activities
            WHERE user_id = %d
            AND start_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ";

        return $wpdb->get_row($wpdb->prepare($stats_query, $mentee_id));
    }

    private function get_mentee_activities_with_scores($mentee_id)
    {
        global $wpdb;

        $activities_query = "
            SELECT sa.*, acs.pace_score, acs.distance_score, acs.heart_rate_score, 
                   acs.overall_score, acs.comments, acs.scored_at
            FROM {$wpdb->prefix}strava_activities sa
            LEFT JOIN {$wpdb->prefix}activity_scores acs ON sa.id = acs.activity_id
            WHERE sa.user_id = %d
            AND sa.start_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY sa.start_date DESC
            LIMIT 20
        ";

        return $wpdb->get_results($wpdb->prepare($activities_query, $mentee_id));
    }

    /**
     * Existing AJAX handlers from Phase 2
     */
    public function ajax_disconnect_strava()
    {
        check_ajax_referer('disconnect_strava', 'nonce');

        $user_id = intval($_POST['user_id']);
        if ($user_id !== get_current_user_id() && !current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        if (class_exists('Strava_Coaching_API')) {
            $strava_api = new Strava_Coaching_API();
            $result = $strava_api->disconnect_user($user_id);
            wp_send_json_success(array('disconnected' => $result));
        } else {
            wp_send_json_error(array('message' => 'Strava API class not found'));
        }
    }

    public function ajax_sync_activities()
    {
        $user_id = get_current_user_id();

        if (class_exists('Strava_Coaching_API')) {
            $strava_api = new Strava_Coaching_API();
            $synced_count = $strava_api->sync_user_activities($user_id, 30);

            if ($synced_count !== false) {
                wp_send_json_success(array(
                    'message' => "Synced {$synced_count} new activities",
                    'count' => $synced_count
                ));
            } else {
                wp_send_json_error(array('message' => 'Failed to sync activities'));
            }
        } else {
            wp_send_json_error(array('message' => 'Strava API class not found'));
        }
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_styles()
    {
        wp_enqueue_style(
            $this->plugin_name,
            STRAVA_COACHING_PLUGIN_URL . 'admin/css/admin-style.css',
            array(),
            $this->version,
            'all'
        );       

        // Add chart styles
        wp_enqueue_style(
            $this->plugin_name . '-charts',
            STRAVA_COACHING_PLUGIN_URL . 'admin/css/admin-charts.css',
            array(),
            $this->version,
            'all'
        );
    }

    /**
     * Enhanced enqueue_scripts method with Chart.js
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script(
            $this->plugin_name,
            STRAVA_COACHING_PLUGIN_URL . 'admin/js/admin-script.js',
            array('jquery'),
            $this->version,
            false
        );

         wp_enqueue_script(
            'custom-script',
            STRAVA_COACHING_PLUGIN_URL . 'admin/js/custom-script.js',
            array(),
            '1.0',
            'all'
        );

        // Add Chart.js from CDN
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js',
            array(),
            '4.4.0',
            false
        );

        // Add our chart script
        wp_enqueue_script(
            $this->plugin_name . '-charts',
            STRAVA_COACHING_PLUGIN_URL . 'admin/js/admin-charts.js',
            array('jquery', 'chartjs'),
            $this->version,
            false
        );

        // Pass comprehensive data to JavaScript
        wp_localize_script($this->plugin_name, 'stravaCoaching', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'currentUserId' => get_current_user_id(),
            'disconnectNonce' => wp_create_nonce('disconnect_strava'),
            'searchNonce' => wp_create_nonce('search_users'),
            'addMenteeNonce' => wp_create_nonce('add_mentee'),
            'removeMenteeNonce' => wp_create_nonce('remove_mentee'),
            'scoreNonce' => wp_create_nonce('score_activity'),
            'progressNonce' => wp_create_nonce('get_mentee_progress'),
            'chartNonce' => wp_create_nonce('chart_data'),
            'strings' => array(
                'confirmDisconnect' => 'Are you sure you want to disconnect from Strava?',
                'confirmSync' => 'This will sync data for all mentees. Continue?',
                'confirmRemoveMentee' => 'Are you sure you want to remove this mentee?'
            )
        ));
    }

    // Placeholder methods for future implementation
    public function add_mentee()
    {
        wp_send_json_error(array('message' => 'Feature coming in next phase'));
    }

    public function remove_mentee()
    {
        wp_send_json_error(array('message' => 'Feature coming in next phase'));
    }

    public function score_activity()
    {
        wp_send_json_error(array('message' => 'Feature coming in next phase'));
    }

    public function show_strava_profile_fields()
    {
        echo '<h3>Strava Connection</h3>';
        echo '<p>Strava profile integration coming soon...</p>';
    }
}
?>