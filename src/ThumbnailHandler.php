<?php

namespace Avunu\WPCloudFiles;

class ThumbnailHandler
{
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

    private function getThumbnailer(): DocumentThumbnailer
    {
        if ($this->thumbnailer === null) {
            $this->thumbnailer = new DocumentThumbnailer();
        }
        return $this->thumbnailer;
    }

    /**
     * Generate thumbnails for documents during wp_generate_attachment_metadata.
     * This follows the same pattern as WordPress core and webp-uploads plugin.
     */
    public function handleDocumentThumbnails(array $metadata, int $attachmentId): array
    {
        $mimeType = get_post_mime_type($attachmentId);
        
        if (!in_array($mimeType, $this->documentTypes, true)) {
            return $metadata;
        }
        
        $filePath = get_attached_file($attachmentId);
        
        if (empty($filePath) || !file_exists($filePath)) {
            return $metadata;
        }
        
        if (!isset($metadata['sizes'])) {
            $metadata['sizes'] = [];
        }
        
        // Get registered image sizes to match WordPress behavior
        $sizes = $this->getImageSizes();
        $attachmentDir = dirname($filePath);
        $originalFilename = pathinfo($filePath, PATHINFO_FILENAME);
        
        foreach ($sizes as $sizeName => $dimensions) {
            // Skip if this size already exists
            if (isset($metadata['sizes'][$sizeName])) {
                continue;
            }
            
            $thumbnailPath = $this->getThumbnailer()->generateThumbnail(
                $filePath,
                $mimeType,
                $dimensions['width'],
                $dimensions['height']
            );
            
            if (!$thumbnailPath || !file_exists($thumbnailPath)) {
                continue;
            }
            
            $thumbnailFilename = "{$originalFilename}-{$sizeName}.jpg";
            $thumbnailDestPath = "{$attachmentDir}/{$thumbnailFilename}";
            
            if (rename($thumbnailPath, $thumbnailDestPath)) {
                $imgDimensions = getimagesize($thumbnailDestPath);
                if ($imgDimensions) {
                    $metadata['sizes'][$sizeName] = [
                        'file'      => $thumbnailFilename,
                        'width'     => $imgDimensions[0],
                        'height'    => $imgDimensions[1],
                        'mime-type' => 'image/jpeg',
                    ];
                    
                    // WordPress expects filesize for full size previews
                    if ($sizeName === 'full') {
                        $metadata['sizes'][$sizeName]['filesize'] = filesize($thumbnailDestPath);
                    }
                }
            } else {
                @unlink($thumbnailPath);
            }
        }
        
        return $metadata;
    }
    
    /**
     * Get image sizes to generate, including 'full' for media library preview.
     */
    private function getImageSizes(): array
    {
        return [
            'full' => [
                'width'  => 1500,
                'height' => 1500,
            ],
            'thumbnail' => [
                'width'  => (int) get_option('thumbnail_size_w', 150),
                'height' => (int) get_option('thumbnail_size_h', 150),
            ],
            'medium' => [
                'width'  => (int) get_option('medium_size_w', 300),
                'height' => (int) get_option('medium_size_h', 300),
            ],
            'large' => [
                'width'  => (int) get_option('large_size_w', 1024),
                'height' => (int) get_option('large_size_h', 1024),
            ],
        ];
    }
    
    /**
     * Prepare attachment data for JavaScript.
     * Ensures document thumbnails appear in the media library.
     */
    public function prepareAttachmentForJs($response, $attachment, $meta)
    {
        if (empty($meta['sizes']) || !in_array($attachment->post_mime_type, $this->documentTypes, true)) {
            return $response;
        }
        
        $fileUrl = wp_get_attachment_url($attachment->ID);
        $baseUrl = dirname($fileUrl);
        
        foreach (['full', 'thumbnail', 'medium', 'large'] as $size) {
            if (!empty($meta['sizes'][$size])) {
                $response['sizes'][$size] = [
                    'url'    => $baseUrl . '/' . $meta['sizes'][$size]['file'],
                    'width'  => $meta['sizes'][$size]['width'],
                    'height' => $meta['sizes'][$size]['height'],
                ];
                
                if ($size === 'thumbnail') {
                    $response['icon'] = $baseUrl . '/' . $meta['sizes'][$size]['file'];
                }
            }
        }
        
        return $response;
    }
}
