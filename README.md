# WP Cloud Files

WP Cloud Files is a WordPress plugin that seamlessly integrates your WordPress media library with S3-compatible object storage. It automatically moves uploaded files to S3 and redirects requests to them from there, helping you scale your WordPress site and reduce server storage requirements.

## Features

- Automatically upload media files to S3 storage when added to WordPress
- Serve media files directly from S3, saving your server's bandwidth
- Optional **direct browser-to-S3 uploads** via presigned PUT URLs, bypassing the
  web server and any upload-size-limited proxy (e.g. Cloudflare's 100 MB cap)
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

// Direct browser-to-S3 uploads (optional, opt-in)
define('S3_DIRECT_UPLOADS', true);        // Enable direct uploads. REQUIRES bucket CORS (see below)
define('S3_DIRECT_UPLOAD_MIN_SIZE', 0);   // Min bytes to route direct; 0 = all uploads (default)
define('S3_MAX_UPLOAD_SIZE', 5368709120); // Advertised max upload size in bytes (default 5 GiB)
define('S3_UPLOAD_ACL', 'public-read');   // Sign this ACL on the PUT. Omit for public buckets / R2
define('S3_PRESIGN_EXPIRES', '+15 minutes'); // Presigned URL lifetime (default '+15 minutes')
```

## Direct Browser-to-S3 Uploads

By default, uploads flow browser → web server → S3, so a proxy or PHP limit in
front of your site (such as Cloudflare's 100 MB request-body cap) limits how large
a file can be uploaded. Enabling `S3_DIRECT_UPLOADS` makes the Media Library
uploader send files **straight to your S3 endpoint** with a short-lived presigned
PUT URL — the file never passes through the web server.

Because the file bypasses WordPress entirely during upload, image sizes, WebP/AVIF
variants, and document thumbnails are generated afterward by a background WP-Cron
job (`wpcf_process_direct_upload`): it downloads the original once, runs the normal
WordPress optimization pipeline, and uploads only the derivatives back to S3. Until
that job runs, the full-size original is served in place of thumbnails.

> **Feature is opt-in.** It is disabled unless `S3_DIRECT_UPLOADS` is defined and
> truthy, because direct uploads require bucket CORS to be configured first.

### Required bucket CORS

The browser PUTs cross-origin to your S3 endpoint, so the bucket must allow it.
Set the allowed origin to your site URL. Example CORS rule:

```json
[
  {
    "AllowedOrigins": ["https://your-site.com"],
    "AllowedMethods": ["PUT"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3000
  }
]
```

Notes:
- Your S3 **endpoint** must itself accept large request bodies — only the *site*
  is behind the size-limited proxy, not the storage endpoint.
- For public buckets / Cloudflare R2 that don't support per-object ACLs, leave
  `S3_UPLOAD_ACL` undefined so no `x-amz-acl` header is signed. For AWS S3, MinIO,
  or DigitalOcean Spaces, set it to `public-read`.
- Single presigned PUT supports files up to your provider's per-PUT limit
  (commonly ~5 GB).

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

Releases are fully automated from [Conventional Commits](https://www.conventionalcommits.org/)
via [Release Please](https://github.com/googleapis/release-please). There is no manual version
bump — just write conventional commit messages:

- `fix: ...` → patch release (0.0.x)
- `feat: ...` → minor release (0.x.0)
- `feat!: ...` or a `BREAKING CHANGE:` footer → major release (x.0.0)
- `chore: ...`, `docs: ...`, `refactor: ...` → no release on their own

On every push to `main`, the [Release workflow](.github/workflows/release.yml) opens (or updates)
a **release PR** that accumulates the pending changes and previews the next version + changelog.
Merging that PR:

1. bumps the version in `composer.json` (and the root `VERSION` file) and updates `CHANGELOG.md`;
2. creates the git tag and a GitHub Release with notes generated from the commits;
3. builds the plugin on a Nix runner (`nix build .#zip`) and attaches `wp-cloud-files.zip`
   (with `vendor/` bundled) as the release asset.

Client sites then pick up the new version automatically via
[plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker).

The version in the `index.php` plugin header is stamped from `composer.json` at build time, so
`composer.json` is the single source of truth. (A from-source/dev checkout may show a stale
header version until built — the published zip is always correct.)

> **Repo setting:** Settings → Actions → General → Workflow permissions must allow
> "Read and write permissions" and "Allow GitHub Actions to create and approve pull requests"
> so Release Please can open the release PR.

## Support

For issues, feature requests, or questions, please contact [mail@avu.nu](mailto:mail@avu.nu) or visit [https://avunu.io/](https://avunu.io/).

## License

This plugin is licensed under the GPL v3 or later.
