<?php
/**
 * Main functions file - Now includes modular files
 * 
 * IMPORTANT: Original code remains below. We'll migrate gradually.
 */

// Prevent direct access
defined('ABSPATH') || exit;

// Define path constant
define('THEME_INC', get_stylesheet_directory() . '/inc/');
define('THEME_ADMIN', THEME_INC . 'admin/');
define('THEME_SEO', THEME_INC . 'seo/');
define('THEME_COMMENTS', THEME_INC . 'comments/');

// ========== CORE FUNCTIONALITY ==========
$core_files = [
    'theme-setup.php',
    //'utilities.php',
    'post-types.php',           // CPT registrations only
    'taxonomies.php',
    'shortcodes.php',
    'security/security-functions.php',
    'security/login-security.php',
];

foreach ($core_files as $file) {
    require_once THEME_INC . $file;
}

// ========== FRONT-END ONLY ==========
if (!is_admin()) {
    $frontend_files = [
        'seo/robots-control.php',
        'seo/feeds-control.php',
        'comments/comments-control.php',
        //'frontend/enqueue-scripts.php',
        //'frontend/template-functions.php',
    ];
    
    foreach ($frontend_files as $file) {
        require_once THEME_INC . $file;
    }
}

// ========== ADMIN ONLY ==========
if (is_admin()) {
    $admin_files = [
        'admin/media-restrictions.php',
        'admin/admin-functions.php',
        //'admin/dashboard-widgets.php',
        'post-types/meta-boxes.php',  // Meta boxes are admin-only
    ];
    
    foreach ($admin_files as $file) {
        require_once THEME_INC . $file;
    }
}


/**
 * Consolidated Category Update Handler
 * Works with both dropdown and drag & drop
 */
function handle_consolidated_category_update() {
    error_log('?? [UNIFIED] Consolidated category update called');
    
    $user_id = get_current_user_id();
    $nonce = $_POST['nonce'] ?? $_POST['_ajax_nonce'] ?? '';
    $media_id = intval($_POST['media_id'] ?? $_POST['image_id'] ?? 0);
    $category = sanitize_text_field($_POST['category'] ?? '');
    
    error_log("?? [UNIFIED] Media: $media_id, Category: $category, User: $user_id");
    
    // Verify nonce
    if (!wp_verify_nonce($nonce, 'update_profile_' . $user_id)) {
        error_log('? [UNIFIED] Nonce verification failed');
        wp_send_json_error('Security check failed');
        return;
    }
    
    // Validate
    if (!$media_id || !$category) {
        wp_send_json_error('Missing data');
        return;
    }
    
    // Check ownership
    $post = get_post($media_id);
    if (!$post || $post->post_author != $user_id) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    // Update WordPress category taxonomy
    if ($category === 'none') {
        wp_delete_object_term_relationships($media_id, 'category');
    } else {
        wp_set_object_terms($media_id, [$category], 'category', false);
    }
    
    // Also update custom meta for compatibility
    update_post_meta($media_id, 'profile_image_category', $category);
    
    // Update user's category data
    $categories_data = get_user_meta($user_id, 'profile_images_categories', true);
    if (!is_array($categories_data)) {
        $categories_data = [];
    }
    $categories_data[$media_id] = $category;
    update_user_meta($user_id, 'profile_images_categories', $categories_data);
    
    // Get updated counts
    $counts = get_category_counts($user_id);
    
    error_log('? [UNIFIED] Category updated successfully');
    
    wp_send_json_success([
        'media_id' => $media_id,
        'category' => $category,
        'counts' => $counts,
        'limits' => [
            'gallery' => 6,
            'profile_featured_image' => 1,
            'hands' => 2,
            'vehicle' => 2,
            'other' => 12
        ],
        'message' => 'Category updated'
    ]);
}
add_action('wp_ajax_update_media_category', 'handle_consolidated_category_update');
add_action('wp_ajax_update_image_category', 'handle_consolidated_category_update'); // Keep both for compatibility

/**
 * Consolidated Order Update Handler
 * Accepts both new and old data formats
 */
function handle_consolidated_order_update() {
    error_log('?? [UNIFIED] Consolidated order update called');
    
    $user_id = get_current_user_id();
    $nonce = $_POST['_ajax_nonce'] ?? $_POST['nonce'] ?? '';
    $order_json = stripslashes($_POST['order'] ?? '');
    $categories_json = stripslashes($_POST['categories'] ?? '');
    
    // Verify nonce
    if (!wp_verify_nonce($nonce, 'update_profile_' . $user_id)) {
        error_log('? [UNIFIED] Nonce verification failed for order update');
        wp_send_json_error('Security check failed');
        return;
    }
    
    $order_data = json_decode($order_json, true) ?: [];
    $categories_data = json_decode($categories_json, true) ?: [];
    
    error_log("?? [UNIFIED] Processing order update: " . count($order_data) . " items");
    
    // Handle both data formats:
    // Format 1: Simple array of IDs [123, 456, 789]
    // Format 2: Nested by category {'gallery': [{'id': 123}, {'id': 456}]}
    
    $processed_ids = [];
    $menu_order = 1;
    
    if (isset($order_data[0]) && is_numeric($order_data[0])) {
        // Format 1: Simple array
        error_log('?? [UNIFIED] Detected simple array format');
        foreach ($order_data as $attachment_id) {
            $attachment_id = intval($attachment_id);
            if ($attachment_id) {
                wp_update_post([
                    'ID' => $attachment_id,
                    'menu_order' => $menu_order++
                ]);
                $processed_ids[] = $attachment_id;
                
                // Update category if provided
                if (isset($categories_data[$attachment_id])) {
                    $category = sanitize_text_field($categories_data[$attachment_id]);
                    if ($category === 'none') {
                        wp_delete_object_term_relationships($attachment_id, 'category');
                    } else {
                        wp_set_object_terms($attachment_id, [$category], 'category', false);
                    }
                    update_post_meta($attachment_id, 'profile_image_category', $category);
                }
            }
        }
    } else {
        // Format 2: Nested by category
        error_log('?? [UNIFIED] Detected nested category format');
        foreach ($order_data as $category => $images) {
            if (is_array($images)) {
                foreach ($images as $image) {
                    $attachment_id = isset($image['id']) ? intval($image['id']) : intval($image);
                    if ($attachment_id) {
                        wp_update_post([
                            'ID' => $attachment_id,
                            'menu_order' => $menu_order++
                        ]);
                        $processed_ids[] = $attachment_id;
                        
                        // Update category
                        $category_slug = sanitize_text_field($category);
                        if ($category_slug === 'none') {
                            wp_delete_object_term_relationships($attachment_id, 'category');
                        } else {
                            wp_set_object_terms($attachment_id, [$category_slug], 'category', false);
                        }
                        update_post_meta($attachment_id, 'profile_image_category', $category_slug);
                        $categories_data[$attachment_id] = $category_slug;
                    }
                }
            }
        }
    }
    
    // Update user meta
    update_user_meta($user_id, 'profile_images_order', array_values($processed_ids));
    update_user_meta($user_id, 'profile_images_categories', $categories_data);
    
    // Get updated counts
    $counts = get_category_counts($user_id);
    
    error_log('? [UNIFIED] Order updated: ' . count($processed_ids) . ' items');
    
    wp_send_json_success([
        'message' => 'Order and categories updated',
        'updated' => count($processed_ids),
        'counts' => $counts,
        'limits' => [
            'gallery' => 6,
            'profile_featured_image' => 1,
            'hands' => 2,
            'vehicle' => 2,
            'other' => 12
        ]
    ]);
}
add_action('wp_ajax_update_image_order', 'handle_consolidated_order_update');

/**
 * Helper function to get category counts
 */
function get_category_counts($user_id) {
    $categories = ['gallery', 'profile_featured_image', 'hands', 'vehicle', 'other'];
    $counts = [];
    
    foreach ($categories as $slug) {
        $args = [
            'post_type' => 'attachment',
            'author' => $user_id,
            'tax_query' => [['taxonomy' => 'category', 'field' => 'slug', 'terms' => $slug]],
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];
        $counts[$slug] = (new WP_Query($args))->found_posts;
    }
    
    // Count uncategorized
    $uncat_args = [
        'post_type' => 'attachment',
        'author' => $user_id,
        'tax_query' => [['taxonomy' => 'category', 'operator' => 'NOT EXISTS']],
        'posts_per_page' => -1,
        'fields' => 'ids'
    ];
    $counts['none'] = (new WP_Query($uncat_args))->found_posts;
    
    return $counts;
}

// Add this PHP function to check
function get_display_name() {
    $attachment_id = intval($_POST['attachment_id']);
    $value = get_post_meta($attachment_id, '_profile_display_name', true);
    
    wp_send_json_success([
        'attachment_id' => $attachment_id,
        'value' => $value
    ]);
}
add_action('wp_ajax_get_display_name', 'get_display_name');

function debug_nonce_issue() {
    error_log('=== DEBUG NONCE ISSUE ===');
    
    // Log all POST data
    error_log('Full POST data: ' . print_r($_POST, true));
    
    // Check all possible nonce parameters
    $params = ['nonce', '_ajax_nonce', '_wpnonce', 'security'];
    foreach ($params as $param) {
        if (isset($_POST[$param])) {
            error_log("Found $param: " . $_POST[$param]);
        }
    }
    
    $user_id = get_current_user_id();
    error_log("User ID: $user_id");
    
    // Check verification with all possible nonces
    foreach ($params as $param) {
        if (!empty($_POST[$param])) {
            $valid = wp_verify_nonce($_POST[$param], 'update_profile_' . $user_id);
            error_log("Nonce $param valid: " . ($valid ? 'YES' : 'NO'));
        }
    }
    
    wp_send_json_success(['message' => 'Debug complete']);
}
add_action('wp_ajax_debug_nonce_issue', 'debug_nonce_issue');

// Add this to your functions.php - make sure it's BEFORE the add_action
function debug_specific_attachments() {
    // Check if user is logged in
    if (!is_user_logged_in()) {
        error_log("? User not logged in for debug_specific");
        wp_die('Not logged in');
    }
    
    // Get nonce
    $nonce = $_POST['nonce'] ?? '';
    $user_id = get_current_user_id();
    
    // Verify nonce
    if (!wp_verify_nonce($nonce, 'update_profile_' . $user_id)) {
        error_log("? Nonce verification failed for debug_specific");
        wp_die('Security check failed');
    }
    
    error_log('====== SPECIFIC ATTACHMENT DEBUG ======');
    
    // IDs from your logs
    $success_id = 2737;
    $fail_ids = [2690, 2691, 2712, 2713];
    
    $user_id = get_current_user_id();
    error_log("Current user ID: $user_id");
    
    $results = [];
    
    // Check the successful one
    $success_post = get_post($success_id);
    $results[$success_id] = [
        'type' => $success_post ? $success_post->post_type : 'none',
        'author' => $success_post ? $success_post->post_author : 0,
        'user_is_owner' => $success_post && ($success_post->post_author == $user_id),
        'can_edit' => $success_post ? current_user_can('edit_post', $success_id) : false
    ];
    
    // Check failed ones
    foreach ($fail_ids as $fail_id) {
        $fail_post = get_post($fail_id);
        $results[$fail_id] = [
            'type' => $fail_post ? $fail_post->post_type : 'none',
            'author' => $fail_post ? $fail_post->post_author : 0,
            'user_is_owner' => $fail_post && ($fail_post->post_author == $user_id),
            'can_edit' => $fail_post ? current_user_can('edit_post', $fail_id) : false
        ];
    }
    
    error_log('Debug results: ' . json_encode($results));
    wp_send_json_success($results);
}

// Make sure this is added AFTER the function definition
add_action('wp_ajax_debug_specific', 'debug_specific_attachments');

function test_attachment_exists() {
    error_log('====== TEST ATTACHMENT EXISTS CALLED ======');
    
    $attachment_id = intval($_POST['attachment_id']);
    $nonce = $_POST['nonce'] ?? '';
    $user_id = get_current_user_id();
    
    error_log("Testing attachment ID: $attachment_id");
    error_log("User ID: $user_id");
    error_log("Nonce received: $nonce");
    
    // Verify nonce
    if (!wp_verify_nonce($nonce, 'update_profile_' . $user_id)) {
        error_log('? Nonce verification failed');
        wp_send_json_error('Security check failed');
        return;
    }
    
    $post = get_post($attachment_id);
    
    wp_send_json_success([
        'exists' => !empty($post),
        'post_type' => $post ? $post->post_type : 'none',
        'post_title' => $post ? $post->post_title : '',
        'post_author' => $post ? $post->post_author : 0,
        'current_user' => $user_id,
        'is_attachment' => $post && $post->post_type === 'attachment',
        'mime_type' => $post ? get_post_mime_type($post->ID) : '',
        'post_status' => $post ? $post->post_status : '',
        'guid' => $post ? $post->guid : ''
    ]);
}
add_action('wp_ajax_test_attachment_exists', 'test_attachment_exists');

function debug_attachment_exists_logic() {
    error_log('====== DEBUG ATTACHMENT EXISTS LOGIC ======');
    
    $test_ids = [18, 2690, 2628, 773];
    $user_id = get_current_user_id();
    
    error_log("Current user ID: $user_id");
    
    foreach ($test_ids as $id) {
        error_log("--- Testing ID: $id ---");
        
        // Your actual logic from attachment_exists():
        $post = get_post($id);
        $exists = !empty($post) && $post->post_type === 'attachment';
        
        error_log("get_post result: " . ($post ? 'object' : 'false/null'));
        error_log("post_type: " . ($post ? $post->post_type : 'N/A'));
        error_log("attachment_exists result: " . ($exists ? 'true' : 'false'));
        
        if ($post) {
            error_log("post_author: " . $post->post_author);
            error_log("post_status: " . $post->post_status);
            
            // Check if user can edit
            $can_edit = $user_id && $user_id == $post->post_author;
            error_log("User can edit: " . ($can_edit ? 'yes' : 'no'));
        }
        
        error_log('');
    }
}

// Call this somewhere, like in your handle_update_display_names function


// Audio AJAX handlers
add_action('wp_ajax_upload_profile_audio', 'handle_audio_upload');
add_action('wp_ajax_save_selected_audio', 'save_selected_audio');
add_action('wp_ajax_save_audio_display_name', 'save_audio_display_name');
add_action('wp_ajax_save_audio_order', 'save_audio_order');
add_action('wp_ajax_delete_profile_audio', 'delete_profile_audio');

function handle_audio_upload() {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    // Verify nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . $user_id)) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    
    // Check if current user can edit this profile
    if (!current_user_can('edit_user', $user_id)) {
        wp_send_json_error('Permission denied');
    }
    
    $uploaded = 0;
    $errors = [];
    
    if (!empty($_FILES['profile_audio']['name'][0])) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $files = $_FILES['profile_audio'];
        
        foreach ($files['name'] as $key => $value) {
            if ($files['name'][$key]) {
                $file = array(
                    'name'     => $files['name'][$key],
                    'type'     => $files['type'][$key],
                    'tmp_name' => $files['tmp_name'][$key],
                    'error'    => $files['error'][$key],
                    'size'     => $files['size'][$key]
                );
                
                // Check file type
                $filetype = wp_check_filetype($file['name']);
                if (!in_array($filetype['type'], ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4'])) {
                    $errors[] = $file['name'] . ' is not a valid audio file';
                    continue;
                }
                
                // Check file size (10MB limit)
                if ($file['size'] > 10 * 1024 * 1024) {
                    $errors[] = $file['name'] . ' is too large (max 10MB)';
                    continue;
                }
                
                $_FILES = array('profile_audio' => $file);
                
                $attachment_id = media_handle_upload('profile_audio', 0, array(
                    'post_author' => $user_id,
                    'post_title' => preg_replace('/\.[^.]+$/', '', $file['name'])
                ));
                
                if (is_wp_error($attachment_id)) {
                    $errors[] = $file['name'] . ': ' . $attachment_id->get_error_message();
                } else {
                    // Assign to audio_clip category
                    wp_set_object_terms($attachment_id, 'audio_clip', 'profile_media_cat');
                    $uploaded++;
                }
            }
        }
    }
    
    // Generate HTML for new audio items
    $html = '';
    if ($uploaded > 0) {
        // Get latest audio for this user
        $audio_query = new WP_Query([
            'post_type' => 'attachment',
            'post_mime_type' => 'audio',
            'post_status' => 'inherit',
            'author' => $user_id,
            'posts_per_page' => $uploaded,
            'orderby' => 'date',
            'order' => 'DESC',
            'tax_query' => [
                [
                    'taxonomy' => 'profile_media_cat',
                    'field' => 'slug',
                    'terms' => ['audio_clip']
                ]
            ]
        ]);
        
        if ($audio_query->have_posts()) {
            while ($audio_query->have_posts()) {
                $audio_query->the_post();
                $audio_id = get_the_ID();
                $audio_url = wp_get_attachment_url($audio_id);
                $audio_title = get_the_title();
                
                $html .= '
                <li class="sortable-audio-item" data-id="' . $audio_id . '">
                    <small class="filename-display">File: ' . esc_html(basename($audio_url)) . '</small>
                    <div class="audio-item-content">
                        <span class="drag-handle"><svg height="70px" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><rect width="24" height="24" style="fill: none;"/><circle cx="9.5" cy="6" r="1" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="10" r="1" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="14" r="1" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="18" r="1" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="22" r="1" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="26" r="1" stroke-linecap="round" stroke-linejoin="round"/><circle cx="14.5" cy="6" r="1" stroke-linecap="round" stroke-linejoin="round"/><circle cx="14.5" cy="10" r="1" stroke-linecap="round" stroke-linejoin="round"/><circle cx="14.5" cy="14" r="1" stroke-linecap="round" stroke-linejoin="round"/><circle cx="14.5" cy="18" r="1" stroke-linecap="round" stroke-linejoin="round"/><circle cx="14.5" cy="22" r="1" stroke-linecap="round" stroke-linejoin="round"/><circle cx="14.5" cy="26" r="1" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                        <audio controls>
                            <source src="' . esc_url($audio_url) . '" type="audio/mpeg">
                            Your browser does not support the audio element.
                        </audio>
                        <label class="audio-select-label">
                            <input type="radio" class="selected_audio" name="selected_audio" value="' . $audio_id . '">
                            Selected
                        </label>
                        <button type="button" class="button button-small delete-audio" data-id="' . $audio_id . '">Delete</button>
                    </div>
                    <input type="text" class="audio-display-name-input" data-id="' . $audio_id . '" placeholder="Audio display name..." value="' . esc_attr($audio_title) . '">
                </li>';
            }
            wp_reset_postdata();
        }
    }
    
    wp_send_json_success(array(
        'uploaded_count' => $uploaded,
        'errors' => $errors,
        'html' => $html
    ));
}

function save_selected_audio() {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    // Verify nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . $user_id)) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    $audio_id = intval($_POST['audio_id']);
    
    // Check if current user can edit this profile
    if (!current_user_can('edit_user', $user_id)) {
        wp_send_json_error('Permission denied');
    }
    
    // Save selected audio
    update_user_meta($user_id, 'selected_audio', $audio_id);
    
    // Update menu_order to ensure it's first
    wp_update_post(array(
        'ID' => $audio_id,
        'menu_order' => 0
    ));
    
    wp_send_json_success('Selected audio saved');
}

function save_audio_display_name() {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    // Verify nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . $user_id)) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    $audio_id = intval($_POST['audio_id']);
    $display_name = sanitize_text_field($_POST['display_name']);
    
    // Check if current user can edit this profile
    if (!current_user_can('edit_user', $user_id)) {
        wp_send_json_error('Permission denied');
    }
    
    // Save display name
    update_post_meta($audio_id, '_profile_display_name', $display_name);
    
    wp_send_json_success('Display name saved');
}

function save_audio_order() {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    // Verify nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . $user_id)) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    $order = json_decode(stripslashes($_POST['order']), true);
    
    // Check if current user can edit this profile
    if (!current_user_can('edit_user', $user_id)) {
        wp_send_json_error('Permission denied');
    }
    
    // Update menu_order for each audio file
    if (is_array($order)) {
        foreach ($order as $position => $audio_id) {
            wp_update_post(array(
                'ID' => $audio_id,
                'menu_order' => $position + 1
            ));
        }
    }
    
    wp_send_json_success('Audio order saved');
}

function delete_profile_audio() {
    // Debug log
    error_log('DELETE_PROFILE_AUDIO called with POST data: ' . print_r($_POST, true));
    
    // Get parameters
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $audio_id = isset($_POST['audio_id']) ? intval($_POST['audio_id']) : 0;
    
    // Validate
    if (!$user_id || !$audio_id) {
        wp_send_json_error('Missing required parameters. User ID: ' . $user_id . ', Audio ID: ' . $audio_id);
        return;
    }
    
    // Check permissions - user can edit this profile
    if (!current_user_can('edit_user', $user_id)) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    // Verify nonce - use the same pattern as other functions
    $nonce = $_POST['_ajax_nonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'update_profile_' . $user_id)) {
        // Try alternative if needed
        if (!wp_verify_nonce($nonce, 'profile_edit_nonce')) {
            wp_send_json_error('Security check failed. Please refresh the page.');
            return;
        }
    }
    
    // Check if audio exists
    $audio = get_post($audio_id);
    if (!$audio) {
        wp_send_json_error('Audio file not found or already deleted');
        return;
    }
    
    // Verify ownership - the audio belongs to the profile being edited
    if ($audio->post_author != $user_id) {
        wp_send_json_error('You do not own this audio file');
        return;
    }
    
    // Delete the attachment
    $deleted = wp_delete_attachment($audio_id, true);
    
    if ($deleted) {
        // Check if this was the selected audio
        $selected_audio = get_user_meta($user_id, 'selected_audio', true);
        if ($selected_audio == $audio_id) {
            delete_user_meta($user_id, 'selected_audio');
        }
        wp_send_json_success('Audio deleted');
    } else {
        wp_send_json_error('Failed to delete audio');
    }
}
add_action('wp_ajax_get_fresh_nonce', 'get_fresh_nonce_handler');

function get_fresh_nonce_handler() {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    
    if (!$user_id) {
        wp_send_json_error('User ID required');
        return;
    }
    
    // Check if current user can edit this profile
    if (!current_user_can('edit_user', $user_id)) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    // Create fresh nonce
    $nonce = wp_create_nonce('update_profile_' . $user_id);
    
    wp_send_json_success(array(
        'nonce' => $nonce
    ));
}

function fix_sfba_select2_dependency_chain() {
    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_script(
        'iris',
        admin_url( 'js/iris.min.js' ),
        array( 'jquery-ui-draggable', 'jquery-ui-slider', 'jquery-touch-punch' ),
        false,
        1
    );
    wp_enqueue_script(
        'wp-color-picker',
        admin_url( 'js/color-picker.min.js' ),
        array( 'iris' ),
        false,
        1
    );

    // Localize script for translations
    $colorpicker_l10n = array(
        'clear'         => __( 'Clear' ),
        'defaultString' => __( 'Default' ),
        'pick'          => __( 'Select Color' ),
        'current'       => __( 'Current Color' ),
    );
    wp_localize_script( 'wp-color-picker', 'wpColorPickerL10n', $colorpicker_l10n );
}
add_action('wp_enqueue_scripts', 'fix_sfba_select2_dependency_chain', 100);
add_action('admin_enqueue_scripts', 'fix_sfba_select2_dependency_chain', 100);

add_action('admin_init', function () {
    if (
        is_user_logged_in() &&
        !current_user_can('administrator') &&
        strpos($_SERVER['REQUEST_URI'], 'profile.php') !== false
    ) {
        wp_redirect(get_frontend_edit_profile_url());
        exit;
    }
});

function get_frontend_edit_profile_url($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    return home_url('/edit-profile/');
}

add_filter('get_edit_user_link', function ($link, $user_id) {
    if (get_current_user_id() === (int) $user_id) {
        return get_frontend_edit_profile_url($user_id);
    }
    return $link;
}, 10, 2);

add_action('admin_bar_menu', function ($wp_admin_bar) {

    if (!is_user_logged_in()) {
        return;
    }

    $user_id = get_current_user_id();

    $node = $wp_admin_bar->get_node('edit-profile');

    if ($node) {
        $node->href = get_frontend_edit_profile_url($user_id);
        $wp_admin_bar->add_node($node);
    }

}, 100);

add_filter('edit_profile_url', function ($url, $user_id) {
    if (get_current_user_id() === (int) $user_id) {
        return get_frontend_edit_profile_url($user_id);
    }
    return $url;
}, 10, 2);

// Check image usage before deletion
add_action('wp_ajax_check_image_usage', 'check_image_usage_handler');
add_action('wp_ajax_nopriv_check_image_usage', 'check_image_usage_auth_check');

function check_image_usage_handler() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['_ajax_nonce'], 'profile_edit_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
        wp_die();
    }
    
    $image_id = intval($_POST['image_id']);
    $user_id = intval($_POST['user_id']);
    
    // Verify user owns the image
    $image_owner = get_post_field('post_author', $image_id);
    if ($image_owner != $user_id && !current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
        wp_die();
    }
    
    $usage_data = get_image_usage_data($image_id);
    
    wp_send_json_success(['data' => $usage_data]);
    wp_die();
}

function get_image_usage_data($image_id) {
    $usage = [
        'is_used' => false,
        'as_featured_image' => 0,
        'as_video_poster' => 0,
        'in_post_content' => 0,
        'in_other_media' => 0,
        'video_titles' => [],
        'post_titles' => []
    ];
    
    // 1. Check as featured image
    $featured_posts = get_posts([
        'post_type' => ['post', 'page', 'video'], // Add your custom post types
        'meta_key' => '_thumbnail_id',
        'meta_value' => $image_id,
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);
    
    if ($featured_posts) {
        $usage['as_featured_image'] = count($featured_posts);
        $usage['is_used'] = true;
        
        // Get post titles
        foreach ($featured_posts as $post_id) {
            $usage['post_titles'][] = get_the_title($post_id);
        }
    }
    
    // 2. Check as video poster (assuming you have a custom field)
    $video_posts = get_posts([
        'post_type' => 'video', // Your video post type
        'meta_key' => '_video_poster_id', // Your custom field name
        'meta_value' => $image_id,
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);
    
    if ($video_posts) {
        $usage['as_video_poster'] = count($video_posts);
        $usage['is_used'] = true;
        
        // Get video titles
        foreach ($video_posts as $video_id) {
            $usage['video_titles'][] = get_the_title($video_id);
        }
    }
    
    // 3. Check if image is embedded in post content
    $posts_with_image = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} 
        WHERE post_content LIKE %s 
        AND post_status = 'publish' 
        AND post_type IN ('post', 'page', 'video')",
        '%wp-image-' . $image_id . '%'
    ));
    
    if ($posts_with_image) {
        $usage['in_post_content'] = count($posts_with_image);
        $usage['is_used'] = true;
        
        // Add titles if not already in list
        foreach ($posts_with_image as $post_id) {
            if (!in_array(get_the_title($post_id), $usage['post_titles'])) {
                $usage['post_titles'][] = get_the_title($post_id);
            }
        }
    }
    
    // 4. Check other media associations (customize based on your setup)
    // Example: Check if used in sliders, galleries, etc.
    $other_uses = apply_filters('check_image_other_uses', [], $image_id);
    if (!empty($other_uses)) {
        $usage['in_other_media'] = count($other_uses);
        $usage['is_used'] = true;
    }
    
    return $usage;
}

// Replace image in all uses
add_action('wp_ajax_replace_image_in_all_uses', 'replace_image_in_all_uses_handler');

function replace_image_in_all_uses_handler() {
    // Verify nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . $user_id)) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    $old_image_id = intval($_POST['old_image_id']);
    $new_image_id = intval($_POST['new_image_id']);
    $user_id = intval($_POST['user_id']);
    
    // Verify user owns both images
    $old_owner = get_post_field('post_author', $old_image_id);
    $new_owner = get_post_field('post_author', $new_image_id);
    
    if (($old_owner != $user_id || $new_owner != $user_id) && !current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
        wp_die();
    }
    
    $replaced_count = 0;
    
    // 1. Replace in featured images
    $featured_posts = get_posts([
        'post_type' => ['post', 'page', 'video'],
        'meta_key' => '_thumbnail_id',
        'meta_value' => $old_image_id,
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);
    
    foreach ($featured_posts as $post_id) {
        update_post_meta($post_id, '_thumbnail_id', $new_image_id);
        $replaced_count++;
    }
    
    // 2. Replace as video poster
    $video_posts = get_posts([
        'post_type' => 'video',
        'meta_key' => '_video_poster_id',
        'meta_value' => $old_image_id,
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);
    
    foreach ($video_posts as $video_id) {
        update_post_meta($video_id, '_video_poster_id', $new_image_id);
        $replaced_count++;
    }
    
    // 3. Replace in post content (this is complex - usually better to leave as-is)
    // You can optionally update image IDs in post_content, but it's risky
    
    // 4. Allow other plugins to handle replacement
    $replaced_count += apply_filters('replace_image_in_other_uses', 0, $old_image_id, $new_image_id);
    
    wp_send_json_success([
        'message' => 'Image replaced successfully',
        'replaced_count' => $replaced_count
    ]);
    wp_die();
}

// Check if image is used as a featured image
add_action('wp_ajax_check_featured_image_usage', 'check_featured_image_usage_handler');

function check_featured_image_usage_handler() {
    // Verify nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . $user_id)) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    $image_id = intval($_POST['image_id']);
    $user_id = intval($_POST['user_id']);
    
    // Verify user owns the image
    $image_owner = get_post_field('post_author', $image_id);
    if ($image_owner != $user_id && !current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
        wp_die();
    }
    
    $usage_data = [
        'is_featured_image' => false,
        'posts' => []
    ];
    
    // Check if image is used as a featured image in any post
    global $wpdb;
    
    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_type 
        FROM {$wpdb->posts} p 
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
        WHERE pm.meta_key = '_thumbnail_id' 
        AND pm.meta_value = %d 
        AND p.post_status IN ('publish', 'draft', 'private')",
        $image_id
    ));
    
    if ($posts) {
        $usage_data['is_featured_image'] = true;
        
        foreach ($posts as $post) {
            $edit_url = get_edit_post_link($post->ID, '');
            
            $usage_data['posts'][] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'post_type' => $post->post_type,
                'edit_url' => $edit_url
            ];
        }
    }
    
    wp_send_json_success(['data' => $usage_data]);
    wp_die();
}

// Batch check for featured images
add_action('wp_ajax_batch_check_featured_images', 'batch_check_featured_images_handler');

function batch_check_featured_images_handler() {
    $image_ids = isset($_POST['image_ids']) ? array_map('intval', (array)$_POST['image_ids']) : [];
    $user_id = intval($_POST['user_id']);
    // Verify nonce for security
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . $user_id)) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    
    if (empty($image_ids)) {
        wp_send_json_success(['data' => []]);
        wp_die();
    }
    
    // Find which images are used as featured images
    global $wpdb;
    
    $image_ids_string = implode(',', $image_ids);
    
    $featured_image_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT pm.meta_value 
        FROM {$wpdb->postmeta} pm 
        WHERE pm.meta_key = '_thumbnail_id' 
        AND pm.meta_value IN (%s)
        AND EXISTS (
            SELECT 1 FROM {$wpdb->posts} p 
            WHERE p.ID = pm.post_id 
            AND p.post_status IN ('publish', 'draft', 'private')
        )",
        $image_ids_string
    ));
    
    // Verify user owns these images (optional but recommended)
    $valid_featured_images = [];
    foreach ($featured_image_ids as $img_id) {
        $owner = get_post_field('post_author', $img_id);
        if ($owner == $user_id || current_user_can('manage_options')) {
            $valid_featured_images[] = intval($img_id);
        }
    }
    
    wp_send_json_success(['data' => $valid_featured_images]);
    wp_die();
}

// Register AJAX handlers for both logged-in and non-logged-in users if needed
add_action('wp_ajax_set_video_featured_image', 'handle_set_video_featured_image');
//add_action('wp_ajax_nopriv_set_video_featured_image', 'handle_set_video_featured_image_auth_check');

function handle_set_video_featured_image() {
    $user_id = intval($_POST['user_id'] ?? get_current_user_id());

    // Verify nonce for security
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . $user_id)) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    // Check if user is logged in (if required)
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in to set featured images']);
        wp_die();
    }
    
    // Get and sanitize data
    $video_id = intval($_POST['video_id']);
    $image_id = intval($_POST['image_id']);
    $slot = sanitize_text_field($_POST['slot']);
    
    // Validate inputs
    if (!$video_id || !$image_id) {
        wp_send_json_error(['message' => 'Invalid video or image ID']);
        wp_die();
    }
    
    // Check if user has permission to edit this video
    if (!current_user_can('edit_post', $video_id)) {
        wp_send_json_error(['message' => 'You do not have permission to edit this video']);
        wp_die();
    }
    
    // Check if the image exists
    if (!wp_attachment_is_image($image_id)) {
        wp_send_json_error(['message' => 'Invalid image']);
        wp_die();
    }
    
    // Set as featured image (post thumbnail)
    $result = set_post_thumbnail($video_id, $image_id);
    
    if ($result) {
        // Optional: Store additional metadata about which slot was used
        //update_post_meta($video_id, '_poster_slot_' . $slot, $image_id);
        
        // Get image URL for response
        $image_url = wp_get_attachment_image_url($image_id, 'medium');
        
        wp_send_json_success([
            'message' => 'Poster image set successfully',
            'image_url' => $image_url,
            'image_id' => $image_id,
            'video_id' => $video_id,
            'slot' => $slot
        ]);
    } else {
        wp_send_json_error(['message' => 'Failed to set featured image']);
    }
    
    wp_die();
}

// Handler for non-logged-in users (if you want to allow)
function handle_set_video_featured_image_auth_check() {
    // If you want to allow non-logged-in users, implement differently
    // For example, use a different nonce system or require authentication
    wp_send_json_error(['message' => 'Authentication required']);
    wp_die();
}

function get_user_videos($user_id) {
    $videos = [];
    
	// Count existing profile videos
//	$existing = new WP_Query([
//		'post_type'      => 'attachment',
//		'author'         => $user_id,
//		'post_mime_type' => 'video',
//		'post_status'    => 'inherit',
//		'tax_query' => [
//			[
//				'taxonomy' => 'profile_media_cat',
//				'field'    => 'slug',
//				'terms'    => 'gallery',
//			],
//		],
//		'posts_per_page' => -1,
//		'fields'         => 'ids',
//	]);
//
//	$video_count = $existing->found_posts;
//	$max_videos = 2;
//	$can_upload = $video_count < $max_videos;

	$query = new WP_Query([
        'post_type'      => 'attachment',
        'author'         => $user_id,
        'post_mime_type' => 'video',
        'post_status'    => 'inherit',
        'tax_query' => [
            [
                'taxonomy' => 'profile_media_cat',
                'field'    => 'slug',
                'terms'    => 'gallery',
            ],
        ],
        'posts_per_page' => 2,
//        'orderby'        => 'meta_value_num',
//        'meta_key'       => 'video_slot',
//        'order'          => 'ASC',
    ]);
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $videos[] = [
                'id' => get_the_ID(),
                'url' => wp_get_attachment_url(get_the_ID()),
                'title' => get_the_title(),
                'slot' => get_post_meta(get_the_ID(), 'video_slot', true) ?: 1
            ];
        }
        wp_reset_postdata();
    }
    
    return $videos;
}

// Helper function to get video poster URL
function get_video_poster_url($video_id) {
    // Check for custom poster
    $custom_poster = get_post_meta($video_id, '_video_poster_id', true);
    if ($custom_poster) {
        return wp_get_attachment_url($custom_poster);
    }
    
    // Check for video frame as poster
    $video_url = wp_get_attachment_url($video_id);
    $poster = get_post_meta($video_id, '_video_poster', true);
    
    if ($poster) {
        return $poster;
    }
    
    // Default placeholder
    return 'https://www.yendis.co.uk/dj/wp-content/uploads/2025/03/landscape-placeholder.svg';
}

// Check if video has custom poster
function has_custom_poster($video_id) {
    return get_post_meta($video_id, '_video_poster_id', true) || get_post_meta($video_id, '_video_poster', true);
}

// Get user videos with all metadata
function get_user_videos_with_metadata($user_id) {
    $videos = [];
    
    $query = new WP_Query([
        'post_type'      => 'attachment',
        'author'         => $user_id,
        'post_mime_type' => 'video',
        'post_status'    => 'inherit',
        'posts_per_page' => 2,
        'orderby'        => 'date',
        'order'          => 'ASC',
    ]);
    
    if ($query->have_posts()) {
        $slot = 1;
        while ($query->have_posts()) {
            $query->the_post();
            $video_id = get_the_ID();
            
            $videos[] = [
                'id' => $video_id,
                'url' => wp_get_attachment_url($video_id),
                'title' => get_the_title(),
                'date' => get_the_date('F j, Y'),
                'file_name' => basename(get_attached_file($video_id)),
                'file_size' => size_format(filesize(get_attached_file($video_id))),
                'mime_type' => get_post_mime_type($video_id),
                'display_name' => get_post_meta($video_id, '_profile_display_name', true) ?: get_the_title(),
                'poster_url' => get_video_poster_url($video_id),
                'has_custom_poster' => has_custom_poster($video_id),
                'slot' => $slot++
            ];
        }
        wp_reset_postdata();
    }
    
    return $videos;
}

// AJAX handler for video slot HTML (returns full HTML for a slot)
add_action('wp_ajax_get_video_slot_html', 'get_video_slot_html');
function get_video_slot_html() {
    $slot = intval($_POST['slot'] ?? 1);
    $video_data = json_decode(stripslashes($_POST['video_data'] ?? '{}'), true);
    
    ob_start();
    include 'video-slot-template.php'; // Template file for video slot
    $html = ob_get_clean();
    
    wp_send_json_success(['html' => $html]);
}

// AJAX handler for video data auto-save
add_action('wp_ajax_save_video_data', 'handle_save_video_data');
add_action('wp_ajax_nopriv_save_video_data', 'handle_save_video_data');

function handle_save_video_data() {
    // Verify nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . $user_id)) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    $user_id = intval($_POST['user_id'] ?? get_current_user_id());
    
    // Check if user can edit this profile
    if (!current_user_can('edit_user', $user_id)) {
        wp_send_json_error('You do not have permission to edit this profile');
        return;
    }
    
    $video_data = json_decode(stripslashes($_POST['video_data'] ?? '{}'), true);
    
    if (!is_array($video_data)) {
        wp_send_json_error('Invalid video data');
        return;
    }
    
    $saved_items = [];
    
    // Save video order
    if (isset($video_data['order']) && is_array($video_data['order'])) {
        $valid_order = array_filter($video_data['order'], 'is_numeric');
        update_user_meta($user_id, 'profile_video_order', $valid_order);
        $saved_items[] = 'order (' . count($valid_order) . ' videos)';
    }
    
    // Save video display names
    if (isset($video_data['display_names']) && is_array($video_data['display_names'])) {
        foreach ($video_data['display_names'] as $video_id => $display_name) {
            if (is_numeric($video_id) && !empty($display_name)) {
                // Update attachment title
                wp_update_post([
                    'ID' => $video_id,
                    'post_title' => sanitize_text_field($display_name)
                ]);
                
                // Also save in user meta for reference
                $existing_names = get_user_meta($user_id, 'video_display_names', true);
                if (empty($existing_names) || !is_array($existing_names)) {
                    $existing_names = [];
                }
                $existing_names[$video_id] = sanitize_text_field($display_name);
                update_user_meta($user_id, 'video_display_names', $existing_names);
            }
        }
        $saved_items[] = 'display names (' . count($video_data['display_names']) . ')';
    }
    
    wp_send_json_success([
        'message' => 'Video data saved successfully',
        'saved_items' => $saved_items,
        'timestamp' => current_time('mysql')
    ]);
}

// AJAX handler for video uploads
add_action('wp_ajax_upload_profile_videos', 'handle_ajax_video_upload');
add_action('wp_ajax_nopriv_upload_profile_videos', 'handle_ajax_video_upload');

function handle_ajax_video_upload() {
     $user_id = intval($_POST['user_id'] ?? get_current_user_id());
    // Verify nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . $user_id)) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    
    // Check if user can edit this profile
    if (!current_user_can('edit_user', $user_id)) {
        wp_send_json_error('You do not have permission to edit this profile');
        return;
    }
    
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    
    // Check existing videos count
    $existing = new WP_Query([
        'post_type'      => 'attachment',
        'author'         => $user_id,
        'post_mime_type' => 'video',
        'post_status'    => 'inherit',
        'tax_query' => [
            [
                'taxonomy' => 'profile_media_cat',
                'field'    => 'slug',
                'terms'    => 'gallery',
            ],
        ],
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);
    
    // Check uploaded files
    if (empty($_FILES['profile_videos'])) {
        wp_send_json_error('No video files uploaded');
        return;
    }
    
    $uploaded_files = $_FILES['profile_videos'];
    $uploaded_count = 0;
    $errors = [];
    $uploaded_ids = [];
    
    // Process each uploaded file
    for ($i = 0; $i < count($uploaded_files['name']); $i++) {
        // Check if we've reached the limit
        if (($existing->found_posts + $uploaded_count) >= 2) {
            $errors[] = 'Video limit reached (max 2 videos)';
            break;
        }
        
        // Prepare file array for media_handle_upload
        $file = [
            'name'     => $uploaded_files['name'][$i],
            'type'     => $uploaded_files['type'][$i],
            'tmp_name' => $uploaded_files['tmp_name'][$i],
            'error'    => $uploaded_files['error'][$i],
            'size'     => $uploaded_files['size'][$i]
        ];
        
        // Validate file type
        $allowed_types = ['video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/x-ms-wmv'];
        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = $file['name'] . ': Invalid file type. Allowed: MP4, MPEG, MOV, AVI, WMV';
            continue;
        }
        
        // Validate file size (max 100MB)
        if ($file['size'] > 100 * 1024 * 1024) {
            $errors[] = $file['name'] . ': File too large. Max 100MB';
            continue;
        }
        
        // Upload the file
        $_FILES['profile_video'] = $file;
        $attachment_id = media_handle_upload('profile_video', 0);
        
        if (is_wp_error($attachment_id)) {
            $errors[] = $file['name'] . ': ' . $attachment_id->get_error_message();
        } else {
            // Set taxonomy term
            wp_set_object_terms($attachment_id, 'gallery', 'profile_media_cat');
            
            // Set post author
            wp_update_post([
                'ID' => $attachment_id,
                'post_author' => $user_id
            ]);
            
            $uploaded_count++;
            $uploaded_ids[] = $attachment_id;
        }
    }
    
    if ($uploaded_count > 0) {
        // Generate HTML for newly uploaded videos
        $html = '';
        foreach ($uploaded_ids as $video_id) {
            $video_url = wp_get_attachment_url($video_id);
            $video_title = get_the_title($video_id);
            
            $html .= '
            <li class="sortable-video-item" data-id="' . esc_attr($video_id) . '">
                <div class="video-preview">
                    <video width="150" height="100" controls>
                        <source src="' . esc_url($video_url) . '" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                    <div class="video-info">
                        <input type="text" 
                               class="video-display-name" 
                               data-id="' . esc_attr($video_id) . '" 
                               value="' . esc_attr($video_title) . '"
                               placeholder="Video title">
                    </div>
                    <button type="button" class="delete-video" data-video-id="' . esc_attr($video_id) . '">
                        Delete Video
                    </button>
                </div>
            </li>';
        }
        
        wp_send_json_success([
            'message' => sprintf('Uploaded %d video(s)', $uploaded_count),
            'uploaded_count' => $uploaded_count,
            'html' => $html,
            'errors' => $errors,
            'remaining_slots' => max(0, 2 - ($existing->found_posts + $uploaded_count))
        ]);
    } else {
        wp_send_json_error([
            'message' => 'No videos were uploaded',
            'errors' => $errors
        ]);
    }
}		
add_action('wp_ajax_test_ajax_connection', 'test_ajax_connection_handler');
add_action('wp_ajax_check_upload_limits', 'check_upload_limits_handler');

function test_ajax_connection_handler() {
    // Simple test that returns success
    wp_send_json_success(array(
        'message' => 'AJAX connection working',
        'timestamp' => time(),
        'nonce_valid' => wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . get_current_user_id())
    ));
}

function check_upload_limits_handler() {
    check_ajax_referer('update_profile_' . get_current_user_id(), '_ajax_nonce');
    
    $max_upload = ini_get('upload_max_filesize');
    $max_post = ini_get('post_max_size');
    $max_execution = ini_get('max_execution_time');
    
    wp_send_json_success(array(
        'max_file_size' => $max_upload,
        'max_post_size' => $max_post,
        'max_execution_time' => $max_execution . ' seconds',
        'server' => $_SERVER['SERVER_SOFTWARE']
    ));
}

// Add this to your AJAX handlers
add_action('wp_ajax_upload_profile_images', 'handle_profile_image_upload');
add_action('wp_ajax_nopriv_upload_profile_images', 'handle_profile_image_upload');

function handle_profile_image_upload() {
    // Check nonce
    check_ajax_referer('update_profile_' . get_current_user_id(), '_ajax_nonce');
    
    // Check user permissions
    if (!current_user_can('edit_user', $_POST['user_id'])) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }
    
    $user_id = intval($_POST['user_id']);
    
    // Check if files were uploaded
    if (empty($_FILES['profile_images'])) {
        wp_send_json_error(array('message' => 'No files uploaded'));
    }
    
    $uploaded_count = 0;
    $errors = array();
    $attachments = array();
    
    // Process each file
    $file_count = count($_FILES['profile_images']['name']);
    for ($i = 0; $i < $file_count; $i++) {
        $name = $_FILES['profile_images']['name'][$i];
        $tmp_name = $_FILES['profile_images']['tmp_name'][$i];
        $error = $_FILES['profile_images']['error'][$i];
        
        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = "File '$name' upload error: $error";
            continue;
        }
        
        // Check if file is an image
        $file_info = getimagesize($tmp_name);
        if (!$file_info) {
            $errors[] = "File '$name' is not a valid image";
            continue;
        }
        
        // Handle WordPress upload - CORRECT WAY
        $file = array(
            'name' => $name,
            'type' => $_FILES['profile_images']['type'][$i],
            'tmp_name' => $tmp_name,
            'error' => $error,
            'size' => $_FILES['profile_images']['size'][$i]
        );
        
        $upload_overrides = array('test_form' => false);
        $upload = wp_handle_upload($file, $upload_overrides);
        
        if (isset($upload['error'])) {
            $errors[] = "File '$name' error: " . $upload['error'];
            continue;
        }
        
        // Create attachment
        $attachment = array(
            'post_mime_type' => $upload['type'],
            'post_title'     => preg_replace('/\.[^.]+$/', '', $name),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => $user_id,
        );
        
        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        
        if (is_wp_error($attach_id)) {
            $errors[] = "File '$name' attachment error: " . $attach_id->get_error_message();
            continue;
        }
        
        // Generate metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        // Attach to user with default category 'none' (uncategorized)
        update_post_meta($attach_id, '_user_id', $user_id);
        update_post_meta($attach_id, '_profile_image_category', 'none');
        
        $uploaded_count++;
        $attachments[] = array(
            'id' => $attach_id,
            'name' => $name
        );
    }
    
    // Prepare response
    if ($uploaded_count > 0) {
        // Generate HTML for each uploaded image
        $html_parts = array();
        
        foreach ($attachments as $attachment) {
            $attach_id = $attachment['id'];
            $original_name = $attachment['name'];
            
            // Get image data
            $thumbnail = wp_get_attachment_image_src($attach_id, 'thumbnail');
            $full = wp_get_attachment_image_src($attach_id, 'full');
            $image_meta = wp_get_attachment_metadata($attach_id);
            
            // Dimensions and shape
            $width = $image_meta['width'] ?? 0;
            $height = $image_meta['height'] ?? 0;
            $dimensions = $width . ' x ' . $height;
            
            // Determine shape class
            if ($width == $height) {
                $shape_class = 'shape-square';
				$shape_name = 'Square';
          } elseif ($width > $height) {
                $shape_class = 'shape-landscape';
				$shape_name = 'Landscape';
            } else {
                $shape_class = 'shape-portrait';
				$shape_name = 'Portrait';
            }
            
            // Display name based on filename (without extension)
            $display_name = preg_replace('/\.[^.]+$/', '', $original_name);
            
            // Simple category options (without counts for now)
            $category_options = '
                <option value="gallery">Gallery</option>
                <option value="profile_featured_image">Featured</option>
                <option value="hands">Hands</option>
                <option value="vehicle">Vehicle</option>
                <option value="other">Other</option>
                <option value="none" selected>Uncategorized</option>
            ';
            
            // Build HTML matching your template
            $html = sprintf('
            <div class="sortable-image-item uncategorized %s" 
                 data-id="%d" 
                 data-category="none">
                
                <div class="shape-info">
                   %s (%s)
                </div>
                
                <div class="image-preview">
                    <img src="%s" alt="%s">
                </div>
                
                <div class="image-controls">
                    <input type="text" 
                           class="image-display-name" 
                           data-id="%d" 
                           placeholder="Display name..." 
                           value="%s">
                    
                    <select class="image-category" data-id="%d">
                        %s
                    </select>
                    
                    <div class="action-buttons">
                        <button type="button" 
                                class="button button-small set-profile-pic"
                                data-id="%d"
                                title="Set as profile picture">
                            Set Profile
                        </button>
                        
                        <button type="button" 
                                class="button button-small view-image"
                                data-full="%s">
                            View
                        </button>
                        
                        <button type="button" 
                                class="button button-small delete-image"
                                data-id="%d">
                            Delete
                        </button>
                    </div>
                    
                    <small class="filename-display">
                        %s
                    </small>
                </div>
            </div>',
            $shape_class,
            $attach_id,
			esc_html($shape_name),
            esc_html($dimensions),
            esc_url($thumbnail[0]),
            esc_attr($display_name),
            $attach_id,
            esc_attr($display_name),
            $attach_id,
            $category_options,
            $attach_id,
            esc_url($full[0]),
            $attach_id,
            esc_html(basename($original_name))
            );
            
            $html_parts[] = $html;
        }
        
        wp_send_json_success(array(
            'message' => "Uploaded $uploaded_count image(s)",
            'uploaded_count' => $uploaded_count,
            'errors' => $errors,
            'html' => implode('', $html_parts),
            'default_category' => 'none',
            'prepend' => true
        ));
        
    } else {
        wp_send_json_error(array(
            'message' => 'No images were uploaded',
            'errors' => $errors
        ));
    }
}

// Add test endpoint in PHP
add_action('wp_ajax_test_nonce_verification', 'handle_test_nonce_verification');
function handle_test_nonce_verification() {
    $nonce = $_POST['_ajax_nonce'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    
    error_log("Test: Verifying nonce $nonce for user $user_id");
    
    if (wp_verify_nonce($nonce, 'update_profile_' . $user_id)) {
        wp_send_json_success('Nonce valid for update_profile_' . $user_id);
    } else if (wp_verify_nonce($nonce, 'update_profile_1')) {
        wp_send_json_success('Nonce valid for update_profile_1');
    } else {
        wp_send_json_error('Nonce invalid for both actions');
    }
}
	
// Replace your enqueue function with this corrected version
add_action('admin_enqueue_scripts', 'enqueue_profile_auto_save_script');
add_action('wp_enqueue_scripts', 'enqueue_profile_auto_save_script');

function enqueue_profile_auto_save_script($hook) {
    global $user_id;
    
    // Only load on profile pages
    $allowed_hooks = ['profile.php', 'user-edit.php'];
    $current_hook = $hook;
    
    // If front-end profile page, check differently
    if (!in_array($current_hook, $allowed_hooks) && !is_page('profile')) {
        return;
    }
    
    // Get user ID safely
    if (empty($user_id)) {
        $user_id = get_current_user_id();
    }
    
    // Enqueue the scripts
	
    wp_enqueue_script(
        'profile-auto-save',
        get_template_directory_uri() . '/js/profile-auto-save.js',
        ['jquery'],
        '1.0',
        true
    );
    
    // CORRECT WAY: Use wp_add_inline_script() instead of deprecated wp_localize_script()
//    $inline_script = sprintf(
//        'var profileAutoSave = %s;',
//        wp_json_encode([
//            'ajaxurl' => admin_url('admin-ajax.php'),
//            'user_id' => $user_id,
//            'i18n' => [
//                'saving' => __('Saving...', 'textdomain'),
//                'saved' => __('Saved', 'textdomain'),
//                'error' => __('Error', 'textdomain')
//            ]
//        ])
//    );
    
   $inline_script = "
// Just mark the system as loaded, don't re-initialize
window.addEventListener('load', function() {
    console.log('✅ Profile edit system loaded');
    // The init.js auto-initializer will handle everything
});
";
	wp_add_inline_script('profile-auto-save', $inline_script, 'before');
    
    // Add CSS for save indicators
    wp_add_inline_style('admin-bar', '
        .save-indicator {
            display: inline-block;
            margin-left: 10px;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: normal;
            line-height: 1.4;
        }
        .save-indicator.saving {
            background-color: #fff8e5;
            color: #dba617;
            border: 1px solid #ffb900;
        }
        .save-indicator.saved {
            background-color: #ecf7ed;
            color: #46b450;
            border: 1px solid #46b450;
        }
        .save-indicator.error {
            background-color: #fbeaea;
            color: #dc3232;
            border: 1px solid #dc3232;
        }
    ');
}
// add_action('wp_ajax_save_profile_field', 'handle_auto_save_profile_field');
// add_action('wp_ajax_nopriv_save_profile_field', 'handle_profile_auto_save');

// Remove nopriv unless you want non-logged-in users to save (probably not)
// add_action('wp_ajax_nopriv_save_profile_field', 'handle_profile_auto_save');
add_action('wp_ajax_save_profile_field', 'handle_profile_auto_save');

function handle_profile_auto_save() {
    // Get POST data
    $nonce = $_POST['_ajax_nonce'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    $field = sanitize_text_field($_POST['field'] ?? '');
    $value = $_POST['value'] ?? '';
    
    // ========== DEBUG LOGGING ==========
    error_log("=== AUTO-SAVE DEBUG ===");
    error_log("User ID: " . $user_id);
    error_log("Field: " . $field);
    error_log("Value: " . substr($value, 0, 100));
    error_log("Nonce received: " . substr($nonce, 0, 8) . '...');
    error_log("Expected action: update_profile_" . $user_id);
    error_log("Current user ID: " . get_current_user_id());
    
    // Try to verify with different actions
    $possible_actions = [
        'update_profile_' . $user_id,
        'update_profile_nonce',
        'profile_edit_ajax',
        'wp_rest',
        'ajax_nonce'
    ];
    
    foreach ($possible_actions as $action) {
        $result = wp_verify_nonce($nonce, $action);
        error_log("Checking action '{$action}': " . ($result ? 'VALID' : 'INVALID'));
        if ($result) {
            error_log("✅ Nonce matches action: {$action}");
        }
    }
    
    // ========== SECURITY CHECKS ==========
    
    // 1. Check user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in');
    }
    
    // 2. Verify nonce (front-end only, matches your enqueue function)
    if (!wp_verify_nonce($nonce, 'update_profile_' . $user_id)) {
        wp_send_json_error('Security check failed. Please refresh the page.');
    }
    
    // 3. User can only edit their own profile (front-end assumption)
    if (get_current_user_id() !== $user_id) {
        wp_send_json_error('You can only edit your own profile');
    }
    
    // ========== FIELD VALIDATION ==========
    
    // Define which fields are user table fields vs user meta
    $user_table_fields = [
        'first_name', 'last_name', 'nickname', 'user_email', 
        'user_url', 'description', 'display_name'
    ];
    
    // Track original value for comparison
    $original_value = '';
    if (in_array($field, $user_table_fields)) {
        $user = get_userdata($user_id);
        if ($user) {
            $original_value = $user->$field ?? '';
        }
    } else {
        $original_value = get_user_meta($user_id, $field, true);
    }
    
    // Skip if no change
    if ($value === $original_value) {
        wp_send_json_success('No change needed');
    }
    
    // ========== FIELD-SPECIFIC SANITIZATION ==========
    
    $sanitized_value = '';
    $validation_error = '';
    
    switch(true) {
        case strpos($field, 'email') !== false:
            $sanitized_value = sanitize_email($value);
            if (!is_email($sanitized_value)) {
                wp_send_json_error('Please enter a valid email address');
            }
            break;
            
        case strpos($field, 'url') !== false || 
             in_array($field, ['rep_logo', 'imdb', 'facebook', 'twitter', 'instagram', 'tiktok', 'linkedin', 'youtube']):
            $sanitized_value = esc_url_raw($value);
            // Allow empty URLs
            if (!empty($value) && !filter_var($sanitized_value, FILTER_VALIDATE_URL)) {
                wp_send_json_error('Please enter a valid URL');
            }
            break;
            
        case in_array($field, ['dbs_basic', 'dbs_standard', 'dbs_enhanced', 'passport_expiration']):
            $sanitized_value = sanitize_text_field($value);
            // Validate date if not empty
            if (!empty($sanitized_value) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sanitized_value)) {
                wp_send_json_error('Date must be in YYYY-MM-DD format');
            }
            // Check date is not in the past for expirations
            if (!empty($sanitized_value) && strpos($field, 'expiration') !== false) {
                $expiry_date = DateTime::createFromFormat('Y-m-d', $sanitized_value);
                $today = new DateTime();
                if ($expiry_date < $today) {
                    wp_send_json_error('Expiration date cannot be in the past');
                }
            }
            break;
            
        case in_array($field, ['height_ft', 'height_in', 'height_cm']):
            $sanitized_value = intval($value);
            // Validate ranges
            if ($field === 'height_ft' && ($sanitized_value < 3 || $sanitized_value > 8)) {
                wp_send_json_error('Height (feet) must be between 3 and 8');
            }
            if ($field === 'height_in' && ($sanitized_value < 0 || $sanitized_value > 11)) {
                wp_send_json_error('Height (inches) must be between 0 and 11');
            }
            if ($field === 'height_cm' && ($sanitized_value < 100 || $sanitized_value > 250)) {
                wp_send_json_error('Height (cm) must be between 100 and 250');
            }
            break;
            
		 case $field === 'gender':
			$allowed_genders = [
				'Male',
				'Female',
				'Non-binary',
				'Transgender',
				'Intersex',
				'Two-Spirit',
				'Genderqueer',
				'Agender',
				'Other',
				'Prefer not to say'
			];

			// Allow empty (user cleared selection)
			if ($value === '') {
				$sanitized_value = '';
				break;
			}

			if (!in_array($value, $allowed_genders, true)) {
				wp_send_json_error('Please select a valid gender option');
			}

			$sanitized_value = sanitize_text_field($value);
			break;
			
		case $field === 'uk_drivers_license':
            $sanitized_value = ($value === '1' || $value === true || $value === 'true') ? '1' : '0';
            break;
            
        case $field === 'nationality':
            $allowed_nationalities = get_allowed_nationalities(); // Make sure this function exists
            if (!empty($value) && !array_key_exists($value, $allowed_nationalities)) {
                wp_send_json_error('Please select a valid nationality');
            }
            $sanitized_value = sanitize_text_field($value);
            break;
            
        default:
            $sanitized_value = sanitize_text_field($value);
            // Length limits for text fields
            if (strlen($sanitized_value) > 255) {
                wp_send_json_error('Text is too long (max 255 characters)');
            }
    }
    
    // ========== SAVE DATA ==========
    
    if (in_array($field, $user_table_fields)) {
        // Update user table
        $userdata = ['ID' => $user_id];
        
        if ($field === 'description') {
            $userdata['description'] = wp_kses_post($sanitized_value);
        } else {
            $userdata[$field] = $sanitized_value;
        }
        
        $result = wp_update_user($userdata);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        // Special handling for display name
        if (($field === 'first_name' || $field === 'last_name') && empty(get_user_meta($user_id, 'display_name', true))) {
            // Auto-update display name if it hasn't been customized
            $first_name = get_user_meta($user_id, 'first_name', true);
            $last_name = get_user_meta($user_id, 'last_name', true);
            $display_name = trim("$first_name $last_name");
            if (!empty($display_name)) {
                wp_update_user([
                    'ID' => $user_id,
                    'display_name' => $display_name
                ]);
            }
        }
    } else {
        // Update user meta
        $result = update_user_meta($user_id, $field, $sanitized_value);
        
        if ($result === false) {
            wp_send_json_error('Failed to save. Please try again.');
        }
    }
    
    // ========== SUCCESS RESPONSE ==========
    
    $response_data = [
        'message' => 'Saved successfully',
        'field' => $field,
        'value' => $sanitized_value,
        'timestamp' => current_time('mysql')
    ];
    
    wp_send_json_success($response_data);
}

// Helper function for nationalities (make sure this exists)
function get_allowed_nationalities() {
    // Return your actual nationalities array
    return [
        // Core existing
        'GB' => 'British',
        'IE' => 'Irish',
        'US' => 'American',
        'CA' => 'Canadian',
        'AU' => 'Australian',
        'NZ' => 'New Zealander',
        'ZA' => 'South African',
        
        // Major London communities (top 10+)
        'IN' => 'Indian',
        'PL' => 'Polish',
        'PK' => 'Pakistani',
        'NG' => 'Nigerian',
        'BD' => 'Bangladeshi',
        'RO' => 'Romanian',
        'IT' => 'Italian',
        'LT' => 'Lithuanian',
        'CN' => 'Chinese',
        'FR' => 'French',
        'PT' => 'Portuguese',
        'ES' => 'Spanish',
        'TR' => 'Turkish',
        'LK' => 'Sri Lankan',
        'GH' => 'Ghanaian',
        'JM' => 'Jamaican',
        'PH' => 'Filipino',
        'BR' => 'Brazilian',
        'DE' => 'German',
        'GR' => 'Greek',
        'NL' => 'Dutch',
        'RU' => 'Russian',
        'UA' => 'Ukrainian',
        'SO' => 'Somali',
        'AF' => 'Afghan',
        'CO' => 'Colombian',
        
        // Other significant European
        'BG' => 'Bulgarian',
        'HU' => 'Hungarian',
        'CZ' => 'Czech',
        'SK' => 'Slovak',
        'HR' => 'Croatian',
        'RS' => 'Serbian',
        'AL' => 'Albanian',
    ];
}

// Optional: Add JavaScript to handle tab switching if needed
function edit_profile_shortcode_scripts() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Handle edit profile links with data attributes
        document.querySelectorAll('.edit-profile-link').forEach(function(link) {
            link.addEventListener('click', function(e) {
                const tab = this.getAttribute('data-tab');
                const subtab = this.getAttribute('data-subtab');
                
                // If we're already on the edit profile page
                if (window.location.pathname.includes('edit-profile')) {
                    e.preventDefault();
                    
                    // Switch to the specified tab
                    const tabButton = document.querySelector('.tab-button[data-tab="' + tab + '"]');
                    if (tabButton) {
                        tabButton.click();
                        
                        // If subtab is specified and we're on media tab
                        if (subtab && tab === 'media') {
                            setTimeout(function() {
                                const subtabButton = document.querySelector('.subtab-button[data-subtab="' + subtab + '"]');
                                if (subtabButton) {
                                    subtabButton.click();
                                }
                            }, 100);
                        }
                    }
                }
            });
        });
    });
    </script>
    <?php
}
add_action('wp_footer', 'edit_profile_shortcode_scripts');

// Handle video poster deletion
add_action('wp_ajax_delete_video_poster', 'handle_delete_video_poster');
function handle_delete_video_poster() {
    // Verify nonce
    if (!check_ajax_referer('update_profile_' . get_current_user_id(), '_ajax_nonce', false)) {
        wp_send_json_error('Security check failed');
    }
    
    // Verify user permissions
    if (!current_user_can('edit_posts')) {
        wp_die('Insufficient permissions');
    }
    
    $video_id = intval($_POST['video_id']);
    
    // Verify the video belongs to current user
    $video = get_post($video_id);
    if (!$video || $video->post_author != get_current_user_id()) {
        wp_die('Invalid video');
    }
    
    // Only unset/remove the featured image, don't delete the attachment
    $thumbnail_id = get_post_thumbnail_id($video_id);
    if ($thumbnail_id) {
        // This removes the featured image association without deleting the attachment
        delete_post_thumbnail($video_id);
        
        // Optional: You could also delete the post meta directly
        // delete_post_meta($video_id, '_thumbnail_id');
    }
    
    wp_send_json_success('Poster removed successfully');
}

// Handle video poster upload
function handle_upload_video_poster() {
    // Verify nonce using check_ajax_referer (checks both _ajax_nonce and nonce)
    if (!check_ajax_referer('update_profile_' . get_current_user_id(), '_ajax_nonce', false)) {
        wp_send_json_error('Security check failed');
    }
    
    // Verify user permissions
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Insufficient permissions');
    }
        
    $video_id = isset($_POST['video_id']) ? intval($_POST['video_id']) : 0;
    
    // Verify the video belongs to current user
    $video = get_post($video_id);
    if (!$video || $video->post_author != get_current_user_id()) {
        wp_send_json_error('Invalid video');
    }
    
    // Check if file was uploaded
    if (!isset($_FILES['poster_image']) || $_FILES['poster_image']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error('No file uploaded or upload error');
    }
    
    // Check file type
    $file_type = wp_check_filetype(basename($_FILES['poster_image']['name']));
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    
    if (!in_array($file_type['type'], $allowed_types)) {
        wp_send_json_error('Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.');
    }
    
    // Check file size (optional - limit to 5MB)
    $max_size = 5 * 1024 * 1024; // 5MB
    if ($_FILES['poster_image']['size'] > $max_size) {
        wp_send_json_error('File is too large. Maximum size is 5MB.');
    }
    
    // Prepare upload
    $upload_overrides = array(
        'test_form' => false,
        'mimes' => array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'gif' => 'image/gif',
            'png' => 'image/png',
            'webp' => 'image/webp'
        )
    );
    
    $upload = wp_handle_upload($_FILES['poster_image'], $upload_overrides);

    if (isset($upload['error'])) {
        wp_send_json_error($upload['error']);
    }
    
    // Create attachment
    $attachment = array(
        'post_mime_type' => $upload['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_author'    => get_current_user_id(),
        'guid'           => $upload['url'],
        'post_parent'    => $video_id  // Link to video post as parent
    );
    
    $attach_id = wp_insert_attachment($attachment, $upload['file'], $video_id);
    
    if (is_wp_error($attach_id)) {
        wp_send_json_error($attach_id->get_error_message());
    }
    
    // Generate attachment metadata
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
    wp_update_attachment_metadata($attach_id, $attach_data);
    
    // Set as featured image of the video post
    $result = set_post_thumbnail($video_id, $attach_id);
    
    if (!$result) {
        wp_send_json_error('Failed to set featured image');
    }
    
    // Optionally add taxonomy term
//    if (taxonomy_exists('profile_media_cat')) {
//        wp_set_object_terms($attach_id, 'gallery', 'profile_media_cat');
//    }
    
    // Get the featured image URL
    // $poster_url = wp_get_attachment_image_url($attach_id, 'custom-landscape');
    
    // Fallback to full size if custom size doesn't exist
    if (!$poster_url) {
        $poster_url = wp_get_attachment_image_url($attach_id, 'full');
    }
    
    wp_send_json_success(array(
        'message' => 'Poster uploaded and set as featured image successfully',
        'poster_url' => $poster_url,
        'featured_image_id' => $attach_id,
        'video_id' => $video_id
    ));
}
add_action('wp_ajax_upload_video_poster', 'handle_upload_video_poster');
add_action('wp_ajax_delete_video_poster', 'handle_delete_video_poster');

// Add admin-ajax.php URL to front-end for non-logged in users if needed
//add_action('wp_ajax_nopriv_delete_video_poster', 'handle_delete_video_poster');
//add_action('wp_ajax_nopriv_upload_video_poster', 'handle_upload_video_poster');

// AJAX handler for saving display names
add_action('wp_ajax_update_display_names', 'handle_update_display_names');
function handle_update_display_names() {
    error_log('====== UPDATE DISPLAY NAMES AJAX CALLED ======');
    debug_attachment_exists_logic();
    $user_id = get_current_user_id();
    error_log("Current user ID: $user_id");
    
    // Check if user is logged in
    if (!is_user_logged_in()) {
        error_log('? User not logged in');
        wp_send_json_error('Not logged in');
    }
    
    // Check if display_names is set
    if (!isset($_POST['display_names']) || empty($_POST['display_names'])) {
        error_log('?? No display names in POST');
        wp_send_json_success([
            'count' => 0, 
            'breakdown' => ['images' => 0, 'audio' => 0, 'video' => 0],
            'message' => 'No display names to save'
        ]);
    }
    
    error_log('Display names received (first 500 chars): ' . substr($_POST['display_names'], 0, 500));
    
    // Decode JSON
    $display_names = json_decode(stripslashes($_POST['display_names']), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('? JSON decode error: ' . json_last_error_msg());
        wp_send_json_error('Invalid JSON data: ' . json_last_error_msg());
    }
    
    if (!is_array($display_names)) {
        error_log('? Display names is not an array');
        wp_send_json_error('Display names data is not valid');
    }
    
    error_log('Processing ' . count($display_names) . ' display names');
    
    $breakdown = ['images' => 0, 'audio' => 0, 'video' => 0];
    $success_count = 0;
    $fail_count = 0;
    
    foreach ($display_names as $attachment_id => $display_name) {
        $attachment_id = intval($attachment_id);
        $display_name = sanitize_text_field($display_name);
        
        // Check if attachment exists
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            error_log("   ? Attachment $attachment_id doesn't exist");
            $fail_count++;
            continue;
        }
        
        // Check if user owns this attachment
        if ($attachment->post_author != $user_id) {
            error_log("   ? User $user_id doesn't own attachment $attachment_id (author: {$attachment->post_author})");
            $fail_count++;
            continue;
        }
        
        // Check current value
        $current_value = get_post_meta($attachment_id, '_profile_display_name', true);
        if ($current_value === $display_name) {
            error_log("   [NO CHANGE] Attachment $attachment_id already has value: \"$display_name\"");
            $success_count++; // Count as success
        } else {
            // Update the meta
            $updated = update_post_meta($attachment_id, '_profile_display_name', $display_name);
            
			// Check what actually got stored
			$new_value = get_post_meta($attachment_id, '_profile_display_name', true);

			if ($new_value === $display_name) {
				// Successfully updated
				error_log("   [UPDATED] ID $attachment_id: \"$current_value\" ? \"$new_value\"");
				$success_count++;
			} else {
				// Failed to update
				error_log("   [FAILED] ID $attachment_id: Could not update. Wanted: \"$display_name\", Got: \"$new_value\"");
				$fail_count++;
			}
			
			if ($updated === false) {
                error_log("   [FAILED] Failed to update $attachment_id => \"$display_name\"");

                $fail_count++;
            } else {
                error_log("   [SUCCESS] Updated $attachment_id => \"$display_name\"");
                $success_count++;
            }
        }
        
        // Track breakdown by mime type
        $mime_type = get_post_mime_type($attachment_id);
        if (strpos($mime_type, 'image/') === 0) {
            $breakdown['images']++;
        } elseif (strpos($mime_type, 'audio/') === 0) {
            $breakdown['audio']++;
        } elseif (strpos($mime_type, 'video/') === 0) {
            $breakdown['video']++;
        }
    }
    
    error_log("[RESULTS] $success_count succeeded, $fail_count failed");
    
    wp_send_json_success([
        'count' => $success_count,
        'failed' => $fail_count,
        'breakdown' => $breakdown,
        'message' => "Saved $success_count display names ($fail_count failed)"
    ]);
}

// ============================================
// CATEGORY UPDATE HANDLER
// Add this RIGHT AFTER handle_update_display_names
// ============================================

/**
 * Handle media category updates
 */
// In functions.php - OPTIMIZED handler
function handle_update_media_category() {
    // Get essentials
    $user_id = get_current_user_id();
    $nonce = $_POST['nonce'] ?? '';
    
    // Quick nonce check
    if (!wp_verify_nonce($nonce, 'update_profile_' . $user_id)) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    // Get data
    $media_id = intval($_POST['media_id'] ?? 0);
    $category = sanitize_text_field($_POST['category'] ?? '');
    
    if (!$media_id || !$category) {
        wp_send_json_error('Missing data');
        return;
    }
    
    // Verify ownership (quick check)
    $post = get_post($media_id);
    if (!$post || $post->post_author != $user_id) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    // Update category
    if ($category === 'none') {
        wp_delete_object_term_relationships($media_id, 'category');
    } else {
        wp_set_object_terms($media_id, [$category], 'category', false);
    }
    
    // OPTIMIZED: Return minimal response for 260+ items
    wp_send_json_success([
        'media_id' => $media_id,
        'category' => $category,
        'message' => 'Updated',
        'timestamp' => time()
    ]);
}
// Register the handler
//add_action('wp_ajax_update_media_category', 'handle_update_media_category');

// Log that it's registered
//error_log('? [CATEGORY] handle_update_media_category REGISTERED in functions.php');

// Hook it ONCE
function add_order_ajax_handlers() {
	function handle_update_image_order() {
		error_log('====== update_image_order CALLED ======');

		// Get parameters
		$nonce = $_POST['_ajax_nonce'] ?? $_POST['nonce'] ?? '';
		$user_id = intval($_POST['user_id'] ?? 0);
		$order = $_POST['order'] ?? '';
		$categories = $_POST['categories'] ?? '';

		// Log for debugging
		error_log("Received nonce: $nonce");
		error_log("User ID from POST: $user_id");
		error_log("Current user ID: " . get_current_user_id());

		// Use current logged-in user ID for security
		$current_user_id = get_current_user_id();
		if ($user_id !== $current_user_id) {
			error_log("Warning: POST user_id ($user_id) doesn't match current user ($current_user_id)");
			$user_id = $current_user_id;
		}

		// Verify nonce - use the current user's ID
		if (!wp_verify_nonce($nonce, 'update_profile_' . $current_user_id)) {
			error_log('Nonce verification FAILED for action: update_profile_' . $current_user_id);

			// Fallback for user_id = 1 (admin) if needed
			if ($current_user_id === 1 && !wp_verify_nonce($nonce, 'update_profile_1')) {
				error_log('Also failed for update_profile_1');
				wp_send_json_error('Security check failed');
			}
		}

		error_log('? Nonce verified successfully');

		// Check permissions
		if (!current_user_can('edit_user', $current_user_id)) {
			wp_send_json_error('Permission denied');
		}

		try {
			$order_data = json_decode(stripslashes($order), true);
			$categories_data = json_decode(stripslashes($categories), true);

			if (!is_array($order_data) || !is_array($categories_data)) {
				error_log('Invalid data format');
				wp_send_json_error('Invalid data format');
			}

			// Save to user meta
			update_user_meta($current_user_id, 'profile_images_order', $order_data);
			update_user_meta($current_user_id, 'profile_images_categories', $categories_data);

			// Also update individual attachment post metas
			foreach ($categories_data as $attachment_id => $category) {
				update_post_meta(intval($attachment_id), 'profile_image_category', sanitize_text_field($category));
			}

			// Update menu_order for each image
			$global_order = 1;
			foreach ($order_data as $category => $images) {
				foreach ($images as $image) {
					if (isset($image['id'])) {
						wp_update_post(array(
							'ID' => intval($image['id']),
							'menu_order' => $global_order++
						));
					}
				}
			}

			error_log('? Order saved successfully for user ' . $current_user_id);
			wp_send_json_success(array(
				'message' => 'Order saved',
				'counts' => array(
					'categories' => count($order_data),
					'total_images' => $global_order - 1
				)
			));

		} catch (Exception $e) {
			error_log('? Error saving order: ' . $e->getMessage());
			wp_send_json_error('Error saving: ' . $e->getMessage());
		}
	}
	add_action('wp_ajax_update_image_order', 'handle_update_image_order');
	
	function handle_update_image_menu_order() {
		$user_id = get_current_user_id();
		// Verify nonce
		//if (!wp_verify_nonce($_POST['nonce'], 'profile_edit_nonce')) {
		if (!wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . $user_id)) {
			wp_send_json_error('Security check failed');
		}

		$positions = json_decode(stripslashes($_POST['positions']), true);

		if ($positions) {
			foreach ($positions as $attachment_id => $menu_order) {
				wp_update_post([
					'ID' => intval($attachment_id),
					'menu_order' => intval($menu_order)
				]);
			}
		}

		wp_send_json_success('Menu order updated');
	}
	add_action('wp_ajax_update_image_menu_order', 'handle_update_image_menu_order');
}
add_action('init', 'add_order_ajax_handlers');

function handle_update_image_category() {
    error_log('====== update_image_category CALLED ======');
    
    // Get parameters
    $nonce = $_POST['_ajax_nonce'] ?? $_POST['nonce'] ?? '';
    $image_id = intval($_POST['image_id'] ?? 0);
    $category = sanitize_text_field($_POST['category'] ?? '');
    $user_id = get_current_user_id();
    
    // Log for debugging
    error_log("Image ID: $image_id");
    error_log("Category: $category");
    error_log("User ID: $user_id");
    
    // Verify nonce
    if (!wp_verify_nonce($nonce, 'update_profile_' . $user_id)) {
        error_log('? Nonce verification failed');
        wp_send_json_error('Security check failed');
    }
    
    // Check permissions - user can edit this attachment
    if (!current_user_can('edit_post', $image_id)) {
        error_log('? Permission denied for image ' . $image_id);
        wp_send_json_error('Permission denied');
    }
    
    if (!$image_id || !$category) {
        error_log('? Missing image ID or category');
        wp_send_json_error('Missing data');
    }
    
    try {
        // Update the attachment's category
        $result = update_post_meta($image_id, 'profile_image_category', $category);
        
        // Also update the user's category data
        $categories_data = get_user_meta($user_id, 'profile_images_categories', true);
        if (!is_array($categories_data)) {
            $categories_data = array();
        }
        
        $categories_data[$image_id] = $category;
        update_user_meta($user_id, 'profile_images_categories', $categories_data);
        
        error_log('? Category updated for image ' . $image_id . ' to ' . $category);
        
        wp_send_json_success(array(
            'message' => 'Category updated',
            'image_id' => $image_id,
            'category' => $category,
            'updated' => $result
        ));
        
    } catch (Exception $e) {
        error_log('? Error updating category: ' . $e->getMessage());
        wp_send_json_error('Error updating category: ' . $e->getMessage());
    }
}
//add_action('wp_ajax_update_image_category', 'handle_update_image_category');

//function enqueue_profile_edit_scripts() {
//    global $post;
//    
//    // Only load on front-end profile page
//    if (!is_page('profile') && !($post && $post->post_name == 'edit-profile')) {
//        return;
//    }
//    
//    // Get current user ID
//    $user_id = get_current_user_id();
//    if (!$user_id) {
//        return; // Not logged in
//    }
//    
//    // FORCE new version every load
//    $version = time(); // ← TIMESTAMP, not static version
//
//	// Enqueue jQuery UI Sortable for drag & drop
//    wp_enqueue_script('jquery-ui-sortable');
//    wp_enqueue_media();
//    // Enqueue your custom script
//    wp_enqueue_script(
//        'profile-edit',
//        get_stylesheet_directory_uri() . '/js/profile-edit.js',
//        array('jquery', 'jquery-ui-sortable'),
//        $version,
//        true
//    );
//        
//    // Generate fresh nonce
//    $nonce_action = 'update_profile_' . $user_id;
//    $nonce = wp_create_nonce($nonce_action);
//    
//    $inline_script = sprintf(
//        'var profileEdit = %s;',
//        wp_json_encode([
//            'ajaxurl' => admin_url('admin-ajax.php'),
//            'nonce' => wp_create_nonce('update_profile_' . $user_id),
//            'userId' => $user_id,
//            'uploadingText' => 'Uploading...',
//            'successText' => 'Success!',
//            'errorText' => 'Error!'
//        ])
//    );
//	
//	wp_add_inline_script('profile-edit', $inline_script, 'before');
//
//    // Add cache control via output buffering
//    add_action('wp_head', function() {
//        echo '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">';
//        echo '<meta http-equiv="Pragma" content="no-cache">';
//        echo '<meta http-equiv="Expires" content="0">';
//    }, 1);
//	
//	// Optional: Add minimal CSS for sortable
//    wp_add_inline_style('wp-core-ui', '
//        .sortable-gallery { display: grid; gap: 15px; }
//        .sortable-image-item { cursor: move; }
//        .sortable-image-item.ui-sortable-helper { transform: rotate(2deg); }
//    ');
//
//}
// Only hook to front-end
//add_action('wp_enqueue_scripts', 'enqueue_profile_edit_scripts');

function enqueue_profile_edit_scripts() {
    global $post;
    
    // [Your existing checks remain the same...]
    
	$is_profile_page = ( is_page('profile') || is_page('edit-profile') || ( is_author() && get_the_author_meta('ID') == get_current_user_id() ) );
	if ( ! $is_profile_page ) {
		return;
	}
	
    // FORCE new version every load
    $version = time(); // ← TIMESTAMP, not static version

	$user_id = get_current_user_id();
    if (!$user_id) {
        return;
    }
    
    // Version control
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $version = time();
    } else {
        $version = filemtime(get_stylesheet_directory() . '/js/profile-edit/profile-edit-core.js');
    }
    
    // Enqueue dependencies
    wp_enqueue_script('jquery');
    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_script('jquery-ui-draggable');
    wp_enqueue_script('jquery-ui-droppable');
    wp_enqueue_media();
    
    // Generate fresh nonce
    $nonce_action = 'update_profile_' . $user_id;
    $nonce = wp_create_nonce($nonce_action);
    
    // ========== CRITICAL FIX: REGISTER MODULES FIRST ==========
    
    // 1. Core & Config (must be first)
    wp_register_script('profile-edit-config', 
        get_stylesheet_directory_uri() . '/js/profile-edit/config.js',
        array('jquery'),
        $version,
        true
    );
    
    wp_register_script('profile-edit-core', 
        get_stylesheet_directory_uri() . '/js/profile-edit/profile-edit-core.js',
        array('jquery', 'profile-edit-config'),
        $version,
        true
    );
    
    // 2. Utilities (shared helper functions)
    wp_register_script('profile-edit-utils', 
        get_stylesheet_directory_uri() . '/js/profile-edit/modules/utilities.js',
        array('jquery', 'profile-edit-core'),
        $version,
        true
    );
    
    // 3. UI Components
    wp_register_script('profile-edit-modals', 
        get_stylesheet_directory_uri() . '/js/profile-edit/modules/modal-dialogs.js',
        array('jquery', 'profile-edit-utils'),
        $version,
        true
    );
    
    wp_register_script('profile-edit-styles', 
        get_stylesheet_directory_uri() . '/js/profile-edit/styles/profile-styles.js',
        array('jquery'),
        $version,
        true
    );
    
    // 4. Feature Modules (independent)
    wp_register_script('profile-edit-dragdrop-enhanced', 
        get_stylesheet_directory_uri() . '/js/profile-edit/modules/drag-drop-enhanced.js',
        array('jquery', 'jquery-ui-sortable', 'profile-edit-utils'),
        $version,
        true
    );
    
    wp_register_script('profile-edit-autosave', 
        get_stylesheet_directory_uri() . '/js/profile-edit/modules/auto-save.js',
        array('jquery', 'profile-edit-utils'),
        $version,
        true
    );
    
    wp_register_script('profile-edit-categories', 
        get_stylesheet_directory_uri() . '/js/profile-edit/modules/category-system.js',
        array('jquery', 'profile-edit-utils'),
        $version,
        true
    );
    
    wp_register_script('profile-edit-images', 
        get_stylesheet_directory_uri() . '/js/profile-edit/modules/image-manager.js',
        array('jquery', 'profile-edit-utils', 'profile-edit-dragdrop-enhanced'),
        $version,
        true
    );
    
    wp_register_script('profile-edit-audio', 
        get_stylesheet_directory_uri() . '/js/profile-edit/modules/audio-manager.js',
        array('jquery', 'profile-edit-utils'),
        $version,
        true
    );
    
    wp_register_script('profile-edit-video', 
        get_stylesheet_directory_uri() . '/js/profile-edit/modules/video-manager.js',
        array('jquery', 'profile-edit-utils'),
        $version,
        true
    );
    
	wp_register_script('profile-edit-unified-bridge', 
		get_stylesheet_directory_uri() . '/js/profile-edit/modules/unified-bridge.js',
		array('jquery', 'profile-edit-utils', 'profile-edit-categories'),
		$version,
		true
	);

    // 5. Debug tools (optional - load only in debug mode)
    if (defined('WP_DEBUG') && WP_DEBUG) {
        wp_register_script('profile-edit-debug', 
            get_stylesheet_directory_uri() . '/js/profile-edit/modules/debug.js',
            array('jquery', 'profile-edit-utils'),
            $version,
            true
        );
    }
    
    // 6. Build dependencies array for init script AFTER registering modules
    $init_deps = array(
        'jquery',
        'profile-edit-core',
        'profile-edit-utils',
        'profile-edit-modals',
        'profile-edit-styles',
        'profile-edit-dragdrop-enhanced',
        'profile-edit-autosave',
		'profile-edit-categories',
        'profile-edit-images',
        'profile-edit-audio',
		'profile-edit-unified-bridge',
        'profile-edit-video'
    );
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $init_deps[] = 'profile-edit-debug';
    }
    
    // 7. Main initialization (loads LAST - register after all modules)
    wp_register_script('profile-edit-init', 
        get_stylesheet_directory_uri() . '/js/profile-edit/init.js',
        $init_deps, // All dependencies are now registered
        $version,
        true
    );
    
    // ========== ENQUEUE & LOCALIZE ==========
    
    // Enqueue all scripts (dependencies will auto-load)
    wp_enqueue_script('profile-edit-init');
    
    // Generate fresh nonce - SIMPLIFIED
    $nonce_action = 'update_profile_' . $user_id;
    $nonce = wp_create_nonce($nonce_action);
    
    // Pass data to core script - USE SAME NONCE EVERYWHERE
    $profile_data = [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => $nonce, // Use this nonce for ALL category updates
        'userId' => $user_id,
    ];

	wp_localize_script('profile-edit-core', 'profileEdit', $profile_data);
    
    
    wp_enqueue_style('profile-edit-styles', 
        get_stylesheet_directory_uri() . '/css/profile-edit.css',
        array(),
        $version
    );
    
} // Hook to front-end
add_action('wp_enqueue_scripts', 'enqueue_profile_edit_scripts');

// ========== OPTIONAL: LAZY LOAD MODULES ==========

/**
 * Conditionally load modules only when needed
 */
function enqueue_profile_edit_module($module) {
    static $modules_loaded = array();
    
    if (in_array($module, $modules_loaded)) {
        return;
    }
    
    $modules = array(
        'images' => 'profile-edit-images',
        'audio' => 'profile-edit-audio',
        'video' => 'profile-edit-video',
        'autosave' => 'profile-edit-autosave',
        'dragdrop' => 'profile-edit-dragdrop-enhanced',
    );
    
    if (isset($modules[$module])) {
        wp_enqueue_script($modules[$module]);
        $modules_loaded[] = $module;
    }
}

// ========== AJAX HANDLERS REGISTRATION ==========

/**
 * Register all AJAX handlers needed by the profile edit system
 */
function register_profile_edit_ajax_handlers() {
    // Nonce management
    add_action('wp_ajax_get_fresh_nonce', 'profile_edit_get_fresh_nonce_handler');
    add_action('wp_ajax_test_nonce_verification', 'profile_edit_test_nonce_verification');
    
    // Image management
    add_action('wp_ajax_upload_profile_images', 'handle_profile_image_upload');
    add_action('wp_ajax_delete_profile_image', 'handle_profile_image_delete');
    add_action('wp_ajax_update_image_order', 'handle_image_order_update');
    add_action('wp_ajax_check_featured_image_usage', 'check_featured_image_usage');
    
    // Audio management
    add_action('wp_ajax_upload_profile_audio', 'handle_profile_audio_upload');
    add_action('wp_ajax_delete_profile_audio', 'handle_profile_audio_delete');
    add_action('wp_ajax_save_audio_display_name', 'save_audio_display_name');
    add_action('wp_ajax_save_selected_audio', 'save_selected_audio');
    
    // Video management
    add_action('wp_ajax_upload_profile_videos', 'handle_profile_video_upload');
    add_action('wp_ajax_delete_profile_video', 'handle_profile_video_delete');
    add_action('wp_ajax_save_video_display_name', 'save_video_display_name');
    
    // Auto-save
    add_action('wp_ajax_save_profile_field', 'handle_profile_field_save');
    add_action('wp_ajax_update_display_names', 'handle_display_names_update');
    
    // Debug endpoints
    if (defined('WP_DEBUG') && WP_DEBUG) {
        add_action('wp_ajax_check_upload_limits', 'check_upload_limits_handler');
        add_action('wp_ajax_test_ajax_connection', 'test_ajax_connection_handler');
    }
}

add_action('init', 'register_profile_edit_ajax_handlers');

// ========== SHORTCODE SUPPORT ==========

// Make sure these are added to functions.php
add_action('wp_ajax_set_profile_picture', 'handle_set_profile_picture');
add_action('wp_ajax_delete_profile_image', 'handle_delete_profile_image');
//add_action('wp_ajax_delete_profile_audio', 'handle_delete_profile_audio');
add_action('wp_ajax_delete_profile_video', 'handle_delete_profile_video');

function handle_set_profile_picture() {
    $nonce = $_POST['_ajax_nonce'] ?? '';
    $user_id = get_current_user_id();
    // VERIFY: This should match the nonce in profileEdit.nonce
    if (!wp_verify_nonce($nonce, 'update_profile_' . $user_id)) {
        wp_die('Invalid nonce', 403);
    }
    
    //check_ajax_referer('profile_edit_nonce', 'nonce');
    
    $attachment_id = intval($_POST['attachment_id']);
    
    // Verify ownership
    $attachment = get_post($attachment_id);
    if (!$attachment || $attachment->post_author != $user_id) {
        wp_send_json_error('You do not own this image.');
    }
    
    // Check if it's an image
    if (!wp_attachment_is_image($attachment_id)) {
        wp_send_json_error('File is not an image.');
    }
    
    // METHOD 1: Simple Local Avatars (most common)
    if (function_exists('simple_local_avatars')) {
        update_user_meta($user_id, 'simple_local_avatar', $attachment_id);
        wp_send_json_success('Profile picture updated (Simple Local Avatars).');
    }
    
    // METHOD 2: WP User Avatar plugin
    if (class_exists('WP_User_Avatar')) {
        update_user_meta($user_id, 'wp_user_avatar', $attachment_id);
        wp_send_json_success('Profile picture updated (WP User Avatar).');
    }
    
    // METHOD 3: Basic WordPress
    update_user_meta($user_id, '_custom_profile_picture', $attachment_id);
    update_user_meta($user_id, 'profile_picture_id', $attachment_id);
    
    // Force refresh
    clean_user_cache($user_id);
    
    wp_send_json_success('Profile picture updated.');
}

function handle_delete_profile_image() {
    // Get parameters
    $nonce = $_POST['_ajax_nonce'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    $image_id = intval($_POST['image_id'] ?? 0);
    
    // VERIFY: This should match the nonce in profileEdit.nonce
    if (!wp_verify_nonce($nonce, 'update_profile_' . $user_id)) {
        wp_die('Invalid nonce', 403);
    }
    
    // Check permissions
    if (!current_user_can('edit_user', $user_id)) {
        wp_die('Permission denied', 403);
    }
    
    // Delete the image
    if (wp_delete_attachment($image_id, true)) {
        wp_send_json_success('Image deleted successfully');
    } else {
        wp_send_json_error('Failed to delete image');
    }
}

//function handle_delete_profile_audio() {
//    // Use the same nonce as other handlers for consistency
//    $nonce = $_POST['_ajax_nonce'] ?? '';
//    $user_id = get_current_user_id();
//    // VERIFY: This should match the nonce in profileEdit.nonce
//    if (!wp_verify_nonce($nonce, 'update_profile_' . $user_id)) {
//        wp_die('Invalid nonce', 403);
//    }
//    
//    $audio_id = intval($_POST['audio_id']);
//    
//    // Get the attachment to verify ownership
//    $attachment = get_post($audio_id);
//    
//    if (!$attachment || $attachment->post_author != $user_id) {
//        wp_send_json_error('Invalid audio file or permissions');
//    }
//    
//    // Delete the attachment
//    $deleted = wp_delete_attachment($audio_id, true);
//    
//    if ($deleted) {
//        wp_send_json_success('Audio deleted.');
//    } else {
//        wp_send_json_error('Failed to delete audio.');
//    }
//}


function handle_delete_profile_video() {
    $nonce = $_POST['_ajax_nonce'] ?? '';
    $user_id = get_current_user_id();
    // VERIFY: This should match the nonce in profileEdit.nonce
    if (!wp_verify_nonce($nonce, 'update_profile_' . $user_id)) {
        wp_die('Invalid nonce', 403);
    }
    
    $video_id = intval($_POST['video_id']);
    
    // Check if video belongs to current user
    $video = get_post($video_id);
    
    if (!$video || $video->post_author != $user_id) {
        wp_send_json_error('You do not have permission to delete this video.');
    }
    
    // Check taxonomy term (optional - remove if you want to allow deleting any video)
    $terms = wp_get_object_terms($video_id, 'profile_media_cat');
    $has_gallery_term = false;
    
    foreach ($terms as $term) {
        if ($term->slug === 'gallery') {
            $has_gallery_term = true;
            break;
        }
    }
    
    // Comment this out if you want to allow deleting any video
    /*
    if (!$has_gallery_term) {
        wp_send_json_error('This video cannot be deleted from this location.');
    }
    */
    
    // Delete the attachment
    $deleted = wp_delete_attachment($video_id, true);
    
    if ($deleted) {
        wp_send_json_success('Video deleted.');
    } else {
        wp_send_json_error('Failed to delete video.');
    }
}

// Filter to use custom avatar
add_filter('get_avatar_data', 'custom_profile_avatar_data', 10, 2);
function custom_profile_avatar_data($args, $id_or_email) {
    $user = false;
    
    if (is_numeric($id_or_email)) {
        $user = get_user_by('id', $id_or_email);
    } elseif (is_string($id_or_email) && is_email($id_or_email)) {
        $user = get_user_by('email', $id_or_email);
    } elseif ($id_or_email instanceof WP_User) {
        $user = $id_or_email;
    }
    
    if ($user && $user->ID) {
        $custom_avatar = get_user_meta($user->ID, 'custom_profile_avatar', true);
        
        if ($custom_avatar) {
            $image_url = wp_get_attachment_url($custom_avatar);
            if ($image_url) {
                $args['url'] = $image_url;
            }
        }
    }
    
    return $args;
}

// Add AJAX handler for avatar refresh
add_action('wp_ajax_refresh_avatar_cache', 'handle_refresh_avatar_cache');
function handle_refresh_avatar_cache() {
    $user_id = get_current_user_id();
    clean_user_cache($user_id);
    wp_send_json_success();
}

// Add test function to verify avatar system
function test_avatar_system() {
    $user_id = get_current_user_id();
    
    echo '<div style="background: #f0f0f0; padding: 15px; margin: 15px 0; border-radius: 5px;">';
    echo '<h4>Avatar System Test</h4>';
    
    // Test different meta keys
    $meta_keys = ['simple_local_avatar', 'wp_user_avatar', '_custom_profile_picture', 'profile_picture_id'];
    
    foreach ($meta_keys as $key) {
        $value = get_user_meta($user_id, $key, true);
        echo '<p><strong>' . $key . ':</strong> ' . ($value ? 'Set (' . $value . ')' : 'Not set') . '</p>';
    }
    
    // Show current avatar
    echo '<p><strong>Current Avatar:</strong> ' . get_avatar($user_id, 96) . '</p>';
    
    echo '</div>';
}
// Call this in your template temporarily: test_avatar_system();

// Filter to override avatar with our custom one
add_filter('get_avatar_data', 'custom_avatar_data_filter', 10, 2);
function custom_avatar_data_filter($args, $id_or_email) {
    $user = false;
    
    // Get user object from ID or email
    if (is_numeric($id_or_email)) {
        $user = get_user_by('id', (int) $id_or_email);
    } elseif (is_string($id_or_email) && is_email($id_or_email)) {
        $user = get_user_by('email', $id_or_email);
    } elseif ($id_or_email instanceof WP_User) {
        $user = $id_or_email;
    }
    
    if ($user && $user->ID) {
        // Try different meta keys
        $attachment_id = false;
        
        // Check Simple Local Avatars
        $attachment_id = get_user_meta($user->ID, 'simple_local_avatar', true);
        
        // Check WP User Avatar
        if (!$attachment_id) {
            $attachment_id = get_user_meta($user->ID, 'wp_user_avatar', true);
        }
        
        // Check our custom meta
        if (!$attachment_id) {
            $attachment_id = get_user_meta($user->ID, '_custom_profile_picture', true);
        }
        
        // Check profile_picture_id
        if (!$attachment_id) {
            $attachment_id = get_user_meta($user->ID, 'profile_picture_id', true);
        }
        
        // If we found an attachment ID, get the image URL
        if ($attachment_id) {
            $image_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
            if ($image_url) {
                $args['url'] = $image_url;
                
                // Also update the size-specific URLs
                $args['found_avatar'] = true;
            }
        }
    }
    
    return $args;
}

// Also hook into pre_get_avatar to ensure compatibility
add_filter('pre_get_avatar', 'custom_pre_get_avatar', 10, 3);
function custom_pre_get_avatar($avatar, $id_or_email, $args) {
    $user = false;
    
    if (is_numeric($id_or_email)) {
        $user = get_user_by('id', (int) $id_or_email);
    } elseif (is_string($id_or_email) && is_email($id_or_email)) {
        $user = get_user_by('email', $id_or_email);
    } elseif ($id_or_email instanceof WP_User) {
        $user = $id_or_email;
    }
    
    if ($user && $user->ID) {
        $attachment_id = false;
        
        // Check all possible meta keys
        $meta_keys = ['simple_local_avatar', 'wp_user_avatar', '_custom_profile_picture', 'profile_picture_id'];
        foreach ($meta_keys as $key) {
            $attachment_id = get_user_meta($user->ID, $key, true);
            if ($attachment_id) break;
        }
        
        if ($attachment_id) {
            // Get the appropriate image size
            $size = isset($args['size']) ? $args['size'] : 96;
            $image_url = wp_get_attachment_image_url($attachment_id, [$size, $size]);
            
            if ($image_url) {
                // Build custom avatar HTML
                $class = isset($args['class']) ? $args['class'] : 'avatar';
                $alt = isset($args['alt']) ? $args['alt'] : sprintf(__('Avatar of %s'), $user->display_name);
                
                $avatar = sprintf(
                    '<img src="%s" class="%s" height="%d" width="%d" alt="%s" loading="lazy" />',
                    esc_url($image_url),
                    esc_attr($class),
                    (int) $size,
                    (int) $size,
                    esc_attr($alt)
                );
            }
        }
    }
    
    return $avatar;
}
//function handle_delete_profile_image() {
//    $user_id = get_current_user_id();
//    
//    if (!wp_verify_nonce($_POST['nonce'], 'delete_image_' . $user_id)) {
//        wp_die('Security check failed');
//    }
//    
//    $image_id = intval($_POST['image_id']);
//    $attachment = get_post($image_id);
//    
//    if ($attachment && $attachment->post_author == $user_id) {
//        wp_delete_attachment($image_id, true);
//        wp_send_json_success();
//    } else {
//        wp_send_json_error('Invalid image or permissions');
//    }
//}

/**
 * 2. Use posts_clauses for more reliable SQL injection.
 * Logic: (Type is 'canon') OR (Type is 'post' AND (Author is 1 OR Meta is Featured))
 * Added: Exclude canon posts with _canon_pinned meta field
 */
add_filter('posts_clauses', function ($clauses, $query) {
    global $wpdb;

    // Only run on the front-end main query for specific pages
    if (is_admin() || !$query->is_main_query()) {
        return $clauses;
    }

    if ($query->is_home() || $query->is_category() || $query->is_tag() || $query->is_archive()) {
        
        // Use a subquery for the featured check
        $featured_sq = "SELECT 1 FROM {$wpdb->postmeta} 
                        WHERE {$wpdb->postmeta}.post_id = {$wpdb->posts}.ID 
                        AND {$wpdb->postmeta}.meta_key = '_is_featured' 
                        AND {$wpdb->postmeta}.meta_value = '1'";
        
        // Use a subquery to check for pinned canon posts
        $pinned_canon_sq = "SELECT 1 FROM {$wpdb->postmeta} 
                           WHERE {$wpdb->postmeta}.post_id = {$wpdb->posts}.ID 
                           AND {$wpdb->postmeta}.meta_key = '_canon_pinned'";

        // Inject our conditional logic
        // Modified to exclude canon posts that have the _canon_pinned meta field
        $clauses['where'] .= " AND (
            (
                {$wpdb->posts}.post_type = 'canon'
                AND NOT EXISTS ({$pinned_canon_sq})
            )
            OR (
                {$wpdb->posts}.post_type = 'post' 
                AND (
                    {$wpdb->posts}.post_author = 1 
                    OR EXISTS ($featured_sq)
                )
            )
        )";
    }

    return $clauses;
}, 10, 2);

add_filter('excerpt_length', function() { return 20; });
// Handle video deletion via AJAX
add_action('wp_ajax_delete_profile_video', 'handle_delete_profile_video');
add_action('wp_ajax_nopriv_delete_profile_video', 'handle_delete_profile_video');

add_action('pre_get_posts', function ($query) {

    if (is_admin() || !$query->is_main_query()) {
        return;
    }

    // Leave the front page alone
    if ($query->is_front_page()) {
        return;
    }

    // Leave static pages alone
    if ($query->is_page()) {
        return;
    }

    // Constrain the blog/home loop only
    if ($query->is_home() || $query->is_archive() || $query->is_search()) {
        //$query->set('post_type', ['post']);
    }

});


add_action( 'admin_bar_menu', 'customize_tuzongo_admin_bar', 20 );

function customize_tuzongo_admin_bar( $wp_admin_bar ) {
    // 1. Replace the logo
    $wp_admin_bar->remove_node( 'wp-logo' );
    $wp_admin_bar->add_node( array(
        'id'    => 'wp-logo',
        'title' => '<span style="height: 20px; width:24px; margin-top: 4px; display: inline-block;">' . 
                   '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024">
  <title>tz</title>
  <g id="a17363a6-c675-4f36-ac41-333ba217d7be" data-name="Layer 3">
    <g>
      <g style="isolation: isolate">
        <path d="M740.64,211.46l-120,116.14H431.9L430,810.32l-117.1,32.89,2.9-515.61h-270L158,211.46Z" style="fill: none;stroke: #5fc553;stroke-miterlimit: 10;stroke-width: 29.54863166809082px"/>
      </g>
      <g style="isolation: isolate">
        <path d="M976.07,744.76l-80.66,87.65H535.24L781.1,517.51H623.72q-15,0-46.4,3.76L549.73,525l80.76-82.64H935.38L688.71,757.29h201q15,0,50.71-6.26Z" style="fill: none;stroke: #5fc553;stroke-miterlimit: 10;stroke-width: 28.670455932617188px"/>
      </g>
    </g>
  </g>
</svg>' . 
                   '</span>',
        'href'  => admin_url(),
        'meta'  => array( 'title' => __( 'Go to Dashboard' ) )
    ) );
    
    // 2. Replace "About WordPress" with your link
    $wp_admin_bar->remove_node( 'about' );
    $wp_admin_bar->add_node( array(
        'id'     => 'about-tuzongo',
        'parent' => 'wp-logo',
        'title'  => __( 'About TUZONGO' ),
        'href'   => 'https://tuzongo.com/about',
        'meta'   => array( 'target' => '_blank' )
    ) );
    
    // 3. REMOVE unwanted items for non-administrators - SINGLE CONDITION
    if ( ! current_user_can( 'manage_options' ) ) {
        // First, try to remove the entire secondary submenu
        $wp_admin_bar->remove_node( 'wp-logo-external' );
        
        // Then remove any remaining individual items
        $items_to_remove = array(
            'wporg',           // "WordPress.org"
            'documentation',   // "Documentation"
            'contribute',      // "Get Involved"
            'learn',           // "Learn WordPress"
            'support-forums',  // "Support"
            'feedback'         // "Feedback"
        );
        
        foreach ( $items_to_remove as $item_id ) {
            $wp_admin_bar->remove_node( $item_id );
        }
    }
}

add_action('template_redirect', function () {
    if (is_author()) {
        $author = get_queried_object();
        if ($author && $author->user_nicename === 'sid') {
            wp_redirect(home_url('/sid'), 301);
            exit;
        }
    }
});

add_action( 'wp_enqueue_scripts', function() {
    if ( is_author() ) {
        wp_enqueue_script(
            'author-profile-toggle',
            get_stylesheet_directory_uri() . '/js/author-profile.js',
            [],
            null,
            true
        );
    }
});

// Show the field on the user's own profile and the edit user screen
add_action( 'show_user_profile', 'add_custom_user_tagline_field' );
add_action( 'edit_user_profile', 'add_custom_user_tagline_field' );

function add_custom_user_tagline_field( $user ) {
    ?>
    <h3>Extra Profile Information</h3>
    <table class="form-table">
        <tr>
            <th><label for="user_tagline">Personal Tagline</label></th>
            <td>
                <input type="text" name="user_tagline" id="user_tagline" value="<?php echo esc_attr( get_the_author_meta( 'user_tagline', $user->ID ) ); ?>" class="regular-text" /><br />
                <span class="description">Enter a short storyboard-style tagline for your profile.</span>
            </td>
        </tr>
    </table>
    <?php
}

// Save the field when the profile is updated
add_action( 'personal_options_update', 'save_custom_user_tagline_field' );
add_action( 'edit_user_profile_update', 'save_custom_user_tagline_field' );

add_filter( 'author_link', 'custom_author_link_for_user', 10, 3 );
function custom_author_link_for_user( $link, $author_id, $author_nicename ) {
    $sid_id    = 2; // Sid's user ID
    $yendis_id = 1; // Yendis user ID

    if ( $author_id == $sid_id ) {
        // Sid profile stays active
        return $link;
    } elseif ( $author_id == $yendis_id ) {
        // Yendis page redirects to platform message
        return home_url( '/yendis/' );
    } else {
        // All other users go to the hang-tight page
        return home_url( '/registered/' );
    }
}

function save_custom_user_tagline_field( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) { 
        return false; 
    }
    update_user_meta( $user_id, 'user_tagline', sanitize_text_field( $_POST['user_tagline'] ) );
}

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) return;

    wp_enqueue_script(
        'post-flags-admin',
        get_stylesheet_directory_uri() . '/js/post-flags-admin.js',
        [ 'jquery' ],
        '1.0',
        true
    );
});

// 1. Create the combined Meta Box
add_action( 'add_meta_boxes', function() {
    add_meta_box( 'post_flags_meta', 'Editorial Flags', 'render_post_flags_meta', 'post', 'side', 'high' );
});

// 2. Render the UI
function render_post_flags_meta( $post ) {
    $pinned = get_post_meta( $post->ID, '_is_pinned', true );
    $featured = get_post_meta( $post->ID, '_is_featured', true );
    $pinned_tagline = get_post_meta( $post->ID, '_pinned_tagline', true );
    $featured_tagline = get_post_meta( $post->ID, '_featured_tagline', true );

    wp_nonce_field( 'save_flags_nonce', 'flags_nonce' );

    // Display Pinned Option (Visible to everyone who can edit)
    echo '<p><label><input type="checkbox" id="pinned_flag" name="pinned_flag" value="1" ' . checked( $pinned, 1, false ) . ' /> ðŸ“Œ Pin this post</label></p>';

    // Pinned Tagline (hidden unless pinned)
    echo '<div id="pinned_tagline_wrap" style="margin-top:8px; display:none;">
        <label style="font-size:12px; display:block; margin-bottom:4px;">
            Pinned descriptor
        </label>
        <input type="text"
            name="pinned_tagline"
            value="' . esc_attr( $pinned_tagline ) . '"
            style="width:100%;"
            maxlength="60"
            placeholder="e.g. A quiet short about perception" />
        <p style="font-size:11px; color:#666; margin:4px 0 0;">
            Appears under the title when shown as a selected work.
        </p>
    </div>';
    
    // Display Featured Option (ONLY for Editors and Admins)
    if ( current_user_can( 'edit_others_posts' ) ) {
        echo '<p style="border-top: 1px solid #eee; padding-top:10px; margin-top:10px;"><label><input type="checkbox" id="featured_flag" name="featured_flag" value="1" ' . checked( $featured, 1, false ) . ' />â­Mark as Featured</label></p>';
        
        // Featured Tagline (hidden unless featured)
        echo '<div id="featured_tagline_wrap" style="margin-top:8px; display:none;">
            <label style="font-size:12px; display:block; margin-bottom:4px;">
                Featured descriptor
            </label>
            <input type="text"
                name="featured_tagline"
                value="' . esc_attr( $featured_tagline ) . '"
                style="width:100%;"
                maxlength="60"
                placeholder="e.g. This week\'s editor\'s pick" />
            <p style="font-size:11px; color:#666; margin:4px 0 0;">
                Appears under the title when shown as a featured post.
            </p>
        </div>';
    }
}

// 3. Save the data
add_action( 'save_post', function( $post_id ) {
    if ( ! isset( $_POST['flags_nonce'] ) || ! wp_verify_nonce( $_POST['flags_nonce'], 'save_flags_nonce' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

    // Save Pinned
    $is_pinned = isset( $_POST['pinned_flag'] ) ? 1 : 0;
    update_post_meta( $post_id, '_is_pinned', $is_pinned );

    if ( $is_pinned && isset( $_POST['pinned_tagline'] ) ) {
        update_post_meta(
            $post_id,
            '_pinned_tagline',
            sanitize_text_field( $_POST['pinned_tagline'] )
        );
    } else {
        delete_post_meta( $post_id, '_pinned_tagline' );
    }

    // Save Featured (Check permissions again during save for security)
    if ( current_user_can( 'edit_others_posts' ) ) {
        $is_featured = isset( $_POST['featured_flag'] ) ? 1 : 0;
        update_post_meta( $post_id, '_is_featured', $is_featured );
        
        if ( $is_featured && isset( $_POST['featured_tagline'] ) ) {
            update_post_meta(
                $post_id,
                '_featured_tagline',
                sanitize_text_field( $_POST['featured_tagline'] )
            );
        } else {
            delete_post_meta( $post_id, '_featured_tagline' );
        }
    }
});

add_filter( 'block_template', function ( $template, $id, $type ) {
    if ( $type === 'single' ) {
        return null;
    }
    return $template;
}, 10, 3 );

add_action( 'init', function () {
    register_block_type( 'yendis/login-panel', [
        'render_callback' => function () {
            ob_start();
            get_template_part( 'parts/header-login' );
            return ob_get_clean();
        }
    ] );
} );

add_action( 'wp_enqueue_scripts', function () {
    // Ensure block layout + spacing CSS is loaded on the frontend
    wp_enqueue_style( 'wp-block-library' );
    wp_enqueue_style( 'wp-block-library-theme' );
    wp_enqueue_style( 'global-styles' );
}, 5 );

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style( 'wp-block-navigation' );
} );

add_filter( 'nav_menu_css_class', function ( $classes ) {
    $classes[] = 'wp-block-navigation-item';
    return $classes;
} );

add_action( 'wp_template_part_after', function ( $template_part ) {
    if ( $template_part->slug === 'header' ) {
        get_template_part( 'parts/header-login' );
    }
}, 10 );

function yendis_insert_login_header() {
    get_template_part( 'parts/header-login' );
}

add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_user_logged_in() ) {
        wp_enqueue_style( 'dashicons' );
    }
});

// 1. Disable comments on posts older than 30 days
function asapc_disable_old_comments() {
    if (is_singular('post') && comments_open()) {
        $days_old = 30;
        $post_age = time() - get_the_time('U');
        if ($post_age > (60 * 60 * 24 * $days_old)) {
            add_filter('comments_open', '__return_false', 20, 2);
        }
    }
}
//add_action('template_redirect', 'asapc_disable_old_comments');

// 2. Add honeypot field
function asapc_add_honeypot_field() {
    echo '<p style="display:none"><input type="text" name="my_hp_field" value="" /></p>';
}
add_action('comment_form', 'asapc_add_honeypot_field');

function asapc_check_honeypot_field($commentdata) {
    if (!empty($_POST['my_hp_field'])) {
        wp_die('Spam detected.');
    }
    return $commentdata;
}
add_filter('preprocess_comment', 'asapc_check_honeypot_field');

// 3. Limit number of links in comments
function asapc_limit_comment_links($commentdata) {
    $max_links = 2;
    $content = $commentdata['comment_content'];
    $link_count = preg_match_all('/<a [^>]+>/i', $content);
    if ($link_count > $max_links) {
        wp_die('Too many links in your comment.');
    }
    return $commentdata;
}
add_filter('preprocess_comment', 'asapc_limit_comment_links');

// 4. Block blacklisted words
function asapc_block_blacklisted_words($commentdata) {
    $blacklist = ['viagra', 'casino', 'porn', 'sex', 'btc', 'crypto'];
    foreach ($blacklist as $word) {
        if (stripos($commentdata['comment_content'], $word) !== false) {
            wp_die('Your comment contains a blocked word.');
        }
    }
    return $commentdata;
}
add_filter('preprocess_comment', 'asapc_block_blacklisted_words');

// 5. Require JavaScript for comments
function asapc_add_js_token() {
    echo '<input type="hidden" id="js_token" name="js_token" value="">';
    echo '<script>document.getElementById("js_token").value = "human";</script>';
}
add_action('comment_form', 'asapc_add_js_token');

function asapc_verify_js_token($commentdata) {
    if (!isset($_POST['js_token']) || $_POST['js_token'] !== 'human') {
        wp_die('JavaScript is required to post comments.');
    }
    return $commentdata;
}
add_filter('preprocess_comment', 'asapc_verify_js_token');

// 6. Disable pingbacks and trackbacks
//add_filter('xmlrpc_methods', function($methods) {
//    unset($methods['pingback.ping']);
//    return $methods;
//});

//add_filter('wp_headers', function($headers) {
//    unset($headers['X-Pingback']);
//    return $headers;
//});

function custom_admin_bar_css() {
    echo '<style>
    #wp-admin-bar-public_profile a.ab-item {
        padding-left: 96px !important;
    }
    </style>';
}
add_action('admin_head', 'custom_admin_bar_css');

function hide_profile_picture_for_non_admins($buffer) {
    if (!current_user_can('manage_options')) { // Check if user is NOT an admin
        $buffer = preg_replace('/<tr class="user-profile-picture">.*?<\/tr>/s', '', $buffer);
    }
    return $buffer;
}

function start_buffering_profile_edit_page() {
    ob_start('hide_profile_picture_for_non_admins');
}

function end_buffering_profile_edit_page() {
    ob_end_flush();
}

add_action('admin_head', 'start_buffering_profile_edit_page');
add_action('admin_footer', 'end_buffering_profile_edit_page');

// Add user meta field for profile featured image
function add_profile_featured_image_field($user) {
    if (current_user_can('contributor') || current_user_can('edit_users')) {
        ?>
        <h3><?php _e('Profile Featured Image', 'textdomain'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="profile_featured_image"><?php _e('Featured Image', 'textdomain'); ?></label></th>
                <td>
                    <?php
                    $image_id = get_user_meta($user->ID, 'profile_featured_image', true);
                    $image_url = wp_get_attachment_url($image_id);
                    ?>
                    <input type="hidden" name="profile_featured_image" id="profile_featured_image" value="<?php echo esc_attr($image_id); ?>" />
                    <div id="profile-featured-image-container">
                        <?php if ($image_id): ?>
                            <img src="<?php echo esc_url($image_url); ?>" style="max-width: 200px;" />
                        <?php endif; ?>
                    </div>
                    <input type="button" class="button" value="<?php _e('Upload Image', 'textdomain'); ?>" id="upload-profile-featured-image" />
                    <input type="button" class="button <?php if (!$image_id) echo 'hidden'; ?>" value="<?php _e('Remove Image', 'textdomain'); ?>" id="remove-profile-featured-image" />
                </td>
            </tr>
        </table>
        <script>
            jQuery(document).ready(function($) {
                // Uploading files
                var file_frame;
                
                $('#upload-profile-featured-image').on('click', function(event) {
                    event.preventDefault();
                    
                    if (file_frame) {
                        file_frame.open();
                        return;
                    }
                    
                    file_frame = wp.media.frames.file_frame = wp.media({
                        title: '<?php _e('Select Featured Image', 'textdomain'); ?>',
                        button: {
                            text: '<?php _e('Use as Featured Image', 'textdomain'); ?>',
                        },
                        multiple: false
                    });
                    
                    file_frame.on('select', function() {
                        var attachment = file_frame.state().get('selection').first().toJSON();
                        $('#profile_featured_image').val(attachment.id);
                        $('#profile-featured-image-container').html('<img src="' + attachment.url + '" style="max-width: 200px;" />');
                        $('#remove-profile-featured-image').removeClass('hidden');
                    });
                    
                    file_frame.open();
                });
                
                $('#remove-profile-featured-image').on('click', function(event) {
                    event.preventDefault();
                    $('#profile_featured_image').val('');
                    $('#profile-featured-image-container').html('');
                    $(this).addClass('hidden');
                });
            });
        </script>
        <?php
    }
}
add_action('show_user_profile', 'add_profile_featured_image_field');
add_action('edit_user_profile', 'add_profile_featured_image_field');

// Save the user meta
function save_profile_featured_image_field($user_id) {
    if (current_user_can('edit_user', $user_id)) {
        update_user_meta($user_id, 'profile_featured_image', sanitize_text_field($_POST['profile_featured_image']));
    }
}
add_action('personal_options_update', 'save_profile_featured_image_field');
add_action('edit_user_profile_update', 'save_profile_featured_image_field');

/**
 * Remove latest comments from the Activity Dashboard widget (approach #5)
 */
add_action( 'load-index.php', function()
{
    if (!current_user_can('administrator')) {
		add_filter( 'dashboard_recent_posts_query_args', function( $args )
		{
			// Let's exit the WP_Comment_Query from the get_comments_ids() method:
			add_action( 'pre_get_comments', function( \WP_Comment_Query $q )
			{
				if( 1 === did_action( 'pre_get_comments' ) )
					$q->set( 'count', true );

				// Eliminate the next query         
				add_filter( 'query', function( $query )
				{
					static $instance = 0;
					if( 1 === ++$instance )
						$query = false;
					return $query;
				}, PHP_INT_MAX );

			} );
			return $args;
		} );
	}
} );

function remove_wp_events_news_dashboard_widget() {
    if (!current_user_can('administrator')) {
        remove_meta_box('dashboard_primary', 'dashboard', 'side'); 
    }
}
add_action('wp_dashboard_setup', 'remove_wp_events_news_dashboard_widget');

function restrict_subscribe_forms_capabilities() {
    if (!current_user_can('administrator')) {
        remove_menu_page('edit.php?post_type=sfba_subscribe_form'); // Hides menu for non-admins
        remove_menu_page('edit-comments.php'); 
        remove_menu_page('wpcf7'); 
        remove_menu_page('tools.php'); 
    }
}
add_action('admin_menu', 'restrict_subscribe_forms_capabilities', 99);

function remove_new_subscribe_form_link_from_admin_bar($wp_admin_bar) {
    if (!current_user_can('administrator')) {
        $wp_admin_bar->remove_node('new-sfba_subscribe_form'); // Remove "New Subscribe Form" link
    }
}
add_action('admin_bar_menu', 'remove_new_subscribe_form_link_from_admin_bar', 999);

function time_left_in_years_months($expiration_date) {
    $current_time = time();
    
    if ($expiration_date <= $current_time) {
        // If already expired, calculate how long ago it expired
        $diff = $current_time - $expiration_date;
        $prefix = 'Expired ';
    } else {
        // If still valid, calculate remaining time
        $diff = $expiration_date - $current_time;
        $prefix = 'Expires in ';
    }

    $years = floor($diff / YEAR_IN_SECONDS);
    $remaining_seconds = $diff % YEAR_IN_SECONDS;
    $months = floor($remaining_seconds / (30 * 24 * 60 * 60)); // Approximate months

    if ($years >= 2) {
        return "$prefix <strong>$years years</strong>";
    } else {
        return "$prefix $months months (" . date('F j, Y', $expiration_date) . ")";
    }
}

// Add the documentation section to the user profile
function add_documentation_fields($user) { ?>
    <h3>Documentation Details</h3>

    <table class="form-table">
        <tr>
            <th><label for="passport_issuer">Passport Issuer</label></th>
            <td>
                <input type="text" id="passport_issuer" name="passport_issuer" value="<?php echo esc_attr(get_the_author_meta('passport_issuer', $user->ID)); ?>" />
            </td>
        </tr>
        <tr>
            <th><label for="passport_expiration">Passport Expiration Date</label></th>
            <td>
                <input type="date" id="passport_expiration" name="passport_expiration" value="<?php echo esc_attr(get_the_author_meta('passport_expiration', $user->ID)); ?>" />
            </td>
        </tr>
        <tr>
            <th><label for="dbs_basic">DBS Basic Check Date</label></th>
            <td>
                <input type="date" id="dbs_basic" name="dbs_basic" value="<?php echo esc_attr(get_the_author_meta('dbs_basic', $user->ID)); ?>" />
            </td>
        </tr>
        <tr>
            <th><label for="dbs_standard">DBS Standard Check Date</label></th>
            <td>
                <input type="date" id="dbs_standard" name="dbs_standard" value="<?php echo esc_attr(get_the_author_meta('dbs_standard', $user->ID)); ?>" />
            </td>
        </tr>
        <tr>
            <th><label for="dbs_enhanced">DBS Enhanced Check Date</label></th>
            <td>
                <input type="date" id="dbs_enhanced" name="dbs_enhanced" value="<?php echo esc_attr(get_the_author_meta('dbs_enhanced', $user->ID)); ?>" />
            </td>
        </tr>
        <tr>
            <th><label for="uk_drivers_license">Valid UK Driver's License</label></th>
            <td>
                <input type="checkbox" id="uk_drivers_license" name="uk_drivers_license" value="1" <?php checked(get_the_author_meta('uk_drivers_license', $user->ID), 1); ?> />
                <span>Check if the user holds a valid UK driver's license</span>
            </td>
        </tr>
    </table>

<?php }
add_action('show_user_profile', 'add_documentation_fields');
add_action('edit_user_profile', 'add_documentation_fields');


// Save the documentation details with date validation
function save_documentation_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    // Save passport issuer
    $passport_issuer = sanitize_text_field($_POST['passport_issuer']);
    update_user_meta($user_id, 'passport_issuer', $passport_issuer);
    
    // Save passport expiration date and validate it
    $passport_expiration = sanitize_text_field($_POST['passport_expiration']);
    if ($passport_expiration && strtotime($passport_expiration) < time()) {
        // Expiration date should be in the future
        add_user_meta($user_id, 'passport_expiration_error', 'The passport expiration date must be in the future.', true);
        return;
    } else {
        delete_user_meta($user_id, 'passport_expiration_error'); // Clear any error
        update_user_meta($user_id, 'passport_expiration', $passport_expiration);
    }
    
    // Save DBS check dates and validate them
    $dbs_basic = sanitize_text_field($_POST['dbs_basic']);
    $dbs_standard = sanitize_text_field($_POST['dbs_standard']);
    $dbs_enhanced = sanitize_text_field($_POST['dbs_enhanced']);
    
    if ($dbs_basic && strtotime($dbs_basic) > time()) {
        add_user_meta($user_id, 'dbs_basic_error', 'DBS Basic Check date cannot be in the future.', true);
        return;
    } else {
        delete_user_meta($user_id, 'dbs_basic_error');
        update_user_meta($user_id, 'dbs_basic', $dbs_basic);
    }
    
    if ($dbs_standard && strtotime($dbs_standard) > time()) {
        add_user_meta($user_id, 'dbs_standard_error', 'DBS Standard Check date cannot be in the future.', true);
        return;
    } else {
        delete_user_meta($user_id, 'dbs_standard_error');
        update_user_meta($user_id, 'dbs_standard', $dbs_standard);
    }
    
    if ($dbs_enhanced && strtotime($dbs_enhanced) > time()) {
        add_user_meta($user_id, 'dbs_enhanced_error', 'DBS Enhanced Check date cannot be in the future.', true);
        return;
    } else {
        delete_user_meta($user_id, 'dbs_enhanced_error');
        update_user_meta($user_id, 'dbs_enhanced', $dbs_enhanced);
    }
    
    // Save UK driver's license status
    $uk_drivers_license = isset($_POST['uk_drivers_license']) ? 1 : 0;
    update_user_meta($user_id, 'uk_drivers_license', $uk_drivers_license);
}

add_action('personal_options_update', 'save_documentation_fields');
add_action('edit_user_profile_update', 'save_documentation_fields');

// Display documentation with expiration time details
function display_user_documentation($user) {
    $passport_issuer = get_user_meta($user->ID, 'passport_issuer', true);
    $passport_expiration = get_user_meta($user->ID, 'passport_expiration', true);
    $dbs_basic = get_user_meta($user->ID, 'dbs_basic', true);
    $dbs_standard = get_user_meta($user->ID, 'dbs_standard', true);
    $dbs_enhanced = get_user_meta($user->ID, 'dbs_enhanced', true);
    $uk_drivers_license = get_user_meta($user->ID, 'uk_drivers_license', true);

    // Passport expiration date
    $passport_expiration_text = '';
    if ($passport_expiration) {
        $passport_expiration_date = strtotime($passport_expiration);
        if ($passport_expiration_date < time()) {
            $passport_expiration_text = 'Passport expired on ' . date('F j, Y', $passport_expiration_date);
        } else {
            $time_left = $passport_expiration_date - time();
            $days_left = ceil($time_left / (60 * 60 * 24));
            $passport_expiration_text = 'Passport expires in ' . $days_left . ' days (' . date('F j, Y', $passport_expiration_date) . ')';
        }
    }

    // DBS check expiration dates
    $dbs_basic_text = '';
    if ($dbs_basic) {
        $dbs_basic_date = strtotime($dbs_basic);
        if ($dbs_basic_date < time()) {
            $dbs_basic_text = 'DBS Basic Check expired on ' . date('F j, Y', $dbs_basic_date);
        } else {
            $time_left = $dbs_basic_date - time();
            $days_left = ceil($time_left / (60 * 60 * 24));
            $dbs_basic_text = 'DBS Basic Check expires in ' . $days_left . ' days (' . date('F j, Y', $dbs_basic_date) . ')';
        }
    }

    $dbs_standard_text = '';
    if ($dbs_standard) {
        $dbs_standard_date = strtotime($dbs_standard);
        if ($dbs_standard_date < time()) {
            $dbs_standard_text = 'DBS Standard Check expired on ' . date('F j, Y', $dbs_standard_date);
        } else {
            $time_left = $dbs_standard_date - time();
            $days_left = ceil($time_left / (60 * 60 * 24));
            $dbs_standard_text = 'DBS Standard Check expires in ' . $days_left . ' days (' . date('F j, Y', $dbs_standard_date) . ')';
        }
    }

    $dbs_enhanced_text = '';
    if ($dbs_enhanced) {
        $dbs_enhanced_date = strtotime($dbs_enhanced);
        if ($dbs_enhanced_date < time()) {
            $dbs_enhanced_text = 'DBS Enhanced Check expired on ' . date('F j, Y', $dbs_enhanced_date);
        } else {
            $time_left = $dbs_enhanced_date - time();
            $days_left = ceil($time_left / (60 * 60 * 24));
            $dbs_enhanced_text = 'DBS Enhanced Check expires in ' . $days_left . ' days (' . date('F j, Y', $dbs_enhanced_date) . ')';
        }
    }

    // UK driver's license status
    $uk_drivers_license_text = $uk_drivers_license ? 'Valid UK Driver\'s License.' : 'No UK Driver\'s License.';

    // Output the information
    echo '<h3>Documentation Details</h3>';
    echo '<table class="form-table">';

    echo '<tr><th>Passport Issuer</th><td>' . esc_html($passport_issuer) . '</td></tr>';
    echo '<tr><th>Passport Expiration</th><td>' . esc_html($passport_expiration_text) . '</td></tr>';
    echo '<tr><th>DBS Basic Check</th><td>' . esc_html($dbs_basic_text) . '</td></tr>';
    echo '<tr><th>DBS Standard Check</th><td>' . esc_html($dbs_standard_text) . '</td></tr>';
    echo '<tr><th>DBS Enhanced Check</th><td>' . esc_html($dbs_enhanced_text) . '</td></tr>';
    echo '<tr><th>UK Driver\'s License</th><td>' . esc_html($uk_drivers_license_text) . '</td></tr>';

    echo '</table>';
}

//add_action('show_user_profile', 'display_user_documentation');
//add_action('edit_user_profile', 'display_user_documentation');

function add_public_profile_link_to_admin_bar($wp_admin_bar) {
    if (!is_user_logged_in()) {
        return;
    }

    $current_user = wp_get_current_user();
    $public_profile_url = home_url('/author/' . $current_user->user_nicename);

    $args = array(
        'id'     => 'public_profile',
        'title'  => 'Public Profile',
        'href'   => $public_profile_url,
        'parent' => 'my-account', // Adds it under 'My Account' dropdown
        'meta'   => array('target' => '_blank') // Open in new tab
    );

    $wp_admin_bar->add_node($args);
}
add_action('admin_bar_menu', 'add_public_profile_link_to_admin_bar', 999);

function custom_user_profile_fields($user) { 
	$gender = get_the_author_meta('gender', $user->ID);
    $nationality = get_the_author_meta('nationality', $user->ID) ?: ''; // Default to UK
	$gender = get_the_author_meta('gender', $user->ID);
    $gender_options = [
        'Male', 'Female', 'Non-binary', 'Transgender', 'Intersex', 'Two-Spirit', 'Genderqueer', 'Agender', 'Other', 'Prefer not to say'
    ];

	$countries_file = get_stylesheet_directory() . '/countries.php'; ?>
    <h3>Physical Attributes</h3>
    
    <table class="form-table">
		<tr>
			<th><label for="gender">Gender</label></th>
			<td>
				<select name="gender" id="gender">
					<option value="">Select Gender</option>
					<?php foreach ($gender_options as $option) : ?>
						<option value="<?php echo esc_attr($option); ?>" <?php selected($gender, $option); ?>><?php echo esc_html($option); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
            <th><label for="height_ft">Height</label></th>
            <td>
                <input type="number" id="height_ft" name="height_ft" value="<?php echo esc_attr(get_the_author_meta('height_ft', $user->ID)); ?>" min="3" max="8" placeholder="Feet" style="width: 60px;"> ft 
                <input type="number" id="height_in" name="height_in" value="<?php echo esc_attr(get_the_author_meta('height_in', $user->ID)); ?>" min="0" max="11" placeholder="Inches" style="width: 60px;"> in 
                <span>- or -</span>
                <input type="number" id="height_cm" name="height_cm" value="<?php echo esc_attr(get_the_author_meta('height_cm', $user->ID)); ?>" min="100" max="250" placeholder="Centimeters" style="width: 80px;"> cm
            </td>
        </tr>
		<tr>
			<th><label for="nationality">Nationality</label></th>
			<td>
				<select name="nationality" id="nationality"> <?php
				if (file_exists($countries_file)) {
					$countries = include($countries_file);

					if (is_array($countries)) {
						$current_nationality = $nationality; ?>

					<option value="" <?php echo selected($current_nationality, '', false); ?>>-- Please select --</option>
					<?php foreach ($countries as $value => $label) { ?>
						<option value="<?php echo esc_attr($value); ?>" <?php echo selected($current_nationality, $value); ?>>
							<?php echo esc_html($label); ?>
						</option><?php 
					} ?>
					<!--Additional options at the end-->
					<option value="stateless" <?php echo selected($current_nationality, 'stateless', false); ?>>Stateless</option>
					<option value="decline" <?php echo selected($current_nationality, 'decline', false); ?>>Decline to state</option>
					<?php
					} 
					else { ?>
						<option value="">Error loading countries</option><?php
					}
				} 
				else { ?>
					<option value="">Countries file not found</option><?php
				}
					
					?>
				</select>
			</td>
		</tr>
	</table>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            function convertToCm(ft, inches) {
                return Math.round((ft * 30.48) + (inches * 2.54));
            }

            function convertToFtIn(cm) {
                let totalInches = Math.round(cm / 2.54);
                let feet = Math.floor(totalInches / 12);
                let inches = totalInches % 12;
                return { feet, inches };
            }

            let heightFt = document.getElementById("height_ft");
            let heightIn = document.getElementById("height_in");
            let heightCm = document.getElementById("height_cm");

            function updateHeightCm() {
                if (heightFt.value && heightIn.value) {
                    heightCm.value = convertToCm(parseInt(heightFt.value), parseInt(heightIn.value));
                }
            }

            function updateHeightFtIn() {
                if (heightCm.value) {
                    let { feet, inches } = convertToFtIn(parseInt(heightCm.value));
                    heightFt.value = feet;
                    heightIn.value = inches;
                }
            }

            heightFt.addEventListener("input", updateHeightCm);
            heightIn.addEventListener("input", updateHeightCm);
            heightCm.addEventListener("input", updateHeightFtIn);
        });
    </script>
<?php }
add_action('show_user_profile', 'custom_user_profile_fields');
add_action('edit_user_profile', 'custom_user_profile_fields');

function save_custom_user_profile_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    update_user_meta($user_id, 'gender', sanitize_text_field($_POST['gender']));
    update_user_meta($user_id, 'nationality', sanitize_text_field($_POST['nationality']));

    // Validate Playing Age
    $playing_age_from = isset($_POST['playing_age_from']) ? intval($_POST['playing_age_from']) : 0;
    $playing_age_to = isset($_POST['playing_age_to']) ? intval($_POST['playing_age_to']) : 0;

    if ($playing_age_from < $playing_age_to && $playing_age_to <= 110) {
        update_user_meta($user_id, 'playing_age_from', $playing_age_from);
        update_user_meta($user_id, 'playing_age_to', $playing_age_to);
    }

    // Handle Height
    $height_ft = isset($_POST['height_ft']) && $_POST['height_ft'] !== '' ? intval($_POST['height_ft']) : null;
    $height_in = isset($_POST['height_in']) && $_POST['height_in'] !== '' ? intval($_POST['height_in']) : null;
    $height_cm = isset($_POST['height_cm']) && $_POST['height_cm'] !== '' ? intval($_POST['height_cm']) : null;

    if ($height_ft !== null && $height_in !== null) {
        $height_cm = round(($height_ft * 30.48) + ($height_in * 2.54));
    } elseif ($height_cm !== null) {
        $total_inches = round($height_cm / 2.54);
        $height_ft = floor($total_inches / 12);
        $height_in = $total_inches % 12;
    }

    if ($height_ft !== null) update_user_meta($user_id, 'height_ft', $height_ft);
    if ($height_in !== null) update_user_meta($user_id, 'height_in', $height_in);
    if ($height_cm !== null) update_user_meta($user_id, 'height_cm', $height_cm);
}

add_action('personal_options_update', 'save_custom_user_profile_fields');
add_action('edit_user_profile_update', 'save_custom_user_profile_fields');

add_action('wp_ajax_dismiss_glance_reminder', function () {
    check_ajax_referer('dismiss_glance', 'nonce');
    update_user_meta(get_current_user_id(), '_glance_reminder_dismissed', time());
    wp_die();
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script(
        'glance_reminder',
        get_stylesheet_directory_uri() . '/js/glance-reminder.js',
        [],
        '1.0',
        true
    );

    $inline_script = sprintf(
        'var glance_reminder = %s;',
        wp_json_encode([
            'ajaxurl' => admin_url('admin-ajax.php')
        ])
    );
    
    wp_add_inline_script('glance_reminder', $inline_script, 'before');
});

function display_user_meta($user_id) {
    $user_meta = get_user_meta($user_id);
    
    echo '<pre>';
    print_r($user_meta);
    echo '</pre>';
}

function custom_profile_site_title($title) {
    if (is_author()) { // Check if it's an author profile page
        $author = get_queried_object(); // Get author data
        if ($author) {
            $title['title'] = $author->display_name; // Modify title
        }
    }
    return $title;
}
add_filter('document_title_parts', 'custom_profile_site_title');

// Allow contributors to upload files (required for featured images)
function allow_contributor_uploads() {
    $contributor = get_role('contributor');
    $contributor->add_cap('upload_files');
}
add_action('admin_init', 'allow_contributor_uploads');

function allow_contributors_uploads() {
    $role = get_role('contributor');
    if ($role && !$role->has_cap('upload_files')) {
        $role->add_cap('upload_files');
    }
}
add_action('init', 'allow_contributors_uploads');

function add_base_rep_to_user_profile($user) { ?>
    <h3>Base(s)</h3>
    <table class="form-table">
        <tr>
            <th><label for="base1">Base</label></th>
            <td><input type="text" name="base1" id="base1" value="<?php echo esc_attr(get_the_author_meta('base1', $user->ID)); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="base2">Base</label></th>
            <td><input type="text" name="base2" id="base2" value="<?php echo esc_attr(get_the_author_meta('base2', $user->ID)); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="base3">Base</label></th>
            <td><input type="text" name="base3" id="base3" value="<?php echo esc_attr(get_the_author_meta('base3', $user->ID)); ?>" class="regular-text" /></td>
        </tr>
    </table>
    <h3>Representation</h3>
    <table class="form-table">
        <tr>
            <th><label for="rep_contact">Contact</label></th>
            <td><input type="text" name="rep_contact" id="rep_contact" value="<?php echo esc_attr(get_the_author_meta('rep_contact', $user->ID)); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="rep_agency">Agency</label></th>
            <td><input type="text" name="rep_agency" id="rep_agency" value="<?php echo esc_attr(get_the_author_meta('rep_agency', $user->ID)); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="rep_phone">Phone</label></th>
            <td><input type="text" name="rep_phone" id="rep_phone" value="<?php echo esc_attr(get_the_author_meta('rep_phone', $user->ID)); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="rep_email">Email</label></th>
            <td><input type="text" name="rep_email" id="rep_email" value="<?php echo esc_attr(get_the_author_meta('rep_email', $user->ID)); ?>" class="regular-text" /></td>
        </tr>
        <tr>
            <th><label for="rep_logo">Logo</label></th>
            <td><input type="text" name="rep_logo" id="rep_logo" value="<?php echo esc_attr(get_the_author_meta('rep_logo', $user->ID)); ?>" class="regular-text" /></td>
        </tr>
    </table>
<?php }
add_action('show_user_profile', 'add_base_rep_to_user_profile');
add_action('edit_user_profile', 'add_base_rep_to_user_profile');

function save_base_rep($user_id) {
    if (!current_user_can('edit_user', $user_id)) return false;
    update_user_meta($user_id, 'base1', $_POST['base1']);
    update_user_meta($user_id, 'base2', $_POST['base2']);
    update_user_meta($user_id, 'base3', $_POST['base3']);
    update_user_meta($user_id, 'rep_contact', $_POST['rep_contact']);
    update_user_meta($user_id, 'rep_phone', $_POST['rep_phone']);
    update_user_meta($user_id, 'rep_agency', $_POST['rep_agency']);
    update_user_meta($user_id, 'rep_email', $_POST['rep_email']);
    update_user_meta($user_id, 'rep_logo', $_POST['rep_logo']);
}
add_action('personal_options_update', 'save_base_rep');
add_action('edit_user_profile_update', 'save_base_rep');

function save_social_links($user_id) {
    if (!current_user_can('edit_user', $user_id)) return false;
    update_user_meta($user_id, 'facebook', $_POST['facebook']);
    update_user_meta($user_id, 'twitter', $_POST['twitter']);
    update_user_meta($user_id, 'bsky', $_POST['bsky']);
    update_user_meta($user_id, 'imdb', $_POST['imdb']);
    update_user_meta($user_id, 'tmdb', $_POST['tmdb']);
    update_user_meta($user_id, 'spotlight', $_POST['spotlight']);
    update_user_meta($user_id, 'backstage', $_POST['backstage']);
    update_user_meta($user_id, 'instagram', $_POST['instagram']);
    update_user_meta($user_id, 'tiktok', $_POST['tiktok']);
    update_user_meta($user_id, 'linkedin', $_POST['linkedin']);
    update_user_meta($user_id, 'youtube', $_POST['youtube']);
}
add_action('personal_options_update', 'save_social_links');
add_action('edit_user_profile_update', 'save_social_links');

function update_actor_list_json() {
    // 1. Get all users
    $users = get_users();
    $actor_list = array();

    foreach ($users as $user) {
        // Change 'tmdb' to the actual meta_key used in your database
        $tmdb_url = get_user_meta($user->ID, 'tmdb', true);

        if ($tmdb_url) {
            /**
             * Regex Explanation:
             * Matches the ID (digits) and the name (slug) from:
             * https://www.themoviedb.org/person/5081871-sid-edwards
             */
            if (preg_match('/person\/(\d+)-(.*)/', $tmdb_url, $matches)) {
                $tmdb_id = $matches[1];
                // Clean up the name: replace dashes with spaces and capitalize
                $actor_name = ucwords(str_replace('-', ' ', $matches[2]));

                $actor_list[$user->ID] = array(
                    "tmdb_id" => $tmdb_id,
                    "name"    => $actor_name
                );
            }
        }
    }

    // 2. Define the file path (saves to wp-content/uploads/actor_list.json)
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['basedir'] . '/actors_list.json';

    // 3. Encode and Save
    $json_data = json_encode($actor_list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    if (!empty($actor_list)) {
        file_put_contents($file_path, $json_data);
    }
}

// Optional: Trigger this function when a user profile is updated
add_action('profile_update', 'update_actor_list_json');// Register the shortcode

function increase_upload_size_for_admin($size) {
    if (current_user_can('administrator')) {
        return 100 * 1024 * 1024; // 100MB
    }
    return $size;
}
add_filter('upload_size_limit', 'increase_upload_size_for_admin');

function increase_post_max_size($bytes) {
    if (current_user_can('administrator')) {
        return 100 * 1024 * 1024; // 100MB
    }
    return $bytes;
}
add_filter('wp_max_upload_size', 'increase_post_max_size');

function enqueue_author_posts_script() {
    wp_enqueue_script('author-posts-js', get_stylesheet_directory_uri() . '/js/author-posts.js', array('jquery'), null, true);
    wp_localize_script('author-posts-js', 'ajax_params', array('ajax_url' => admin_url('admin-ajax.php')));
}
add_action('wp_enqueue_scripts', 'enqueue_author_posts_script');

// AJAX handler for loading author posts - UPDATED VERSION
add_action('wp_ajax_load_author_posts', 'load_author_posts_ajax');
add_action('wp_ajax_nopriv_load_author_posts', 'load_author_posts_ajax');

function load_author_posts_ajax() {
    // Check if this is for mode B with meta query
    $mode = isset($_GET['mode']) ? sanitize_text_field($_GET['mode']) : 'b';
    
    $authorId = isset($_GET['authorId']) ? intval($_GET['authorId']) : 1;
    $paged = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $posts_per_page = isset($_GET['posts_per_page']) ? intval($_GET['posts_per_page']) : 6;
    $title_text = 'Works<span id="page_no_top" class="page_no page_no_top">Page ' . $paged . '</span>';

    // Build query args based on mode
    $args = array(
        'post_type'      => 'post',
        'posts_per_page' => $posts_per_page,
        'post_status'    => 'publish',
        'author'         => $authorId,
        'paged'          => $paged,
        'category__not_in' => array(1)
    );

    // Add meta query for mode B (not pinned posts)
    if ($mode === 'b') {
        $args['meta_query'] = [
            'relation' => 'OR',
            [
                'key'     => '_is_pinned',
                'value'   => '1',
                'compare' => '!='
            ],
            [
                'key'     => '_is_pinned',
                'compare' => 'NOT EXISTS'
            ]
        ];
    }
    // Add meta query for mode A (pinned posts only)
    elseif ($mode === 'a') {
        $args['meta_query'] = [
            [
                'key'     => '_is_pinned',
                'value'   => '1',
                'compare' => '='
            ]
        ];
    }

    $query = new WP_Query($args);
    $max_pages = $query->max_num_pages;

    // Capture Grid Content
    ob_start();
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $descriptor = get_post_meta( get_the_ID(), '_pinned_tagline', true );
            $image = get_the_post_thumbnail_url(get_the_ID(), 'medium') ?: 'https://www.yendis.co.uk/dj/wp-content/uploads/2025/03/landscape-placeholder.svg';
            $post_tags = get_the_tag_list('<div class="post-tags"><span>Tags: </span>', ', ', '</div>');
            $date = get_the_date();

            echo '<div class="post-item fade-in">';
            echo '<a href="' . get_permalink() . '">';
            echo '<img src="' . esc_url($image) . '" alt="' . esc_attr(get_the_title()) . '">';
            echo '<div class="post-content">';
            echo '<h3>' . get_the_title() . '</h3>';
            echo '</div>';
            echo '</a>';
            if ( $descriptor ) {
                echo '<div class="work-descriptor">' . esc_html( $descriptor ) . '</div>';
            }
            echo '</div>';
        }
    } else {
        echo '<p>No posts found.</p>';
    }
    $grid_html = ob_get_clean();

    // Capture Pagination HTML
    ob_start();
    ?>
    <button class="prev-page pagination-btn" data-page="<?php echo max(1, $paged - 1); ?>" <?php echo ($paged <= 1) ? 'disabled' : ''; ?>>&larr; Previous</button>
    <span id="pagno" class="page_no" data-page="<?php echo $paged; ?>">Page <?php echo $paged; ?> of <?php echo $max_pages; ?></span>
    <button class="next-page pagination-btn" data-page="<?php echo $paged + 1; ?>" <?php echo ($paged >= $max_pages) ? 'disabled' : ''; ?>>Next &rarr;</button>
    <?php
    $pagination_html = ob_get_clean();

    // Return JSON response
    wp_send_json(array(
        'html'       => $grid_html,
        'pagination' => $pagination_html,
        'max_pages'  => $max_pages,
        'title'      => $title_text,
        'paged'      => $paged
    ));
}

function load_author_posts() {
    header('Content-Type: application/json'); // Ensure JSON response

    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $posts_per_page = 6; // Adjust as needed
    $args = array(
        'post_type'      => 'post',
        'posts_per_page' => $posts_per_page,
        'post_status'    => 'publish',
        'author'         => get_query_var('author'),
        'paged'          => $page,
        'category__not_in' => array(1) // Exclude Uncategorized
    );

    $query = new WP_Query($args);
    $max_pages = $query->max_num_pages;

    ob_start();
    if ($query->have_posts()) {
        echo '<div class="author-posts-grid">';
        while ($query->have_posts()) {
            $query->the_post();

            echo '<div class="post-item"><a href="' . the_permalink() . '">' . the_post_thumbnail('medium') . '<h2>' . the_title() . '</h2></a><p class="post-date">' . get_the_date() . '</p><div class="post-tags">' . the_tags('', ', ', '') . '</div></div>';
        }
        echo '</div>';
    } else {
        echo '<p>No posts found.</p>';
    }
    wp_reset_postdata();

    $html = ob_get_clean();

    // Ensure JSON encoding is error-free
    $response = array(
        'html' => $html,
        'max_pages' => $max_pages
    );

    echo json_encode($response);
    wp_die();
}

add_action('wp_ajax_load_author_posts', 'load_author_posts');
add_action('wp_ajax_nopriv_load_author_posts', 'load_author_posts');

function custom_author_page_title($title) {
//    if (is_author()) {
//        $author_name = get_the_author_meta('display_name');
//        return $author_name; // Removes "Author: " and returns just the name
//    }
//    return $title;
	$user_data = wp_update_user( array( 'ID' => 1, 'nationality' => '' ) );

	if ( is_wp_error( $user_data ) ) {
		// There was an error; possibly this user doesn't exist.
		echo 'Error.';
	} else {
		// Success!
		echo 'User profile updated.';
	}
}
//add_filter('pre_get_document_title', 'custom_author_page_title');

function modify_author_archive_title($title) {
    if (is_author()) {
        $author = get_queried_object();
        $author_name = $author->display_name; // Or $author->nickname, $author->user_nicename
        $tagline = do_shortcode('[author_tagline]');
        
        return "<h1>{$author_name}<p>{$tagline}</p></h1>";
    }
    return $title;
}
add_filter('get_the_archive_title', 'modify_author_archive_title', 10, 1);

add_action('wp_ajax_upload_profile_video', 'handle_upload_profile_video');
add_action('wp_ajax_nopriv_upload_profile_video', 'handle_upload_profile_video');

function handle_upload_profile_video() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . $user_id)) {
        wp_send_json_error('Security check failed');
    }
    
    // Check if file was uploaded
    if (!isset($_FILES['profile_video']) || $_FILES['profile_video']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error('No file uploaded or upload error');
    }
    
    // Check file type
    $file_type = wp_check_filetype($_FILES['profile_video']['name']);
    if ($file_type['ext'] !== 'mp4') {
        wp_send_json_error('Only MP4 files are allowed');
    }
    
    // Upload file
    $upload = wp_handle_upload($_FILES['profile_video'], array('test_form' => false));
    
    if (isset($upload['error'])) {
        wp_send_json_error($upload['error']);
    }
    
    // Create attachment post
    $attachment = array(
        'post_mime_type' => $upload['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_author'    => get_current_user_id()
    );
    
    $attach_id = wp_insert_attachment($attachment, $upload['file']);
    
    if (is_wp_error($attach_id)) {
        wp_send_json_error('Failed to save video to database');
    }
    
    // Generate metadata and update attachment
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
    wp_update_attachment_metadata($attach_id, $attach_data);
    
    // ==================== ADD TO CATEGORY ====================
    // Check if 'profile_media_cat' taxonomy exists and add to 'Gallery' category
    $taxonomy = 'profile_media_cat';
    $category_slug = 'gallery'; // or whatever slug your Gallery category has
    
    // Method 1: Using term slug (recommended)
    $term = get_term_by('slug', $category_slug, $taxonomy);
    
    // If term doesn't exist, create it
    if (!$term) {
        $term = wp_insert_term(
            'Gallery', // The term name
            $taxonomy, // The taxonomy
            array(
                'slug' => $category_slug,
                'description' => 'Gallery category for profile media'
            )
        );
        
        if (!is_wp_error($term)) {
            $term_id = $term['term_id'];
        }
    } else {
        $term_id = $term->term_id;
    }
    
    // Assign the term to the attachment
    if (isset($term_id)) {
        wp_set_object_terms($attach_id, $term_id, $taxonomy, false); // 'false' means don't append, replace existing
        
        // Alternative: Append to existing terms
        // wp_set_object_terms($attach_id, $term_id, $taxonomy, true);
    }
	
	// Store in user meta
    $user_id = get_current_user_id();
    $current_videos = get_user_meta($user_id, 'profile_videos', true);
    if (!is_array($current_videos)) {
        $current_videos = array();
    }
    $current_videos[] = $attach_id;
    update_user_meta($user_id, 'profile_videos', $current_videos);
    
    // Return success with video data
    wp_send_json_success(array(
        'id'    => $attach_id,
        'url'   => $upload['url'],
        'title' => $attachment['post_title']
    ));
}

// Add this function to output the modal once per page
function add_profile_gallery_modal() {
	$post = get_post();
	if (is_author() || ($post && has_shortcode($post->post_content, 'author_profile_image_gallery'))) {
        ?>
        <div id="profileGalleryModal" class="profile-gallery-modal" style="display: none;">
            <span class="modal-close">&times;</span>
            <button class="modal-nav modal-prev">&#10094;</button>
            <div class="modal-content">
                <img id="modalImage" src="" alt="">
                <div id="modalTitle" class="image-title"></div>
            </div>
            <button class="modal-nav modal-next">&#10095;</button>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Single modal for all galleries
            const $modal = $('#profileGalleryModal');
            const $modalImg = $('#modalImage');
            const $modalTitle = $('#modalTitle');
            
            let currentImages = [];
            let currentIndex = 0;
            
            // Click handler for all gallery images
            $('.gallery-link').on('click', function(e) {
                e.preventDefault();
                
                // Get all images in this gallery
                const $gallery = $(this).closest('.profile-gallery');
                currentImages = [];
                
                $gallery.find('.gallery-link').each(function(index) {
                    currentImages.push({
                        full: $(this).data('full'),
                        title: $(this).data('title') || ''
                    });
                    
                    // Find which image was clicked
                    if ($(this).is(e.currentTarget)) {
                        currentIndex = index;
                    }
                });
                
                // Show clicked image
                showImage(currentIndex);
                $modal.fadeIn();
            });
            
            // Navigation
            $('.modal-prev').on('click', function() {
                currentIndex = (currentIndex - 1 + currentImages.length) % currentImages.length;
                showImage(currentIndex);
            });
            
            $('.modal-next').on('click', function() {
                currentIndex = (currentIndex + 1) % currentImages.length;
                showImage(currentIndex);
            });
            
            // Close modal
            $('.modal-close, .profile-gallery-modal').on('click', function(e) {
                if (e.target === this || $(e.target).hasClass('modal-close')) {
                    $modal.fadeOut();
                }
            });
            
            // Keyboard navigation
            $(document).on('keydown', function(e) {
                if (!$modal.is(':visible')) return;
                
                if (e.key === 'Escape') {
                    $modal.fadeOut();
                } else if (e.key === 'ArrowLeft') {
                    $('.modal-prev').trigger('click');
                } else if (e.key === 'ArrowRight') {
                    $('.modal-next').trigger('click');
                }
            });
            
            function showImage(index) {
                const image = currentImages[index];
                $modalImg.attr('src', image.full);
                $modalTitle.text(image.title);
            }
        });
        </script>
        
        <style>
        .profile-gallery-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            justify-content: center;
            align-items: center;
        }
        
        .profile-gallery-modal .modal-content {
            max-width: 90%;
            max-height: 90%;
            text-align: center;
        }
        
        .profile-gallery-modal img {
            max-width: 100%;
            max-height: 70vh;
            object-fit: contain;
        }
        
        .modal-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 20px;
            font-size: 24px;
            cursor: pointer;
        }
        
        .modal-prev { left: 20px; }
        .modal-next { right: 20px; }
        
        .modal-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 40px;
            cursor: pointer;
        }
        
        .image-title {
            color: white;
            margin-top: 20px;
            font-size: 18px;
        }
        
        .profile-gallery {
            margin: 20px 0;
        }
        
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
        }
        
        .gallery-item img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
        }
        
        .gallery-link {
            display: block;
            transition: transform 0.3s;
        }
        
        .gallery-link:hover {
            transform: scale(1.05);
        }
        </style>
        <?php
    }
}
add_action('wp_footer', 'add_profile_gallery_modal');

// Function to ensure all attachments have menu_order set
function init_attachment_menu_orders() {
    // Run this once to initialize menu_order for existing attachments
    if (get_option('attachment_menu_orders_initialized') !== '1') {
        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_status' => 'inherit'
        ));
        
        foreach ($attachments as $index => $attachment) {
            if ($attachment->menu_order == 0) {
                wp_update_post(array(
                    'ID' => $attachment->ID,
                    'menu_order' => $index + 1
                ));
            }
        }
        
        update_option('attachment_menu_orders_initialized', '1');
    }
}
add_action('init', 'init_attachment_menu_orders');

// Function to get ordered images by category
function get_ordered_profile_images($user_id, $category, $limit = 0) {
    $args = array(
        'post_type' => 'attachment',
        'posts_per_page' => $limit > 0 ? $limit : -1,
        'author' => $user_id,
        'post_status' => 'inherit',
        'post_mime_type' => 'image',
        'orderby' => 'menu_order',
        'order' => 'ASC',
        'tax_query' => array(
            array(
                'taxonomy' => 'profile_media_cat',
                'field' => 'slug',
                'terms' => $category,
            ),
        ),
    );
    
    $query = new WP_Query($args);
    $images = array();
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $images[] = array(
                'id' => get_the_ID(),
                'url' => wp_get_attachment_url(get_the_ID()),
                'thumb' => wp_get_attachment_image_src(get_the_ID(), 'medium'),
                'title' => get_the_title(),
                'menu_order' => get_post_field('menu_order', get_the_ID())
            );
        }
        wp_reset_postdata();
    }
    
    return $images;
}

// Enqueue JavaScript file properly
function profile_gallery_enqueue_scripts() {
	if ( is_author() ) {
		wp_register_script('profile-gallery-script', get_stylesheet_directory_uri() . '/js/profile-gallery.js', array('jquery'), null, true);
		wp_enqueue_script('profile-gallery-script');

		// Localize AFTER enqueueing
		global $gallery_image_urls;
		if (!empty($gallery_image_urls)) {
			wp_localize_script('profile-gallery-script', 'galleryData', array('images' => $gallery_image_urls));
		}

		wp_register_script('profile-hands-script', get_stylesheet_directory_uri() . '/js/profile-hands.js', array('jquery'), null, true);
		wp_enqueue_script('profile-hands-script');

		// Localize AFTER enqueueing
		global $hands_image_urls;
		if (!empty($hands_image_urls)) {
			wp_localize_script('profile-hands-script', 'handsData', array('images' => $hands_image_urls));
		}

		wp_register_script('profile-vehicle-script', get_stylesheet_directory_uri() . '/js/profile-vehicle.js', array('jquery'), null, true);
		wp_enqueue_script('profile-vehicle-script');

		// Localize AFTER enqueueing
		global $vehicle_image_urls;
		if (!empty($vehicle_image_urls)) {
			wp_localize_script('profile-vehicle-script', 'vehicleData', array('images' => $vehicle_image_urls));
		}

		wp_register_script('profile-other-script', get_stylesheet_directory_uri() . '/js/profile-other.js', array('jquery'), null, true);
		wp_enqueue_script('profile-other-script');

		// Localize AFTER enqueueing
		global $other_image_urls;
		if (!empty($other_image_urls)) {
			wp_localize_script('profile-other-script', 'otherData', array('images' => $other_image_urls));
		}
	}
}
add_action('wp_enqueue_scripts', 'profile_gallery_enqueue_scripts');

function enqueue_unified_gallery_script() {
    if (is_author()) {
        // First, gather all image data from your global variables
        $gallery_data = array();
        
        // Collect gallery images
        global $gallery_image_urls;
        if (!empty($gallery_image_urls)) {
            $gallery_data['gallery'] = $gallery_image_urls;
        }
        
        // Collect hands images
        global $hands_image_urls;
        if (!empty($hands_image_urls)) {
            $gallery_data['hands'] = $hands_image_urls;
        }
        
        // Collect vehicle images
        global $vehicle_image_urls;
        if (!empty($vehicle_image_urls)) {
            $gallery_data['vehicle'] = $vehicle_image_urls;
        }
        
        // Collect other images
        global $other_image_urls;
        if (!empty($other_image_urls)) {
            $gallery_data['other'] = $other_image_urls;
        }
        
        // Collect profile images (you might need to create this global variable)
        global $profile_image_urls;
        if (!empty($profile_image_urls)) {
            $gallery_data['profile'] = $profile_image_urls;
        }
        
        // Register and enqueue the unified script
        wp_register_script(
            'unified-gallery-script',
            get_stylesheet_directory_uri() . '/js/unified-gallery.js',
            array('jquery'),
            '1.0.0',
            true
        );
        
        // Enqueue the script FIRST
        wp_enqueue_script('unified-gallery-script');
        
        // THEN localize it with all the gallery data
        wp_localize_script(
            'unified-gallery-script',
            'unifiedGalleryData',
            array(
                'galleries' => $gallery_data,
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('gallery_nonce')
            )
        );
        
		// Optionally, dequeue old scripts to prevent conflicts
		wp_dequeue_script('profile-gallery-script');
		wp_dequeue_script('profile-hands-script');
		wp_dequeue_script('profile-vehicle-script');
		wp_dequeue_script('profile-other-script');
    }
}
add_action('wp_enqueue_scripts', 'enqueue_unified_gallery_script', 20); // Higher priority to run after old function

function profile_media_cat_meta_callback($post) {
    $terms = get_terms(array(
        'taxonomy' => 'profile_media_cat',
        'hide_empty' => false,
    ));

    $selected_terms = wp_get_object_terms($post->ID, 'profile_media_cat', array('fields' => 'ids'));

    echo '<select name="profile_media_cat[]" multiple="multiple" style="width:100%;">';
    foreach ($terms as $term) {
        $selected = in_array($term->term_id, $selected_terms) ? 'selected' : '';
        echo '<option value="' . esc_attr($term->term_id) . '" ' . $selected . '>' . esc_html($term->name) . '</option>';
    }
    echo '</select>';
}

function save_profile_media_category($post_id) {
    if (get_post_type($post_id) !== 'attachment') {
        return;
    }

    if (isset($_POST['profile_media_cat'])) {
        $selected_cats = array_map('intval', $_POST['profile_media_cat']);
        wp_set_object_terms($post_id, $selected_cats, 'profile_media_cat', false);
    }
}
add_action('edit_attachment', 'save_profile_media_category');

function profile_media_cat_single_selection_script() {
    global $pagenow;
    if ($pagenow === 'post.php' || $pagenow === 'upload.php') {
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                let checkboxes = document.querySelectorAll("#profile_media_catdiv input[type=checkbox]");
                checkboxes.forEach(function(checkbox) {
                    checkbox.addEventListener("change", function() {
                        if (this.checked) {
                            checkboxes.forEach(cb => {
                                if (cb !== this) cb.checked = false;
                            });
                        }
                    });
                });
            });
        </script>';
    }
}
add_action('admin_footer', 'profile_media_cat_single_selection_script');

function enqueue_profile_modal_script() {
	if ( is_author() ) {
		wp_enqueue_script('profile-modal', get_stylesheet_directory_uri() . '/js/profile-modal.js', array(), false, true);
	}
}
add_action('wp_enqueue_scripts', 'enqueue_profile_modal_script');


function custom_contributor_capabilities() {
    // Get the Contributor role
    $contributor = get_role('contributor');
    
    // Allow editing their own posts, including published ones
    $contributor->add_cap('edit_posts');
    $contributor->add_cap('edit_published_posts');
    $contributor->add_cap('delete_posts');
    $contributor->add_cap('upload_files');
    
    // Remove the ability to publish or delete others' posts
    $contributor->remove_cap('publish_posts');
    $contributor->remove_cap('delete_published_posts');
    
    // Allow editing of only their own posts, but not others
    $contributor->remove_cap('edit_others_posts');
}
add_action('init', 'custom_contributor_capabilities');

// customize the URL being shared by the social share icons
function heateor_sss_customize_shared_url( $postUrl, $sharingType, $standardWidget ) {
    return esc_url_raw( $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"] );
}
add_filter( 'heateor_sss_target_share_url_filter', 'heateor_sss_customize_shared_url', 10, 3 );

function enqueue_category_description_script() {
    if (is_category(95)) {
        wp_enqueue_script(
            'category-description-script',
            get_stylesheet_directory_uri() . '/category-description.js',
            array(),
            null,
            true
        );
        
        wp_localize_script('category-description-script', 'categoryData', array(
            'description' => category_description()
        ));
    }
}
add_action('wp_enqueue_scripts', 'enqueue_category_description_script');


function custom_footer_css() {
    if ( isset($_GET['popup']) && $_GET['popup'] == 'true' ) {
        echo '<style>body { background: linear-gradient(180deg, var(--wp--preset--color--base) 0 min(24rem, 0%), var(--wp--preset--color--secondary) 0% 30%, var(--wp--preset--color--base) 100%); --wp--style--root--padding-top: 0; } footer { display: none !important; }.wp-block-template-part .wp-block-group.has-global-padding.is-layout-constrained.wp-block-group-is-layout-constrained { display: none; }</style>';
    }
}
add_action('wp_head', 'custom_footer_css');

function use_author_link_as_comment_author_url( $url, $id, $comment ) {
    if ( $comment->user_id ) {
        return get_author_posts_url( $comment->user_id );
    }
    return $url;
}
add_filter( 'get_comment_author_url', 'use_author_link_as_comment_author_url', 10, 3 );

function player_script($filename)
{
    
    echo '<script>
                var myCirclePlayer' . $filename . ' = new CirclePlayer("#jquery_jplayer_' . $filename . '",
    {
        m4a: "https://www.yendis.co.uk/Player/' . $filename . '.m4a",
        oga: "https://www.yendis.co.uk/Player/' . $filename . '.ogg"
    }, {
        cssSelectorAncestor: "#' . $filename . '"
    });
</script>';
}

/* Enqueue Styles */
if ( ! function_exists('thr_enqueue_styles') ) {
    function thr_enqueue_styles() {
        wp_enqueue_style( 'twenty-twenty-three-style-child', get_stylesheet_directory_uri() .'/style.css' );
		wp_add_inline_style('twenty-twenty-three-style-child', '
		.post-type-platform-voice .post-title a:hover {
			color: var(--wp--preset--color--primary) !important;
		}
		.post-type-pinned .post-title a:hover {
			color: var(--wp--preset--color--secondary) !important;
		}
		.post-type-featured .post-title a:hover {
			color: var(--wp--preset--color--tertiary) !important;
		}
		');
   }
    add_action('wp_enqueue_scripts', 'thr_enqueue_styles');
}

function fb_opengraph() {
	{
		global $post, $product, $wp, $user;
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		$first_img = $term = $coverdata = '';
		$share_url = get_permalink(); 
		if ( isset( wp_get_attachment_image_src( get_post_thumbnail_id(), 'share-image', false )[0] )) {
			$first_img = wp_get_attachment_image_src( get_post_thumbnail_id(), 'share-image', false )[0];
		}
		
		$excerpt = get_bloginfo('description') . ' &bull; .';
		$is_profile_page = '';
		$url = '';
		$is_profile = '';
		$pagename = '';
		if ( isset($wp->query_vars['pagename']) ) {
			if ( $wp->query_vars['pagename'] == 'profile' ) {
				$pagename = 'profile';
			}
		}
		if (is_author()) {
			$author_id = get_the_author_meta('ID');
			$image_id = get_user_meta($author_id, 'profile_featured_image', true);
			if ($image_id) {
				$first_img = wp_get_attachment_url($image_id);;
			}
			$share_url = esc_url( get_author_posts_url( $author_id ) ) .'?' . current_time('timestamp');
		}
		?>
<meta http-equiv="Permissions-Policy" content="interest-cohort=()">
<meta name="google-site-verification" content="MNtPYkFI08tihhvYtY98CbdNx2ei5TJzDciSMkAJtRk" />
<!--<link href="https://fonts.cdnfonts.com/css/calistoga-2" rel="stylesheet">-->
<link rel="stylesheet"
          href="https://fonts.googleapis.com/css?family=Calistoga">
<meta property="fb:app_id" content="142961422420587"/>
		<?php
		$width = $height = '';
		if ( $first_img ) 
		{
			$img_src = $first_img;
			if (@getimagesize($img_src))
			list($width, $height, $type, $attr) = getimagesize($img_src);
		}
		else 
		{
			$img_src = 'https://www.yendis.co.uk/dj/wp-content/uploads/2023/10/yendis-fw-1.png';
			if (@getimagesize($img_src))
			list($width, $height, $type, $attr) = getimagesize($img_src);
		}
		
		if ( is_page() )
		{
			$excerpt = get_the_excerpt();
			$title = get_the_title() . ' &#x40; ' . get_bloginfo();
		}
		elseif (is_author()) {
			$title = get_the_author() . ' @ ' . get_bloginfo('site_name');			
			$excerpt = get_user_meta($author_id, 'description', true);
		}
		else
		{
			$excerpt = get_the_excerpt(); 
			$excerpt = substr( $excerpt, 0, 260 ); // Only display first 260 characters of excerpt
			$result = substr( $excerpt, 0, strrpos( $excerpt, ' ' ) );

			if ( !$result ) {
				$excerpt = !empty($post->post_content) ? substr(strip_tags(apply_filters('the_excerpt', $post->post_content)), 0, 200) : get_bloginfo ( 'description' );
			}
			//$excerpt = !empty($post->post_content) ? substr(strip_tags(apply_filters('the_excerpt', $post->post_content)), 0, 200) : get_bloginfo ( 'description' );
			$title = get_the_title();
		}
		$excerpt = $excerpt != '' ? $excerpt : 'Here And Now';
		?>
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="manifest" href="/site.webmanifest">
<link rel="mask-icon" href="/safari-pinned-tab.svg" color="#5bbad5">
<meta name="msapplication-TileColor" content="#da532c">
<meta name="theme-color" content="#ffffff">
<meta property="og:title" content="<?php echo $title; ?>"/>
<meta property="og:description" content="<?php echo $excerpt; ?>"/>
<meta property="og:type" content="article"/>
<meta property="og:url" content="<?php echo $share_url; ?>"/>
<meta property="og:site_name" content="<?php echo get_bloginfo(); ?>"/>
<meta property="og:image" content="<?php echo $img_src; ?>"/>
<meta property="og:image:width" content="<?php echo $width; ?>" />
<meta property="og:image:height" content="<?php echo $height; ?>" />
<meta property="og:locale" content="en_GB" />
<meta name="twitter:site" content="@djyendis" />
<meta name='twitter:creator' content='@djyendis' />
<meta name="twitter:image" content="<?php echo $img_src; ?>" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="<?php echo $title; ?>" />
<meta name="twitter:description" content="<?php echo $excerpt; ?>" />
		
<meta itemprop="name" content="<?php echo $title; ?>"/>
<meta itemprop="url" content="<?php echo $share_url; ?>"/>
<meta itemprop="description" content="<?php echo $excerpt; ?>"/>
<meta itemprop="thumbnailUrl" content="<?php echo $img_src; ?>"/>
<meta itemprop="image" content="<?php echo $img_src; ?>"/>
<meta itemprop="headline" content="<?php echo $title; ?>"/>
<meta itemprop="publisher" content="<?php echo get_bloginfo(); ?>"/>
<script type="application/ld+json">{"url":"<?php echo home_url(); ?>","name":"<?php echo get_bloginfo(); ?>","description":"<?php echo get_bloginfo( 'description'); ?> â€¢ ","image":"https://www.yendis.co.uk/dj/wp-content/uploads/2023/10/yendis-1.svg","@context":"http://schema.org","@type":"WebSite"}</script>
<script type="application/ld+json">{"address":"Birmingham, UK","name":"<?php echo get_bloginfo(); ?>","image":"https://www.yendis.co.uk/dj/wp-content/uploads/2023/10/yendis-1.svg","@context":"http://schema.org","@type":"LocalBusiness"}</script>

<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-8508580632576058"
     crossorigin="anonymous"></script>
<!--Global site tag (gtag.js) - Google Analytics-->
<!--<script async src="https://www.googletagmanager.com/gtag/js?id=G-4HVKVX0473"></script>-->
<!--<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-4HVKVX0473');
</script> -->
<?php
	}
}
add_action('wp_head', 'fb_opengraph', 7);

function the_slug_exists($post_name) {
    global $wpdb;
    if($wpdb->get_row("SELECT post_name FROM wp_posts WHERE post_name = '" . $post_name . "'", 'ARRAY_A')) {
        return true;
    } else {
        return false;
    }
}

function custom_document_title_separator( $sep ) {
    $sep = ' &#x40; '; // Replace ' | ' with your desired separator
    return $sep;
}
add_filter( 'document_title_separator', 'custom_document_title_separator' );

function cutString($str, $amount = 1, $dir = "right")
{
  if(($n = strlen($str)) > 0)
  {
    if($dir == "right")
    {
      $start = 0;
      $end = $n-$amount;
    } elseif( $dir == "left") {
      $start = $amount;
      $end = $n;
    }
   
    return substr($str, $start, $end);
  } else return false;
}

//add_filter('the_content','append_this');
function append_this($content)
{
	if ( in_category( 'wording' ) || in_category( 'sounding' ) ) {
		$content = $content . '<script async src="//pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script> <!-- Column --> <ins class="adsbygoogle" style="display: block;" data-ad-client="ca-pub-7604240402870701" data-ad-slot="1228721473" data-ad-format="auto"></ins> <script>
(adsbygoogle = window.adsbygoogle || []).push({});
</script>';
	}
return $content;

}

add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script('child-js', get_stylesheet_directory_uri() . '/child.js', [
        'jquery'
    ], null, true);
	if ( is_front_page() ) {
		wp_enqueue_script('fullscreen-js', get_stylesheet_directory_uri() . '/js/fullscreen-slider.js', [
			'jquery'
		], null, true);
	}
});