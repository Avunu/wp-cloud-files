<?php

namespace Avunu\WPCloudFiles;

class DirectUploadHandler
{
    /**
     * Handle AJAX request for pre-signed URL
     */
    public function handlePresignedUrlRequest(): void
    {
        // Verify user can upload files
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
            return;
        }
        
        // Verify nonce
        if (!check_ajax_referer('wp-cloud-files-upload', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            return;
        }
        
        // Get parameters
        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $fileType = sanitize_text_field($_POST['fileType'] ?? '');
        
        if (empty($filename) || empty($fileType)) {
            wp_send_json_error(['message' => 'Missing required parameters'], 400);
            return;
        }
        
        // Generate upload directory path
        $upload_dir = wp_upload_dir();
        $relative_path = trim(str_replace($upload_dir['basedir'], '', $upload_dir['path']), '/');
        
        // Create unique filename to prevent conflicts
        $file_info = pathinfo($filename);
        $unique_filename = wp_unique_filename($upload_dir['path'], $filename);
        
        // Build S3 path
        $s3_path = $relative_path . '/' . $unique_filename;
        
        // Generate pre-signed URL
        $s3Client = S3Client::getInstance();
        $presigned_url = $s3Client->generatePresignedUploadUrl($s3_path, $fileType);
        
        // Get public URL for the file
        $public_url = $s3Client->getPublicUrl($s3_path);
        
        wp_send_json_success([
            'upload_url' => $presigned_url,
            'public_url' => $public_url,
            's3_path' => $s3_path,
            'filename' => $unique_filename,
        ]);
    }
    
    /**
     * Create WordPress attachment from directly uploaded file
     */
    public function createAttachmentFromDirectUpload(): void
    {
        // Verify user can upload files
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
            return;
        }
        
        // Verify nonce
        if (!check_ajax_referer('wp-cloud-files-upload', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            return;
        }
        
        // Get parameters
        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $s3_path = sanitize_text_field($_POST['s3_path'] ?? '');
        $file_type = sanitize_text_field($_POST['file_type'] ?? '');
        $file_size = intval($_POST['file_size'] ?? 0);
        
        if (empty($filename) || empty($s3_path) || empty($file_type)) {
            wp_send_json_error(['message' => 'Missing required parameters'], 400);
            return;
        }
        
        $upload_dir = wp_upload_dir();
        
        // Create attachment post
        $attachment = [
            'post_mime_type' => $file_type,
            'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        
        $attachment_id = wp_insert_attachment($attachment, false);
        
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => 'Failed to create attachment'], 500);
            return;
        }
        
        // Set the file path in attachment metadata
        $file_path = $upload_dir['basedir'] . '/' . $s3_path;
        update_attached_file($attachment_id, $file_path);
        
        // Handle images: need to download temporarily to get dimensions
        if (strpos($file_type, 'image/') === 0) {
            $this->handleImageAttachment($attachment_id, $s3_path, $file_type);
        } else {
            // For non-images, create minimal metadata
            $metadata = [
                'file' => $s3_path,
                'filesize' => $file_size,
            ];
            
            // Queue thumbnail generation for supported document types
            if ($this->isDocumentType($file_type)) {
                $this->queueThumbnailGeneration($attachment_id);
                update_post_meta($attachment_id, '_thumbnail_generation_pending', 1);
            }
            
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
        
        wp_send_json_success([
            'attachment_id' => $attachment_id,
            'attachment' => wp_prepare_attachment_for_js($attachment_id),
        ]);
    }
    
    /**
     * Handle image attachment metadata
     */
    private function handleImageAttachment(int $attachment_id, string $s3_path, string $file_type): void
    {
        $s3Client = S3Client::getInstance();
        $upload_dir = wp_upload_dir();
        
        // Download image temporarily to get dimensions
        $temp_file = wp_tempnam($s3_path);
        
        if ($s3Client->downloadFile($s3_path, $temp_file)) {
            // Get image dimensions
            $image_size = getimagesize($temp_file);
            
            if ($image_size) {
                // Create basic metadata
                $metadata = [
                    'width'  => $image_size[0],
                    'height' => $image_size[1],
                    'file'   => $s3_path,
                    'filesize' => filesize($temp_file),
                ];
                
                // Queue thumbnail generation asynchronously
                $this->queueThumbnailGeneration($attachment_id);
                update_post_meta($attachment_id, '_thumbnail_generation_pending', 1);
                
                wp_update_attachment_metadata($attachment_id, $metadata);
            }
            
            // Clean up temp file
            @unlink($temp_file);
        }
    }
    
    /**
     * Queue thumbnail generation for processing
     */
    private function queueThumbnailGeneration(int $attachment_id): void
    {
        // Get existing queue or create new one
        $queue = get_option('wp_cloud_files_thumbnail_queue', []);
        
        if (!in_array($attachment_id, $queue)) {
            $queue[] = $attachment_id;
            update_option('wp_cloud_files_thumbnail_queue', $queue, false);
        }
        
        // Schedule cron job if not already scheduled
        if (!wp_next_scheduled('wp_cloud_files_process_thumbnails')) {
            wp_schedule_single_event(time() + 60, 'wp_cloud_files_process_thumbnails');
        }
    }
    
    /**
     * Check if mime type is a supported document type
     */
    private function isDocumentType(string $mime_type): bool
    {
        $document_types = [
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
        
        return in_array($mime_type, $document_types, true);
    }
    
    /**
     * Enqueue admin scripts for direct upload
     */
    public function enqueueAdminScripts(): void
    {
        // Only load on media upload pages
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['upload', 'media', 'post', 'page'])) {
            return;
        }
        
        wp_enqueue_script(
            'wp-cloud-files-direct-upload',
            plugins_url('assets/direct-upload.js', dirname(__FILE__)),
            ['jquery', 'media-upload'],
            '1.0.0',
            true
        );
        
        wp_localize_script('wp-cloud-files-direct-upload', 'wpCloudFiles', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp-cloud-files-upload'),
        ]);
    }
}
