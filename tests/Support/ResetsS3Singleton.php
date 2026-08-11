<?php

/**
 * S3Client is a singleton with no reset method and no public constructor
 * injection. Tests must null the static between cases or configuration from one
 * test leaks into the next.
 *
 * This is test-side state management, not a production seam: the S3 double
 * itself is just the S3_ENDPOINT / S3_PATH_STYLE constants pointing at MinIO,
 * which S3Client already feeds straight to the AWS SDK.
 */

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Support;

use Avunu\WPCloudFiles\S3Client;
use ReflectionProperty;

trait ResetsS3Singleton
{
    protected function resetS3Singleton(): void
    {
        $instance = new ReflectionProperty(S3Client::class, 'instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }
}
