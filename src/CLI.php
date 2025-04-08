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
}
