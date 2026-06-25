<?php

namespace Avunu\WPCloudFiles\DirectUpload;

use Avunu\WPCloudFiles\S3Client;

/**
 * Background job that optimizes a directly-uploaded file.
 *
 * Files uploaded straight to S3 never touch the web server, so WordPress never
 * got a chance to generate image sizes, modern-format variants, or document
 * thumbnails. This handler downloads the original once, runs the native
 * metadata pipeline, and lets the existing MediaHandler upload only the
 * derivatives back to S3 (the original is already there).
 */
class DirectUploadProcessor
{
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY  = 300; // seconds

    /**
     * Mime types worth optimizing. Images are matched by prefix; the rest are
     * the document types DocumentThumbnailer/ThumbnailHandler can render.
     */
    private const DOCUMENT_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'application/rtf',
        'text/rtf',
        'text/csv',
    ];

    public static function isOptimizable(string $mime): bool
    {
        return strpos($mime, 'image/') === 0 || in_array($mime, self::DOCUMENT_TYPES, true);
    }

    /**
     * Cron callback: download, generate metadata, re-upload derivatives.
     */
    public function process(int $attachmentId): void
    {
        if (get_post_type($attachmentId) !== 'attachment') {
            return;
        }

        $key = (string) get_post_meta($attachmentId, '_wpcf_direct_upload_key', true);
        if ($key === '') {
            // Fall back to the stored relative path.
            $key = (string) get_post_meta($attachmentId, '_wp_attached_file', true);
        }
        if ($key === '') {
            return;
        }

        $uploads = wp_upload_dir();
        $basedir = trailingslashit($uploads['basedir']);
        $localPath = $basedir . $key;

        // Pull the original down from S3.
        if (!file_exists($localPath)) {
            wp_mkdir_p(dirname($localPath));

            if (!S3Client::getInstance()->downloadFile($key, $localPath)) {
                $this->handleFailure($attachmentId);
                return;
            }
        }

        wp_raise_memory_limit('image');
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        // wp_generate_attachment_metadata lives in an admin include that cron
        // requests do not load by default.
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Generates image sizes + modern formats, and (via the
        // wp_generate_attachment_metadata hook) document thumbnails.
        $metadata = wp_generate_attachment_metadata($attachmentId, $localPath);

        // The original is already on S3; delete the local copy so MediaHandler's
        // file_exists() guards skip re-uploading it. Derivatives (and any -scaled
        // main file) remain local and get uploaded.
        if (file_exists($localPath)) {
            @unlink($localPath);
        }

        // Triggers MediaHandler::processMedia (priority 999): uploads derivatives
        // to S3 and removes the local copies.
        wp_update_attachment_metadata($attachmentId, is_array($metadata) ? $metadata : []);

        delete_post_meta($attachmentId, '_wpcf_pending_optimization');
        delete_post_meta($attachmentId, '_wpcf_direct_upload_key');
        delete_post_meta($attachmentId, '_wpcf_attempts');
        delete_post_meta($attachmentId, '_wpcf_optimize_error');
    }

    /**
     * Retry a few times on download failure, then give up and flag the error.
     */
    private function handleFailure(int $attachmentId): void
    {
        $attempts = (int) get_post_meta($attachmentId, '_wpcf_attempts', true) + 1;
        update_post_meta($attachmentId, '_wpcf_attempts', $attempts);

        if ($attempts < self::MAX_ATTEMPTS) {
            wp_schedule_single_event(time() + self::RETRY_DELAY, 'wpcf_process_direct_upload', [$attachmentId]);
            return;
        }

        update_post_meta($attachmentId, '_wpcf_optimize_error', 'download_failed');
        delete_post_meta($attachmentId, '_wpcf_pending_optimization');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Cloud Files: gave up optimizing attachment {$attachmentId} after {$attempts} attempts");
        }
    }
}
