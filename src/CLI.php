<?php

namespace Avunu\WPCloudFiles;

use WP_CLI;
use WP_CLI\Utils;
use WP_Query;

class CLI
{
    /**
     * Migrate existing WordPress media library to S3.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum number of attachments to process.
     * ---
     * default: 0 (unlimited)
     * ---
     *
     * [--offset=<number>]
     * : Number of attachments to skip.
     * ---
     * default: 0
     * ---
     *
     * [--batch-size=<number>]
     * : Process attachments in batches of this size.
     * ---
     * default: 20
     * ---
     *
     * [--keep-local]
     * : Keep local copy of files after upload.
     *
     * [--force]
     * : Re-upload attachments that may have already been processed.
     *
     * ## EXAMPLES
     *
     *     # Migrate all media files to S3
     *     $ wp s3uploads migrate
     *
     *     # Migrate 50 media files starting from item 100
     *     $ wp s3uploads migrate --limit=50 --offset=100
     *
     *     # Migrate and keep local copies
     *     $ wp s3uploads migrate --keep-local
     *
     *     # Force re-upload all media
     *     $ wp s3uploads migrate --force
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function migrate($args, $assoc_args)
    {
        // Parse arguments
        $limit = (int) ($assoc_args['limit'] ?? 0);
        $offset = (int) ($assoc_args['offset'] ?? 0);
        $batch_size = (int) ($assoc_args['batch-size'] ?? 20);
        $keep_local = isset($assoc_args['keep-local']);
        $force = isset($assoc_args['force']);

        // Validate batch size
        if ($batch_size < 1) {
            $batch_size = 20;
        }

        // Get total count for progress bar
        $total_attachments = $this->get_attachment_count($limit, $offset);
        if ($total_attachments === 0) {
            WP_CLI::warning('No media items found to migrate.');
            return;
        }

        WP_CLI::log(sprintf('Preparing to migrate %d media items to S3...', $total_attachments));

        // Create progress bar
        $progress = Utils\make_progress_bar('Migrating media to S3', $total_attachments);
        
        // Track statistics
        $stats = [
            'success' => 0,
            'failed'  => 0,
            'skipped' => 0,
        ];

        // Process in batches
        $processed = 0;
        $mediaHandler = new MediaHandler();
        $s3Client = S3Client::getInstance();
        
        while ($processed < $total_attachments) {
            $remaining = $limit > 0 ? min($batch_size, $limit - $processed) : $batch_size;
            $attachments = $this->get_attachments($remaining, $offset + $processed);
            
            if (empty($attachments)) {
                break;
            }
            
            foreach ($attachments as $attachment) {
                // Get metadata
                $attachment_id = $attachment->ID;
                $metadata = wp_get_attachment_metadata($attachment_id);
                
                // Check if it's a valid attachment
                if (empty($metadata)) {
                    WP_CLI::debug(sprintf('Skipping attachment #%d - no metadata found.', $attachment_id));
                    $stats['skipped']++;
                    $progress->tick();
                    $processed++;
                    continue;
                }
                
                // Process the attachment
                $result = $this->process_attachment($attachment_id, $metadata, $mediaHandler, $s3Client, $keep_local, $force);
                
                // Update stats
                if ($result === true) {
                    $stats['success']++;
                } elseif ($result === null) {
                    $stats['skipped']++;
                } else {
                    $stats['failed']++;
                }
                
                $progress->tick();
                $processed++;
            }
            
            // Clear any object cache
            wp_cache_flush();
        }
        
        $progress->finish();
        
        // Display results
        WP_CLI::success(sprintf(
            'Migration completed. Successfully migrated: %d, Failed: %d, Skipped: %d.',
            $stats['success'],
            $stats['failed'],
            $stats['skipped']
        ));
    }

    /**
     * Get the total count of media attachments
     *
     * @param int $limit Maximum number of attachments to count
     * @param int $offset Number of attachments to skip
     * @return int
     */
    private function get_attachment_count(int $limit = 0, int $offset = 0): int
    {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'offset'         => $offset,
        ];
        
        $query = new WP_Query($args);
        $count = $query->found_posts - $offset;
        
        // If limit is set, limit the total count
        if ($limit > 0 && $count > $limit) {
            $count = $limit;
        }
        
        return max(0, $count);
    }
    
    /**
     * Get a batch of attachments
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    private function get_attachments(int $limit, int $offset = 0): array
    {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];
        
        $query = new WP_Query($args);
        return $query->posts;
    }
    
    /**
     * Process a single attachment
     *
     * @param int $attachment_id
     * @param array $metadata
     * @param MediaHandler $mediaHandler
     * @param S3Client $s3Client
     * @param bool $keep_local
     * @param bool $force
     * @return bool|null True on success, false on failure, null if skipped
     */
    private function process_attachment(
        int $attachment_id,
        array $metadata,
        MediaHandler $mediaHandler,
        S3Client $s3Client,
        bool $keep_local,
        bool $force
    ): ?bool {
        try {
            $uploads = wp_upload_dir();
            $basedir = trailingslashit($uploads['basedir']);
            
            // Get the main file path
            $mainFilePath = $mediaHandler->getOriginalFilePath($metadata, $attachment_id);
            
            if (empty($mainFilePath)) {
                WP_CLI::debug(sprintf('Attachment #%d: Cannot determine file path.', $attachment_id));
                return null;
            }
            
            $mainLocalPath = $basedir . $mainFilePath;
            $baseDir = dirname($mainFilePath);
            
            // Check if main file is already on S3 (unless force option is used)
            if (!$force && $this->isFileOnS3($mainFilePath, $s3Client)) {
                WP_CLI::debug(sprintf('Attachment #%d: Already on S3, skipping.', $attachment_id));
                return null;
            }
            
            // Check if main file exists locally
            if (!file_exists($mainLocalPath)) {
                WP_CLI::debug(sprintf('Attachment #%d: File not found locally: %s', $attachment_id, $mainLocalPath));
                return false;
            }
            
            // Upload main file
            if (!$this->uploadFileToS3($mainLocalPath, $mainFilePath, $s3Client, $keep_local)) {
                WP_CLI::debug(sprintf('Attachment #%d: Failed to upload main file.', $attachment_id));
                return false;
            }
            
            // Process original image if it exists (for scaled images)
            if (!empty($metadata['original_image'])) {
                $originalPath = trailingslashit($baseDir) . $metadata['original_image'];
                $originalLocalPath = $basedir . $originalPath;
                if (file_exists($originalLocalPath)) {
                    $this->uploadFileToS3($originalLocalPath, $originalPath, $s3Client, $keep_local);
                }
            }
            
            // Process image sizes
            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size) {
                    if (!empty($size['file'])) {
                        $sizePath = trailingslashit($baseDir) . $size['file'];
                        $sizeLocalPath = $basedir . $sizePath;
                        if (file_exists($sizeLocalPath)) {
                            $this->uploadFileToS3($sizeLocalPath, $sizePath, $s3Client, $keep_local);
                        }
                        
                        // Process sources for this size (WebP/AVIF variants)
                        if (!empty($size['sources']) && is_array($size['sources'])) {
                            foreach ($size['sources'] as $source) {
                                if (!empty($source['file'])) {
                                    $sourcePath = trailingslashit($baseDir) . $source['file'];
                                    $sourceLocalPath = $basedir . $sourcePath;
                                    if (file_exists($sourceLocalPath)) {
                                        $this->uploadFileToS3($sourceLocalPath, $sourcePath, $s3Client, $keep_local);
                                    }
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
                        if (file_exists($sourceLocalPath)) {
                            $this->uploadFileToS3($sourceLocalPath, $sourcePath, $s3Client, $keep_local);
                        }
                    }
                }
            }
            
            WP_CLI::debug(sprintf('Attachment #%d: Successfully migrated to S3.', $attachment_id));
            return true;
        } catch (\Exception $e) {
            WP_CLI::debug(sprintf('Attachment #%d: Error: %s', $attachment_id, $e->getMessage()));
            return false;
        }
    }
    
    /**
     * Check if a file is already on S3
     *
     * @param string $filePath
     * @param S3Client $s3Client
     * @return bool
     */
    private function isFileOnS3(string $filePath, S3Client $s3Client): bool
    {
        try {
            return $s3Client->getFilesystem()->fileExists($filePath);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Upload a file to S3
     *
     * @param string $localPath
     * @param string $remotePath
     * @param S3Client $s3Client
     * @param bool $keepLocal
     * @return bool
     */
    private function uploadFileToS3(string $localPath, string $remotePath, S3Client $s3Client, bool $keepLocal): bool
    {
        if (!file_exists($localPath)) {
            return false;
        }
        
        $success = $s3Client->uploadFile($localPath, $remotePath);
        
        // Delete local file if not keeping local copies
        if ($success && !$keepLocal) {
            @unlink($localPath);
        }
        
        return $success;
    }


    /**
     * Regenerate thumbnails for document attachments, including those on S3.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum number of attachments to process.
     * ---
     * default: 0 (unlimited)
     * ---
     *
     * [--offset=<number>]
     * : Number of attachments to skip.
     * ---
     * default: 0
     * ---
     *
     * [--force]
     * : Regenerate thumbnails even if they already exist.
     *
     * [--type=<mime-type>]
     * : Only process attachments of this mime type (e.g., application/pdf).
     * ---
     * default: all supported document types
     * ---
     *
     * ## EXAMPLES
     *
     *     # Regenerate thumbnails for all supported documents
     *     $ wp s3uploads regenerate-thumbnails
     *
     *     # Regenerate thumbnails only for PDFs
     *     $ wp s3uploads regenerate-thumbnails --type=application/pdf
     *
     *     # Regenerate thumbnails for 50 attachments starting from item 100
     *     $ wp s3uploads regenerate-thumbnails --limit=50 --offset=100
     *
     *     # Force regenerate all thumbnails
     *     $ wp s3uploads regenerate-thumbnails --force
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function regenerate_thumbnails($args, $assoc_args)
    {
        $limit = (int) ($assoc_args['limit'] ?? 0);
        $offset = (int) ($assoc_args['offset'] ?? 0);
        $force = isset($assoc_args['force']);
        $type_filter = $assoc_args['type'] ?? null;

        // Supported document mime types (from DocumentThumbnailer)
        $supported_mime_types = [
            // PDF
            'application/pdf',
            // Word documents
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword',
            'application/vnd.oasis.opendocument.text',
            'application/rtf',
            'text/rtf',
            // Spreadsheets
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'application/vnd.oasis.opendocument.spreadsheet',
            'text/csv',
            // Presentations
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint',
            'application/vnd.oasis.opendocument.presentation',
        ];

        // Filter to specific type if requested
        if ($type_filter) {
            if (!in_array($type_filter, $supported_mime_types, true)) {
                WP_CLI::error(sprintf('Unsupported mime type: %s', $type_filter));
                return;
            }
            $mime_types = [$type_filter];
        } else {
            $mime_types = $supported_mime_types;
        }

        // Get attachments
        $query_args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => $mime_types,
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ];

        $attachments = get_posts($query_args);
        $total = count($attachments);

        if ($total === 0) {
            WP_CLI::warning('No document attachments found.');
            return;
        }

        WP_CLI::log(sprintf('Found %d document attachments to process...', $total));

        $progress = Utils\make_progress_bar('Regenerating thumbnails', $total);
        $stats = ['success' => 0, 'failed' => 0, 'skipped' => 0];

        $thumbnailer = new DocumentThumbnailer();
        $s3Client = S3Client::getInstance();
        $uploads = wp_upload_dir();
        $basedir = trailingslashit($uploads['basedir']);

        foreach ($attachments as $attachment_id) {
            $metadata = wp_get_attachment_metadata($attachment_id);
            
            // Ensure metadata is an array
            if (!is_array($metadata)) {
                $metadata = [];
            }

            // Skip if already has thumbnails (unless force)
            if (!$force && !empty($metadata['sizes'])) {
                WP_CLI::debug(sprintf('Attachment #%d: Already has thumbnails, skipping.', $attachment_id));
                $stats['skipped']++;
                $progress->tick();
                continue;
            }

            // Get mime type for this attachment
            $mime_type = get_post_mime_type($attachment_id);
            
            if (!$mime_type || !in_array($mime_type, $supported_mime_types, true)) {
                WP_CLI::debug(sprintf('Attachment #%d: Unsupported mime type %s, skipping.', $attachment_id, $mime_type));
                $stats['skipped']++;
                $progress->tick();
                continue;
            }

            // Get the file path
            $file = get_attached_file($attachment_id);
            $s3_url = wp_get_attachment_url($attachment_id);
            $relative_path = str_replace($basedir, '', $file);
            $file_dir = dirname($file);
            $cleanup_temp = false;

            // Download from S3 if file doesn't exist locally
            if (!file_exists($file)) {
                WP_CLI::debug(sprintf('Attachment #%d: Downloading from S3...', $attachment_id));

                // Create directory if it doesn't exist
                if (!is_dir($file_dir)) {
                    wp_mkdir_p($file_dir);
                }

                $response = wp_remote_get($s3_url, ['timeout' => 120]);

                if (is_wp_error($response)) {
                    WP_CLI::debug(sprintf('Attachment #%d: Failed to download - %s', $attachment_id, $response->get_error_message()));
                    $stats['failed']++;
                    $progress->tick();
                    continue;
                }

                $http_code = wp_remote_retrieve_response_code($response);
                if ($http_code !== 200) {
                    WP_CLI::debug(sprintf('Attachment #%d: Failed to download - HTTP %d', $attachment_id, $http_code));
                    $stats['failed']++;
                    $progress->tick();
                    continue;
                }

                file_put_contents($file, wp_remote_retrieve_body($response));
                $cleanup_temp = true;
            }

            // Generate thumbnails
            $sizes = [
                'thumbnail' => [
                    'width'  => get_option('thumbnail_size_w', 150),
                    'height' => get_option('thumbnail_size_h', 150),
                ],
                'medium' => [
                    'width'  => get_option('medium_size_w', 300),
                    'height' => get_option('medium_size_h', 300),
                ],
                'large' => [
                    'width'  => get_option('large_size_w', 1024),
                    'height' => get_option('large_size_h', 1024),
                ],
            ];

            if (!isset($metadata['sizes'])) {
                $metadata['sizes'] = [];
            }

            $generated = false;
            $original_filename = pathinfo($file, PATHINFO_FILENAME);
            $base_relative_dir = dirname($relative_path);

            foreach ($sizes as $size_name => $dimensions) {
                $thumbnail_path = $thumbnailer->generateThumbnail(
                    $file,
                    $mime_type,
                    $dimensions['width'],
                    $dimensions['height']
                );

                if (!$thumbnail_path || !file_exists($thumbnail_path)) {
                    WP_CLI::debug(sprintf('Attachment #%d: Failed to generate %s thumbnail.', $attachment_id, $size_name));
                    continue;
                }

                // Create filename and destination
                $thumbnail_filename = "{$original_filename}-{$size_name}.jpg";
                $thumbnail_dest = "{$file_dir}/{$thumbnail_filename}";
                $thumbnail_relative = ltrim("{$base_relative_dir}/{$thumbnail_filename}", '/');

                // Move thumbnail to uploads directory
                if (rename($thumbnail_path, $thumbnail_dest)) {
                    // Get dimensions
                    $img_dimensions = getimagesize($thumbnail_dest);

                    // Add to metadata
                    $metadata['sizes'][$size_name] = [
                        'file'      => $thumbnail_filename,
                        'width'     => $img_dimensions[0],
                        'height'    => $img_dimensions[1],
                        'mime-type' => 'image/jpeg',
                    ];

                    // Upload thumbnail to S3
                    if ($s3Client->uploadFile($thumbnail_dest, $thumbnail_relative)) {
                        // Delete local thumbnail after upload
                        @unlink($thumbnail_dest);
                        $generated = true;
                        WP_CLI::debug(sprintf('Attachment #%d: Generated and uploaded %s thumbnail.', $attachment_id, $size_name));
                    }
                } else {
                    // Clean up temp file if move failed
                    @unlink($thumbnail_path);
                }
            }

            // Clean up downloaded file if we downloaded it
            if ($cleanup_temp && file_exists($file)) {
                @unlink($file);
            }

            // Update metadata
            if ($generated) {
                wp_update_attachment_metadata($attachment_id, $metadata);
                $stats['success']++;
                WP_CLI::debug(sprintf('Attachment #%d: Successfully regenerated thumbnails.', $attachment_id));
            } else {
                $stats['failed']++;
                WP_CLI::debug(sprintf('Attachment #%d: Failed to generate any thumbnails.', $attachment_id));
            }

            $progress->tick();
        }

        $progress->finish();

        WP_CLI::success(sprintf(
            'Thumbnail regeneration completed. Success: %d, Failed: %d, Skipped: %d.',
            $stats['success'],
            $stats['failed'],
            $stats['skipped']
        ));
    }
}
