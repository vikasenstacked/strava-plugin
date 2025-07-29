<?php
class ACS_Settings {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function register_settings_page() {
        add_options_page(
            'Activity Coach Settings',
            'Activity Coach',
            'manage_options',
            'acs-settings',
            [__CLASS__, 'settings_page_html']
        );
    }

    public static function register_settings() {
        register_setting('acs_settings_group', 'acs_strava_client_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]);
        register_setting('acs_settings_group', 'acs_strava_client_secret', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]);
        // Register 4 metric label settings
        $default_metrics = ['Pace', 'Distance', 'Consistency', 'Elevation'];
        for ($i = 1; $i <= 4; $i++) {
            register_setting('acs_settings_group', 'acs_feedback_label_' . $i, [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => $default_metrics[$i-1]
            ]);
        }
    }

    public static function settings_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $client_id = get_option('acs_strava_client_id', '');
        $client_secret = get_option('acs_strava_client_secret', '');
        $redirect_uri = admin_url('admin.php?page=acs-settings&acs_strava_oauth=1');
        // Get metric labels
        $metric_labels = [];
        $default_metrics = ['Pace', 'Distance', 'Consistency', 'Elevation'];
        for ($i = 1; $i <= 4; $i++) {
            $metric_labels[$i] = get_option('acs_feedback_label_' . $i, $default_metrics[$i-1]);
        }
        ?>
        <div class="wrap acs-admin">
            <h1 class="acs-admin-heading">Activity Coach – Strava API Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('acs_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="acs_strava_client_id">Strava Client ID</label></th>
                        <td><input name="acs_strava_client_id" type="text" id="acs_strava_client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="acs_strava_client_secret">Strava Client Secret</label></th>
                        <td><input name="acs_strava_client_secret" type="text" id="acs_strava_client_secret" value="<?php echo esc_attr($client_secret); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row">OAuth Redirect URI</th>
                        <td><code><?php echo esc_html($redirect_uri); ?></code><br><small>Copy this to your Strava app settings.</small></td>
                    </tr>
                    <tr>
                        <th colspan="2"><h2>Custom Metric Labels</h2></th>
                    </tr>
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                    <tr>
                        <th scope="row"><label for="acs_feedback_label_<?php echo $i; ?>">Metric <?php echo $i; ?> Label</label></th>
                        <td><input name="acs_feedback_label_<?php echo $i; ?>" type="text" id="acs_feedback_label_<?php echo $i; ?>" value="<?php echo esc_attr($metric_labels[$i]); ?>" class="regular-text"></td>
                    </tr>
                    <?php endfor; ?>
                </table>
                <?php submit_button('Save Settings', 'primary acs-admin-btn'); ?>
            </form>
        </div>
        <?php
    }
}
