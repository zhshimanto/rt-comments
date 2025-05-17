<?php
/*
Plugin Name: RT Comments - Real Time Comments
Description: Allow users to submit and display comments in real-time with AJAX support
Version: 1.0
Author: Websolt
Author URI: https://websolt.com
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: ws-rt-comments
Domain Path: /languages

RT Comments - Real Time Comments is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

RT Comments - Real Time Comments is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

Copyright (C) 2025 Websolt
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    wp_die(
        esc_html__('Direct access to this file is not allowed.', 'ws-rt-comments'),
        esc_html__('Security Error', 'ws-rt-comments'),
        array('response' => 403)
    );
}

// Load plugin text domain for translations
function ws_rt_comments_load_textdomain() {
    load_plugin_textdomain(
        'ws-rt-comments',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
add_action('plugins_loaded', 'ws_rt_comments_load_textdomain');

// Create database table on plugin activation
function ws_rt_comments_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'match_predictions';
    
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        comment text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'ws_rt_comments_activate');

// Add menu item to WordPress admin
function ws_rt_comments_menu() {
    add_menu_page(
        'RT Comments',
        'RT Comments',
        'manage_options',
        'ws-rt-comments',
        'ws_rt_comments_admin_page',
        'dashicons-format-chat'
    );
}
add_action('admin_menu', 'ws_rt_comments_menu');

// Handle form submission
function ws_rt_comments_handle_submission() {
    if (isset($_POST['prediction_submit'])) {
        // Verify nonce for security
        if (!isset($_POST['prediction_nonce']) || !wp_verify_nonce($_POST['prediction_nonce'], 'submit_prediction')) {
            wp_die(
                esc_html__('Security check failed. Please refresh the page and try again.', 'realtime-comments'),
                esc_html__('Security Error', 'ws-rt-comments'),
                array('response' => 403, 'back_link' => true)
            );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'match_predictions';
        
        // Validate and sanitize input
        if (empty($_POST['prediction_name']) || empty($_POST['prediction_comment'])) {
            wp_die(
                esc_html__('Name and comment are required fields.', 'realtime-comments'),
                esc_html__('Validation Error', 'realtime-comments'),
                array('response' => 400, 'back_link' => true)
            );
        }

        $name = sanitize_text_field($_POST['prediction_name']);
        $comment = sanitize_textarea_field($_POST['prediction_comment']);

        // Length validation
        if (strlen($name) > 100) {
            wp_die(esc_html__('Name is too long. Maximum 100 characters allowed.', 'realtime-comments'));
        }
        if (strlen($comment) > 1000) {
            wp_die(esc_html__('Comment is too long. Maximum 1000 characters allowed.', 'realtime-comments'));
        }
        
        // Set timezone to Thailand (GMT+7)
        $thailand_timezone = new DateTimeZone('Asia/Bangkok');
        $current_time = new DateTime('now', $thailand_timezone);
        
        // Store time in UTC in the database (best practice)
        $utc_time = clone $current_time;
        $utc_time->setTimezone(new DateTimeZone('UTC'));
        $formatted_time = $utc_time->format('Y-m-d H:i:s');
        
        // Use prepared statement for insertion with explicit Thailand time
        $result = $wpdb->prepare(
            "INSERT INTO $table_name (name, comment, created_at) VALUES (%s, %s, %s)",
            $name, 
            $comment,
            $formatted_time
        );
        $wpdb->query($result);

        // Redirect after submission to prevent form resubmission
        wp_redirect(add_query_arg('submitted', '1', wp_get_referer()));
        exit();
    }
}
add_action('init', 'ws_rt_comments_handle_submission');

// Shortcode to display form and predictions
function ws_rt_comments_shortcode() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'match_predictions';
    
    ob_start();
    ?>
<div class="match-predictions-container">
    <div class="predictions-list">
        <h3><?php esc_html_e('ความคิดเห็นล่าสุด', 'ws-rt-comments'); ?></h3>
        <?php
            // Ensure newest comments appear at the top by ordering by ID (auto-increment) in descending order
            // This is more reliable than using created_at if there are any timezone issues
            $predictions = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC");
            foreach ($predictions as $prediction) {
                try {
                    // Convert database timestamp to DateTime object
                    $timestamp = new DateTime($prediction->created_at, new DateTimeZone('UTC'));
                    $timestamp->setTimezone(new DateTimeZone('Asia/Bangkok')); // Convert to Thailand timezone
                    
                    // Format as 12-hour clock without seconds
                    $comment_time = $timestamp->format('g:i A'); // e.g., 8:30 PM
                } catch (Exception $e) {
                    // If there's an error, use a placeholder
                    $comment_time = 'Time unavailable';
                }
                echo '<div class="prediction-item">';
                echo '<strong>' . esc_html($prediction->name) . '</strong>';
                echo '<p>' . esc_html($prediction->comment) . '</p>';
                echo '<small>' . esc_html($comment_time) . '</small>';
                echo '</div>';
            }
            ?>
    </div>

    <form method="post" class="prediction-form">
        <?php wp_nonce_field('submit_prediction', 'prediction_nonce'); ?>
        <div class="form-group">
            <label for="prediction_name"><?php esc_html_e('ยูสเซอร์:', 'realtime-comments'); ?></label>
            <input type="text" name="prediction_name" id="prediction_name" required>
        </div>
        <div class="form-group">
            <label for="prediction_comment"><?php esc_html_e('แสดงความคิดเห็น:', 'realtime-comments'); ?></label>
            <textarea name="prediction_comment" id="prediction_comment" required></textarea>
        </div>
        <button type="submit" name="prediction_submit"><?php esc_html_e('ตอบกระทู้', 'realtime-comments'); ?></button>
    </form>


</div>

<style>
.match-predictions-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.prediction-form {
    margin-bottom: 30px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
}

.form-group input,
.form-group textarea {
    width: 100%;
    padding: 8px;
}

.prediction-item {
    border-bottom: 1px solid #eee;
    padding: 15px 0;
}

.prediction-item small {
    color: #666;
    font-size: 0.8em;
}
</style>
<?php
    return ob_get_clean();
}
add_shortcode('realtime_comments', 'ws_rt_comments_shortcode');

// Handle CSV Export
function ws_rt_comments_export_csv() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    if (isset($_POST['export_predictions']) && check_admin_referer('export_predictions_nonce')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'match_predictions';
        
        $predictions = $wpdb->get_results("SELECT name, comment, created_at FROM $table_name ORDER BY created_at DESC", ARRAY_A);
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=predictions-export-' . date('Y-m-d') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for proper Excel display
        fputs($output, "\xEF\xBB\xBF");
        
        // Add headers
        fputcsv($output, array('Name', 'Comment', 'Submitted Date'));
        
        // Add data
        foreach ($predictions as $prediction) {
            fputcsv($output, array(
                $prediction['name'],
                $prediction['comment'],
                $prediction['created_at']
            ));
        }
        
        fclose($output);
        exit();
    }
}
add_action('admin_init', 'ws_rt_comments_export_csv');

// Handle reset action
function ws_rt_comments_handle_reset() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['reset_predictions']) && check_admin_referer('prediction_actions_nonce')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'match_predictions';
        $wpdb->query("TRUNCATE TABLE $table_name");
        
        // Store message in transient
        set_transient('ws_rt_comments_message', esc_html__('All Comments have been reset!', 'ws-rt-comments'), 45);
        
        // Redirect to prevent form resubmission
        wp_redirect(add_query_arg('page', 'ws-rt-comments', admin_url('admin.php')));
        exit();
    }
}
add_action('admin_init', 'ws_rt_comments_handle_reset');

// Admin page
function ws_rt_comments_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'match_predictions';

    // Display admin message if exists
    $message = get_transient('ws_rt_comments_message');
    if ($message) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        delete_transient('ws_rt_comments_message');
    }
    ?>
<div class="wrap">
    <h1><?php esc_html_e('Comments Management', 'realtime-comments'); ?></h1>

    <div class="prediction-actions" style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
        <h2><?php esc_html_e('Comment Management Actions', 'realtime-comments'); ?></h2>
        
        <div style="display: flex; gap: 10px; align-items: center;">
            <!-- Export Form -->
            <form method="post" style="margin: 0;">
                <?php wp_nonce_field('export_predictions_nonce'); ?>
                <button type="submit" name="export_predictions" class="button button-primary">
                    <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                    <?php esc_html_e('Export All Comments to CSV', 'realtime-comments'); ?>
                </button>
            </form>

            <!-- Reset Form -->
            <form method="post" style="margin: 0;">
                <?php wp_nonce_field('prediction_actions_nonce'); ?>
                <button type="submit" name="reset_predictions" class="button button-secondary" 
                        onclick="return confirm('Are you sure you want to reset all predictions? This action cannot be undone!')">
                    <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                    <?php esc_html_e('Reset All Comments', 'realtime-comments'); ?>
                </button>
            </form>
        </div>
    </div>
<div class="shortcode-instructions" style="margin: 20px 0; padding: 20px; background: #f8f9fa; border: 1px solid #ccd0d4; border-left: 4px solid #2271b1;">
        <h3 style="margin-top: 0;"><?php esc_html_e('Shortcode Instructions', 'ws-rt-comments'); ?></h3>
        <p><?php esc_html_e('To display the comments form and list on any page or post, use this shortcode:', 'ws-rt-comments'); ?></p>
        <code style="display: inline-block; background: #fff; padding: 10px; border: 1px solid #ddd; font-size: 14px;">[realtime_comments]</code>
    </div>

    <h2><?php esc_html_e('Recent Comments', 'ws-rt-comments'); ?></h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Name', 'ws-rt-comments'); ?></th>
                <th><?php esc_html_e('Comment', 'ws-rt-comments'); ?></th>
                <th><?php esc_html_e('Created At', 'ws-rt-comments'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
                // Ensure newest comments appear at the top by ordering by ID (auto-increment) in descending order
            // This is more reliable than using created_at if there are any timezone issues
            $predictions = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC");
                foreach ($predictions as $prediction) {
                    echo '<tr>';
                    echo '<td>' . esc_html($prediction->name) . '</td>';
                    echo '<td>' . esc_html($prediction->comment) . '</td>';
                    echo '<td>' . esc_html($prediction->created_at) . '</td>';
                    echo '</tr>';
                }
                ?>
        </tbody>
    </table>
</div>
<?php
}