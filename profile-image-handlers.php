<?php
/**
 * File: inc/ajax/profile-image-handlers.php
 * Description: All AJAX handlers for profile image management
 * 
 * Includes: category updates, order updates, debug functions, utility helpers
 */

defined('ABSPATH') || exit;

/**
 * ============================================
 * MAIN HANDLERS
 * ============================================
 */

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
add_action('wp_ajax_debug_specific', 'debug_specific_attachments');

// Make sure this is added AFTER the function definition
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
// Check image usage before deletion
add_action('wp_ajax_check_image_usage', 'check_image_usage_handler');
add_action('wp_ajax_nopriv_check_image_usage', 'check_image_usage_auth_check');

// Replace image in all uses
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
add_action('wp_ajax_replace_image_in_all_uses', 'replace_image_in_all_uses_handler');

// Check if image is used as a featured image
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
add_action('wp_ajax_check_featured_image_usage', 'check_featured_image_usage_handler');

// Batch check for featured images
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
add_action('wp_ajax_batch_check_featured_images', 'batch_check_featured_images_handler');

// Register AJAX handlers for both logged-in and non-logged-in users if needed
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
add_action('wp_ajax_set_video_featured_image', 'handle_set_video_featured_image');
//add_action('wp_ajax_nopriv_set_video_featured_image', 'handle_set_video_featured_image_auth_check');

// AJAX handler for video slot HTML (returns full HTML for a slot)
function get_video_slot_html() {
    $slot = intval($_POST['slot'] ?? 1);
    $video_data = json_decode(stripslashes($_POST['video_data'] ?? '{}'), true);
    
    ob_start();
    include 'video-slot-template.php'; // Template file for video slot
    $html = ob_get_clean();
    
    wp_send_json_success(['html' => $html]);
}
add_action('wp_ajax_get_video_slot_html', 'get_video_slot_html');

// AJAX handler for video data auto-save
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
add_action('wp_ajax_save_video_data', 'handle_save_video_data');
add_action('wp_ajax_nopriv_save_video_data', 'handle_save_video_data');

// AJAX handler for video uploads
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
add_action('wp_ajax_upload_profile_videos', 'handle_ajax_video_upload');
add_action('wp_ajax_nopriv_upload_profile_videos', 'handle_ajax_video_upload');

function test_ajax_connection_handler() {
    // Simple test that returns success
    wp_send_json_success(array(
        'message' => 'AJAX connection working',
        'timestamp' => time(),
        'nonce_valid' => wp_verify_nonce($_POST['_ajax_nonce'], 'update_profile_' . get_current_user_id())
    ));
}
add_action('wp_ajax_test_ajax_connection', 'test_ajax_connection_handler');
add_action('wp_ajax_check_upload_limits', 'check_upload_limits_handler');

// Add this to your AJAX handlers
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
add_action('wp_ajax_upload_profile_images', 'handle_profile_image_upload');
add_action('wp_ajax_nopriv_upload_profile_images', 'handle_profile_image_upload');

// Add test endpoint in PHP
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
add_action('wp_ajax_test_nonce_verification', 'handle_test_nonce_verification');
	
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
// Remove nopriv unless you want non-logged-in users to save (probably not)
// add_action('wp_ajax_nopriv_save_profile_field', 'handle_profile_auto_save');
// add_action('wp_ajax_nopriv_save_profile_field', 'handle_profile_auto_save');
// add_action('wp_ajax_save_profile_field', 'handle_auto_save_profile_field');
add_action('wp_ajax_save_profile_field', 'handle_profile_auto_save');

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

// Make sure these are added to functions.php
add_action('wp_ajax_set_profile_picture', 'handle_set_profile_picture');
add_action('wp_ajax_delete_profile_image', 'handle_delete_profile_image');
//add_action('wp_ajax_delete_profile_audio', 'handle_delete_profile_audio');
add_action('wp_ajax_delete_profile_video', 'handle_delete_profile_video');
//add_action('wp_ajax_nopriv_delete_profile_video', 'handle_delete_profile_video');

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

// Handle video deletion via AJAX

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

// Add AJAX handler for avatar refresh
add_action('wp_ajax_refresh_avatar_cache', 'handle_refresh_avatar_cache');
function handle_refresh_avatar_cache() {
    $user_id = get_current_user_id();
    clean_user_cache($user_id);
    wp_send_json_success();
}

add_action('wp_ajax_dismiss_glance_reminder', function () {
    check_ajax_referer('dismiss_glance', 'nonce');
    update_user_meta(get_current_user_id(), '_glance_reminder_dismissed', time());
    wp_die();
});
// AJAX handler for loading author posts - UPDATED VERSION
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
add_action('wp_ajax_load_author_posts', 'load_author_posts_ajax');
add_action('wp_ajax_nopriv_load_author_posts', 'load_author_posts_ajax');

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
add_action('wp_ajax_upload_profile_video', 'handle_upload_profile_video');
add_action('wp_ajax_nopriv_upload_profile_video', 'handle_upload_profile_video');

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
