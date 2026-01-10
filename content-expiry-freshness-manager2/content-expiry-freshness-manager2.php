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

        // Normalize expiry format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
            $expiry .= ' 23:59:59';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $expiry)) {
            $expiry .= ':00';
        }

        $dt = date_create_from_format(
            'Y-m-d H:i:s',
            $expiry,
            wp_timezone()
        );

        if ($dt) {
            $expiry_html_value = $dt->format('Y-m-d\TH:i');
        }
    }




   
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
  
    $datetime = str_replace('T', ' ', $datetime_raw);
    update_post_meta($post_id, '_expiry_date', $datetime);


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


$wc_enabled = get_option('cefm_wc_enable_product_expiry', '0');

// Skip WooCommerce products only when disabled
if ($post->post_type === 'product' && $wc_enabled !== '1') {
    continue;
}

$expiry_raw = get_post_meta($post->ID, '_expiry_date', true);
if (!$expiry_raw) continue;

$expiry_ts = cefm_get_expiry_timestamp($expiry_raw);
$now = current_time('timestamp');



        if ($expiry_ts > 0 && $expiry_ts <= $now) {

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
    echo '<ul style="font-size:16px; line-height:1.7; ">';
    echo '<li><a href="' . admin_url('admin.php?page=content-expiry-overview') . '">📋 Open Content Expiry Overview</a></li>';
    echo '</ul>';
    echo '</div>';
}


// =======================
// Dashboard (Main Page)
// =======================
function cefm_get_expiry_timestamp($expiry) {

    if (empty($expiry)) {
        return 0;
    }

    // Normalize formats
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
        $expiry .= ' 23:59:59';
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $expiry)) {
        $expiry .= ':00';
    }

    try {
        $dt = new DateTime($expiry, wp_timezone());
        return $dt->getTimestamp();
    } catch (Exception $e) {
        return 0;
    }
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
    // $new_expiry = date('Y-m-d H:i', strtotime("+{$days_to_extend} days", current_time('timestamp')));
    $dt = new DateTime('now', wp_timezone());
$dt->modify("+{$days_to_extend} days");

$new_expiry = $dt->format('Y-m-d H:i:s');
update_post_meta($post_id, '_expiry_date', $new_expiry);


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









// -------------------------------------------------
// ---------------- csv file download --------------
// --------------------------------------------------

add_action('admin_post_cefm_download_csv', 'cefm_download_expiry_csv');

function cefm_download_expiry_csv() {

    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('cefm_download_csv');

while (ob_get_level() > 0) {
    ob_end_clean();
}

    nocache_headers();

    // Get filters from URL
    $filter_post_type = isset($_GET['filter_post_type'])
        ? sanitize_text_field($_GET['filter_post_type'])
        : '';

    $filter_status = isset($_GET['filter_status'])
        ? sanitize_text_field($_GET['filter_status'])
        : '';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=content-expiry-overview.csv');

    $output = fopen('php://output', 'w');

    // CSV header
    fputcsv($output, [
        'Post ID',
        'Title',
        'Post Type',
         'Post URL',
        'Expiry Date',
        'Action on Expiry',
        'Status'
    ]);

    $posts = get_posts([
        'post_type'   => $filter_post_type ?: 'any',
        'post_status' => ['publish', 'private', 'draft', 'pending'],
        'meta_query'  => [
            [
                'key'     => '_expiry_date',
                'compare' => 'EXISTS',
            ],
        ],
        'numberposts' => -1,
    ]);

    $now_ts      = current_time('timestamp');
    $upcoming_ts = $now_ts + (DAY_IN_SECONDS * 7);

    foreach ($posts as $post) {
                       
        $expiry  = get_post_meta($post->ID, '_expiry_date', true);
        $action  = get_post_meta($post->ID, '_expiry_action', true);
        $expiry_ts = cefm_get_expiry_timestamp($expiry);

        // Determine status (same logic as table)
        if ($action === 'disable' || !$expiry_ts) {
            $status = 'disabled';
        } elseif ($post->post_status === 'pending') {
            $status = 'pending';
        } elseif ($expiry_ts <= $now_ts) {
            $status = 'expired';
        } elseif ($expiry_ts <= $upcoming_ts) {
            $status = 'expiring';
        } else {
            $status = 'active';
        }

        // Apply status filter
        if ($filter_status && $filter_status !== $status) {
            continue;
        }

        fputcsv($output, [
            $post->ID,
            get_the_title($post),
            $post->post_type,
             get_permalink($post),
            $expiry_ts ? wp_date('Y-m-d H:i', $expiry_ts) : '',
            ucfirst($action ?: '—'),
            ucfirst($status),
        ]);
    }

    fclose($output);
    exit;
}

// add_action('admin_post_cefm_download_csv', 'cefm_download_expiry_csv');

// function cefm_download_expiry_csv() {

//     if (!current_user_can('manage_options')) {
//         wp_die('Unauthorized');
//     }

//     check_admin_referer('cefm_download_csv');

//     header('Content-Type: text/csv; charset=UTF-8');
//     header('Content-Disposition: attachment; filename=content-expiry-overview.csv');

//     $output = fopen('php://output', 'w');

//     // CSV header row
//     fputcsv($output, [
//         'Post Type',
//         'Expiry Date',
//         'Action on Expiry'
//     ]);

//     $posts = get_posts([
//         'post_type'   => 'any',
//         'post_status' => ['publish', 'private', 'draft', 'pending'],
//         'meta_query'  => [
//             [
//                 'key'     => '_expiry_date',
//                 'compare' => 'EXISTS',
//             ],
//         ],
//         'numberposts' => -1,
//     ]);

//     foreach ($posts as $post) {

//         $expiry = get_post_meta($post->ID, '_expiry_date', true);
//         $action = get_post_meta($post->ID, '_expiry_action', true);

//         $expiry_ts = cefm_get_expiry_timestamp($expiry);
//         $expiry_display = $expiry_ts
//             ? wp_date('Y-m-d H:i', $expiry_ts)
//             : '';

//         fputcsv($output, [
//             $post->post_type,
//             $expiry_display,
//             ucfirst($action ?: '—'),
//         ]);
//     }

//     fclose($output);
//     exit;
// }









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

//                 if ($old_expiry) {
//                     // $new_expiry = date('Y-m-d H:i', strtotime("+30 days", strtotime($old_expiry)));
//                     $new_expiry = wp_date(
//     'Y-m-d H:i:s',
//     strtotime('+30 days', strtotime($old_expiry))
// );

//                     update_post_meta($post_id, '_expiry_date', $new_expiry);
//                 }
            $old_ts = cefm_get_expiry_timestamp($old_expiry);

            if ($old_ts) {
                $new_ts = $old_ts + (DAY_IN_SECONDS * 30);
                $new_expiry = wp_date('Y-m-d H:i:s', $new_ts);
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


//     echo '<a href="' . esc_url(
//     wp_nonce_url(
//         admin_url('admin-post.php?action=cefm_download_csv'),
//         'cefm_download_csv'
//     )
// ) . '" class="button button-secondary">⬇ Download CSV</a>';
echo '<a href="' . esc_url(
    wp_nonce_url(
        add_query_arg([
            'action'            => 'cefm_download_csv',
            'filter_post_type'  => $filter_post_type,
            'filter_status'     => $filter_status,
        ], admin_url('admin-post.php')),
        'cefm_download_csv'
    )
) . '" class="button button-secondary">⬇ Download CSV</a>';





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
        $action     = get_post_meta($post->ID, '_expiry_action', true);


        $expiry_ts   = cefm_get_expiry_timestamp($expiry);
        $now_ts      = current_time('timestamp');
        $upcoming_ts = $now_ts + (DAY_IN_SECONDS * 7);

        if ($action === 'disable' || !$expiry_ts) {

            $status = 'disabled';
            $status_label = '<span style="color:gray;">Disabled</span>';

        } elseif ($post->post_status === 'pending') {

            $status = 'pending';
            $status_label = '<span style="color:blue;">Pending Review</span>';
            $counts['pending']++;

        } elseif ($now_ts >= $expiry_ts) {

            // 🔴 Expired ONLY after exact time passed
            $status = 'expired';
            $status_label = '<span style="color:red;font-weight:bold;">Expired</span>';
            $counts['expired']++;

        } elseif ($expiry_ts <= $upcoming_ts) {

            // 🟠 Expiring Soon (within 7 days)
            $status = 'expiring';
            $status_label = '<span style="color:orange;">Expiring Soon</span>';
            $counts['expiring']++;

        } else {

            // 🟢 Fully Active
            $status = 'active';
            $status_label = '<span style="color:green;">Active</span>';
            $counts['active']++;
        }


        // Apply filters
        if ($filter_status && $filter_status !== $status) continue;

        $refresh_url = wp_nonce_url(
            admin_url("admin-post.php?action=cefm_refresh_expiry&post_id={$post->ID}"),
            'cefm_refresh_' . $post->ID
        );

$expiry_display = $expiry_ts
    ? wp_date('Y-m-d H:i', $expiry_ts)
    : '';
// $expiry_display1 = $now_ts
//     ? wp_date('Y-m-d H:i', $now_ts)
//     : '';
$expiry_display1 = wp_date('Y-m-d H:i:s', $now_ts);

        echo '<tr>
            <td><input type="checkbox" name="selected_posts[]" value="' . esc_attr($post->ID) . '"></td>
            <td>' . esc_html(get_the_title($post)) . '</td>
            <td>' . esc_html($post->post_type) . '</td>
             <td>' . esc_html($expiry_display) . '</td>
             
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
    echo '<ul style="font-size:16px; line-height:1.6; display:flex;">';
    echo '<li style="margin-right:20px"><strong style="color:green;">Active:</strong> ' . $counts['active'] . '</li>';
    echo '<li style="margin-right:20px"><strong style="color:orange;">Expiring Soon:</strong> ' . $counts['expiring'] . '</li>';
    echo '<li style="margin-right:20px"><strong style="color:red;">Expired:</strong> ' . $counts['expired'] . '</li>';
    echo '<li style="margin-right:20px"><strong style="color:blue;">Pending Review:</strong> ' . $counts['pending'] . '</li>';
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
















// add_action('cefm_notify_expiring_posts', 'cefm_send_expiry_notifications');

function cefm_send_expiry_notifications() {

     if (get_option('cefm_email_notifications', 'yes') !== 'yes') {
        return;
    }
    
    $admin_email = get_option('admin_email');
    $today = strtotime(date('Y-m-d', current_time('timestamp')));

    $posts = get_posts([
        'post_type'   => 'any',
        'post_status' => ['publish', 'private'],
        'meta_query'  => [
            [
                'key'     => '_expiry_date',
                'compare' => 'EXISTS',
            ],
        ],
        'numberposts' => -1,
    ]);

    if (!$posts) return;

    foreach ($posts as $post) {

        $expiry_action = get_post_meta($post->ID, '_expiry_action', true);
        if ($expiry_action === 'disable') continue;

        $expiry_raw = get_post_meta($post->ID, '_expiry_date', true);
        if (!$expiry_raw) continue;

        $expiry_time = strtotime($expiry_raw);
        if (!$expiry_time) continue;

        $expiry_day = strtotime(date('Y-m-d', $expiry_time));
        $days_left  = (int)(($expiry_day - $today) / DAY_IN_SECONDS);
//         $expiry_ts = cefm_get_expiry_timestamp($expiry_raw);
// $now_ts    = current_time('timestamp');

// $seconds_left = $expiry_ts - $now_ts;
// $days_left = (int) ceil($seconds_left / DAY_IN_SECONDS);


        // 🔑 ONLY 0–7 DAYS
        if ($days_left < 0 || $days_left > 7) continue;

        // Prevent duplicate emails per day
        $flag_key = '_cefm_notified_' . $days_left;
        if (get_post_meta($post->ID, $flag_key, true)) continue;

        // -------------------
        // EMAIL CONTENT
        // -------------------
        $subject = sprintf(
            '⏰ Content Expiry Alert: "%s" (%d day%s left)',
            $post->post_title,
            $days_left,
            $days_left === 1 ? '' : 's'
        );

        // $expiry_date = date('Y-m-d', $expiry_time);
        $expiry_date = wp_date('Y-m-d H:i', $expiry_ts);

        $edit_link = admin_url("post.php?post={$post->ID}&action=edit");

        $message = "
Hi,

The following content is nearing expiration:

------------------------------------
Title: {$post->post_title}
Post Type: {$post->post_type}
Expiry Date: {$expiry_date}
Days Left: {$days_left}
Action on Expiry: {$expiry_action}

Edit Post:
{$edit_link}
------------------------------------

– Content Expiry & Freshness Manager
";

        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        // Send to Admin
        wp_mail($admin_email, $subject, $message, $headers);

        // Send to Author
        // $author = get_userdata($post->post_author);
        // if ($author && !empty($author->user_email)) {
        //     wp_mail($author->user_email, $subject, $message, $headers);
        // }

        // Mark as notified
        update_post_meta($post->ID, $flag_key, current_time('mysql'));
    }
}



add_action('save_post', function($post_id){

    if (!isset($_POST['expiry_nonce']) || !wp_verify_nonce($_POST['expiry_nonce'], 'save_expiry_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Disable expiry
    if (isset($_POST['expiry_action']) && $_POST['expiry_action'] === 'disable') {
        delete_post_meta($post_id, '_expiry_date');
        delete_post_meta($post_id, '_expiry_action');
        delete_post_meta($post_id, '_expiry_redirect');
        delete_post_meta($post_id, '_expiry_message');
        delete_post_meta($post_id, '_active_redirect');
        delete_post_meta($post_id, '_replaced_content');
        return;
    }

    // Save expiry date
    if (!empty($_POST['expiry_date'])) {
        $datetime_raw = sanitize_text_field($_POST['expiry_date']);
        $datetime = str_replace('T', ' ', $datetime_raw);
        update_post_meta($post_id, '_expiry_date', $datetime);

        // Clear old expiry effects if moved to future
        if (strtotime($datetime) > current_time('timestamp')) {
            delete_post_meta($post_id, '_active_redirect');
            delete_post_meta($post_id, '_replaced_content');
        }

        // 🔁 Reset email notification flags
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta}
                 WHERE post_id = %d
                 AND meta_key LIKE '_cefm_notified_%'",
                $post_id
            )
        );
    }

    update_post_meta($post_id, '_expiry_action', sanitize_text_field($_POST['expiry_action']));
    update_post_meta($post_id, '_expiry_redirect', esc_url_raw($_POST['expiry_redirect']));
    update_post_meta($post_id, '_expiry_message', sanitize_textarea_field($_POST['expiry_message']));
});





// ============================
// Trigger email immediately on post save
// ============================
add_action('save_post', 'cefm_trigger_expiry_email_on_save', 30, 1);
function cefm_trigger_expiry_email_on_save($post_id) {

    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Only run if expiry date was submitted
    if (!isset($_POST['expiry_date']) || empty($_POST['expiry_date'])) return;

    // Run notification immediately
    cefm_send_expiry_notifications();
}












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

        <form method="post" action="options.php">
            <?php
                settings_fields('cefm_settings_group');
                do_settings_sections('cefm-settings');
                submit_button(); // ✅ SINGLE SAVE BUTTON
            ?>
        </form>
    </div>
    <?php
}



add_action('admin_init', 'cefm_register_wc_settings');

function cefm_register_wc_settings() {

    // Email notification option
    register_setting(
        'cefm_settings_group',
        'cefm_email_notifications'
    );

    // WooCommerce option
    register_setting(
        'cefm_settings_group',
        'cefm_wc_enable_product_expiry'
    );

    add_settings_section(
        'cefm_wc_settings_section',
        __('General Settings', 'cefm'),
        function () {
            echo '<p>Configure expiry behavior.</p>';
        },
        'cefm-settings'
    );

    // Email checkbox
    add_settings_field(
        'cefm_email_notifications',
        __('Expiry Email Notifications', 'cefm'),
        'cefm_email_field_callback',
        'cefm-settings',
        'cefm_wc_settings_section'
    );

    // WooCommerce checkbox
    add_settings_field(
        'cefm_wc_enable_product_expiry',
        __('Enable Product Expiry for WooCommerce', 'cefm'),
        'cefm_wc_enable_product_expiry_field_callback',
        'cefm-settings',
        'cefm_wc_settings_section'
    );
}

function cefm_email_field_callback() {
    $enabled = get_option('cefm_email_notifications', 'yes');
    ?>
    <label>
        <input type="checkbox" name="cefm_email_notifications" value="yes"
            <?php checked('yes', $enabled); ?>>
        Enable expiry email notifications
    </label>
    <?php
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

