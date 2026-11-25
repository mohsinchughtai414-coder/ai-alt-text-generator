<?php
// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

function alt_generator_admin_nav($active) {
    $pages = array(
        'alt-text-generator' => 'Overview & Settings',
        'alt-text-generator-manual' => 'Manual Generation',
        'alt-text-generator-bulk' => 'Bulk Generation',
    );
    echo '<nav class="nav-tab-wrapper" aria-label="Alt Text Generator navigation">';
    foreach ($pages as $slug => $label) {
        $url = add_query_arg('page', $slug, admin_url('upload.php'));
        $class = 'nav-tab' . ($active === $slug ? ' nav-tab-active' : '');
        echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';
}

function alt_generator_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }
    $api_key_saved = !empty(get_option('alt_generator_anthropic_api_key', ''));
    ?>
    <div class="wrap">
        <h1>Alt Text Generator</h1>
        <?php alt_generator_admin_nav('alt-text-generator'); ?>
        <p>Generate image descriptions for images missing alternative text.</p>
        <div class="alt-generator-dashboard">
            <div class="alt-generator-dashboard-card">
                <h2>Manual Generation</h2>
                <p>Generate and review a description for one image at a time.</p>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('upload.php?page=alt-text-generator-manual')); ?>">Open Manual Generation</a>
            </div>
            <div class="alt-generator-dashboard-card">
                <h2>Bulk Generation</h2>
                <p>Generate descriptions for multiple images and track batch progress.</p>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('upload.php?page=alt-text-generator-bulk')); ?>">Open Bulk Generation</a>
            </div>
        </div>
        <!-- API SETTINGS SECTION -->

        <h2>API Settings</h2>

        <?php
        // Display a confirmation message after submitting settings.
        if (
            isset($_GET['settings-updated']) &&
            $_GET['settings-updated'] === 'true'
        ) {
            ?>

            <div class="notice notice-success is-dismissible">
                <p>API settings submitted successfully.</p>
            </div>

            <?php
        }
        ?>

        <p>
            <strong>API Key Status:</strong>

            <?php if ($api_key_saved) { ?>

                <span style="color: green;">Configured</span>

            <?php } else { ?>

                <span style="color: red;">Not configured</span>

            <?php } ?>
        </p>


        <!-- API KEY FORM -->

        <form
            method="post"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        >

            <input
                type="hidden"
                name="action"
                value="alt_generator_save_settings"
            >

            <?php wp_nonce_field('alt_generator_save_settings'); ?>

            <p>
                <label for="anthropic_api_key">
                    <strong>Anthropic API Key</strong>
                </label>
            </p>

            <p>
                <input
                    type="password"
                    id="anthropic_api_key"
                    name="anthropic_api_key"
                    class="regular-text"
                    autocomplete="new-password"
                    placeholder="Enter your Anthropic API key"
                >
            </p>

            <p class="description">
                Your saved API key is not displayed for security.
                Leave this field empty to keep the existing key.
            </p>

            <?php submit_button('Save API Key'); ?>

        </form>

        <hr>

        <!-- IMAGE DETECTION SECTION -->

        <h2>Image Detection</h2>

        <p>
            <strong>Images missing alt text:</strong>
            <?php echo esc_html(alt_generator_count_missing_images()); ?>
        </p>



    </div>
    <?php
}

function alt_generator_manual_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }
    $api_key_saved = !empty(get_option('alt_generator_anthropic_api_key', ''));
    ?>
    <div class="wrap">
        <h1>Manual Generation</h1>
        <?php alt_generator_admin_nav('alt-text-generator-manual'); ?>
        <?php
        $save_success_key = 'alt_generator_save_success_' . get_current_user_id();
        $save_success = get_transient($save_success_key);
        if ($save_success) {
            delete_transient($save_success_key);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($save_success) . '</p></div>';
        }
        ?>
        <!-- NEXT IMAGE SECTION -->

        <?php

        // Retrieve the newest image missing alt text.
        $image = alt_generator_get_next_image();

        if ($image) {
            ?>

            <h2>Next Image to Process</h2>

            <p>
                <strong>Image ID:</strong>
                <?php echo esc_html($image['id']); ?>
            </p>

            <p>
                <strong>Filename:</strong>
                <?php echo esc_html($image['filename']); ?>
            </p>

            <?php if ($image['url']) { ?>

                <img
                    src="<?php echo esc_url($image['url']); ?>"
                    alt=""
                    style="max-width: 300px; height: auto;"
                >

            <?php } ?>

            <?php
        } else {

            echo '<p>No images missing alt text were found.</p>';

        }
        ?>


        <!-- GENERATE DESCRIPTION SECTION -->

        <?php if ($image && $api_key_saved) { ?>

            <hr>

            <h2>Generate Image Description</h2>

            <p>
                Generate a description for the newest image missing alt text.
            </p>

            <form
                method="post"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            >

                <input
                    type="hidden"
                    name="action"
                    value="alt_generator_generate"
                >

                <?php wp_nonce_field('alt_generator_generate'); ?>

                <?php submit_button('Generate Description'); ?>

            </form>

        <?php } elseif ($image && !$api_key_saved) { ?>

            <p>
                <strong>
                    Please configure your Anthropic API key before generating descriptions.
                </strong>
            </p>

        <?php } ?>


        <!-- GENERATED RESULT SECTION -->

        <?php

        // Retrieve the temporary result for the current administrator.
        $result_key = 'alt_generator_result_' . get_current_user_id();

        $result = get_transient($result_key);

        // Retain the transient until the administrator saves the generated description.

        if ($result !== false) {

            if (!empty($result['success'])) {
                ?>

                <hr>

                <h2>Generated Description</h2>

                <div class="notice notice-success inline">

                    <p>
                        <strong>Image ID:</strong>
                        <?php echo esc_html($result['image_id']); ?>
                    </p>

                    <p>
                        <strong>Claude's Description:</strong>
                    </p>

                    <p>
                        <?php echo esc_html($result['description']); ?>
                    </p>

                </div>


                <!-- SAVE DESCRIPTION SECTION -->

                <?php if (empty($result['save_error'])) { ?>

                    <form
                        method="post"
                        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="alt_generator_save_description"
                        >

                        <?php wp_nonce_field('alt_generator_save_description'); ?>

                        <?php submit_button('Save Description'); ?>

                    </form>

                <?php } else { ?>

                    <div class="notice notice-error inline">

                        <p>
                            <strong>Saving failed:</strong>
                            <?php echo esc_html($result['save_error']); ?>
                        </p>

                    </div>

                <?php } ?>

                <p>
                    <em>
                        This description has not been saved to the Media Library.
                    </em>
                </p>

                <?php

            } else {
                ?>

                <hr>

                <h2>Generation Result</h2>

                <div class="notice notice-error inline">

                    <p>
                        <strong>Generation failed:</strong>

                        <?php echo esc_html(
                            isset($result['message'])
                                ? $result['message']
                                : 'An unknown error occurred.'
                        ); ?>
                    </p>

                </div>

                <?php
            }
        }
        ?>


    </div>
    <?php
}

function alt_generator_bulk_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }
    $api_key_saved = !empty(get_option('alt_generator_anthropic_api_key', ''));
    ?>
    <div class="wrap">
        <h1>Bulk Generation</h1>
        <?php alt_generator_admin_nav('alt-text-generator-bulk'); ?>
        <p>Process multiple images missing alt text, newest first. Keep this page open while a batch is running.</p>
        <!-- BULK IMAGE DETECTION TEST -->

        <?php
        // Retrieve all images missing alt text.
        $missing_image_ids = alt_generator_get_missing_image_ids();
        ?>

        <h3>Bulk Processing Preview</h3>

        <p>
            <strong>Images available for processing:</strong>
            <span id="alt-generator-missing-count"><?php echo esc_html(count($missing_image_ids)); ?></span>
        </p>

        <p id="alt-generator-preview-label" <?php echo empty($missing_image_ids) ? 'hidden' : ''; ?>>
            <strong>Processing order (newest first):</strong>
        </p>

        <ol id="alt-generator-missing-list">
            <?php foreach ($missing_image_ids as $image_id) { ?>
                <li data-image-id="<?php echo esc_attr($image_id); ?>">
                    Image ID: <?php echo esc_html($image_id); ?>
                    — <?php echo esc_html(get_the_title($image_id)); ?>
                </li>
            <?php } ?>
        </ol>

        <p id="alt-generator-preview-empty" <?php echo !empty($missing_image_ids) ? 'hidden' : ''; ?>>
            No images are currently missing alt text.
        </p>

        <!-- BULK ALT TEXT GENERATION SECTION -->

        <hr>

        <h2>Bulk Alt Text Generation</h2>

        <p>
            Select how many images you want to generate alt text for.
            Images will be processed newest first.
        </p>

        <p>
            <label for="alt-generator-limit">
                <strong>Number of images:</strong>
            </label>

            <select id="alt-generator-limit">
                <option value="1">1 image</option>
                <option value="5">5 images</option>
                <option value="10">10 images</option>
                <option value="all">All images</option>
            </select>
        </p>

        <p>
            <label for="alt-generator-delay">
                <strong>Display each result for (seconds):</strong>
            </label>
            <input
                type="number"
                id="alt-generator-delay"
                min="0"
                max="60"
                step="1"
                value="3"
                class="small-text"
            >
            <span class="description">0 = continue immediately; maximum 60 seconds. Applies after each image, before starting the next.</span>
        </p>

        <p>
            <button
                type="button"
                id="alt-generator-start"
                class="button button-primary"
                <?php disabled(!$api_key_saved || empty($missing_image_ids)); ?>
            >
                Generate Alt Text
            </button>

            <!-- Pause the current bulk-processing session. -->
            <button
                type="button"
                id="alt-generator-pause"
                class="button button-secondary"
                disabled
            >
                Pause Processing
            </button>

            <button
                type="button"
                id="alt-generator-cancel"
                class="button button-secondary"
                disabled
            >
                Cancel Batch
            </button>

            <!-- Resume an existing processing session. -->
            
            <button
                type="button"
                id="alt-generator-resume"
                class="button button-secondary"
                disabled
            >
                Resume Processing
            </button>

        </p>

        <p
            id="alt-generator-progress"
            role="status"
            aria-live="polite"
        >
            Ready to process images.
        </p>


        <!-- Failed images are kept visible after processing or a page reload. -->
        <section id="alt-generator-failures" class="alt-generator-failures" aria-label="Failed images" hidden>
            <h3>Images that could not be processed</h3>
            <p>These images still need alt text. Resolve the error and include them in a new batch.</p>
            <ol id="alt-generator-failure-list"></ol>
        </section>

        <!-- LIVE IMAGE PROCESSING DISPLAY -->

        <section
            id="alt-generator-result"
            class="alt-generator-result"
            aria-label="Latest processed image"
            hidden
        >

            <h3>Latest Processed Image</h3>

            <p
                id="alt-generator-result-status"
                aria-live="polite"
            ></p>

            <p id="alt-generator-result-details"></p>

            <img
                id="alt-generator-result-image"
                src=""
                alt="Preview of the latest processed image"
                hidden
            >

            <p>
                <strong>Generated Alt Text:</strong>
            </p>

            <p id="alt-generator-result-description"></p>

            <div id="alt-generator-countdown" class="alt-generator-countdown" hidden>
                <p class="alt-generator-countdown-title"><strong>Next image in</strong></p>
                <div id="alt-generator-countdown-time" class="alt-generator-countdown-time" role="timer">00:00</div>
                <progress id="alt-generator-countdown-bar" max="100" value="0" aria-label="Time elapsed before next image"></progress>
                <p id="alt-generator-countdown-state" class="description" aria-live="polite"></p>
                <p class="description">Change the display delay above to adjust this countdown.</p>
            </div>

        </section>


    </div>
    <?php
}
