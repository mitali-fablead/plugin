<?php
/**
 * Plugin Name: Content Expiry & Freshness Manager2
 * Plugin URI:  https://example.com
 * Description: Manage expiry/refresh rules for posts, pages and custom post types. Archive, redirect, replace or delete content automatically and notify authors.
 * Version:     0.1.0
 * Author:      Mitali (starter)
 * Text Domain: content-expiry-freshness
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}





// add_action('add_meta_boxes', function() {
//     add_meta_box('content_expiry_box', 'Content Expiry Settings', 'render_expiry_box', ['post', 'page'], 'side', 'default');
// });
add_action('add_meta_boxes', function() {
    $post_types = get_post_types(['public' => true]); // includes custom types
    foreach ($post_types as $post_type) {
        add_meta_box(
            'cefm_expiry_box',
            __('Content Expiry Settings', 'cefm'),
            'render_expiry_box',
            $post_type,
            'side'
        );
    }
});




function render_expiry_box($post) {
    $expiry = get_post_meta($post->ID, '_expiry_date', true);
    $action = get_post_meta($post->ID, '_expiry_action', true);
    $redirect = get_post_meta($post->ID, '_expiry_redirect', true);
    $replace_msg = get_post_meta($post->ID, '_expiry_message', true);

    wp_nonce_field('save_expiry_meta', 'expiry_nonce');
    ?>
    <p><strong>Expiry Date:</strong><br>
        <input type="datetime-local" name="expiry_date" value="<?php echo esc_attr($expiry); ?>" style="width:100%">
    </p>
    <p><strong>Action on Expiry:</strong><br>
        <select name="expiry_action" style="width:100%">
            <option value="draft" <?php selected($action, 'draft'); ?>>Move to Draft</option>
            <option value="trash" <?php selected($action, 'trash'); ?>>Move to Trash</option>
            <option value="redirect" <?php selected($action, 'redirect'); ?>>Redirect to Another URL</option>
            <option value="replace" <?php selected($action, 'replace'); ?>>Replace with Custom Message</option>
            <option value="delete" <?php selected($action, 'delete'); ?>>Delete Permanently</option>
            <option value="disable" <?php selected($action, 'disable'); ?>>Disable Expiry</option>

        </select>
    </p>
    <p><strong>Redirect URL (if applicable):</strong><br>
        <input type="url" name="expiry_redirect" value="<?php echo esc_url($redirect); ?>" style="width:100%">
    </p>
    <p><strong>Replace Message (if applicable):</strong><br>
        <textarea name="expiry_message" rows="2" style="width:100%"><?php echo esc_textarea($replace_msg); ?></textarea>
    </p>
    <?php
}


// Save meta box data
add_action('save_post', function($post_id) {
    if (!isset($_POST['expiry_nonce']) || !wp_verify_nonce($_POST['expiry_nonce'], 'save_expiry_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Handle disable action
    if (isset($_POST['expiry_action']) && $_POST['expiry_action'] === 'disable') {
        delete_post_meta($post_id, '_expiry_date');
        delete_post_meta($post_id, '_expiry_action');
        delete_post_meta($post_id, '_expiry_redirect');
        delete_post_meta($post_id, '_expiry_message');
        delete_post_meta($post_id, '_active_redirect');
        delete_post_meta($post_id, '_replaced_content');
        return;
    }

    // Normalize and save expiry date
    $new_expiry = !empty($_POST['expiry_date']) ? str_replace('T', ' ', sanitize_text_field($_POST['expiry_date'])) : '';
    $old_expiry = get_post_meta($post_id, '_expiry_date', true);

    if ($new_expiry) {
        update_post_meta($post_id, '_expiry_date', $new_expiry);

        // Reset notification flags if expiry date changed
        if ($old_expiry !== $new_expiry) {
            delete_post_meta($post_id, '_expiry_notified_7');
            delete_post_meta($post_id, '_expiry_notified_1');
            delete_post_meta($post_id, '_expiry_notified_0');
        }

        // Clear active states if in future
        if (strtotime($new_expiry) > current_time('timestamp')) {
            delete_post_meta($post_id, '_active_redirect');
            delete_post_meta($post_id, '_replaced_content');
        }
    }

    update_post_meta($post_id, '_expiry_action', sanitize_text_field($_POST['expiry_action']));
    update_post_meta($post_id, '_expiry_redirect', esc_url_raw($_POST['expiry_redirect']));
    update_post_meta($post_id, '_expiry_message', sanitize_textarea_field($_POST['expiry_message']));
});




// Schedule a daily cron event
add_action('wp', function() {
    if (!wp_next_scheduled('check_expired_posts')) {
        wp_schedule_event(time(), 'hourly', 'check_expired_posts');
    }
});

add_action('check_expired_posts', 'handle_expired_posts');

function handle_expired_posts() {
    $now = current_time('Y-m-d H:i');
    $expired = get_posts([
        'post_type' => 'any',
        'meta_query' => [
    [
        'key' => '_expiry_date',
        'value' => $now,
        'compare' => '<=',
        'type' => 'CHAR',
    ]
],

        'post_status' => ['publish', 'private'],
        'numberposts' => -1
    ]);

    foreach ($expired as $post) {
        $expiry_action = get_post_meta($post->ID, '_expiry_action', true);
if ($expiry_action === 'disable') {
    continue; // Skip disabled posts
}

        $action = get_post_meta($post->ID, '_expiry_action', true);
        $redirect = get_post_meta($post->ID, '_expiry_redirect', true);
        $replace = get_post_meta($post->ID, '_expiry_message', true);

        switch ($action) {
            case 'draft':
                wp_update_post(['ID' => $post->ID, 'post_status' => 'draft']);
                break;
            case 'trash':
                wp_trash_post($post->ID);
                break;
            case 'delete':
                wp_delete_post($post->ID, true);
                break;
            case 'redirect':
                update_post_meta($post->ID, '_active_redirect', esc_url_raw($redirect));
                break;
            case 'replace':
                update_post_meta($post->ID, '_replaced_content', wp_kses_post($replace));
                break;
        //      case 'disable':
        // // Simply clear expiry
        // delete_post_meta($post->ID, '_expiry_date');
        // delete_post_meta($post->ID, '_expiry_action');
        // delete_post_meta($post->ID, '_expiry_redirect');
        // delete_post_meta($post->ID, '_expiry_message');
        // break;
        }
        if ( class_exists( 'WooCommerce' ) && 'product' === get_post_type( $post->ID ) ) {
        // Get Shop page URL
        $shop_url = wc_get_page_permalink( 'shop' );

        // If no custom redirect was set by user, default to shop page
        if ( empty( $redirect ) ) {
            update_post_meta( $post->ID, '_active_redirect', esc_url_raw( $shop_url ) );
        }
    }
    }
}

// Optional: Handle front-end redirect or replace
add_action('template_redirect', function() {
    if (is_singular()) {
        global $post;
        $redirect = get_post_meta($post->ID, '_active_redirect', true);
        if ($redirect) {
            wp_redirect($redirect, 301);
            exit;
        }

        $replace = get_post_meta($post->ID, '_replaced_content', true);
        if ($replace) {
            add_filter('the_content', fn() => wpautop($replace));
        }
    }
});















// =======================
// Admin Dashboard Overview Page with Refresh & Versioning
// =======================

add_action('admin_menu', function() {
    add_menu_page(
        'Content Expiry Manager',// Page title
        'Content Expiry',                
        'manage_options',                 // Capability
        'content-expiry-overview',        // Slug
        'render_content_expiry_overview', // Callback function
        'dashicons-clock',                // Icon
        25                                // Position in admin menu
    );
});

// Handle "Refresh" Action
add_action('admin_post_cefm_refresh_expiry', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized user');
    }

    if (!isset($_GET['post_id']) || !is_numeric($_GET['post_id'])) {
        wp_die('Invalid post ID');
    }

    $post_id = intval($_GET['post_id']);
    $old_expiry = get_post_meta($post_id, '_expiry_date', true);

    $days_to_extend = apply_filters('cefm_refresh_days', 90);
    $new_expiry = date('Y-m-d H:i', strtotime("+{$days_to_extend} days", current_time('timestamp')));

    update_post_meta($post_id, '_expiry_date', $new_expiry);

    delete_post_meta($post_id, '_active_redirect');
    delete_post_meta($post_id, '_replaced_content');

    $logs = (array) get_post_meta($post_id, '_expiry_logs', true);
    $logs[] = [
        'user'        => wp_get_current_user()->user_login,
        'old_expiry'  => $old_expiry,
        'new_expiry'  => $new_expiry,
        'timestamp'   => current_time('mysql'),
    ];
    update_post_meta($post_id, '_expiry_logs', $logs);

    wp_redirect(admin_url('admin.php?page=content-expiry-overview&refreshed=1'));
    exit;
});


function render_content_expiry_overview() {
    
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_GET['refreshed'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Expiry date refreshed successfully!</p></div>';
    }

    $now = current_time('Y-m-d H:i');
    $upcoming = date('Y-m-d H:i', strtotime('+7 days', current_time('timestamp')));

    $posts = get_posts([
        'post_type' => 'any',
        'meta_query' => [
            [
                'key' => '_expiry_date',
                'compare' => 'EXISTS'
            ]
        ],
        'numberposts' => -1,
        'post_status' => ['publish', 'private', 'draft', 'pending']
    ]);

    // ========== Counters for Analytics ==========
    $counts = [
        'active' => 0,
        'expiring' => 0,
        'expired' => 0,
        'pending' => 0
    ];

    echo '<div class="wrap"><h1>🕒 Content Expiry Overview</h1>';
    echo '<p>View all posts with expiry dates. You can refresh expiry, see status analytics, or view logs.</p>';
    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>
            <th>Title</th>
            <th>Type</th>
            <th>Expiry Date</th>
            <th>Action on Expiry</th>
            <th>Status</th>
            <th>Actions</th>
          </tr></thead><tbody>';

    foreach ($posts as $post) {
        $expiry = get_post_meta($post->ID, '_expiry_date', true);
        $action = get_post_meta($post->ID, '_expiry_action', true);
        $status_label = '—';

        $action = get_post_meta($post->ID, '_expiry_action', true);

if ($action === 'disable' || empty($expiry)) {
    $status_label = '<span style="color:gray;">Disabled</span>';
} elseif ($expiry) {
            if ($post->post_status === 'pending') {
                $status_label = '<span style="color:blue;">Pending Review</span>';
                $counts['pending']++;
            } elseif ($expiry <= $now) {
                $status_label = '<span style="color:red;font-weight:bold;">Expired</span>';
                $counts['expired']++;
            } elseif ($expiry <= $upcoming) {
                $status_label = '<span style="color:orange;">Expiring Soon</span>';
                $counts['expiring']++;
            } else {
                $status_label = '<span style="color:green;">Active</span>';
                $counts['active']++;
            }

            // Create secure refresh URL
            $refresh_url = wp_nonce_url(
                admin_url("admin-post.php?action=cefm_refresh_expiry&post_id={$post->ID}"),
                'cefm_refresh_' . $post->ID
            );

            echo '<tr>
                <td>' . esc_html(get_the_title($post)) . '</td>
                <td>' . esc_html($post->post_type) . '</td>
                <td>' . esc_html($expiry) . '</td>
                <td>' . esc_html(ucfirst($action)) . '</td>
                <td>' . $status_label . '</td>
                <td>
                    <a href="' . get_edit_post_link($post->ID) . '">Edit</a> | 
                    <a href="' . esc_url($refresh_url) . '" class="button button-small">🔁 Refresh</a>
                </td>
            </tr>';
        }
    }

    echo '</tbody></table>';

    // =======================
    // Analytics Section
    // =======================
    echo '<hr><h2> Content Expiry Analytics</h2>';
    echo '<p>Overall status of content freshness across your site.</p>';
    echo '<ul style="font-size:16px; line-height:1.2;">';
    echo '<li><strong style="color:green;">Active:</strong> ' . $counts['active'] . '</li>';
    echo '<li><strong style="color:orange;">Expiring Soon:</strong> ' . $counts['expiring'] . '</li>';
    echo '<li><strong style="color:red;">Expired:</strong> ' . $counts['expired'] . '</li>';
    echo '<li><strong style="color:blue;">Pending Review:</strong> ' . $counts['pending'] . '</li>';
    echo '</ul>';

    // =======================
    // Google Chart (Pie Chart)
    // =======================
    ?>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script type="text/javascript">
      google.charts.load('current', {'packages':['corechart']});
      google.charts.setOnLoadCallback(drawChart);
      function drawChart() {
        var data = google.visualization.arrayToDataTable([
          ['Status', 'Count'],
          ['Active', <?php echo $counts['active']; ?>],
          ['Expiring Soon', <?php echo $counts['expiring']; ?>],
          ['Expired', <?php echo $counts['expired']; ?>],
          ['Pending Review', <?php echo $counts['pending']; ?>]
        ]);

        var options = {
          title: 'Content Expiry Status Overview',
          pieHole: 0.4,
          colors: ['#4CAF50', '#FF9800', '#F44336', '#2196F3']
        };

        var chart = new google.visualization.PieChart(document.getElementById('expiry_chart'));
        chart.draw(data, options);
      }
    </script>
    <div id="expiry_chart" style="width: 600px; height: 350px;"></div>
    <?php

    echo '</div>'; // End wrap
}















// =======================
// Notification System for Expiring Content
// =======================

// Schedule the notification cron (if not already scheduled)
add_action('wp', function() {
    if (!wp_next_scheduled('notify_expiring_posts')) {
        wp_schedule_event(time(), 'hourly', 'notify_expiring_posts');
    }
});


add_action('admin_init', function() {
    if ( isset($_GET['force_notify_test']) ) {
        do_action('notify_expiring_posts');
        wp_die('Notifications sent (check your mail logs)');
    }
});

add_action('init', function() {
    do_action('notify_expiring_posts');
});


add_action('notify_expiring_posts', 'cefm_notify_admin_and_authors_about_expiry');

// function cefm_notify_admin_and_authors_about_expiry() {
//     $now = current_time('timestamp');
//     $admin_email = get_option('admin_email');


//     $posts = get_posts([
//         'post_type'   => 'any',
//         'post_status' => ['publish', 'private'],
//         'meta_query'  => [
//             [
//                 'key'     => '_expiry_date',
//                 'compare' => 'EXISTS',
//             ]
//         ],
//         'numberposts' => -1,
//     ]);

//     foreach ($posts as $post) {
//         $expiry_date = get_post_meta($post->ID, '_expiry_date', true);
//         if (!$expiry_date) continue;

//         $expiry_time = strtotime($expiry_date);
//         $days_left = floor(($expiry_time - $now) / DAY_IN_SECONDS);


//         if (in_array($days_left, [7, 0])) {
//             $expiry_action = get_post_meta($post->ID, '_expiry_action', true);

//             $subject = " Content Expiry Alert: '{$post->post_title}' expires in {$days_left} day" . ($days_left === 1 ? '' : 's');
//             $message = "Hi Admin,\n\n";
//             $message .= "The following post is nearing expiry:\n";
//             $message .= "Title: {$post->post_title}\n";
//             $message .= "Post Type: {$post->post_type}\n";
//             $message .= "Expiry Date: {$expiry_date}\n";
//             $message .= "Action on Expiry: {$expiry_action}\n";
//             $message .= "Edit Post: " . get_edit_post_link($post->ID) . "\n\n";
//             $message .= "Regards,\nContent Expiry & Freshness Manager Plugin";
// // print_r($message);
// // die;

//             $flag_key = '_expiry_notified_' . $days_left;
//             if (!get_post_meta($post->ID, $flag_key, true)) {
               
//                 wp_mail($admin_email, $subject, $message);

//                 $author = get_userdata($post->post_author);
//                 if ($author && $author->user_email) {
//                     $author_msg = "Hi {$author->display_name},\n\n";
//                     $author_msg .= "Your post \"{$post->post_title}\" is nearing its expiry date ({$expiry_date}).\n";
//                     $author_msg .= "Please review or update it soon.\n\n";
//                     $author_msg .= "Edit Here: " . get_edit_post_link($post->ID) . "\n\n";
//                     $author_msg .= "Thanks,\nContent Expiry & Freshness Manager";
//                     wp_mail($author->user_email, $subject, $author_msg);
//                 }

//                 // Mark as notified
//                 update_post_meta($post->ID, $flag_key, current_time('mysql'));
//             }
//         }
//     }
// }


function cefm_notify_admin_and_authors_about_expiry() {
    
    $now = current_time('timestamp');
    $admin_email = get_option('admin_email');

    // Get all posts that have an expiry date set

    $posts = get_posts([
        'post_type'   => 'any',
        'post_status' => ['publish', 'private'],
        'meta_query'  => [
            [
                'key'     => '_expiry_date',
                'compare' => 'EXISTS',
            ]
        ],
        'numberposts' => -1,
    ]);

    if (empty($posts)) {
        return;
    }

    foreach ($posts as $post) {
        $expiry_action = get_post_meta($post->ID, '_expiry_action', true);
if ($expiry_action === 'disable') {
    continue; // Skip disabled posts
}

        $expiry_date = get_post_meta($post->ID, '_expiry_date', true);
        if (empty($expiry_date)) continue;



        $expiry_time = strtotime($expiry_date);
        $days_left = floor(($expiry_time - $now) / DAY_IN_SECONDS);

    

        // Only send notifications for 7 days before and on expiry day
       
if ($days_left >= 0 && $days_left <= 7) {

            $flag_key = '_expiry_notified_' . $days_left;

            // Prevent duplicate notifications
            if (get_post_meta($post->ID, $flag_key, true)) {
                continue;
            }

            $expiry_action = get_post_meta($post->ID, '_expiry_action', true);
            $subject = sprintf(
                "Content Expiry Alert: \"%s\" expires in %d day%s",
                $post->post_title,
                $days_left,
                ($days_left === 1 ? '' : 's')
                
            );
   
            $message = "Hi Admin,\n\n";
            $message .= "The following post is nearing expiry:\n";
            $message .= "------------------------------------\n";
            $message .= "Title: {$post->post_title}\n";
            $message .= "Post Type: {$post->post_type}\n";
            $message .= "Expiry Date: {$expiry_date}\n";
            $message .= "Action on Expiry: {$expiry_action}\n";
            $message .= "Edit Post: " . admin_url("post.php?post={$post->ID}&action=edit") . "\n\n";
            $message .= "------------------------------------\n";
            $message .= "Regards,\nContent Expiry & Freshness Manager";

            // print_r($message);
            // die;
            // Send to admin
            $headers = ['Content-Type: text/plain; charset=UTF-8'];
            wp_mail($admin_email, $subject, $message, $headers);

            // Send to author
            $author = get_userdata($post->post_author);
            if ($author && !empty($author->user_email)) {
                $author_message = "Hi {$author->display_name},\n\n";
                $author_message .= "Your post \"{$post->post_title}\" is nearing its expiry date ({$expiry_date}).\n";
                $author_message .= "Please review or update it soon.\n\n";
                $author_message .= "Edit Here: " . admin_url("post.php?post={$post->ID}&action=edit") . "\n\n";
                $author_message .= "Thanks,\nContent Expiry & Freshness Manager";
                wp_mail($author->user_email, $subject, $author_message, $headers);
            }

            // Mark as notified
            update_post_meta($post->ID, $flag_key, current_time('mysql'));
        }
    }
}


// Schedule hourly check if not already scheduled
add_action('wp', function() {
    if (!wp_next_scheduled('cefm_check_expiry_posts')) {
        wp_schedule_event(time(), 'hourly', 'cefm_check_expiry_posts');
    }
});

add_action('cefm_check_expiry_posts', 'cefm_notify_admin_and_authors_about_expiry');

// Manual test trigger (for debugging)
add_action('admin_init', function() {
    if (isset($_GET['test_cefm_notify'])) {
        cefm_notify_admin_and_authors_about_expiry();
        wp_die('Expiry notifications sent (check your email or logs)');
    }
});













// =======================
// Multisite / Network Support
// =======================

// Activation Hook
register_activation_hook(__FILE__, 'cefm_activate');
register_deactivation_hook(__FILE__, 'cefm_deactivate');

function cefm_activate($network_wide) {
    // If network-activated on Multisite
    if (is_multisite() && $network_wide) {
        $sites = get_sites();
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            cefm_setup_site_environment(); // Setup for each subsite
            restore_current_blog();
        }
    } else {
        cefm_setup_site_environment(); // Normal single site activation
    }
}

function cefm_deactivate($network_wide) {
    if (is_multisite() && $network_wide) {
        $sites = get_sites();
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            cefm_cleanup_site_environment(); // Cleanup for each subsite
            restore_current_blog();
        }
    } else {
        cefm_cleanup_site_environment(); // Single site
    }
}

// =======================
// Setup and Cleanup for Each Site
// =======================
function cefm_setup_site_environment() {
    error_log('🟢 cefm_setup_site_environment() called on site ID: ' . get_current_blog_id());
    if (!wp_next_scheduled('check_expired_posts')) {
        wp_schedule_event(time(), 'hourly', 'check_expired_posts');
    }
    if (!wp_next_scheduled('notify_expiring_posts')) {
        wp_schedule_event(time(), 'hourly', 'notify_expiring_posts');
    }
}

function cefm_cleanup_site_environment() {
    error_log('🔴 cefm_cleanup_site_environment() called on site ID: ' . get_current_blog_id());
    wp_clear_scheduled_hook('check_expired_posts');
    wp_clear_scheduled_hook('notify_expiring_posts');
}









