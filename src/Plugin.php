<?php

namespace Avunu\WPCloudFiles;

use Avunu\WPCloudFiles\DirectUpload\DirectUploadProcessor;
use Avunu\WPCloudFiles\DirectUpload\RestController;

class Plugin
{
    public static function boot(): void
    {
        $instance = new self();
        $instance->registerHooks();
    }

    private function registerHooks(): void
    {
        // Initialize our handlers
        $mediaHandler = new MediaHandler();
        $urlRewriter = new UrlRewriter();
        $thumbnailHandler = new ThumbnailHandler();

        // URL rewriting for attachments
        add_filter('wp_get_attachment_url', [$urlRewriter, 'rewriteAttachmentUrl'], 10, 2);

        // Handle image srcset URLs
        add_filter('wp_calculate_image_srcset', [$urlRewriter, 'rewriteSrcsetUrls'], 10, 5);

        // Process complete media after WordPress is done with it
        add_filter('wp_update_attachment_metadata', [$mediaHandler, 'processMedia'], 999, 2);

        // Handle deletions
        add_action('delete_attachment', [$mediaHandler, 'handleDeletion'], 10);

        // Handle document thumbnail generation
        add_filter('wp_generate_attachment_metadata', [$thumbnailHandler, 'handleDocumentThumbnails'], 10, 2);

        // Filter to show thumbnails in admin
        add_filter('wp_prepare_attachment_for_js', [$thumbnailHandler, 'prepareAttachmentForJs'], 10, 3);

        // Background optimization for directly-uploaded files. Bound
        // unconditionally so any already-queued events still run even if the
        // feature is later disabled.
        add_action('wpcf_process_direct_upload', [new DirectUploadProcessor(), 'process'], 10, 1);

        // Direct browser-to-S3 uploads (presigned PUT), opt-in via S3_DIRECT_UPLOADS.
        if (self::directUploadsEnabled()) {
            add_action('rest_api_init', [new RestController(), 'register']);
            add_action('admin_enqueue_scripts', [$this, 'enqueueDirectUploadScript'], 100);

            // Advertise the S3 ceiling instead of the PHP limit, so the uploader
            // UI is accurate and Plupload doesn't reject large files client-side.
            add_filter('upload_size_limit', [$this, 'filterUploadSizeLimit']);
        }
        
        // // Handle image editing (in case it bypasses normal upload flow)
        // add_action('wp_ajax_image-editor', function() use ($mediaHandler) {
        //     add_filter('wp_update_attachment_metadata', function($metadata, $attachment_id) use ($mediaHandler) {
        //         return $mediaHandler->processMedia($metadata, $attachment_id);
        //     }, 999, 2);
        // }, 1);

        // Handle content URL rewrites
        // add_filter('the_content', [$urlRewriter, 'rewriteContentUrls'], 10);
    }

    private static function directUploadsEnabled(): bool
    {
        return defined('S3_DIRECT_UPLOADS') && S3_DIRECT_UPLOADS;
    }

    private static function directUploadMinSize(): int
    {
        return (defined('S3_DIRECT_UPLOAD_MIN_SIZE') && S3_DIRECT_UPLOAD_MIN_SIZE)
            ? (int) S3_DIRECT_UPLOAD_MIN_SIZE
            : 0;
    }

    private static function directUploadMaxSize(): int
    {
        return (defined('S3_MAX_UPLOAD_SIZE') && S3_MAX_UPLOAD_SIZE)
            ? (int) S3_MAX_UPLOAD_SIZE
            : 5 * 1024 * 1024 * 1024; // 5 GiB — common single-PUT ceiling
    }

    /**
     * Raise the advertised upload limit to the S3 direct-upload ceiling. Fixes
     * both the "Maximum upload file size" UI text and Plupload's client-side
     * max_file_size check (which would otherwise reject large files before our
     * JS can intercept them). Never lowers a higher pre-existing limit.
     *
     * @param int $size Current limit (min of upload_max_filesize / post_max_size).
     */
    public function filterUploadSizeLimit($size): int
    {
        return max((int) $size, self::directUploadMaxSize());
    }

    /**
     * Enqueue the direct-upload script, but only on screens where the wp.media
     * uploader is actually present.
     */
    public function enqueueDirectUploadScript(): void
    {
        if (!wp_script_is('media-views', 'enqueued')) {
            return;
        }

        $pluginFile = dirname(__DIR__) . '/index.php';
        $scriptPath = dirname(__DIR__) . '/assets/js/direct-upload.js';
        $version = file_exists($scriptPath) ? (string) filemtime($scriptPath) : false;

        wp_enqueue_script(
            'wpcf-direct-upload',
            plugins_url('assets/js/direct-upload.js', $pluginFile),
            ['jquery', 'underscore', 'wp-plupload', 'media-views', 'wp-api-fetch'],
            $version,
            true
        );

        wp_localize_script('wpcf-direct-upload', 'wpcfDirectUpload', [
            'enabled' => true,
            'minSize' => self::directUploadMinSize(),
        ]);
    }
}
