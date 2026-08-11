<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Support;

use Avunu\WPCloudFiles\S3Client;
use Throwable;

/**
 * Empties the test bucket between cases.
 *
 * WP_UnitTestCase wraps each test in a database transaction and rolls it back,
 * but MinIO has no such thing and its data directory survives across runs. The
 * bucket is therefore emptied in set_up() as well as tear_down(), so a crashed
 * or killed run cannot poison the next one.
 */
trait S3BucketFixture
{
    protected function emptyBucket(): void
    {
        try {
            $filesystem = S3Client::getInstance()->getFilesystem();

            // listContents + delete rather than deleteDirectory(''): with S3_ROOT
            // unset the latter targets the entire bucket, which is a much bigger
            // hammer than intended if this ever runs against a real endpoint.
            foreach ($filesystem->listContents('', true) as $item) {
                if ($item->isFile()) {
                    $filesystem->delete($item->path());
                }
            }
        } catch (Throwable $e) {
            // The broken-s3 profile points at a closed port on purpose; there is
            // nothing to clean and nothing to report.
            if (!Profile::is(Profile::BROKEN_S3)) {
                throw $e;
            }
        }
    }

    /**
     * Put an object into the bucket without going through the plugin, for tests
     * that need to simulate a browser having already uploaded directly to S3.
     */
    protected function putObject(string $key, string $contents): void
    {
        S3Client::getInstance()->getFilesystem()->write($key, $contents);
    }

    protected function objectExists(string $key): bool
    {
        return S3Client::getInstance()->getFilesystem()->fileExists($key);
    }

    /** @return list<string> */
    protected function listObjects(): array
    {
        $paths = [];

        foreach (S3Client::getInstance()->getFilesystem()->listContents('', true) as $item) {
            if ($item->isFile()) {
                $paths[] = $item->path();
            }
        }

        sort($paths);

        return $paths;
    }
}
