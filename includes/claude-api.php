<?php

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}


// Generate alt text for a WordPress image using Claude.
function alt_generator_generate_description($image_id) {

    // Retrieve the saved Anthropic API key.
    $api_key = get_option('alt_generator_anthropic_api_key', '');

    if (empty($api_key)) {
        return new WP_Error(
            'missing_api_key',
            'Anthropic API key has not been configured.'
        );
    }


    // Check that the attachment is an image.
    if (!wp_attachment_is_image($image_id)) {
        return new WP_Error(
            'invalid_image',
            'The selected attachment is not a valid image.'
        );
    }


    // Retrieve the image file location.
    $image_path = get_attached_file($image_id);

    if (!$image_path || !is_readable($image_path)) {
        return new WP_Error(
            'missing_image',
            'The image file could not be accessed.'
        );
    }


    // Check the image format.
    $image_info = wp_getimagesize($image_path);

    if (!$image_info || empty($image_info['mime'])) {
        return new WP_Error(
            'invalid_format',
            'The image format could not be identified.'
        );
    }

    $mime_type = $image_info['mime'];

    $supported_formats = array(
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp'
    );

    if (!in_array($mime_type, $supported_formats, true)) {
        return new WP_Error(
            'unsupported_format',
            'This image format is not currently supported.'
        );
    }


    // Enforce the plugin's 5 MB image file size limit.
    $file_size = filesize($image_path);

    if ($file_size === false || $file_size > 5 * 1024 * 1024) {
        return new WP_Error(
            'image_too_large',
            'The image exceeds the 5 MB file size limit.'
        );
    }


    // Read and encode the image.
    $image_data = file_get_contents($image_path);

    if ($image_data === false) {
        return new WP_Error(
            'image_read_error',
            'The image could not be read.'
        );
    }

    $encoded_image = base64_encode($image_data);


    // Alt text prompt: adjust these instructions if different output requirements are needed.
    $prompt = 'Generate concise, accurate alternative text for this image.

Describe its meaningful visual content for accessibility.

If the image is a logo, identify the organisation or brand when clearly visible.

Do not invent details or make assumptions.

Return only the alt text, without quotation marks or explanations.

Aim for fewer than 125 characters where practical.';


    // Prepare the request for Anthropic. The model and output token limit can be adjusted here.
    $request_body = array(

        'model' => 'claude-haiku-4-5-20251001',

        'max_tokens' => 150,

        'messages' => array(
            array(
                'role' => 'user',

                'content' => array(

                    array(
                        'type' => 'image',

                        'source' => array(
                            'type' => 'base64',
                            'media_type' => $mime_type,
                            'data' => $encoded_image
                        )
                    ),

                    array(
                        'type' => 'text',
                        'text' => $prompt
                    )

                )
            )
        )
    );


    // Send the image to Claude.
    $response = wp_remote_post(
        'https://api.anthropic.com/v1/messages',

        array(
            'headers' => array(
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json'
            ),

            'body' => wp_json_encode($request_body),

            'timeout' => 60
        )
    );


    // Check for WordPress connection errors.
    if (is_wp_error($response)) {
        return new WP_Error(
            'connection_error',
            'Could not connect to Anthropic: ' .
            $response->get_error_message()
        );
    }


    // Check the API response.
    $status_code = wp_remote_retrieve_response_code($response);

    $response_body = json_decode(
        wp_remote_retrieve_body($response),
        true
    );

    // Handle different Anthropic API errors.
    if ($status_code !== 200) {

        switch ($status_code) {

            case 401:
                return new WP_Error(
                    'invalid_api_key',
                    'Invalid Anthropic API key. Processing must stop.'
                );

            case 403:
                return new WP_Error(
                    'api_access_denied',
                    'Anthropic API access denied. Processing must stop.'
                );

            case 402:
                return new WP_Error(
                    'api_billing',
                    'Anthropic billing needs attention. Check your account balance before resuming.'
                );

            case 413:
                return new WP_Error(
                    'image_too_large',
                    'Anthropic rejected the image size. Try a smaller image.'
                );

            case 429:
                return new WP_Error(
                    'rate_limit',
                    'Anthropic rate limit reached. Processing should pause.'
                );

            case 500:
            case 502:
            case 503:
            case 529:
                return new WP_Error(
                    'api_unavailable',
                    'Anthropic is temporarily unavailable. Try again later.'
                );

            default:
                return new WP_Error(
                    'api_error',
                    'Anthropic API request failed. HTTP status: ' .
                    $status_code
                );
        }
    }


    // Extract the generated description.
    if (
        !isset($response_body['content'][0]['text']) ||
        !is_string($response_body['content'][0]['text'])
    ) {
        return new WP_Error(
            'invalid_response',
            'Claude did not return a valid description.'
        );
    }

    $description = trim($response_body['content'][0]['text']);

    if ($description === '') {
        return new WP_Error(
            'empty_description',
            'Claude returned an empty description.'
        );
    }

    return sanitize_text_field($description);
}