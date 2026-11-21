# WP Cloud Files

WP Cloud Files is a WordPress plugin that seamlessly integrates your WordPress media library with S3-compatible object storage. It automatically moves uploaded files to S3 and redirects requests to them from there, helping you scale your WordPress site and reduce server storage requirements.

## Features

- Automatically upload media files to S3 storage when added to WordPress
- Serve media files directly from S3, saving your server's bandwidth
- Optional **direct browser-to-S3 uploads** via presigned PUT URLs, bypassing the
  web server and any upload-size-limited proxy (e.g. Cloudflare's 100 MB cap)
- Support for all standard WordPress image sizes and optimizations
- Generate thumbnails for documents (PDF, Word, Excel, PowerPoint)
- WP-CLI commands to migrate an existing library, rewrite stored URLs, and check
  (`verify`, `status`, `inventory`) what is where
- Keeps private files (WooCommerce downloads, form uploads, invoices) local and out
  of the public bucket
- Refuses executable files on every path into uploads and the bucket
- Configurable through constants or environment variables, and installable with
  Composer (Bedrock) as well as from the release zip
- Supports WebP and AVIF alternatives when enabled in WordPress
- Handles image editing and regeneration workflows

## Requirements

- WordPress (tested with latest version)
- PHP 8.3 or higher
- S3-compatible storage provider (AWS S3, DigitalOcean Spaces, MinIO, etc.)
- Composer, only when installing from source or as a Composer package

## Installation

### Recommended: install the release zip

1. Download `wp-cloud-files.zip` from the [latest GitHub Release](https://github.com/Avunu/wp-cloud-files/releases/latest)
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the zip
3. Define the required constants in your `wp-config.php` file (see Configuration section)
4. Activate the plugin through the WordPress admin interface

The release zip bundles all Composer dependencies, so there is no separate `composer install`
step. Once installed, the plugin checks GitHub for new releases and offers one-click updates
through the normal WordPress Plugins screen.

### With Composer (Bedrock and other Composer-managed sites)

```bash
composer require avunu/wp-cloud-files
```

The package has type `wordpress-plugin`, so `composer/installers` puts it in the
plugins directory (`web/app/plugins/wp-cloud-files` on Bedrock). Its dependencies
are installed into the project's `vendor/` and loaded by the project autoloader;
the plugin only loads its own `vendor/autoload.php` when that file exists (the
release zip). Under Composer, and on any site with `DISALLOW_FILE_MODS`, the GitHub
self-updater is not loaded: Composer owns updates.

Configure it with environment variables (see below), so `config/application.php`
needs no changes.

### From source (development)

1. Clone the repository into your `/wp-content/plugins/` directory
2. Run `composer install` in the plugin directory to install dependencies
3. Define the required constants in your `wp-config.php` file (see Configuration section)
4. Activate the plugin through the WordPress admin interface

With Nix, `direnv allow` (or `nix develop --impure`) gives you PHP 8.3 with the
right extensions, Composer, Node, MariaDB and an rclone-backed S3 test server. `setup` prepares a local
WordPress; `devenv up` starts the services.

### Tests

```bash
nix develop .#ci --impure --command devenv-flake-test   # everything
WPCF_SUITES="unit" nix develop .#ci --impure --command devenv-flake-test
nix build .#checks.x86_64-linux.phpstan -L              # also: phpcs, unit,
                                                        # thumbnails, plugin-header
```

The browser JS has no build step — `npm run check:types` type-checks it in
place. Note that TypeScript 7 ships as a native binary and provides only `tsc`,
with no `tsserver`: editors need their own bundled TypeScript (the default) or
the TypeScript Native Preview extension. Pointing your editor's TSDK at
`node_modules/typescript/lib` will not work.

## Configuration

Every setting can be a constant in `wp-config.php` or an environment variable of
the same name. A constant wins when both are set; an empty environment variable
counts as unset. Boolean settings read from the environment accept `true`/`false`,
`1`/`0`, `yes`/`no` and `on`/`off`.

As constants:

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

// Uploads-relative prefixes that are never offloaded (default shown)
define('S3_EXCLUDE_PREFIXES', 'woocommerce_uploads/,fluentform/,gravity_forms/,wpo_wcpdf,wc-logs/');
```

Or as environment variables, for example in a Bedrock `.env` or a container's
environment:

```bash
S3_KEY=your-access-key
S3_SECRET=your-secret-key
S3_BUCKET=your-bucket-name
S3_ENDPOINT=https://your-s3-endpoint.com
S3_PUBLIC_URL=https://media.example.com
S3_PATH_STYLE=true
S3_DIRECT_UPLOADS=true
```

### Private files stay local

Some plugins keep private files under uploads and guard access to them
themselves. Copying them to a public bucket would publish them. Files whose
uploads-relative path starts with one of `S3_EXCLUDE_PREFIXES` are never uploaded,
never deleted locally, and keep their local URL, in every code path: new uploads,
document thumbnails, direct uploads, `migrate` (even with `--force`) and
`migrate-urls`.

The default list covers WooCommerce downloadable products
(`woocommerce_uploads/`), Fluent Forms and Gravity Forms entries, WooCommerce PDF
Invoices (`wpo_wcpdf`, which matches its `wpo_wcpdf_<hash>/` directory) and
WooCommerce logs. Setting `S3_EXCLUDE_PREFIXES` replaces the default list; end a
prefix with `/` to match one directory exactly. To exclude nothing, define the
constant as `''`, or set the environment variable to `,` (an empty environment
variable counts as unset and keeps the default list).

For decisions a prefix cannot express, use the filter:

```php
add_filter('wpcf_should_offload', function (bool $offload, string $path, int $attachmentId): bool {
    return $offload && !get_post_meta($attachmentId, 'members_only', true);
}, 10, 3);
```

Direct browser uploads leave purpose-specific uploaders (WooCommerce's downloadable
file picker) on the normal upload path, so WooCommerce can still place those files
in its private directory.

### Executable files are refused

Scripts and programs (`.php` and its variants, `.phar`, `.cgi`, shell scripts,
Windows executables, `.htaccess`, and anything whose content sniffs as one) are
refused when uploaded, are never given a presigned upload URL, and are never
written to the bucket, even if a site's `upload_mimes` filter allows them. An
interpreter extension anywhere in the name counts (`shell.php.jpg`).

### Without credentials (development)

Outside production (`WP_ENVIRONMENT_TYPE` other than `production`), `S3_KEY` and
`S3_SECRET` are optional. With only `S3_BUCKET`, `S3_ENDPOINT` and `S3_PUBLIC_URL`
defined the plugin runs **read-only**: the site's existing media is served from the
public URL, new uploads stay in `wp-content/uploads`, and nothing is written to or
deleted from the bucket — so a development checkout renders the site without holding
a key that could write to the production bucket. wp-admin says so. In production the
credentials are required as before.

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

`migrate` never replaces an object that is already in the bucket unless you pass
`--force`; the local file is kept in that case.

### Rewriting stored URLs

```bash
# Preview, then rewrite the uploads URL to S3_PUBLIC_URL across the database
wp wp-cloud-files migrate-urls --dry-run
wp wp-cloud-files migrate-urls

# Leave files that stay on the local volume on the local URL
wp wp-cloud-files migrate-urls --exclude-prefix=cwicly/,fonts/
```

`migrate-urls` wraps `wp search-replace`. URLs under `S3_EXCLUDE_PREFIXES` and any
`--exclude-prefix` are left alone through a regular-expression lookahead, and the
replacement runs twice: for plain URLs, and for the JSON-escaped form
(`https:\/\/…`) that block attributes, `wp_font_face` posts and page builders store.

### Checking a site

```bash
# Configuration, with the source of every setting; credentials are never shown
wp wp-cloud-files status
wp wp-cloud-files status --format=json

# Every file of 20 random attachments (or --all) must be in the bucket, and no
# private file may be. Exits non-zero otherwise.
wp wp-cloud-files verify
wp wp-cloud-files verify --all --format=json

# What is in the local uploads directory: attachment folders, private folders,
# public files generated by plugins (Cwicly CSS, the Font Library), and unknowns.
wp wp-cloud-files inventory --json
```

`inventory --json` includes `non_attachment_prefixes`, the list to pass to
`migrate-urls --exclude-prefix` for files that are not media library attachments.

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

1. bumps the version in `.release-please-manifest.json` and the `index.php` plugin header, and
   updates `CHANGELOG.md`;
2. creates the git tag and a GitHub Release with notes generated from the commits;
3. builds the plugin on a Nix runner (`nix build .#zip`) and attaches `wp-cloud-files.zip`
   (with `vendor/` bundled) as the release asset.

Client sites then pick up the new version automatically via
[plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker).

`composer.json` carries no `version`: Packagist reads versions from the git tags, so the tag
Release Please creates is the Composer release too. The Nix build reads the version from
`.release-please-manifest.json`, and the `plugin-header` check fails if the committed
`index.php` header disagrees with it.

> **Repo setting:** Settings → Actions → General → Workflow permissions must allow
> "Read and write permissions" and "Allow GitHub Actions to create and approve pull requests"
> so Release Please can open the release PR.

## Support

For issues, feature requests, or questions, please contact [mail@avu.nu](mailto:mail@avu.nu) or visit [https://avunu.net/](https://avunu.net/).

## License

This plugin is licensed under the GPL v3 or later (GPL-3.0-or-later). See [LICENSE](LICENSE) for the full text.
