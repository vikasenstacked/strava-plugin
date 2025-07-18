<?php
/**
 * Dedicated OAuth Handler
 * File: includes/class-oauth-handler.php
 */

class Strava_OAuth_Handler
{

    public function __construct()
    {
        // Hook into WordPress very early
        add_action('plugins_loaded', array($this, 'handle_oauth_callback'), 1);
    }

    /**
     * Handle OAuth callback at the earliest possible moment
     */
    public function handle_oauth_callback()
    {
        // Only process if this is an OAuth callback request
        if (!$this->is_oauth_callback()) {
            return;
        }

        // Prevent any output
        ob_clean();

        // Validate callback parameters
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            $this->redirect_with_error('invalid_callback');
            return;
        }

        // Validate state parameter
        $state_parts = explode('|', $_GET['state']);
        if (count($state_parts) !== 2) {
            $this->redirect_with_error('invalid_state');
            return;
        }

        $nonce = $state_parts[0];
        $user_id = intval($state_parts[1]);

        // Verify nonce
        if (!wp_verify_nonce($nonce, 'strava_oauth_' . $user_id)) {
            $this->redirect_with_error('security_failed');
            return;
        }

        // Check if Strava API class exists
        if (!class_exists('Strava_Coaching_API')) {
            $this->redirect_with_error('api_missing');
            return;
        }

        // Exchange code for token
        $strava_api = new Strava_Coaching_API();
        $token_data = $strava_api->exchange_token($_GET['code']);

        if (!$token_data || !isset($token_data['access_token'])) {
            $this->redirect_with_error('token_failed');
            return;
        }

        // Store tokens
        $success = $strava_api->store_tokens($user_id, $token_data);
        if (!$success) {
            $this->redirect_with_error('store_failed');
            return;
        }

        // Sync activities
        $synced_count = $strava_api->sync_user_activities($user_id, 30);
        if ($synced_count === false) {
            $synced_count = 0;
        }

        // Success redirect
        $this->redirect_with_success($synced_count);
    }

    /**
     * Check if this is an OAuth callback request
     */
    private function is_oauth_callback()
    {
        return (
            isset($_GET['page']) &&
            $_GET['page'] === 'strava-coaching' &&
            isset($_GET['action']) &&
            $_GET['action'] === 'oauth_callback' &&
            is_admin()
        );
    }

    /**
     * Redirect with error message
     */
    private function redirect_with_error($error_code)
    {
        $url = admin_url('admin.php?page=strava-coaching&error=' . $error_code);

        // Multiple redirect methods for maximum compatibility
        if (!headers_sent()) {
            header('Location: ' . $url, true, 302);
            exit;
        }

        // JavaScript fallback
        echo '<script>window.location.href = "' . esc_js($url) . '";</script>';
        echo '<meta http-equiv="refresh" content="0;url=' . esc_attr($url) . '">';
        exit;
    }

    /**
     * Redirect with success message
     */
    private function redirect_with_success($synced_count)
    {
        $url = admin_url('admin.php?page=strava-coaching&connected=1&synced=' . $synced_count);

        // Multiple redirect methods for maximum compatibility
        if (!headers_sent()) {
            header('Location: ' . $url, true, 302);
            exit;
        }

        // JavaScript fallback
        echo '<script>window.location.href = "' . esc_js($url) . '";</script>';
        echo '<meta http-equiv="refresh" content="0;url=' . esc_attr($url) . '">';
        exit;
    }
}