<?php

namespace Avunu\WPCloudFiles\DirectUpload;

use Avunu\WPCloudFiles\S3Client;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST endpoints powering direct browser-to-S3 uploads.
 *
 * Two steps:
 *   1. POST /presign    -> returns a short-lived presigned PUT URL + the S3 key
 *   2. POST /attachment -> registers the WordPress attachment once the PUT lands
 *                          and queues background media optimization
 */
class RestController
{
    private const NAMESPACE = 'wp-cloud-files/v1';

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/presign', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'presign'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/attachment', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'createAttachment'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('upload_files');
    }

    /**
     * Issue a presigned PUT URL for a not-yet-uploaded file.
     */
    public function presign(WP_REST_Request $request)
    {
        $filename = sanitize_file_name((string) $request->get_param('filename'));

        if ($filename === '') {
            return new WP_Error('wpcf_bad_filename', 'A filename is required.', ['status' => 400]);
        }

        // Trust the extension, not the client-sent mime type. wp_check_filetype()
        // resolves the canonical type against the current user's allowed types
        // (which already respects the unfiltered_upload capability).
        $filetype = wp_check_filetype($filename);
        if (empty($filetype['type'])) {
            return new WP_Error('wpcf_disallowed_type', 'This file type is not allowed.', ['status' => 403]);
        }
        $type = $filetype['type'];

        $dir = wp_upload_dir();
        if (!empty($dir['error'])) {
            return new WP_Error('wpcf_uploaddir', $dir['error'], ['status' => 500]);
        }

        $key = $this->uniqueKey($dir, $filename);

        $expires = (defined('S3_PRESIGN_EXPIRES') && S3_PRESIGN_EXPIRES) ? S3_PRESIGN_EXPIRES : '+15 minutes';

        $uploadUrl = S3Client::getInstance()->createPresignedPutUrl($key, $type, $expires);

        return new WP_REST_Response([
            'uploadUrl' => $uploadUrl,
            'key'       => $key,
            'name'      => wp_basename($key),
            'type'      => $type,
        ]);
    }

    /**
     * Register the attachment after the browser has PUT the file to S3,
     * and queue optimization for processable types.
     */
    public function createAttachment(WP_REST_Request $request)
    {
        $key   = ltrim((string) $request->get_param('key'), '/');
        $size  = (int) $request->get_param('size');
        $title = sanitize_text_field((string) $request->get_param('title'));
        $post  = (int) $request->get_param('post');

        // Reject path traversal / absolute escapes.
        if ($key === '' || strpos($key, '..') !== false) {
            return new WP_Error('wpcf_bad_key', 'Invalid object key.', ['status' => 400]);
        }

        // Re-validate the type from the key itself; never trust a client mime.
        $filetype = wp_check_filetype(wp_basename($key));
        if (empty($filetype['type'])) {
            return new WP_Error('wpcf_disallowed_type', 'This file type is not allowed.', ['status' => 403]);
        }
        $type = $filetype['type'];

        $s3 = S3Client::getInstance();

        // The object must actually exist on S3, so we never mint a record for a
        // PUT that never completed (or a fabricated key).
        try {
            if (!$s3->getFilesystem()->fileExists($key)) {
                return new WP_Error('wpcf_missing_object', 'Uploaded object not found on S3.', ['status' => 409]);
            }
        } catch (\Throwable $e) {
            return new WP_Error('wpcf_s3_error', 'Could not verify the uploaded object.', ['status' => 500]);
        }

        if ($title === '') {
            $title = sanitize_text_field(preg_replace('/\.[^.]+$/', '', wp_basename($key)));
        }

        $attachment = [
            'post_mime_type' => $type,
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => $s3->getPublicUrl($key),
        ];

        $attachmentId = wp_insert_attachment($attachment, $key, $post, true);

        if (is_wp_error($attachmentId)) {
            return $attachmentId;
        }

        // Record the original key so the optimizer knows which file is already on
        // S3 and must not be re-uploaded from the server.
        update_post_meta($attachmentId, '_wpcf_direct_upload_key', $key);

        $metadata = ['file' => $key];
        if ($size > 0) {
            $metadata['filesize'] = $size;
        }

        if (DirectUploadProcessor::isOptimizable($type)) {
            // Minimal metadata for now; the cron job fills in sizes/thumbnails.
            wp_update_attachment_metadata($attachmentId, $metadata);
            update_post_meta($attachmentId, '_wpcf_pending_optimization', 1);
            wp_schedule_single_event(time(), 'wpcf_process_direct_upload', [$attachmentId]);
        } else {
            // Nothing to optimize (video, archive, etc.) — finalize immediately.
            wp_update_attachment_metadata($attachmentId, $metadata);
        }

        // Same shape async-upload.php returns, so the JS can hand it straight to
        // wp.media's existing success handler.
        return new WP_REST_Response(wp_prepare_attachment_for_js($attachmentId));
    }

    /**
     * Build an uploads-relative key that is unique both locally and on S3.
     */
    private function uniqueKey(array $dir, string $filename): string
    {
        $filename = wp_unique_filename($dir['path'], $filename);
        $subdir   = ltrim((string) $dir['subdir'], '/');

        $makeKey = static fn(string $name): string => ($subdir !== '' ? $subdir . '/' : '') . $name;

        $key = $makeKey($filename);

        // wp_unique_filename only checks the local filesystem; in S3-only setups
        // the local dir may be empty while the key already exists remotely.
        $s3 = S3Client::getInstance();
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = $ext !== '' ? substr($filename, 0, -(strlen($ext) + 1)) : $filename;
        $suffix = 1;

        try {
            while ($s3->getFilesystem()->fileExists($key)) {
                $candidate = $ext !== '' ? "{$base}-{$suffix}.{$ext}" : "{$base}-{$suffix}";
                $key = $makeKey($candidate);
                $suffix++;
            }
        } catch (\Throwable $e) {
            // If existence checks fail, fall back to the locally-unique name.
        }

        return $key;
    }
}
