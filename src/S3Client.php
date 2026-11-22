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
                    'key'    => Config::string('S3_KEY'),
                    'secret' => Config::string('S3_SECRET'),
                ],
                'region' => Config::string('S3_REGION', 'us-east-1'),
                'version' => 'latest',
                'endpoint' => Config::string('S3_ENDPOINT'),
                'use_path_style_endpoint' => Config::bool('S3_PATH_STYLE', true),
                // The SDK default (when_supported) validates the CRC of any
                // response that carries an x-amz-checksum-* header, even one it
                // never asked for. downloadFile() reads a non-seekable stream,
                // and the validator rewinds the body, so a provider that echoes
                // the checksum from the upload (rclone does) turned every
                // download into "Cannot seek a response body". AWS S3 only
                // returns checksums on request, so this changes nothing there.
                'response_checksum_validation' => 'when_required',
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
                Config::string('S3_BUCKET'),
                Config::string('S3_ROOT'),
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

        $root = Config::string('S3_ROOT');
        if ($root !== '') {
            $key = trim($root, '/') . '/' . $key;
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
            'Bucket' => Config::string('S3_BUCKET'),
            'Key'    => $this->prefixKey($path),
        ];

        if ($contentType !== '') {
            $args['ContentType'] = $contentType;
        }

        // Only sign an ACL when explicitly configured; public buckets / R2 reject ACLs.
        $acl = Config::string('S3_UPLOAD_ACL');
        if ($acl !== '') {
            $args['ACL'] = $acl;
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
        $baseUrl = rtrim(Config::string('S3_PUBLIC_URL'), '/');

        $root = Config::string('S3_ROOT');
        if ($root !== '') {
            $baseUrl .= '/' . trim($root, '/');
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Copy a local file to the bucket.
     *
     * Refuses executable files whatever their declared type (see
     * ExecutableGuard), and, unless $overwrite is true, a key that already
     * holds an object: in an offloaded library the local uploads directory is
     * mostly empty, so a "unique" local file name can still collide with
     * another attachment's object.
     */
    public function uploadFile(string $localPath, string $s3Path, bool $overwrite = true): bool
    {
        if (!file_exists($localPath) || !is_readable($localPath)) {
            return false;
        }

        if (ExecutableGuard::isExecutable($s3Path, null, $localPath)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("S3 Upload: refusing executable file {$s3Path}");
            }
            return false;
        }

        if (!$overwrite && $this->fileExists($s3Path)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("S3 Upload: {$s3Path} already exists; not overwriting");
            }
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

    /**
     * Whether an object exists. False when the bucket cannot be reached; an
     * upload attempted after that fails on its own.
     */
    public function fileExists(string $s3Path): bool
    {
        try {
            return $this->getFilesystem()->fileExists($s3Path);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function deleteFile(string $s3Path): bool
    {
        try {
            if ($this->getFilesystem()->fileExists($s3Path)) {
                // Flysystem's delete() returns void and throws on failure.
                // Returning it directly yielded null from this bool method, which
                // the catch below then swallowed into `false` -- so deleting a
                // file that existed always reported failure, and logged an error,
                // even though the object was gone.
                $this->getFilesystem()->delete($s3Path);
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
