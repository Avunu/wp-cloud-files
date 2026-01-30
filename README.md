# WP Cloud Files

WP Cloud Files is a WordPress plugin that seamlessly integrates your WordPress media library with S3-compatible object storage. It automatically moves uploaded files to S3 and redirects requests to them from there, helping you scale your WordPress site and reduce server storage requirements.

## Features

- **Direct S3 Uploads**: Files are uploaded directly from the browser to S3 using pre-signed URLs, bypassing the WordPress server
- Serve media files directly from S3, saving your server's bandwidth
- Support for all standard WordPress image sizes and optimizations
- **Asynchronous Thumbnail Generation**: Thumbnails are generated in the background via WP-Cron, keeping the upload process fast
- Generate thumbnails for documents (PDF, Word, Excel, PowerPoint)
- WP-CLI command for migrating existing media library to S3
- Supports WebP and AVIF alternatives when enabled in WordPress
- Handles image editing and regeneration workflows

## Requirements

- WordPress (tested with latest version)
- PHP 8.1 or higher
- S3-compatible storage provider (AWS S3, DigitalOcean Spaces, MinIO, etc.)
- Composer (for installing dependencies)

## Installation

1. Download the plugin and upload it to your `/wp-content/plugins/` directory
2. Run `composer install` in the plugin directory to install dependencies
3. Define the required constants in your `wp-config.php` file (see Configuration section)
4. Activate the plugin through the WordPress admin interface

## Configuration

Add the following constants to your `wp-config.php` file:

```php
// S3 API credentials
define('S3_KEY', 'your-access-key');
define('S3_SECRET', 'your-secret-key');

// S3 bucket and endpoint configuration
define('S3_BUCKET', 'your-bucket-name');
define('S3_ENDPOINT', 'https://your-s3-endpoint.com');
define('S3_PUBLIC_URL', 'https://your-public-bucket-url.com');

// Optional configurations
define('S3_REGION', 'us-east-1'); // Defaults to 'us-east-1' if not specified
define('S3_PATH_STYLE', true);    // Set to true for most S3-compatible services (MinIO, DO Spaces)
define('S3_ROOT', '');            // Subfolder within bucket to use as root (optional)
```

## Usage

### Uploading New Media

The plugin supports two upload methods:

#### Direct S3 Uploads (Default)

When you upload files through the WordPress media uploader, files are sent directly from your browser to S3 using pre-signed URLs. This:
- Reduces load on your WordPress server
- Speeds up uploads, especially for large files
- Allows uploads to complete even if the connection to WordPress is interrupted

Thumbnails are generated asynchronously in the background via WP-Cron, so the upload process completes quickly without waiting for thumbnail generation.

#### Traditional Uploads (Fallback)

If direct upload fails for any reason, the plugin falls back to traditional WordPress upload where files are processed on the server before being moved to S3.

### Migrating Existing Media

To migrate your existing media library to S3, use the WP-CLI command:

```bash
# Migrate all media files to S3
wp wp-cloud-files migrate

# Migrate and keep local copies
wp wp-cloud-files migrate --keep-local

# Migrate 50 files starting from item 100
wp wp-cloud-files migrate --limit=50 --offset=100

# Force re-upload of all media
wp wp-cloud-files migrate --force

# Process in smaller batches (default is 20)
wp wp-cloud-files migrate --batch-size=10
```

### Document Thumbnails

The plugin automatically generates thumbnails for supported document types:
- PDF files
- Word documents (DOCX, DOC, ODT, RTF)
- Excel spreadsheets (XLSX, XLS, ODS, CSV)
- PowerPoint presentations (PPTX, PPT, ODP)

These thumbnails will appear in the WordPress media library just like image thumbnails.

### Processing Thumbnail Queue

Thumbnails are generated asynchronously via WP-Cron. You can also manually process the thumbnail queue:

```bash
# Process all pending thumbnails
wp wp-cloud-files process-thumbnails

# Process up to 10 pending thumbnails
wp wp-cloud-files process-thumbnails --limit=10
```

## How It Works

### Direct Upload Flow

1. When a user selects a file in the WordPress media uploader, the plugin requests a pre-signed S3 URL from the server
2. The file is uploaded directly from the browser to S3 using the pre-signed URL
3. After upload completes, a WordPress attachment is created with basic metadata
4. For images and documents, thumbnail generation is queued for background processing
5. WP-Cron processes the thumbnail queue asynchronously:
   - Downloads the file from S3 to temporary storage
   - Generates all required thumbnail sizes
   - Uploads thumbnails back to S3
   - Updates attachment metadata with thumbnail information
   - Cleans up temporary files
6. All URLs are automatically rewritten to point to your S3 bucket

### Traditional Upload Flow (Fallback)

1. Files are uploaded to the WordPress server
2. WordPress processes the file (image resizing, WebP conversion, etc.)
3. Files are then uploaded to your S3 bucket with public read permissions
4. Local files are removed to save disk space
5. All URLs are automatically rewritten to point to your S3 bucket

## Support

For issues, feature requests, or questions, please contact [mail@avu.nu](mailto:mail@avu.nu) or visit [https://avunu.io/](https://avunu.io/).

## License

This plugin is licensed under the GPL v3 or later.
