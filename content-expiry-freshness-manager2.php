<?php
/**
 * Plugin Name: Content Expiry & Freshness Manager
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




// ===========================
// Enqueue Admin Scripts & Styles
// ===========================
add_action('admin_enqueue_scripts', function($hook_suffix) {
    // Only load on post editor screens
    if (in_array($hook_suffix, ['post.php', 'post-new.php'])) {
        wp_enqueue_style(
            'cefm-admin-style',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            filemtime(plugin_dir_path(__FILE__) . 'assets/admin.css')
        );

        wp_enqueue_script(
            'cefm-admin-script',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            ['jquery'],
            filemtime(plugin_dir_path(__FILE__) . 'assets/admin.js'),
            true
        );
    }
});






function render_expiry_box($post) {



    $expiry = get_post_meta($post->ID, '_expiry_date', true);
    $action = get_post_meta($post->ID, '_expiry_action', true);
    $redirect = get_post_meta($post->ID, '_expiry_redirect', true);
    $replace_msg = get_post_meta($post->ID, '_expiry_message', true);

    wp_nonce_field('save_expiry_meta', 'expiry_nonce');

    // Convert stored value to HTML5 datetime-local format
    $expiry_html_value = '';
    if (!empty($expiry)) {
        $time = strtotime($expiry);
        if ($time) {
            $expiry_html_value = date('Y-m-d\TH:i', $time);
        }
    }
    // print_r($time);
    // die;
    ?>
    <p><strong>Expiry Date:</strong><br>
        <input type="datetime-local" name="expiry_date" value="<?php echo esc_attr($expiry_html_value); ?>" style="width:100%">
    </p>

    <p><strong>Action on Expiry:</strong><br>
        <select id="expiry_action" name="expiry_action" style="width:100%">
            <option value="draft" <?php selected($action, 'draft'); ?>>Move to Draft</option>
            <option value="trash" <?php selected($action, 'trash'); ?>>Move to Trash</option>
            <option value="redirect" <?php selected($action, 'redirect'); ?>>Redirect to Another URL</option>
            <option value="replace" <?php selected($action, 'replace'); ?>>Replace with Custom Message</option>
            <option value="delete" <?php selected($action, 'delete'); ?>>Delete Permanently</option>
            <option value="disable" <?php selected($action, 'disable'); ?>>Disable Expiry</option>
        </select>
    </p>

    <div id="expiry_redirect_field">
        <p><strong>Redirect URL (if applicable):</strong><br>
            <input type="url" name="expiry_redirect" value="<?php echo esc_url($redirect); ?>" style="width:100%">
        </p>
    </div>

    <div id="expiry_message_field">
        <p><strong>Replace Message (if applicable):</strong><br>
            <textarea name="expiry_message" rows="2" style="width:100%"><?php echo esc_textarea($replace_msg); ?></textarea>
        </p>
    </div>
    <?php
}




// Save meta box data
add_action('save_post', function($post_id){
    if (!isset($_POST['expiry_nonce']) || !wp_verify_nonce($_POST['expiry_nonce'], 'save_expiry_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $old_expiry = get_post_meta($post_id, '_expiry_date', true);



    // If "Disable" is selected, clear all expiry-related meta
if (isset($_POST['expiry_action']) && $_POST['expiry_action'] === 'disable') {
    delete_post_meta($post_id, '_expiry_date');
    delete_post_meta($post_id, '_expiry_action');
    delete_post_meta($post_id, '_expiry_redirect');
    delete_post_meta($post_id, '_expiry_message');
    delete_post_meta($post_id, '_active_redirect');
    delete_post_meta($post_id, '_replaced_content');
    return;
}



    if (!empty($_POST['expiry_date'])) {
    $datetime_raw = sanitize_text_field($_POST['expiry_date']);
    // Convert HTML5 datetime-local (Y-m-dTH:i) → MySQL-style (Y-m-d H:i)
    $datetime = str_replace('T', ' ', $datetime_raw);
    update_post_meta($post_id, '_expiry_date', $datetime);
//     $datetime_local = str_replace('T', ' ', $datetime_raw);

// // Convert local time → WP Timezone timestamp
// $timestamp = strtotime($datetime_local);

// // Convert timestamp → a normalized WP-time format
// $datetime_wp = date('Y-m-d H:i:s', $timestamp);

// update_post_meta($post_id, '_expiry_date', $datetime_wp);


    // Clear replaced/redirect content if future-dated
    if (strtotime($datetime) > current_time('timestamp')) {
        delete_post_meta($post_id, '_active_redirect');
        delete_post_meta($post_id, '_replaced_content');

        // print_r(current_time('timestamp'));
        // die;
    }
}


    update_post_meta($post_id, '_expiry_action', sanitize_text_field($_POST['expiry_action']));
    update_post_meta($post_id, '_expiry_redirect', esc_url_raw($_POST['expiry_redirect']));
    update_post_meta($post_id, '_expiry_message', sanitize_textarea_field($_POST['expiry_message']));
});



// Schedule a daily cron event
// ============================
// EXPIRY CHECKER — FIXED VERSION
// ============================

// Schedule hourly cron event if not yet registered
add_action('init', function () {
    if (!wp_next_scheduled('cefm_check_expired_posts')) {
        wp_schedule_event(time(), 'hourly', 'cefm_check_expired_posts');
    }
});
add_action('cefm_check_expired_posts', 'cefm_handle_expired_posts');

function cefm_handle_expired_posts() {

    $wc_enabled = get_option('cefm_wc_enable_product_expiry');
    $now = current_time('timestamp');

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

    if (empty($posts)) return;

    foreach ($posts as $post) {

//         // if (empty($wc_enabled) && $post->post_type === 'product') {
//         //     continue;
//         // }
// $wc_enabled = get_option('cefm_wc_enable_product_expiry', '0');

// // Skip WooCommerce products only when disabled
// if ($post->post_type === 'product' && $wc_enabled !== '1') {
//     continue;
// }
//         $expiry_raw = get_post_meta($post->ID, '_expiry_date', true);
//         if (empty($expiry_raw)) continue;

//         $expiry_time = strtotime($expiry_raw);
//         if (!$expiry_time) continue;

//         if ($expiry_time <= $now) {

//             $action   = get_post_meta($post->ID, '_expiry_action', true);
//             $redirect = get_post_meta($post->ID, '_expiry_redirect', true);
//             $replace  = get_post_meta($post->ID, '_expiry_message', true);

//             switch ($action) {

//                 case 'draft':
//                     wp_update_post(['ID' => $post->ID, 'post_status' => 'draft']);
//                     break;

//                 case 'trash':
//                     wp_trash_post($post->ID);
//                     break;

//                 case 'delete':
//                     wp_delete_post($post->ID, true);
//                     break;

//                 case 'redirect':
//                     if (!empty($redirect)) {
//                         update_post_meta($post->ID, '_active_redirect', esc_url_raw($redirect));
//                     }
//                     break;

//                 case 'replace':
//                     if (!empty($replace)) {
//                         update_post_meta($post->ID, '_replaced_content', wp_kses_post($replace));
//                     }
//                     break;
//             }
//         }
$wc_enabled = get_option('cefm_wc_enable_product_expiry', '0');

// Skip WooCommerce products only when disabled
if ($post->post_type === 'product' && $wc_enabled !== '1') {
    continue;
}

$expiry_raw = get_post_meta($post->ID, '_expiry_date', true);
if (empty($expiry_raw)) continue;

$expiry_time = intval($expiry_raw);
if (!$expiry_time) continue;

if ($expiry_time <= $now) {

    $action   = get_post_meta($post->ID, '_expiry_action', true);
    $redirect = get_post_meta($post->ID, '_expiry_redirect', true);
    $replace  = get_post_meta($post->ID, '_expiry_message', true);

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
    }
}
    }
}

// Manual Test Trigger: Run expiry check immediately from URL
add_action('admin_init', function () {
    if (isset($_GET['run_cefm_expiry_check'])) {
        cefm_handle_expired_posts();
        wp_die('✅ Expiry check completed. Reload your post list — expired posts should now be Draft.');
    }
});


// add_action('admin_init', function() {
//     if (isset($_GET['test_expiry_run'])) {
//         handle_expired_posts();
//         wp_die('✅ handle_expired_posts() executed — check if expired posts are now in Draft.');
//     }
// });



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
// =======================

add_action('admin_menu', function() {
    // Main menu (top-level)
    add_menu_page(
        __('Content Expiry & Freshness Manager', 'cefm'), // Page title
        __('Content Expiry & Freshness Manager', 'cefm'), // Menu title
        'manage_options',                                 // Capability
        'cefm-dashboard',                                 // Slug for main page
        'cefm_render_dashboard_page',                     // Callback function
        'dashicons-clock',                                // Icon
        25                                                // Position
    );

    // Submenu: Content Expiry
    add_submenu_page(
        'cefm-dashboard',                                 // Parent slug
        __('Content Expiry', 'cefm'),                     // Page title
        __('Content Expiry', 'cefm'),                     // Submenu label
        'manage_options',                                 // Capability
        'content-expiry-overview',                        // Submenu slug
        'render_content_expiry_overview'                  // Callback
    );
    add_submenu_page(
        'cefm-dashboard',                // Parent slug
        __('Settings', 'cefm'),          // Page title
        __('Settings', 'cefm'),          // Submenu label
        'manage_options',                // Capability
        'cefm-settings',                 // Submenu slug
        'cefm_render_settings_page'      // Callback
    );
});

// =======================
// Dashboard (Main Page)
// =======================
function cefm_render_dashboard_page() {
    echo '<div class="wrap">';
    echo '<h1>🕒 Content Expiry & Freshness Manager</h1>';
    echo '<p>Welcome to the Content Expiry & Freshness Manager Dashboard.</p>';
    echo '<p>Use the <strong>“Content Expiry”</strong> submenu to view and manage expiring posts.</p>';
    echo '<hr>';
    echo '<h2>Quick Links</h2>';
    echo '<ul style="font-size:16px; line-height:1.7;">';
    echo '<li><a href="' . admin_url('admin.php?page=content-expiry-overview') . '">📋 Open Content Expiry Overview</a></li>';
    echo '</ul>';
    echo '</div>';
}


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



// print_r($old_expiry);
// print_r($new_expiry);
//  die;


 
    wp_redirect(admin_url('admin.php?page=content-expiry-overview&refreshed=1'));
    exit;
});







function render_content_expiry_overview() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Handle bulk action
    if (isset($_POST['bulk_action'], $_POST['selected_posts']) && is_array($_POST['selected_posts'])) {
        $action = sanitize_text_field($_POST['bulk_action']);
        $selected_posts = array_map('intval', $_POST['selected_posts']);

        foreach ($selected_posts as $post_id) {
            if ($action === 'extend_30') {
                $old_expiry = get_post_meta($post_id, '_expiry_date', true);

// print_r($old_expiry);
// die;

                if ($old_expiry) {
                    $new_expiry = date('Y-m-d H:i', strtotime("+30 days", strtotime($old_expiry)));
                    update_post_meta($post_id, '_expiry_date', $new_expiry);
                }
            } elseif ($action === 'disable') {
                delete_post_meta($post_id, '_expiry_date');
                update_post_meta($post_id, '_expiry_action', 'disable');
            } elseif ($action === 'draft') {
                update_post_meta($post_id, '_expiry_action', 'draft');
            }
        }

        echo '<div class="notice notice-success is-dismissible"><p>Bulk action applied successfully!</p></div>';
    }

    // Filters
    $filter_post_type = isset($_GET['filter_post_type']) ? sanitize_text_field($_GET['filter_post_type']) : '';
    $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';

    $now = current_time('Y-m-d H:i');
    $upcoming = date('Y-m-d H:i', strtotime('+7 days', current_time('timestamp')));

    // Build query
    $args = [
        'post_type' => $filter_post_type ?: 'any',
        'meta_query' => [
            [
                'key' => '_expiry_date',
                'compare' => 'EXISTS'
            ]
        ],
        'numberposts' => -1,
        'post_status' => ['publish', 'private', 'draft', 'pending']
    ];

    $posts = get_posts($args);

    // Counters
    $counts = ['active' => 0, 'expiring' => 0, 'expired' => 0, 'pending' => 0];

    echo '<div class="wrap"><h1>🕒 Content Expiry Overview</h1>';
    echo '<p>View all posts with expiry dates. Filter, bulk update, or refresh expiry.</p>';

    // Filter Form
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="content-expiry-overview">';

    // Post Type Filter
    echo '<select name="filter_post_type">';
    echo '<option value="">All Post Types</option>';
    foreach (get_post_types(['public' => true]) as $pt) {
        echo '<option value="' . esc_attr($pt) . '" ' . selected($filter_post_type, $pt, false) . '>' . esc_html($pt) . '</option>';
    }
    echo '</select>';

    // Expiry Status Filter
    echo '<select name="filter_status">';
    echo '<option value="">All Statuses</option>';
    echo '<option value="active" ' . selected($filter_status, 'active', false) . '>Active</option>';
    echo '<option value="expiring" ' . selected($filter_status, 'expiring', false) . '>Expiring Soon</option>';
    echo '<option value="expired" ' . selected($filter_status, 'expired', false) . '>Expired</option>';
    echo '</select>';

    echo ' <button class="button">Filter</button>';
    echo '</form><br>';

    // Bulk Actions Form
    echo '<form method="post" action="">';
    echo '<select name="bulk_action">';
    echo '<option value="">Bulk Actions</option>';
    echo '<option value="extend_30">Extend Expiry by 30 Days</option>';
    echo '<option value="disable">Disable Expiry</option>';
    echo '<option value="draft">Set Action to Draft</option>';
    echo '</select> ';
    echo '<button class="button action">Apply</button>';

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>
            <th><input type="checkbox" id="select-all"></th>
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

        if ($action === 'disable' || empty($expiry)) {
            $status = 'disabled';
            $status_label = '<span style="color:gray;">Disabled</span>';
        } elseif ($expiry) {
            if ($post->post_status === 'pending') {
                $status = 'pending';
                $status_label = '<span style="color:blue;">Pending Review</span>';
                $counts['pending']++;
            } elseif ($expiry <= $now) {
                $status = 'expired';
                $status_label = '<span style="color:red;font-weight:bold;">Expired</span>';
                $counts['expired']++;
            } elseif ($expiry <= $upcoming) {
                $status = 'expiring';
                $status_label = '<span style="color:orange;">Expiring Soon</span>';
                $counts['expiring']++;
            } else {
                $status = 'active';
                $status_label = '<span style="color:green;">Active</span>';
                $counts['active']++;
            }
        }

        // Apply filters
        if ($filter_status && $filter_status !== $status) continue;

        $refresh_url = wp_nonce_url(
            admin_url("admin-post.php?action=cefm_refresh_expiry&post_id={$post->ID}"),
            'cefm_refresh_' . $post->ID
        );

        echo '<tr>
            <td><input type="checkbox" name="selected_posts[]" value="' . esc_attr($post->ID) . '"></td>
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

    echo '</tbody></table></form>';

    // Table select-all JS
    echo "<script>
        document.getElementById('select-all').addEventListener('click', function(){
            const checkboxes = document.querySelectorAll('input[name=\"selected_posts[]\"]');
            for (const cb of checkboxes) { cb.checked = this.checked; }
        });
    </script>";

    // Analytics Section
    echo '<hr><h2>📊 Content Expiry Analytics</h2>';
    echo '<p>Overview of content freshness across your site.</p>';
    echo '<ul style="font-size:16px; line-height:1.6;">';
    echo '<li><strong style="color:green;">Active:</strong> ' . $counts['active'] . '</li>';
    echo '<li><strong style="color:orange;">Expiring Soon:</strong> ' . $counts['expiring'] . '</li>';
    echo '<li><strong style="color:red;">Expired:</strong> ' . $counts['expired'] . '</li>';
    echo '<li><strong style="color:blue;">Pending Review:</strong> ' . $counts['pending'] . '</li>';
    echo '</ul>';

    // Google Chart
    ?>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script>
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
add_action('save_post', 'cefm_notify_admin_and_authors_about_expiry');
function cefm_notify_admin_and_authors_about_expiry_on_save($post_id) {

    // Prevent infinite loop & autosave issues
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    // Run your notification function
    cefm_notify_admin_and_authors_about_expiry();
}
add_action('save_post', 'cefm_notify_admin_and_authors_about_expiry_on_save');


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

    if (empty($posts)) return;

    foreach ($posts as $post) {

        $expiry_action = get_post_meta($post->ID, '_expiry_action', true);
        if ($expiry_action === 'disable') continue;

        $expiry_date_raw = get_post_meta($post->ID, '_expiry_date', true);
        if (empty($expiry_date_raw)) continue;

        $expiry_time = strtotime($expiry_date_raw);

        if (!$expiry_time) {
            // Try converting DD/MM/YYYY → YYYY-MM-DD
            if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $expiry_date_raw, $m)) {
                $expiry_time = strtotime("{$m[3]}-{$m[2]}-{$m[1]}");
            }
        }

        if (!$expiry_time) {
            continue;
        }
// print_r($expiry_time);
//  die;
        $days_left = floor(($expiry_time - $now) / DAY_IN_SECONDS);
        // $days_left = (int) floor(($expiry_time - $now) / DAY_IN_SECONDS);
$notify_days = [7, 6, 5, 4, 3, 2, 1, 0];
// print_r($days_left);
//  die;
        // Only notify at 7 days and 0 days
        // if (in_array($days_left, $notify_days, true)) continue;
         if (in_array($days_left, [7, 0])) continue;

        $flag_key = '_expiry_notified_' . $days_left;   
        if (get_post_meta($post->ID, $flag_key, true)) continue;
        
        $subject = sprintf(
            "Content Expiry Alert: \"%s\" expires in %d day%s",
            $post->post_title,
            $days_left,
            ($days_left === 1 ? '' : 's')
        );

        $expiry_date = date('Y-m-d', $expiry_time);

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
  
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        wp_mail($admin_email, $subject, $message, $headers);

        $author = get_userdata($post->post_author);
        if ($author && !empty($author->user_email)) {
            $author_message = "Hi {$author->display_name},\n\n";
            $author_message .= "Your post \"{$post->post_title}\" is nearing expiration ({$expiry_date}).\n";
            $author_message .= "Please review or update it.\n\n";
            $author_message .= "Edit Here: " . admin_url("post.php?post={$post->ID}&action=edit") . "\n\n";
            $author_message .= "Thanks,\nContent Expiry & Freshness Manager";
            wp_mail($author->user_email, $subject, $author_message, $headers);
        }

        update_post_meta($post->ID, $flag_key, current_time('mysql'));
    }
}



// Schedule hourly check if not already scheduled
// add_action('wp', function() {
//     if (!wp_next_scheduled('cefm_check_expiry_posts')) {
//         wp_schedule_event(time(), 'hourly', 'cefm_check_expiry_posts');
//     }
// });

// add_action('cefm_check_expiry_posts', 'cefm_notify_admin_and_authors_about_expiry');

// // Manual test trigger (for debugging)
// add_action('admin_init', function() {
//     if (isset($_GET['test_cefm_notify'])) {
//         cefm_notify_admin_and_authors_about_expiry();
//         wp_die('Expiry notifications sent (check your email or logs)');
//     }
// });













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








// -----------------------------------
// wocommerce product hide/show functionality
// ----------------------------------


function cefm_render_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Content Expiry & Freshness Manager - Settings', 'cefm'); ?></h1>

        <p>Here you can configure plugin settings such as WooCommerce behavior, email settings, default expiry actions, etc.</p>

        <form method="post" action="options.php">
            <?php
                settings_fields('cefm_settings_group');
                do_settings_sections('cefm-settings');
                submit_button();
            ?>
        </form>
    </div>
    <?php
}


add_action('admin_init', 'cefm_register_wc_settings');

function cefm_register_wc_settings() {

    // Register setting
    register_setting(
        'cefm_settings_group',          // Settings group
        'cefm_wc_enable_product_expiry' // Option name
    );

    // Add section
    add_settings_section(
        'cefm_wc_settings_section',
        __('WooCommerce Settings', 'cefm'),
        function() {
            echo "<p>Configure WooCommerce behavior for product expiry.</p>";
        },
        'cefm-settings'
    );

    // Add checkbox field
    add_settings_field(
        'cefm_wc_enable_product_expiry_field',
        __('Enable Product Expiry for WooCommerce', 'cefm'),
        'cefm_wc_enable_product_expiry_field_callback',
        'cefm-settings',
        'cefm_wc_settings_section'
    );
}

function cefm_wc_enable_product_expiry_field_callback() {

    $option = get_option('cefm_wc_enable_product_expiry', '');

    ?>
    <label>
        <input type="checkbox" name="cefm_wc_enable_product_expiry" value="1" 
            <?php checked(1, $option); ?> />
        <?php _e('Enable expiry logic for WooCommerce products (redirect, hide, or expire based on date).', 'cefm'); ?>
    </label>
    <?php
}

// add_action('add_meta_boxes', 'cefm_control_wc_expiry_meta_box');

// function cefm_control_wc_expiry_meta_box() {

//     $enabled = get_option('cefm_wc_enable_product_expiry');

//     // If OFF → hide meta box
//     if (empty($enabled)) {
//         return;
//     }

//     // If ON → show expiry meta box on WooCommerce products
//     add_meta_box(
//         'cefm_expiry_box',
//         __('Content Expiry', 'cefm'),
//         'cefm_handle_expired_posts',   // FIX function name
//         'product',
//         'side',
//         'default'
//     );
// }


add_action('admin_enqueue_scripts', function() {

    wp_enqueue_script(
        'cefm-admin-js',
        plugin_dir_url(__FILE__) . 'assets/admin.js',
        ['jquery'],
        false,
        true
    );

    wp_localize_script('cefm-admin-js', 'cefmAdminData', [
        'enableExpiry' => (bool) get_option('cefm_wc_enable_product_expiry'),
    ]);
});

