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
    private ?AwsS3Client $client = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Get (and lazily build) the underlying AWS S3 client.
     * Shared by both the Flysystem adapter and presigned URL generation.
     */
    public function getClient(): AwsS3Client
    {
        if ($this->client === null) {
            $this->client = new AwsS3Client([
                'credentials' => [
                    'key'    => S3_KEY,
                    'secret' => S3_SECRET,
                ],
                'region' => defined('S3_REGION') ? S3_REGION : 'us-east-1',
                'version' => 'latest',
                'endpoint' => S3_ENDPOINT,
                'use_path_style_endpoint' => defined('S3_PATH_STYLE') ? S3_PATH_STYLE : true,
            ]);
        }

        return $this->client;
    }

    public function getFilesystem(): Filesystem
    {
        if ($this->filesystem === null) {
            // Configure visibility
            $visibility = new PortableVisibilityConverter(
                Visibility::PUBLIC
            );

            // Create adapter and filesystem
            $adapter = new AwsS3V3Adapter(
                $this->getClient(),
                S3_BUCKET,
                defined('S3_ROOT') ? S3_ROOT : '',
                $visibility
            );

            $this->filesystem = new Filesystem($adapter);
        }

        return $this->filesystem;
    }

    /**
     * Prefix a Flysystem-relative path with S3_ROOT to get the raw bucket key,
     * matching how the AwsS3V3Adapter prefixes paths internally.
     */
    private function prefixKey(string $path): string
    {
        $key = ltrim($path, '/');

        if (defined('S3_ROOT') && S3_ROOT) {
            $key = trim(S3_ROOT, '/') . '/' . $key;
        }

        return $key;
    }

    /**
     * Create a presigned PUT URL so a browser can upload directly to S3,
     * bypassing the web server (and any upload-size-limited proxy in front of it).
     *
     * @param string $path        Flysystem-relative key (same as used by uploadFile)
     * @param string $contentType Optional content type to sign; empty to skip
     * @param string $expires     Relative expiry string, e.g. "+15 minutes"
     */
    public function createPresignedPutUrl(string $path, string $contentType = '', string $expires = '+15 minutes'): string
    {
        $args = [
            'Bucket' => S3_BUCKET,
            'Key'    => $this->prefixKey($path),
        ];

        if ($contentType !== '') {
            $args['ContentType'] = $contentType;
        }

        // Only sign an ACL when explicitly configured; public buckets / R2 reject ACLs.
        if (defined('S3_UPLOAD_ACL') && S3_UPLOAD_ACL) {
            $args['ACL'] = S3_UPLOAD_ACL;
        }

        $client = $this->getClient();
        $command = $client->getCommand('PutObject', $args);
        $request = $client->createPresignedRequest($command, $expires);

        return (string) $request->getUri();
    }

    /**
     * Download an S3 object to a local path. Provider-agnostic (uses the API,
     * not the public URL), so it works regardless of public-read configuration.
     */
    public function downloadFile(string $s3Path, string $localPath): bool
    {
        try {
            $readStream = $this->getFilesystem()->readStream($s3Path);
            $writeStream = fopen($localPath, 'w');

            if ($writeStream === false) {
                if (is_resource($readStream)) {
                    fclose($readStream);
                }
                return false;
            }

            stream_copy_to_stream($readStream, $writeStream);

            if (is_resource($readStream)) {
                fclose($readStream);
            }
            fclose($writeStream);

            return true;
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("S3 Download error: {$e->getMessage()}");
            }
            return false;
        }
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
