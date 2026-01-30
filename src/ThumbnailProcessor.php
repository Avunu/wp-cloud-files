<?php

namespace Avunu\WPCloudFiles;

class ThumbnailProcessor
{
    /**
     * Process thumbnail generation queue
     */
    public function processQueue(): void
    {
        $queue = get_option('wp_cloud_files_thumbnail_queue', []);
        
        if (empty($queue)) {
            return;
        }
        
        // Process first item in queue
        $attachment_id = array_shift($queue);
        update_option('wp_cloud_files_thumbnail_queue', $queue, false);
        
        // Generate thumbnails for this attachment
        $this->generateThumbnails($attachment_id);
        
        // If queue still has items, schedule next run
        if (!empty($queue)) {
            wp_schedule_single_event(time() + 30, 'wp_cloud_files_process_thumbnails');
        }
    }
    
    /**
     * Generate thumbnails for an attachment
     */
    public function generateThumbnails(int $attachment_id): bool
    {
        $metadata = wp_get_attachment_metadata($attachment_id);
        $mime_type = get_post_mime_type($attachment_id);
        
        if (!$metadata || !$mime_type) {
            delete_post_meta($attachment_id, '_thumbnail_generation_pending');
            return false;
        }
        
        $s3_path = $metadata['file'] ?? '';
        if (empty($s3_path)) {
            delete_post_meta($attachment_id, '_thumbnail_generation_pending');
            return false;
        }
        
        $s3Client = S3Client::getInstance();
        $upload_dir = wp_upload_dir();
        $basedir = trailingslashit($upload_dir['basedir']);
        
        // Download file from S3 to temporary location
        $temp_file = wp_tempnam($s3_path);
        
        if (!$s3Client->downloadFile($s3_path, $temp_file)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Failed to download file from S3 for thumbnail generation: $s3_path");
            }
            delete_post_meta($attachment_id, '_thumbnail_generation_pending');
            return false;
        }
        
        // Generate thumbnails based on file type
        if (strpos($mime_type, 'image/') === 0) {
            $success = $this->generateImageThumbnails($attachment_id, $temp_file, $metadata);
        } else {
            $success = $this->generateDocumentThumbnails($attachment_id, $temp_file, $metadata, $mime_type);
        }
        
        // Clean up temporary file
        @unlink($temp_file);
        
        if ($success) {
            delete_post_meta($attachment_id, '_thumbnail_generation_pending');
        }
        
        return $success;
    }
    
    /**
     * Generate thumbnails for images
     */
    private function generateImageThumbnails(int $attachment_id, string $temp_file, array $metadata): bool
    {
        $upload_dir = wp_upload_dir();
        $basedir = trailingslashit($upload_dir['basedir']);
        $s3_path = $metadata['file'];
        $base_dir = dirname($s3_path);
        
        // Move temp file to proper location for WordPress to process
        $local_path = $basedir . $s3_path;
        $local_dir = dirname($local_path);
        
        if (!is_dir($local_dir)) {
            wp_mkdir_p($local_dir);
        }
        
        copy($temp_file, $local_path);
        
        // Let WordPress generate the thumbnails
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $new_metadata = wp_generate_attachment_metadata($attachment_id, $local_path);
        
        if (!$new_metadata) {
            @unlink($local_path);
            return false;
        }
        
        // Merge with existing metadata
        $metadata = array_merge($metadata, $new_metadata);
        
        // Upload all generated thumbnails to S3
        $s3Client = S3Client::getInstance();
        
        // Upload sizes
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                if (!empty($size_data['file'])) {
                    $size_path = trailingslashit($base_dir) . $size_data['file'];
                    $size_local_path = $basedir . $size_path;
                    
                    if (file_exists($size_local_path)) {
                        $s3Client->uploadFile($size_local_path, $size_path);
                        @unlink($size_local_path);
                        
                        // Handle sources (WebP/AVIF)
                        if (!empty($size_data['sources']) && is_array($size_data['sources'])) {
                            foreach ($size_data['sources'] as $source) {
                                if (!empty($source['file'])) {
                                    $source_path = trailingslashit($base_dir) . $source['file'];
                                    $source_local_path = $basedir . $source_path;
                                    
                                    if (file_exists($source_local_path)) {
                                        $s3Client->uploadFile($source_local_path, $source_path);
                                        @unlink($source_local_path);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Upload sources for main file (WebP/AVIF)
        if (!empty($metadata['sources']) && is_array($metadata['sources'])) {
            foreach ($metadata['sources'] as $source) {
                if (!empty($source['file'])) {
                    $source_path = trailingslashit($base_dir) . $source['file'];
                    $source_local_path = $basedir . $source_path;
                    
                    if (file_exists($source_local_path)) {
                        $s3Client->uploadFile($source_local_path, $source_path);
                        @unlink($source_local_path);
                    }
                }
            }
        }
        
        // Upload original image if exists
        if (!empty($metadata['original_image'])) {
            $original_path = trailingslashit($base_dir) . $metadata['original_image'];
            $original_local_path = $basedir . $original_path;
            
            if (file_exists($original_local_path)) {
                $s3Client->uploadFile($original_local_path, $original_path);
                @unlink($original_local_path);
            }
        }
        
        // Clean up main file
        @unlink($local_path);
        
        // Update metadata
        wp_update_attachment_metadata($attachment_id, $metadata);
        
        return true;
    }
    
    /**
     * Generate thumbnails for documents
     */
    private function generateDocumentThumbnails(int $attachment_id, string $temp_file, array $metadata, string $mime_type): bool
    {
        $thumbnailer = new DocumentThumbnailer();
        $s3Client = S3Client::getInstance();
        $upload_dir = wp_upload_dir();
        $basedir = trailingslashit($upload_dir['basedir']);
        
        $s3_path = $metadata['file'];
        $base_dir = dirname($s3_path);
        $original_filename = pathinfo($s3_path, PATHINFO_FILENAME);
        
        // Get image sizes to generate
        $sizes = [
            'full' => ['width' => 1500, 'height' => 1500],
            'thumbnail' => ['width' => get_option('thumbnail_size_w', 150), 'height' => get_option('thumbnail_size_h', 150)],
            'medium' => ['width' => get_option('medium_size_w', 300), 'height' => get_option('medium_size_h', 300)],
            'large' => ['width' => get_option('large_size_w', 1024), 'height' => get_option('large_size_h', 1024)],
        ];
        
        if (!isset($metadata['sizes'])) {
            $metadata['sizes'] = [];
        }
        
        $generated = false;
        
        foreach ($sizes as $size_name => $dimensions) {
            // Skip if already exists
            if (isset($metadata['sizes'][$size_name])) {
                continue;
            }
            
            // Generate thumbnail
            $thumbnail_path = $thumbnailer->generateThumbnail(
                $temp_file,
                $mime_type,
                $dimensions['width'],
                $dimensions['height']
            );
            
            if (!$thumbnail_path || !file_exists($thumbnail_path)) {
                continue;
            }
            
            $thumbnail_filename = "{$original_filename}-{$size_name}.jpg";
            $thumbnail_s3_path = trailingslashit($base_dir) . $thumbnail_filename;
            
            // Upload to S3
            if ($s3Client->uploadFile($thumbnail_path, $thumbnail_s3_path)) {
                $img_dimensions = getimagesize($thumbnail_path);
                if ($img_dimensions) {
                    $metadata['sizes'][$size_name] = [
                        'file'      => $thumbnail_filename,
                        'width'     => $img_dimensions[0],
                        'height'    => $img_dimensions[1],
                        'mime-type' => 'image/jpeg',
                    ];
                    
                    if ($size_name === 'full') {
                        $metadata['sizes'][$size_name]['filesize'] = filesize($thumbnail_path);
                    }
                    
                    $generated = true;
                }
            }
            
            // Clean up thumbnail
            @unlink($thumbnail_path);
        }
        
        if ($generated) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
        
        return $generated;
    }
}
