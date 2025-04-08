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
    
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }
    
    public function getFilesystem(): Filesystem
    {
        if ($this->filesystem === null) {
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
            $client = new AwsS3Client($config);
            
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
}
