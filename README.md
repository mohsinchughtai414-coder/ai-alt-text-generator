# AI Alt Text Generator

A WordPress plugin that generates missing image alternative text using the Anthropic API. It supports manual review and bulk processing of images in the WordPress Media Library.

## Features

- Detect images with missing alt text.
- Generate and review descriptions individually before saving.
- Process batches of 1, 5, 10 or all eligible images.
- Pause, resume or recover interrupted bulk sessions.
- View processing progress and failed-image summaries.
- Adjust the display delay between images (0–60 seconds).

## Installation and configuration

1. Install the plugin folder in `wp-content/plugins/`, or upload a ZIP containing the plugin folder through Plugins > Add New > Upload Plugin.
2. Activate **AI Alt Text Generator** in WordPress.
3. Open **Media > Alt Text Generator** as an administrator.
4. Obtain your own Anthropic API key and enter it in the plugin settings. API access and billing must be configured on your account.
5. Use **Media > Manual Generation** to review individual descriptions, or **Media > Bulk Generation** to process multiple images.

No source-code edits are required for normal configuration. Keep your API key private; it is saved in WordPress settings and should never be committed to GitHub.

## Requirements and limitations

- A WordPress installation with administrator access and outbound HTTPS connectivity to Anthropic.
- Locally readable image files in JPEG, PNG, GIF or WebP format, up to 5 MB each (plugin-side limit; API limits may also apply).
- Access to the configured Claude model (`claude-haiku-4-5-20251001`).
- AI descriptions can be inaccurate or lack page-specific context. Review output for accessibility and accuracy, particularly before bulk use. Intentionally empty alt text on decorative images may also be detected as missing.
- Images are read from disk, base64-encoded and sent to Anthropic. Only process images you are authorised to share with the service. API charges may apply.
- Automatic uninstall cleanup is not implemented in this version.

## Source structure

```text
alt-text-generator.php       Plugin registration, settings and request handlers
admin/admin-page.php        WordPress administration interface
admin/admin.css             Administration styling
admin/admin.js              Bulk processing interface and session controls
includes/image-handler.php  Image detection and alt text handling
includes/claude-api.php     Image validation, prompt and Anthropic API integration
uninstall.php               Empty placeholder
```

## Developer configuration

The prompt, model identifier and output token limit are located in `includes/claude-api.php`. Normal users do not need to edit these settings. If you change them, test the integration on a separate WordPress installation before using it on a live site.

## Project status

Version 1.0.0 — prepared for repository review. PHP and JavaScript syntax checked; the prepared copy still requires a live WordPress test. Existing plugin attribution is retained pending publication approval. No distribution licence has been selected.
