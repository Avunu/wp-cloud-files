# WP Cloud Files

WP Cloud Files is a WordPress plugin that seamlessly integrates your WordPress media library with S3-compatible object storage. It automatically moves uploaded files to S3 and redirects requests to them from there, helping you scale your WordPress site and reduce server storage requirements.

## Features

- Automatically upload media files to S3 storage when added to WordPress
- Serve media files directly from S3, saving your server's bandwidth
- Support for all standard WordPress image sizes and optimizations
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

### Recommended: install the release zip

1. Download `wp-cloud-files.zip` from the [latest GitHub Release](https://github.com/Avunu/wp-cloud-files/releases/latest)
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the zip
3. Define the required constants in your `wp-config.php` file (see Configuration section)
4. Activate the plugin through the WordPress admin interface

The release zip bundles all Composer dependencies, so there is no separate `composer install`
step. Once installed, the plugin checks GitHub for new releases and offers one-click updates
through the normal WordPress Plugins screen.

### From source (development)

1. Clone the repository into your `/wp-content/plugins/` directory
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

Once configured, the plugin automatically handles new media uploads. Just use the WordPress media uploader as usual, and files will be sent to S3 after WordPress processes them.

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

## How It Works

1. When media is uploaded to WordPress, the plugin waits for WordPress to complete all processing (image resizing, WebP conversion, etc.)
2. Files are then uploaded to your S3 bucket with public read permissions
3. Local files are removed to save disk space
4. All URLs are automatically rewritten to point to your S3 bucket

## Releasing

Releases are cut from the Nix dev shell (`devenv shell` / `direnv allow`) with a single command:

```bash
release patch   # 0.0.2 -> 0.0.3
release minor   # 0.0.x -> 0.1.0
release major   # 0.x.y -> 1.0.0
release 1.2.0   # set an explicit version
```

The `release` script bumps the version in both `index.php` and `composer.json`, commits, tags,
and pushes (`git push --follow-tags`). Pushing the tag triggers the
[Release workflow](.github/workflows/release.yml), which builds the plugin on a Nix runner,
produces `wp-cloud-files.zip` (with `vendor/` bundled), and publishes a GitHub Release with
auto-generated notes. Client sites pick up the new version automatically via
[plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker).

Pre-flight checks: the command refuses to run unless the working tree is clean, you're on
`main`, and `main` is in sync with `origin`.

## Support

For issues, feature requests, or questions, please contact [mail@avu.nu](mailto:mail@avu.nu) or visit [https://avunu.io/](https://avunu.io/).

## License

This plugin is licensed under the GPL v3 or later.
