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

    /**
     * Upper bound on the S3 collision-suffix search. Each attempt is a network
     * round trip, so an unbounded loop lets any user with upload_files amplify a
     * single presign into arbitrarily many remote calls inside one request.
     */
    private const MAX_KEY_ATTEMPTS = 100;

    /**
     * An uploads-relative key: an optional YYYY/MM/ prefix and then a bare
     * filename. The prefix is optional because uploads_use_yearmonth_folders can
     * be turned off.
     */
    private const KEY_PATTERN = '#^(\d{4}/\d{2}/)?[^/]+$#';

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
        // resolves the canonical type against get_allowed_mime_types(), which
        // varies with the unfiltered_html capability. Note it does NOT sniff the
        // bytes -- the server never sees them on this path -- so this is an
        // extension allowlist, not content validation.
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

        // Bind the key to this user for the life of the presign. Without it,
        // /attachment will accept any key at all -- see createAttachment().
        $expiresAt = strtotime($expires) ?: (time() + 900);

        return new WP_REST_Response([
            'uploadUrl' => $uploadUrl,
            'key'       => $key,
            'name'      => wp_basename($key),
            'type'      => $type,
            'token'     => $this->signKey($key, $expiresAt),
            'expires'   => $expiresAt,
        ]);
    }

    /**
     * Sign an object key for the current user until $expires.
     *
     * Stateless on purpose. A transient would be evicted unpredictably under an
     * external object cache (set_transient defers to wp_cache_set, which Redis
     * and Memcached may drop under LRU pressure mid-upload), and a nonce lives
     * for 12-24 hours, which is 48-96x the presign window and cannot be narrowed
     * without the site-wide nonce_life filter.
     *
     * Binding to the user id means one user's token is useless to another.
     * Replaying your own token only re-mints your own key, so no stored state is
     * required to make this safe.
     */
    private function signKey(string $key, int $expires): string
    {
        return wp_hash(get_current_user_id() . '|' . $key . '|' . $expires, 'nonce', 'sha256');
    }

    private function verifyKey(string $key, string $token, int $expires): bool
    {
        if ($token === '' || $expires <= time()) {
            return false;
        }

        return hash_equals($this->signKey($key, $expires), $token);
    }

    /**
     * Register the attachment after the browser has PUT the file to S3,
     * and queue optimization for processable types.
     */
    public function createAttachment(WP_REST_Request $request)
    {
        $key     = ltrim((string) $request->get_param('key'), '/');
        $token   = (string) $request->get_param('token');
        $expires = (int) $request->get_param('expires');
        $title   = sanitize_text_field((string) $request->get_param('title'));
        $post    = (int) $request->get_param('post');

        // Reject path traversal / absolute escapes.
        if ($key === '' || strpos($key, '..') !== false) {
            return new WP_Error('wpcf_bad_key', 'Invalid object key.', ['status' => 400]);
        }

        // Defence in depth behind the token: keep the key inside the uploads
        // layout so a signing mistake cannot reach the rest of the bucket.
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return new WP_Error('wpcf_bad_key', 'Invalid object key.', ['status' => 400]);
        }

        // The key must be one THIS user was issued a presign for. Without this
        // check any user with upload_files could name an arbitrary object and
        // have it registered as their own attachment -- which exposes it via a
        // public URL and, because deleting an attachment deletes the underlying
        // object, lets them destroy arbitrary bucket contents.
        if (!$this->verifyKey($key, $token, $expires)) {
            return new WP_Error(
                'wpcf_bad_token',
                'This upload could not be verified. Please reload the page and try again.',
                ['status' => 403]
            );
        }

        // Re-validate the type from the key itself; never trust a client mime.
        $filetype = wp_check_filetype(wp_basename($key));
        if (empty($filetype['type'])) {
            return new WP_Error('wpcf_disallowed_type', 'This file type is not allowed.', ['status' => 403]);
        }
        $type = $filetype['type'];

        // Same check core's attachment controller makes: being able to upload
        // does not imply being able to attach media to someone else's post.
        if ($post > 0 && !current_user_can('edit_post', $post)) {
            return new WP_Error(
                'rest_cannot_edit',
                'Sorry, you are not allowed to upload media to this post.',
                ['status' => 403]
            );
        }

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

        // Read the size from S3 rather than believing the browser.
        $metadata = ['file' => $key];
        try {
            $metadata['filesize'] = $s3->getFilesystem()->fileSize($key);
        } catch (\Throwable $e) {
            // Not fatal: the optimizer fills metadata in properly later.
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

        $withSuffix = static fn(string $suffix): string => $ext !== ''
            ? "{$base}-{$suffix}.{$ext}"
            : "{$base}-{$suffix}";

        try {
            // Bounded: each iteration is a network HEAD, so a long collision
            // chain would otherwise turn one presign into an unbounded number of
            // remote calls inside a synchronous request.
            for ($suffix = 1; $suffix <= self::MAX_KEY_ATTEMPTS; $suffix++) {
                if (!$s3->getFilesystem()->fileExists($key)) {
                    return $key;
                }

                $key = $makeKey($withSuffix((string) $suffix));
            }
        } catch (\Throwable $e) {
            // Existence checks are unavailable. Falling through to the plain
            // name here would silently overwrite whatever is already at that key
            // whenever S3 is briefly unhealthy, so take the random suffix below.
        }

        // Either the chain is pathologically long or S3 could not be queried.
        // A random suffix is collision-safe without another round trip.
        //
        // Note this loop reserves nothing, so two concurrent presigns for the
        // same filename can still agree on a key; the random fallback narrows
        // that window but closing it entirely needs a reservation record.
        return $makeKey($withSuffix(bin2hex(random_bytes(4))));
    }
}
