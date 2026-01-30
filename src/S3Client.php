<?php

namespace Avunu\WPCloudFiles;

use Aws\S3\S3Client as AwsS3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Visibility;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;

class S3Client
{
    private static ?self $instance = null;
    private ?Filesystem $filesystem = null;
    private ?AwsS3Client $s3Client = null;
    
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }
    
    public function getS3Client(): AwsS3Client
    {
        if ($this->s3Client === null) {
            // Build AWS S3 client configuration
            $config = [
                'credentials' => [
                    'key'    => S3_KEY,
                    'secret' => S3_SECRET,
                ],
                'region' => defined('S3_REGION') ? S3_REGION : 'us-east-1',
                'version' => 'latest',
                'endpoint' => S3_ENDPOINT,
                'use_path_style_endpoint' => defined('S3_PATH_STYLE') ? S3_PATH_STYLE : true,
            ];
            
            // Create S3 client
            $this->s3Client = new AwsS3Client($config);
        }
        
        return $this->s3Client;
    }
    
    public function getFilesystem(): Filesystem
    {
        if ($this->filesystem === null) {
            // Get or create S3 client
            $client = $this->getS3Client();
            
            // Configure visibility
            $visibility = new PortableVisibilityConverter(
                Visibility::PUBLIC
            );
            
            // Create adapter and filesystem
            $adapter = new AwsS3V3Adapter(
                $client, 
                S3_BUCKET, 
                defined('S3_ROOT') ? S3_ROOT : '',
                $visibility
            );
            
            $this->filesystem = new Filesystem($adapter);
        }
        
        return $this->filesystem;
    }
    
    public function getPublicUrl(string $path): string
    {
        $baseUrl = rtrim(S3_PUBLIC_URL, '/');
        
        if (defined('S3_ROOT') && S3_ROOT) {
            $baseUrl .= '/' . trim(S3_ROOT, '/');
        }
        
        return $baseUrl . '/' . ltrim($path, '/');
    }
    
    public function uploadFile(string $localPath, string $s3Path): bool
    {
        if (!file_exists($localPath) || !is_readable($localPath)) {
            return false;
        }
        
        try {
            $stream = fopen($localPath, 'r');
            $this->getFilesystem()->writeStream($s3Path, $stream);
            
            if (is_resource($stream)) {
                fclose($stream);
            }
            
            return true;
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("S3 Upload error: {$e->getMessage()}");
            }
            return false;
        }
    }
    
    public function deleteFile(string $s3Path): bool
    {
        try {
            if ($this->getFilesystem()->fileExists($s3Path)) {
                return $this->getFilesystem()->delete($s3Path);
            }
            return true; // If file doesn't exist, consider deletion successful
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("S3 Delete error: {$e->getMessage()}");
            }
            return false;
        }
    }
    
    /**
     * Generate a pre-signed URL for direct upload to S3
     *
     * @param string $s3Path The S3 path where the file will be uploaded
     * @param string $contentType The MIME type of the file
     * @param int $expiration Expiration time in minutes (default 60, max 1440)
     * @return string The pre-signed URL
     */
    public function generatePresignedUploadUrl(string $s3Path, string $contentType, int $expiration = 60): string
    {
        // Validate and cap expiration time (max 24 hours)
        $expiration = max(5, min($expiration, 1440));
        
        $client = $this->getS3Client();
        $bucket = S3_BUCKET;
        
        // Add S3_ROOT prefix if configured
        if (defined('S3_ROOT') && S3_ROOT) {
            $s3Path = trim(S3_ROOT, '/') . '/' . ltrim($s3Path, '/');
        }
        
        // Configure command parameters based on S3 configuration
        $commandParams = [
            'Bucket' => $bucket,
            'Key'    => $s3Path,
            'ContentType' => $contentType,
        ];
        
        // Only add ACL if not using bucket owner enforced
        if (!defined('S3_BUCKET_OWNER_ENFORCED') || !S3_BUCKET_OWNER_ENFORCED) {
            $commandParams['ACL'] = 'public-read';
        }
        
        $cmd = $client->getCommand('PutObject', $commandParams);
        
        $request = $client->createPresignedRequest($cmd, "+{$expiration} minutes");
        
        return (string) $request->getUri();
    }
    
    /**
     * Download a file from S3 to a local temporary location
     *
     * @param string $s3Path The S3 path of the file
     * @param string $localPath The local path where to save the file
     * @return bool Success status
     */
    public function downloadFile(string $s3Path, string $localPath): bool
    {
        try {
            $stream = $this->getFilesystem()->readStream($s3Path);
            
            if ($stream === false) {
                return false;
            }
            
            $dir = dirname($localPath);
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            
            $localStream = fopen($localPath, 'w');
            if ($localStream === false) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                return false;
            }
            
            stream_copy_to_stream($stream, $localStream);
            
            fclose($localStream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            
            return true;
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("S3 Download error: {$e->getMessage()}");
            }
            return false;
        }
    }
}
