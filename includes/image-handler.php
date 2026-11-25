<?php

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}


// Count images in the Media Library that are missing alt text.
function alt_generator_count_missing_images() {

    global $wpdb;

    // Count image attachments where alt text is missing or empty.
    $count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} AS p
             WHERE p.post_type = %s
               AND p.post_mime_type LIKE %s
               AND NOT EXISTS (
                   SELECT 1
                   FROM {$wpdb->postmeta} AS pm
                   WHERE pm.post_id = p.ID
                     AND pm.meta_key = %s
                     AND TRIM(pm.meta_value) <> ''
               )",
            'attachment',
            'image/%',
            '_wp_attachment_image_alt'
        )
    );

    return (int) $count;
}

// Retrieve the newest image missing alt text.
function alt_generator_get_next_image() {

    global $wpdb;

    // Find the newest image without alt text.
    $image_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} AS p
             WHERE p.post_type = %s
               AND p.post_mime_type LIKE %s
               AND NOT EXISTS (
                   SELECT 1
                   FROM {$wpdb->postmeta} AS pm
                   WHERE pm.post_id = p.ID
                     AND pm.meta_key = %s
                     AND TRIM(pm.meta_value) <> ''
               )
             ORDER BY p.post_date DESC, p.ID DESC
             LIMIT 1",
            'attachment',
            'image/%',
            '_wp_attachment_image_alt'
        )
    );

    if (!$image_id) {
        return false;
    }

    return array(
        'id'       => (int) $image_id,
        'filename' => basename(get_attached_file($image_id)),
        'url'      => wp_get_attachment_url($image_id)
    );
}

// Save generated alt text to a WordPress image.
function alt_generator_save_image_alt($image_id, $description) {

    // Ensure the attachment ID is valid.
    $image_id = absint($image_id);

    if (!$image_id || !wp_attachment_is_image($image_id)) {
        return new WP_Error(
            'invalid_image',
            'The selected image is invalid.'
        );
    }

    // Check whether the image already has alt text.
    $existing_alt = get_post_meta(
        $image_id,
        '_wp_attachment_image_alt',
        true
    );

    if (trim((string) $existing_alt) !== '') {
        return new WP_Error(
            'alt_exists',
            'This image already has alt text. Existing descriptions will not be overwritten.'
        );
    }

    // Clean the generated description.
    $description = sanitize_text_field($description);

    if ($description === '') {
        return new WP_Error(
            'empty_description',
            'Cannot save an empty description.'
        );
    }

    // Save the description to WordPress.
    $saved = update_post_meta(
        $image_id,
        '_wp_attachment_image_alt',
        $description
    );

    if ($saved === false) {
        return new WP_Error(
            'save_failed',
            'WordPress could not save the image description.'
        );
    }

    return true;
}

// Retrieve all image IDs missing alt text, newest first.
function alt_generator_get_missing_image_ids() {

    global $wpdb;

    // Find image attachments without alt text.
    $image_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} AS p
             WHERE p.post_type = %s
               AND p.post_mime_type LIKE %s
               AND NOT EXISTS (
                   SELECT 1
                   FROM {$wpdb->postmeta} AS pm
                   WHERE pm.post_id = p.ID
                     AND pm.meta_key = %s
                     AND TRIM(pm.meta_value) <> ''
               )
             ORDER BY p.post_date DESC, p.ID DESC",
            'attachment',
            'image/%',
            '_wp_attachment_image_alt'
        )
    );

    // Convert the returned IDs into integers.
    return array_map('intval', $image_ids);
}

// Prepare images for bulk processing.
function alt_generator_prepare_queue($limit = 'all') {

    // Get all images missing alt text, newest first.
    $image_ids = alt_generator_get_missing_image_ids();

    // Return every image if "all" is selected.
    if ($limit === 'all') {
        return $image_ids;
    }

    // Convert the limit to a positive integer.
    $limit = absint($limit);

    // Return an empty queue if the limit is invalid.
    if ($limit < 1) {
        return array();
    }

    // Return only the requested number of images.
    return array_slice($image_ids, 0, $limit);
}

// Generate and automatically save alt text for one image.
function alt_generator_process_image($image_id) {

    // Ensure the image ID is valid.
    $image_id = absint($image_id);

    if (!$image_id || !wp_attachment_is_image($image_id)) {
        return new WP_Error(
            'invalid_image',
            'The selected image is invalid.'
        );
    }

    // Check whether the image already has alt text.
    $existing_alt = get_post_meta(
        $image_id,
        '_wp_attachment_image_alt',
        true
    );

    // Never overwrite existing alt text.
    if (trim((string) $existing_alt) !== '') {
        return new WP_Error(
            'alt_exists',
            'This image already has alt text.'
        );
    }

    // Claim this particular image across ALL administrators. add_option is
    // atomic at the database level because option_name is unique.
    $lock_key = 'alt_generator_image_lock_' . $image_id;
    $existing_lock = get_option($lock_key, false);
    if (is_array($existing_lock) && isset($existing_lock['created_at']) &&
        time() - (int) $existing_lock['created_at'] > 180) {
        // Compare the stored value before clearing an abandoned lock.
        if (get_option($lock_key, false) === $existing_lock) {
            delete_option($lock_key);
        }
    }

    $token = wp_generate_uuid4();
    if (!add_option($lock_key, array('token' => $token, 'created_at' => time()), '', false)) {
        return new WP_Error(
            'image_busy',
            'Another administrator is processing this image. Try resuming shortly.'
        );
    }

    try {
        // Recheck after acquiring the lock to avoid a stale initial read.
        if (trim((string) get_post_meta($image_id, '_wp_attachment_image_alt', true)) !== '') {
            return new WP_Error('alt_exists', 'This image already has alt text.');
        }

        $description = alt_generator_generate_description($image_id);
        if (is_wp_error($description)) {
            return $description;
        }

        $saved = alt_generator_save_image_alt($image_id, $description);
        if (is_wp_error($saved)) {
            return $saved;
        }

        return array(
            'success'     => true,
            'image_id'    => $image_id,
            'description' => $description
        );
    } finally {
        $current_lock = get_option($lock_key, false);
        if (is_array($current_lock) && isset($current_lock['token']) &&
            $current_lock['token'] === $token) {
            delete_option($lock_key);
        }
    }
}
