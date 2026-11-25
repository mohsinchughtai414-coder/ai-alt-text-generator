# AI Alt Text Generator

A WordPress plugin that uses artificial intelligence to generate missing image alternative text through manual and bulk processing.

## Project Purpose

The AI Alt Text Generator was developed to reduce the time and manual effort required to write alternative text for images in WordPress.

The plugin uses the Anthropic API to generate image descriptions, helping website administrators improve accessibility and manage large media libraries more efficiently.

---

## 🧰 Technologies Used

![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![HTML5](https://img.shields.io/badge/HTML5-E34F26?style=for-the-badge&logo=html5&logoColor=white)
![CSS3](https://img.shields.io/badge/CSS3-1572B6?style=for-the-badge&logo=css3&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white)
![Anthropic API](https://img.shields.io/badge/Anthropic_API-D97757?style=for-the-badge)
![REST API](https://img.shields.io/badge/REST_API-005571?style=for-the-badge)

---

## Features

- Automatically detect images with missing alt text.
- Generate and review individual image descriptions before saving.
- Process batches of 1, 5, 10 or all eligible images.
- Process images in newest-first order.
- Pause, resume and cancel bulk processing.
- Recover interrupted processing sessions.
- Monitor successful and failed image processing.
- Configure processing delays between 0 and 60 seconds.
- Configure an Anthropic API key through the WordPress administration interface.

---

## 📸 Screenshots

Some screenshots were captured during development. Images, filenames and identifying information have been redacted to protect confidentiality.

### Overview and Settings

Configure the API key and access manual and bulk generation.

![Overview and Settings](screenshots/overview-settings.png)

### Image Detection

Identify images missing alternative text and prevent processing when no eligible images remain.

![Image Detection](screenshots/image-detection.png)

### Manual Generation

Preview individual images and generate alternative text.

![Manual Generation](screenshots/manual-generation.png)

### Bulk Generation

Configure batch sizes and processing delays.

![Bulk Generation](screenshots/bulk-generation.png)

### Successful Batch Completion

Display processing results, including successful and failed images.

![Bulk Completion](screenshots/bulk-completion.png)

### Session Recovery

Detect an interrupted processing session and provide recovery controls.

![Session Recovery](screenshots/session-recovery.png)

### Recovered Batch

Restore previous processing progress before continuing a batch.

![Recovered Batch](screenshots/batch-recovered.png)

---

## Installation

1. Obtain a copy of the plugin.
2. Open the WordPress administration dashboard.
3. Navigate to **Plugins > Add New > Upload Plugin**.
4. Upload the plugin ZIP file.
5. Install and activate **AI Alt Text Generator**.
6. Navigate to **Media > Alt Text Generator**.

Alternatively, place the extracted plugin folder inside `wp-content/plugins/` and activate it through WordPress.

## Configuration

1. Create an Anthropic account.
2. Obtain an API key from the [Anthropic Console](https://console.anthropic.com/).
3. Ensure API access and billing are configured.
4. Open the plugin's **Overview & Settings** page.
5. Enter and save your API key.
6. Navigate to **Manual Generation** or **Bulk Generation**.

No source-code modifications are required for normal use.

---

## Technical Challenges and Solutions

### Bulk Image Processing

Processing multiple images required a way to manage individual API requests and track progress.

I implemented sequential processing with configurable batch sizes and delays, allowing users to control processing and monitor results.

### Interrupted Processing Sessions

Bulk processing sessions could be interrupted before all images were completed.

I implemented session persistence and recovery functionality to restore previous progress and allow processing to continue.

### API Key Handling

The plugin required API authentication without exposing credentials in the source code or displaying the saved key in the administration interface.

The API key is configured through WordPress settings and retrieved server-side when making API requests.

### Image Validation

Images needed to be validated before being submitted to the Anthropic API.

File format and size checks were implemented to prevent unsupported images from being submitted.

---

## 🧪 Testing

The plugin was tested throughout development in a WordPress environment.

### Development Testing

| Test | Expected Result | Observed Result |
|---|---|---|
| API key display | Saved API key is not visibly displayed | Confirmed |
| Image detection | Images missing alt text are identified | Confirmed |
| Empty processing queue | Generation is disabled when no eligible images remain | Confirmed |
| Bulk image processing | Selected image is processed successfully | 1 successful, 0 failed |
| Interrupted session detection | Recovery controls are displayed | Confirmed |
| Session recovery | Previous processing progress is restored | 3 of 5 images recovered |
| Batch cancellation | Processing stops and saved descriptions are retained | Cancellation confirmation displayed |

### Portfolio Version Testing

| Test | Result |
|---|---|
| Plugin installation | Passed |
| Updated author attribution | Passed |
| Overview and Settings interface | Passed |
| Manual Generation interface | Passed |
| Bulk Generation interface | Passed |
| End-to-end AI description generation | Pending API credits |
| Bulk processing functionality | Previously tested; not retested |
| Session recovery functionality | Previously tested; not retested |

The updated portfolio version was successfully installed and its administration interfaces were checked. Full end-to-end API testing could not be repeated due to insufficient API credits.

---

## Source Code Structure

```text
ai-alt-text-generator/
├── admin/
│   ├── admin-page.php
│   ├── admin.css
│   └── admin.js
├── includes/
│   ├── image-handler.php
│   └── claude-api.php
├── screenshots/
│   ├── overview-settings.png
│   ├── image-detection.png
│   ├── manual-generation.png
│   ├── bulk-generation.png
│   ├── bulk-completion.png
│   ├── session-recovery.png
│   └── batch-recovered.png
├── .gitignore
├── alt-text-generator.php
└── README.md
```

| File | Purpose |
|---|---|
| `alt-text-generator.php` | Plugin registration, settings and request handlers |
| `admin/admin-page.php` | WordPress administration interface |
| `admin/admin.css` | Interface styling |
| `admin/admin.js` | Bulk processing and session controls |
| `includes/image-handler.php` | Image detection and alt text handling |
| `includes/claude-api.php` | Image validation and Anthropic API integration |
| `.gitignore` | Excludes unnecessary local files from Git tracking |
| `README.md` | Project documentation |

---

## Developer Configuration

Developers can customise the following settings within `includes/claude-api.php`:

- AI model identifier.
- Image description prompt.
- Output token limit.

Normal users do not need to modify these settings.

Any modifications should be tested on a separate WordPress installation before deployment.

---

## Requirements and Limitations

### Requirements

- WordPress installation with administrator access.
- Anthropic API key with API access and billing configured.
- Outbound HTTPS connectivity to Anthropic.
- Locally accessible JPEG, PNG, GIF or WebP images.
- Maximum image file size of 5 MB, subject to additional API limits.
- Access to the configured Claude model (`claude-haiku-4-5-20251001`).

### Limitations

- AI-generated descriptions may be inaccurate or lack page-specific context.
- Generated descriptions should be reviewed for accuracy and accessibility.
- Images that intentionally contain empty alt text, such as decorative images, may also be detected as missing alternative text.
- Automatic uninstall cleanup is not implemented in this version.
- Image generation depends on Anthropic API availability and sufficient API credits.

---

## Security and Privacy

Images are read from disk, base64-encoded and submitted to the Anthropic API through server-side requests.

Only process images that you are authorised to share with the external service. API usage charges may apply.

API credentials should never be committed to GitHub or shared publicly.

---

## Project Status

**Version:** 1.0.0

The plugin was developed and functionally tested in a WordPress environment.

The updated portfolio version has been successfully installed, and its administration interfaces have been verified. Further end-to-end API testing is pending due to insufficient API credits.

---

## Author

**Mohsin Chughtai**

Digital Software Development T Level student and aspiring Software Engineer.

[![LinkedIn](https://img.shields.io/badge/LinkedIn-0077B5?style=for-the-badge&logo=linkedin&logoColor=white)](https://www.linkedin.com/in/mohsin-chughtai-4376b73a5/)