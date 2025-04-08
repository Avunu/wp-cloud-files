<?php

namespace Avunu\WPCloudFiles;

class MediaHandler
{
    // Track which attachments are being processed to prevent infinite loops
    private array $processing = [];
    
    /**
     * Process media files after WordPress is completely done with them
     */
    public function processMedia(array $metadata, int $attachmentId): array
    {
        // Prevent infinite recursion
        if (isset($this->processing[$attachmentId])) {
            return $metadata;
        }

        // Check if metadata is complete
        if (!$this->isMetadataComplete($metadata, $attachmentId)) {
            return $metadata;
        }

        // debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("S3 Upload: Processing attachment $attachmentId");
        }
        
        $this->processing[$attachmentId] = true;
        
        try {
            $uploads = wp_upload_dir();
            $basedir = trailingslashit($uploads['basedir']);
            
            // Get the file path - either from metadata or directly from attachment
            $mainFilePath = $this->getOriginalFilePath($metadata, $attachmentId);
            
            if (empty($mainFilePath)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("S3 Upload: Skipping attachment $attachmentId - unable to determine file path");
                }
                return $metadata;
            }
            
            $mainLocalPath = $basedir . $mainFilePath;
            $baseDir = dirname($mainFilePath);
            
            // Check if main file exists before trying to upload
            if (file_exists($mainLocalPath)) {
                $this->uploadFile($mainLocalPath, $mainFilePath);
            } else if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("S3 Upload: Main file $mainLocalPath does not exist for attachment $attachmentId");
            }
            
            // Process original image if it exists (for scaled images)
            if (!empty($metadata['original_image'])) {
                $originalPath = trailingslashit($baseDir) . $metadata['original_image'];
                $originalLocalPath = $basedir . $originalPath;
                $this->uploadFile($originalLocalPath, $originalPath);
            }
            
            // Process image sizes
            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size_name => $size) {
                    if (!empty($size['file'])) {
                        // Process the size
                        $sizePath = trailingslashit($baseDir) . $size['file'];
                        $sizeLocalPath = $basedir . $sizePath;
                        $this->uploadFile($sizeLocalPath, $sizePath);
                        
                        // Process sources for this size (WebP/AVIF variants)
                        if (!empty($size['sources']) && is_array($size['sources'])) {
                            foreach ($size['sources'] as $source) {
                                if (!empty($source['file'])) {
                                    $sourcePath = trailingslashit($baseDir) . $source['file'];
                                    $sourceLocalPath = $basedir . $sourcePath;
                                    $this->uploadFile($sourceLocalPath, $sourcePath);
                                }
                            }
                        }
                    }
                }
            }
            
            // Process modern format alternatives for main file
            if (!empty($metadata['sources']) && is_array($metadata['sources'])) {
                foreach ($metadata['sources'] as $source) {
                    if (!empty($source['file'])) {
                        $sourcePath = trailingslashit($baseDir) . $source['file'];
                        $sourceLocalPath = $basedir . $sourcePath;
                        $this->uploadFile($sourceLocalPath, $sourcePath);
                    }
                }
            }
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("S3 Upload: Successfully processed attachment $attachmentId");
            }
            
            return $metadata;
        } finally {
            // Always clean up the processing flag
            unset($this->processing[$attachmentId]);
        }
    }
    
    /**
     * Get the original file path for an attachment
     * 
     * @param array $metadata The attachment metadata
     * @param int $attachmentId The attachment ID
     * @return string The file path relative to the uploads directory
     */
    public function getOriginalFilePath(array $metadata, int $attachmentId): string
    {
        // If metadata has file path, use it
        if (!empty($metadata['file'])) {
            return $metadata['file'];
        }
        
        // For PDFs with thumbnails, the file key might be missing
        // Get the file path directly from the attachment
        $file = get_attached_file($attachmentId);
        if (empty($file)) {
            return '';
        }
        
        $uploads = wp_upload_dir();
        $basedir = trailingslashit($uploads['basedir']);
        
        // Convert to relative path
        if (str_starts_with($file, $basedir)) {
            return substr($file, strlen($basedir));
        }
        
        return '';
    }
    
    /**
     * Upload a file to S3 and delete the local copy
     * 
     * @param string $localPath Full local path to the file
     * @param string $remotePath Path to use in S3
     * @return bool Success status
     */
    private function uploadFile(string $localPath, string $remotePath): bool
    {
        $s3Client = S3Client::getInstance();
        
        // Upload and delete if file exists
        if (file_exists($localPath)) {
            if ($s3Client->uploadFile($localPath, $remotePath)) {
                unlink($localPath);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Handle attachment deletion
     */
    public function handleDeletion(int $attachmentId): void
    {
        $metadata = wp_get_attachment_metadata($attachmentId);
        $s3Client = S3Client::getInstance();
        
        // Get file path - either from metadata or directly from attachment
        $mainFilePath = $this->getOriginalFilePath($metadata, $attachmentId);
        
        if (empty($mainFilePath)) {
            return;
        }
        
        $baseDir = dirname($mainFilePath);
        
        // Delete main file
        $s3Client->deleteFile($mainFilePath);
        
        // Delete original image if it exists
        if (!empty($metadata['original_image'])) {
            $originalPath = trailingslashit($baseDir) . $metadata['original_image'];
            $s3Client->deleteFile($originalPath);
        }
        
        // Process image sizes and their sources (WebP/AVIF variants)
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $sizePath = trailingslashit($baseDir) . $size['file'];
                    $s3Client->deleteFile($sizePath);
                    
                    // Delete sources for this size
                    if (!empty($size['sources']) && is_array($size['sources'])) {
                        foreach ($size['sources'] as $source) {
                            if (!empty($source['file'])) {
                                $sourcePath = trailingslashit($baseDir) . $source['file'];
                                $s3Client->deleteFile($sourcePath);
                            }
                        }
                    }
                }
            }
        }
        
        // Delete sources for main file
        if (!empty($metadata['sources']) && is_array($metadata['sources'])) {
            foreach ($metadata['sources'] as $source) {
                if (!empty($source['file'])) {
                    $sourcePath = trailingslashit($baseDir) . $source['file'];
                    $s3Client->deleteFile($sourcePath);
                }
            }
        }
    }

    
    /**
     * Check if metadata contains all expected image sizes and formats
     *
     * @param array $metadata The attachment metadata
     * @param int $attachment_id The attachment ID
     * @return bool Whether the metadata appears complete
     */
    private function isMetadataComplete(array $metadata, int $attachment_id): bool
    {
        // Get the attachment's mime type directly from WordPress
        $mime_type = get_post_mime_type($attachment_id);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("S3 Upload: Checking metadata for attachment $attachment_id with mime type: $mime_type");
        }
        
        // Handle PDFs specifically
        if ($mime_type === 'application/pdf') {
            return $this->isPdfMetadataComplete($metadata, $attachment_id);
        }
        
        // Handle images
        if (strpos($mime_type, 'image/') === 0) {
            return $this->isImageMetadataComplete($metadata, $attachment_id, $mime_type);
        }
        
        // For non-image, non-PDF files, consider metadata complete
        return true;
    }
    
    /**
     * Check if PDF metadata is complete
     * 
     * @param array $metadata The attachment metadata
     * @param int $attachment_id The attachment ID
     * @return bool Whether the PDF metadata appears complete
     */
    private function isPdfMetadataComplete(array $metadata, int $attachment_id): bool
    {
        // If there are no sizes, PDF previews aren't being generated, so it's complete
        if (empty($metadata['sizes'])) {
            return true;
        }
        
        // When a PDF has generated image previews but only has 'full' size
        // Wait for WordPress to generate the other sizes
        if (count($metadata['sizes']) === 1 && isset($metadata['sizes']['full'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("S3 Upload: PDF $attachment_id has only 'full' size, waiting for other sizes");
            }
            return false;
        }
        
        // For PDFs with thumbnails, check for minimum expected sizes
        $requiredSizes = ['thumbnail', 'medium'];
        foreach ($requiredSizes as $size) {
            if (!isset($metadata['sizes'][$size])) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("S3 Upload: PDF $attachment_id missing required size: $size");
                }
                return false;
            }
        }
        
        // Check if WebP/AVIF versions are expected but missing
        if ($this->shouldHaveModernFormats() && !$this->hasRequiredModernFormats($metadata)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("S3 Upload: PDF $attachment_id missing modern format alternatives");
            }
            return false;
        }
        
        // PDF with all required sizes is considered complete
        return true;
    }
    
    /**
     * Check if image metadata is complete
     * 
     * @param array $metadata The attachment metadata
     * @param int $attachment_id The attachment ID
     * @param string $mime_type The attachment mime type
     * @return bool Whether the image metadata appears complete
     */
    private function isImageMetadataComplete(array $metadata, int $attachment_id, string $mime_type): bool
    {
        // If no file in metadata, it's incomplete
        if (empty($metadata['file'])) {
            return false;
        }
        
        // If no sizes in metadata, check if it's a small image
        if (empty($metadata['sizes'])) {
            // If it's a very small image, it might not have any sizes
            if (isset($metadata['width'], $metadata['height']) && 
                $metadata['width'] <= get_option('thumbnail_size_w') && 
                $metadata['height'] <= get_option('thumbnail_size_h')) {
                return true;
            }
            return false;
        }
        
        // Check if this is an edited image from Gutenberg
        $is_edited_image = !empty($metadata['parent_image']) || (
            !empty($metadata['file']) && strpos($metadata['file'], '-edited-') !== false
        );
        
        if ($is_edited_image) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("S3 Upload: Image $attachment_id is an edited image, accepting as complete");
            }
            // For edited images, we trust that all necessary sizes are already present
            return true;
        }
        
        // Get all registered image sizes
        $registered_sizes = $this->getRegisteredImageSizes();
        
        // If the image is too small for some sizes, WordPress won't create them
        if (isset($metadata['width'], $metadata['height'])) {
            foreach ($registered_sizes as $size_name => $dimensions) {
                // If the original is smaller than this size in EITHER dimension, WordPress will skip it
                if ($metadata['width'] < $dimensions['width'] || $metadata['height'] < $dimensions['height']) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("S3 Upload: Image $attachment_id too small for size: $size_name, will skip checking for it");
                    }
                    unset($registered_sizes[$size_name]);
                }
            }
        }
        
        // Check if all applicable registered sizes exist in metadata
        $missing_sizes = [];
        foreach (array_keys($registered_sizes) as $size_name) {
            if (!isset($metadata['sizes'][$size_name])) {
                $missing_sizes[] = $size_name;
            }
        }
        
        if (!empty($missing_sizes)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("S3 Upload: Image $attachment_id missing sizes: " . implode(', ', $missing_sizes));
            }
            return false;
        }
        
        // Check for modern format alternatives if they should exist
        if ($this->shouldHaveModernFormats() && !$this->hasRequiredModernFormats($metadata)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("S3 Upload: Image $attachment_id missing modern format alternatives");
            }
            return false;
        }
        
        // If we got here, all expected sizes and formats are present
        return true;
    }
    
    /**
     * Check if WordPress is configured to generate WebP/AVIF alternatives
     * 
     * @return bool Whether modern formats should be generated
     */
    private function shouldHaveModernFormats(): bool
    {
        // Check if output format conversion is enabled
        if (!get_option('image_editor_output_format')) {
            return false;
        }
        
        // Check if WebP or AVIF is supported
        $supports_webp = wp_image_editor_supports(['mime_type' => 'image/webp']);
        $supports_avif = wp_image_editor_supports(['mime_type' => 'image/avif']);
        
        return $supports_webp || $supports_avif;
    }
    
    /**
     * Check if metadata includes the required modern format alternatives
     * 
     * @param array $metadata The attachment metadata
     * @return bool Whether all required modern formats are present
     */
    private function hasRequiredModernFormats(array $metadata): bool
    {
        // For the main image
        if (empty($metadata['sources'])) {
            return false;
        }
        
        // For image sizes
        if (!empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (empty($size['sources'])) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Get all registered image sizes with their dimensions
     * 
     * @return array Array of image sizes with width and height
     */
    private function getRegisteredImageSizes(): array
    {
        $sizes = [];
        
        // Add default sizes
        $sizes['thumbnail'] = [
            'width' => get_option('thumbnail_size_w'),
            'height' => get_option('thumbnail_size_h'),
        ];
        
        $sizes['medium'] = [
            'width' => get_option('medium_size_w'),
            'height' => get_option('medium_size_h'),
        ];
        
        $sizes['medium_large'] = [
            'width' => get_option('medium_large_size_w') ?: 768,
            'height' => get_option('medium_large_size_h') ?: 0,
        ];
        
        $sizes['large'] = [
            'width' => get_option('large_size_w'),
            'height' => get_option('large_size_h'),
        ];
        
        // Get additional image sizes
        $additional_sizes = wp_get_additional_image_sizes();
        
        // Merge them with the default sizes
        foreach ($additional_sizes as $size_name => $size_data) {
            $sizes[$size_name] = [
                'width' => $size_data['width'],
                'height' => $size_data['height'],
            ];
        }
        
        return $sizes;
    }
}
