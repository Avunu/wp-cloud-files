<?php

namespace Avunu\WPCloudFiles;

class ThumbnailHandler
{
    // Document types that should have thumbnails generated
    private array $documentTypes = [
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
        'text/csv'
    ];

    private ?DocumentThumbnailer $thumbnailer = null;

    /**
     * Get the document thumbnailer instance
     */
    private function getThumbnailer(): DocumentThumbnailer
    {
        if ($this->thumbnailer === null) {
            $this->thumbnailer = new DocumentThumbnailer();
        }
        return $this->thumbnailer;
    }

    /**
     * Handle document thumbnail generation during attachment upload
     */
    public function handleDocumentThumbnails(array $metadata, int $attachmentId): array
    {
        // Get attachment MIME type
        $mimeType = get_post_mime_type($attachmentId);
        
        // Check if this is a document type we should handle
        if (!in_array($mimeType, $this->documentTypes)) {
            return $metadata;
        }
        
        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Cloud Files: Generating thumbnail for document {$attachmentId} with MIME type {$mimeType}");
        }
        
        // If metadata doesn't have a 'sizes' array, create one
        if (!isset($metadata['sizes'])) {
            $metadata['sizes'] = [];
        }
        
        // Generate thumbnails at standard WP sizes
        $this->generateThumbnailsForDocument($metadata, $attachmentId);
        
        return $metadata;
    }
    
    /**
     * Generate thumbnails for a document at standard WordPress sizes
     */
    private function generateThumbnailsForDocument(array &$metadata, int $attachmentId): void
    {
        // Get the standard thumbnail sizes
        $sizes = [
            'thumbnail' => [
                'width' => get_option('thumbnail_size_w', 150),
                'height' => get_option('thumbnail_size_h', 150),
            ],
            'medium' => [
                'width' => get_option('medium_size_w', 300),
                'height' => get_option('medium_size_h', 300),
            ],
			'large' => [
				'width' => get_option('large_size_w', 1024),
				'height' => get_option('large_size_h', 1024),
			]
        ];
        
        // Get the file path
        $filePath = get_attached_file($attachmentId);
        $mimeType = get_post_mime_type($attachmentId);
        
        if (empty($filePath) || !file_exists($filePath)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WP Cloud Files: Document file not found for attachment {$attachmentId}");
            }
            return;
        }
        
        // Directory to save thumbnail images
        $uploadsDir = wp_upload_dir();
        $baseDir = $uploadsDir['basedir'];
        $baseUrl = $uploadsDir['baseurl'];
        
        // Get or create a subdirectory for the attachment
        $attachmentDir = dirname($filePath);
        $attachmentSubdir = str_replace($baseDir, '', $attachmentDir);
        $attachmentSubdir = trim($attachmentSubdir, '/');
        
        // Generate thumbnail for each size
        foreach ($sizes as $sizeName => $dimensions) {
            // Generate the thumbnail
            $thumbnailPath = $this->getThumbnailer()->generateThumbnail(
                $filePath,
                $mimeType,
                $dimensions['width'],
                $dimensions['height']
            );
            
            if (!$thumbnailPath || !file_exists($thumbnailPath)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("WP Cloud Files: Failed to generate {$sizeName} thumbnail for document {$attachmentId}");
                }
                continue;
            }
            
            // Create a filename for the thumbnail
            $originalFilename = pathinfo($filePath, PATHINFO_FILENAME);
            $thumbnailFilename = "{$originalFilename}-{$sizeName}.jpg";
            $thumbnailDestPath = "{$attachmentDir}/{$thumbnailFilename}";
            
            // Move the temporary thumbnail to the uploads directory
            if (rename($thumbnailPath, $thumbnailDestPath)) {
                // Add this size to the metadata
                $dimensions = getimagesize($thumbnailDestPath);
                $metadata['sizes'][$sizeName] = [
                    'file' => $thumbnailFilename,
                    'width' => $dimensions[0],
                    'height' => $dimensions[1],
                    'mime-type' => 'image/jpeg',
                ];
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("WP Cloud Files: Generated {$sizeName} thumbnail for document {$attachmentId}");
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("WP Cloud Files: Failed to move thumbnail to {$thumbnailDestPath}");
                }
                
                // Clean up if move failed
                if (file_exists($thumbnailPath)) {
                    unlink($thumbnailPath);
                }
            }
        }
    }
    
    /**
     * Prepare attachment data for JavaScript
     * This ensures document thumbnails appear in the media library
     */
    public function prepareAttachmentForJs($response, $attachment, $meta)
    {
        // Check if this is a document with thumbnail
        if (!empty($meta['sizes']) && in_array($attachment->post_mime_type, $this->documentTypes)) {
            $uploads = wp_upload_dir();
            
            // Add thumbnail URLs to the response
            foreach (['thumbnail', 'medium', 'large'] as $size) {
                if (!empty($meta['sizes'][$size])) {
                    // Get the attachment URL base
                    $fileUrl = wp_get_attachment_url($attachment->ID);
                    $filePath = get_attached_file($attachment->ID);
                    $baseUrl = dirname($fileUrl);
                    
                    // Set the thumbnail URL
                    // This will be rewritten by the S3 URL rewriter later
                    $response['sizes'][$size] = [
                        'url' => $baseUrl . '/' . $meta['sizes'][$size]['file'],
                        'width' => $meta['sizes'][$size]['width'],
                        'height' => $meta['sizes'][$size]['height'],
                    ];
                    
                    // If we're setting the thumbnail size, also set the icon
                    if ($size === 'thumbnail') {
                        $response['icon'] = $baseUrl . '/' . $meta['sizes'][$size]['file'];
                    }
                }
            }
        }
        
        return $response;
    }
}
