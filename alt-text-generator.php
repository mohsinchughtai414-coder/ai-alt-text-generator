<?php
/**
* Plugin Name: AI Alt Text Generator
* Description: Generate image descriptions for WordPress images missing alt text.
* Version: 1.0.0
* Author: Mohsin Chughtai
*/

// Prevent direct access to this file.
if (!defined('ABSPATH')) {
    exit;
}


// Load the plugin's PHP files.
require_once plugin_dir_path(__FILE__) . 'includes/image-handler.php';

require_once plugin_dir_path(__FILE__) . 'includes/claude-api.php';

require_once plugin_dir_path(__FILE__) . 'admin/admin-page.php';


// Register the plugin under Media in WordPress.
function alt_generator_register_menu() {

    add_media_page(
        'Alt Text Generator',
        'Alt Text Generator',
        'manage_options',
        'alt-text-generator',
        'alt_generator_admin_page'
    );

    add_submenu_page(
        'upload.php', 'Manual Generation', 'Manual Generation',
        'manage_options', 'alt-text-generator-manual', 'alt_generator_manual_page'
    );

    add_submenu_page(
        'upload.php', 'Bulk Generation', 'Bulk Generation',
        'manage_options', 'alt-text-generator-bulk', 'alt_generator_bulk_page'
    );

}

// Register the WordPress admin menu callback.
add_action('admin_menu', 'alt_generator_register_menu');

// Handle saving the Anthropic API key.
function alt_generator_save_api_key() {

    // Only authorised administrators can save settings.
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to change these settings.');
    }

    // Verify the settings form security nonce.
    check_admin_referer('alt_generator_save_settings');

    // Check that an API key was submitted.
    if (isset($_POST['anthropic_api_key'])) {

        $api_key = sanitize_text_field(
            wp_unslash($_POST['anthropic_api_key'])
        );

        // Only update the key if the submitted value is not empty.
        if (!empty($api_key)) {
            update_option('alt_generator_anthropic_api_key', $api_key, false);
        }
    }

    // Return to the plugin page.
    wp_safe_redirect(
        add_query_arg(
            array(
                'page' => 'alt-text-generator',
                'settings-updated' => 'true'
            ),
            admin_url('upload.php')
        )
    );

    exit;
}

// Register the settings form handler.
add_action(
    'admin_post_alt_generator_save_settings',
    'alt_generator_save_api_key'
);

// Handle requests to generate a description.
function alt_generator_handle_generate() {

    // Check administrator permissions.
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to generate descriptions.');
    }

    // Verify the security nonce.
    check_admin_referer('alt_generator_generate');

    // Retrieve the newest image missing alt text.
    $image = alt_generator_get_next_image();

    if (!$image) {

        $result = array(
            'success' => false,
            'message' => 'No images missing alt text were found.'
        );

    } else {

        // Send the selected image to Claude.
        $description = alt_generator_generate_description($image['id']);

        if (is_wp_error($description)) {

            $result = array(
                'success' => false,
                'message' => $description->get_error_message()
            );

        } else {

            $result = array(
                'success' => true,
                'image_id' => $image['id'],
                'description' => $description
            );

        }
    }

    // Temporarily store the result for the current administrator.
    // This is not saved as image alt text.
    set_transient(
        'alt_generator_result_' . get_current_user_id(),
        $result,
        5 * MINUTE_IN_SECONDS
    );

    // Return to the plugin page.
    wp_safe_redirect(
        admin_url('upload.php?page=alt-text-generator-manual')
    );

    exit;
}

// Register the Generate Description form handler.
add_action(
    'admin_post_alt_generator_generate',
    'alt_generator_handle_generate'
);

// Handle saving a generated description.
function alt_generator_handle_save_description() {

    // Check administrator permissions.
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to save descriptions.');
    }

    // Verify the security nonce.
    check_admin_referer('alt_generator_save_description');

    // Retrieve the generated description from temporary storage.
    $result_key = 'alt_generator_result_' . get_current_user_id();

    $result = get_transient($result_key);

    if (
        !$result ||
        empty($result['success']) ||
        empty($result['image_id']) ||
        empty($result['description'])
    ) {
        wp_die('No generated description is available to save.');
    }

    // Save the generated description to WordPress.
    $saved = alt_generator_save_image_alt(
        $result['image_id'],
        $result['description']
    );

    if (is_wp_error($saved)) {

        $result['save_error'] = $saved->get_error_message();

        set_transient(
            $result_key,
            $result,
            5 * MINUTE_IN_SECONDS
        );

    } else {

        // Remove the temporary description after saving successfully.
        delete_transient($result_key);

        set_transient(
            'alt_generator_save_success_' . get_current_user_id(),
            'Image alt text saved successfully.',
            60
        );
    }

    // Return to the plugin page.
    wp_safe_redirect(
        admin_url('upload.php?page=alt-text-generator-manual')
    );

    exit;
}

// Register the Save Description handler.
add_action(
    'admin_post_alt_generator_save_description',
    'alt_generator_handle_save_description'
);

// A lock prevents two requests from generating alt text for the same image.
// Locks older than three minutes can be cleared after an interrupted request.
function alt_generator_bulk_lock_active($user_id, $allow_stale_cleanup = true) {
    $lock_key = 'alt_generator_lock_' . absint($user_id);
    $lock = get_option($lock_key, false);
    if ($lock === false) {
        return false;
    }

    if (!$allow_stale_cleanup) {
        return true;
    }

    // New-format locks have their own timestamp.
    if (is_array($lock) && isset($lock['created_at'])) {
        if (time() - (int) $lock['created_at'] > 180) {
            delete_option($lock_key);
            return false;
        }
        return true;
    }

    // Old-format locks were plain tokens without timestamps. Only discard
    // these when the saved session has also been inactive for three minutes.
    $job = get_option('alt_generator_bulk_' . absint($user_id), false);
    if (is_array($job) && isset($job['updated_at']) &&
        time() - (int) $job['updated_at'] > 180) {
        delete_option($lock_key);
        return false;
    }

    return true;
}

// Start a bulk alt text generation session.
function alt_generator_bulk_start() {

    // Verify administrator permissions.
    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            array('message' => 'Permission denied.'),
            403
        );
    }

    // Verify the AJAX security nonce.
    check_ajax_referer('alt_generator_bulk', 'nonce');

    // Check the API key is configured.
    if (!get_option('alt_generator_anthropic_api_key')) {
        wp_send_json_error(
            array('message' => 'Configure your API key first.'),
            400
        );
    }

    // Get the number of images requested.
    $limit = isset($_POST['limit'])
    ? sanitize_text_field(wp_unslash($_POST['limit']))
    : 'all';

    // Prepare the processing queue.
    $queue = alt_generator_prepare_queue($limit);

    // Store the processing queue persistently for this administrator.
    $queue_key = 'alt_generator_bulk_' . get_current_user_id();

    // Check whether an unfinished processing session already exists.
    $existing_job = get_option($queue_key, false);

    if (
        is_array($existing_job) &&
        isset($existing_job['ids'], $existing_job['index']) &&
        $existing_job['index'] < count($existing_job['ids'])
    ) {

        wp_send_json_error(
            array(
                'message' => 'An unfinished processing session already exists. Resume the existing session before starting a new one.',
                'error_code' => 'unfinished_session'
            ),
            409
        );
    }

    update_option(
        $queue_key,
        array(
            'ids'        => $queue,
            'index'      => 0,
            'completed'  => 0,
            'failed'     => 0,
            'failures'   => array(),
            'status'     => 'running',
            'updated_at' => time()
        ),
        false
    );

    // Return the number of images selected.
    wp_send_json_success(
        array(
            'total' => count($queue)
        )
    );
}

// Register the AJAX action.
add_action(
    'wp_ajax_alt_generator_bulk_start',
    'alt_generator_bulk_start'
);

// Process the next image in the bulk queue.
function alt_generator_bulk_step() {

    // Verify administrator permissions.
    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            array('message' => 'Permission denied.'),
            403
        );
    }

    // Verify the AJAX security nonce.
    check_ajax_referer('alt_generator_bulk', 'nonce');

    // Create a processing lock for the current administrator.
    $lock_key = 'alt_generator_lock_' . get_current_user_id();

    // Clear an abandoned lock before claiming the image.
    alt_generator_bulk_lock_active(get_current_user_id());

    // Generate a unique identifier for this processing request.
    $lock_token = wp_generate_uuid4();

    // Only one request can acquire this lock.
    $lock_acquired = add_option(
        $lock_key,
        array('token' => $lock_token, 'created_at' => time()),
        '',
        false
    );

    // Stop if another request already owns the lock.
    if (!$lock_acquired) {

        wp_send_json_error(
            array(
                'message' => 'Another image is already being processed.',
                'error_code' => 'processing_locked'
            ),
            409
        );
    }

    // Release the lock when this PHP request ends.
    register_shutdown_function(
        function () use ($lock_key, $lock_token) {

            // Check that this request still owns the lock.
            $current_token = get_option($lock_key, false);

            if (is_array($current_token) &&
                isset($current_token['token']) &&
                $current_token['token'] === $lock_token) {

                // Release the processing lock owned by this request.
                delete_option($lock_key);

            }

        }
    );

    // Retrieve the persistent processing queue.
    $key = 'alt_generator_bulk_' . get_current_user_id();

    $job = get_option($key, false);

    // Check whether the queue exists.
    if (
        !is_array($job) ||
        !isset($job['ids'], $job['index'])
    ) {
        wp_send_json_error(
            array('message' => 'Processing session not found.'),
            400
        );
    }

    // Prevent processing when the queue is paused.
    if (isset($job['status']) && $job['status'] === 'paused') {

        wp_send_json_error(
            array(
                'message' => 'Processing is paused.',
                'error_code' => 'queue_paused'
            ),
            409
        );
    }

    $total = count($job['ids']);

    // Check whether processing is already complete.
    if ($job['index'] >= $total) {
        wp_send_json_success(
            array(
                'done'      => true,
                'processed' => $job['index'],
                'total'     => $total,
                'completed' => $job['completed'],
                'failed'    => $job['failed'],
                'failures'  => isset($job['failures']) ? $job['failures'] : array()
            )
        );
    }

    // Retrieve the next image ID.
    $image_id = $job['ids'][$job['index']];

    // Generate and save alt text.
    $result = alt_generator_process_image($image_id);

    // Check whether processing succeeded.
    if (is_wp_error($result)) {

        $error = $result->get_error_message();
        $error_code = $result->get_error_code();

        // Errors that require bulk processing to stop.
        $stop_errors = array(
            'missing_api_key',
            'invalid_api_key',
            'api_access_denied',
            'api_billing',
            'rate_limit',
            'api_unavailable',
            'connection_error',
            'api_error',
            'invalid_response',
            'empty_description',
            'image_busy'
        );

        if (in_array($error_code, $stop_errors, true)) {

            // Keep the current image in the queue and allow a later retry.
            $job['status'] = 'paused';
            $job['updated_at'] = time();
            update_option(
                $key,
                $job,
                false
            );

            // Tell JavaScript to stop processing.
            wp_send_json_error(
                array(
                    'message'   => $error,
                    'error_code' => $error_code,
                    'processed' => $job['index'],
                    'total'     => $total
                ),
                400
            );
        }

        // Record each failed image for an actionable end-of-batch report.
        $job['failed']++;
        if (!isset($job['failures']) || !is_array($job['failures'])) {
            $job['failures'] = array();
        }
        $job['failures'][] = array(
            'image_id' => (int) $image_id,
            'code' => sanitize_key($error_code),
            'message' => sanitize_text_field($error)
        );

    } else {

        // Record successful processing.
        $job['completed']++;

        $error = '';
    }

    // Move to the next image.
    $job['index']++;

    // Check whether all images have been processed.
    if ($job['index'] >= $total) {

        // Mark the queue as completed.
        $job['status'] = 'completed';
    }

    // Record when the latest image finished processing.
    $job['updated_at'] = time();

    // Save the updated queue.
    update_option(
        $key,
        $job,
        false
    );

    // Return the current progress.
    wp_send_json_success(
        array(
            'done'        => $job['index'] >= $total,
            'processed'   => $job['index'],
            'total'       => $total,
            'completed'   => $job['completed'],
            'failed'      => $job['failed'],
            'failures'    => isset($job['failures']) ? $job['failures'] : array(),
            'image_id'    => $image_id,
            'preview_url' => wp_get_attachment_image_url($image_id, 'medium') ?: '',
            'filename'    => basename((string) get_attached_file($image_id)),
            'description' => !is_wp_error($result) ? $result['description'] : '',
            'error'       => $error
        )
    );
}

// Register the AJAX action.
add_action(
    'wp_ajax_alt_generator_bulk_step',
    'alt_generator_bulk_step'
);

// Pause the current bulk-processing session.
function alt_generator_bulk_pause() {

    // Verify administrator permissions.
    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            array('message' => 'Permission denied.'),
            403
        );
    }

    // Verify the AJAX security nonce.
    check_ajax_referer('alt_generator_bulk', 'nonce');

    // Retrieve the existing processing queue.
    $key = 'alt_generator_bulk_' . get_current_user_id();

    $job = get_option($key, false);

    // Check whether the queue exists.
    if (
        !is_array($job) ||
        !isset($job['ids'], $job['index'])
    ) {
        wp_send_json_error(
            array('message' => 'No processing session found.'),
            400
        );
    }

    // Check whether processing has already finished.
    if ($job['index'] >= count($job['ids'])) {

        $job['status'] = 'completed';

        update_option($key, $job, false);

        wp_send_json_success(
            array('message' => 'Processing is already complete.')
        );
    }

    // Mark the queue as paused.
    $job['status'] = 'paused';

    // Save the updated queue.
    update_option(
        $key,
        $job,
        false
    );

    // Confirm that processing has paused.
    wp_send_json_success(
        array(
            'message'   => 'Processing paused successfully.',
            'processed' => $job['index'],
            'total'     => count($job['ids'])
        )
    );
}

// Register the Pause AJAX action.
add_action(
    'wp_ajax_alt_generator_bulk_pause',
    'alt_generator_bulk_pause'
);

// Resume an existing bulk-processing session.
function alt_generator_bulk_resume() {

    // Verify administrator permissions.
    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            array('message' => 'Permission denied.'),
            403
        );
    }

    // Verify the AJAX security nonce.
    check_ajax_referer('alt_generator_bulk', 'nonce');

    // Retrieve the existing processing queue.
    $key = 'alt_generator_bulk_' . get_current_user_id();

    $job = get_option($key, false);

    // Check whether a valid queue exists.
    if (
        !is_array($job) ||
        !isset($job['ids'], $job['index'], $job['status'])
    ) {
        wp_send_json_error(
            array('message' => 'No processing session available to resume.'),
            400
        );
    }

    // Only allow paused sessions to resume.
    if ($job['status'] !== 'paused') {
        wp_send_json_error(
            array('message' => 'This processing session is not paused.'),
            409
        );
    }

    $total = count($job['ids']);

    // Check whether every image has already been processed.
    if ($job['index'] >= $total) {
        wp_send_json_error(
            array('message' => 'All images have already been processed.'),
            400
        );
    }

    // Change the queue status back to running.
    $job['status'] = 'running';

    // Record when processing resumes.
    $job['updated_at'] = time();

    // Save the updated queue.
    update_option(
        $key,
        $job,
        false
    );

    // Return the existing progress to JavaScript.
    wp_send_json_success(
        array(
            'message'   => 'Processing resumed.',
            'processed' => $job['index'],
            'total'     => $total,
            'completed' => $job['completed'],
            'failed'    => $job['failed']
        )
    );
}

// Register the Resume AJAX action.
add_action(
    'wp_ajax_alt_generator_bulk_resume',
    'alt_generator_bulk_resume'
);

// Load styling across the plugin pages, and bulk JavaScript only on Bulk Generation.
function alt_generator_enqueue_scripts($hook) {
    $plugin_pages = array(
        'media_page_alt-text-generator',
        'media_page_alt-text-generator-manual',
        'media_page_alt-text-generator-bulk',
    );
    if (!in_array($hook, $plugin_pages, true)) {
        return;
    }

    wp_enqueue_style(
        'alt-generator-admin',
        plugin_dir_url(__FILE__) . 'admin/admin.css',
        array(),
        filemtime(plugin_dir_path(__FILE__) . 'admin/admin.css')
    );

    if ($hook !== 'media_page_alt-text-generator-bulk') {
        return;
    }

    wp_enqueue_script(
        'alt-generator-bulk',
        plugin_dir_url(__FILE__) . 'admin/admin.js',
        array(),
        filemtime(plugin_dir_path(__FILE__) . 'admin/admin.js'),
        true
    );

    wp_localize_script(
        'alt-generator-bulk',
        'altGeneratorBulk',
        array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('alt_generator_bulk'),
            'hasApiKey' => (bool) get_option('alt_generator_anthropic_api_key')
        )
    );
}

// Tell WordPress when to load the script.
add_action(
    'admin_enqueue_scripts',
    'alt_generator_enqueue_scripts'
);

// Recover an interrupted bulk-processing session.
function alt_generator_bulk_recover() {

    // Verify administrator permissions.
    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            array('message' => 'Permission denied.'),
            403
        );
    }

    // Verify the AJAX security nonce.
    check_ajax_referer('alt_generator_bulk', 'nonce');

    // Retrieve the administrator's existing queue.
    $key = 'alt_generator_bulk_' . get_current_user_id();

    // Check whether an image is currently being processed.
    $lock_key = 'alt_generator_lock_' . get_current_user_id();

    if (alt_generator_bulk_lock_active(get_current_user_id())) {

        wp_send_json_error(
            array(
                'message' => 'An image may still be processing. Recovery is blocked until the processing lock is released.'
            ),
            409
        );
    }

    // Retrieve the saved session.
    $job = get_option($key, false);

    if (
        !is_array($job) ||
        !isset($job['ids'], $job['index'], $job['status']) ||
        !is_array($job['ids'])
    ) {

        wp_send_json_error(
            array('message' => 'No valid processing session found.'),
            400
        );
    }

    // Only recover unfinished sessions marked as running.
    if (
        $job['status'] !== 'running' ||
        $job['index'] >= count($job['ids'])
    ) {

        wp_send_json_error(
            array('message' => 'No interrupted session requires recovery.'),
            409
        );
    }

    // Mark the existing queue as paused.
    $job['status'] = 'paused';
    $job['updated_at'] = time();

    // Preserve all existing progress.
    update_option($key, $job, false);

    wp_send_json_success(
        array(
            'message'   => 'Session recovered. You can now resume.',
            'processed' => $job['index'],
            'total'     => count($job['ids'])
        )
    );
}

// Register the Recovery AJAX action.
add_action(
    'wp_ajax_alt_generator_bulk_recover',
    'alt_generator_bulk_recover'
);

// Cancel an unfinished batch without deleting descriptions already saved.
function alt_generator_bulk_cancel() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Permission denied.'), 403);
    }

    check_ajax_referer('alt_generator_bulk', 'nonce');

    if (alt_generator_bulk_lock_active(get_current_user_id())) {
        wp_send_json_error(
            array('message' => 'An image is still processing. Wait for it to finish, then cancel again.'),
            409
        );
    }

    $key = 'alt_generator_bulk_' . get_current_user_id();
    $job = get_option($key, false);

    if (!is_array($job) || !isset($job['ids'], $job['index']) ||
        !is_array($job['ids'])) {
        wp_send_json_error(array('message' => 'No processing session to cancel.'), 400);
    }

    if ((int) $job['index'] >= count($job['ids']) ||
        (isset($job['status']) && $job['status'] === 'completed')) {
        wp_send_json_error(array('message' => 'This batch has already completed.'), 409);
    }

    // Remove the old queue. Successfully saved alt text remains unchanged.
    delete_option($key);
    wp_send_json_success(array(
        'message' => 'Batch cancelled. Saved descriptions have been kept. You can start a new batch.'
    ));
}
add_action('wp_ajax_alt_generator_bulk_cancel', 'alt_generator_bulk_cancel');

// Retrieve the current bulk-processing session status.
function alt_generator_bulk_status() {

    // Verify administrator permissions.
    if (!current_user_can('manage_options')) {
        wp_send_json_error(
            array('message' => 'Permission denied.'),
            403
        );
    }

    // Verify the AJAX security nonce.
    check_ajax_referer('alt_generator_bulk', 'nonce');

    // Retrieve the saved processing queue.
    $key = 'alt_generator_bulk_' . get_current_user_id();

    $job = get_option($key, false);

    // Check whether a valid session exists.
    if (
        !is_array($job) ||
        !isset(
            $job['ids'],
            $job['index'],
            $job['status'],
            $job['completed'],
            $job['failed']
        ) ||
        !is_array($job['ids'])
    ) {
        wp_send_json_success(
            array(
                'exists' => false
            )
        );
    }

    // Retrieve processing progress.
    $total = count($job['ids']);
    $processed = (int) $job['index'];

    // Return the current session information.
    wp_send_json_success(
        array(
            'exists'    => true,
            'status'    => $job['status'],
            'processed' => $processed,
            'total'     => $total,
            'completed' => (int) $job['completed'],
            'failed'    => (int) $job['failed'],
            'failures'  => isset($job['failures']) && is_array($job['failures']) ? $job['failures'] : array(),
            'remaining' => max(0, $total - $processed)
        )
    );
}

// Register the Status AJAX action.
add_action(
    'wp_ajax_alt_generator_bulk_status',
    'alt_generator_bulk_status'
);

// Return the current missing-alt-text list for the live Bulk Preview.
function alt_generator_bulk_missing_images() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Permission denied.'), 403);
    }

    check_ajax_referer('alt_generator_bulk', 'nonce');

    $images = array();
    foreach (alt_generator_get_missing_image_ids() as $image_id) {
        $images[] = array(
            'id' => (int) $image_id,
            'title' => get_the_title($image_id),
        );
    }

    wp_send_json_success(array('images' => $images));
}
add_action('wp_ajax_alt_generator_bulk_missing_images', 'alt_generator_bulk_missing_images');
