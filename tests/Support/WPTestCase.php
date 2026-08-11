<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Support;

use WP_UnitTestCase;

/**
 * Base class for suites that run against a real WordPress install, a real
 * MariaDB and a real MinIO.
 */
abstract class WPTestCase extends WP_UnitTestCase
{
    use ResetsS3Singleton;
    use S3BucketFixture;
    use HookAssertions;

    /**
     * The broken-s3 profile points S3_ENDPOINT at a closed port so the error
     * paths can be reached. Everything that needs a working bucket has to sit
     * that run out; tests written *for* those error paths override this.
     */
    protected bool $requiresWorkingS3 = true;

    public function set_up(): void
    {
        parent::set_up();

        if ($this->requiresWorkingS3 && Profile::is(Profile::BROKEN_S3)) {
            $this->markTestSkipped('needs a working S3 endpoint; the broken-s3 profile has none');
        }

        NoRemoteHttp::reset();
        $this->resetS3Singleton();
        $this->emptyBucket();
    }

    public function tear_down(): void
    {
        $this->emptyBucket();
        $this->resetS3Singleton();

        $attempts = NoRemoteHttp::attempts();
        NoRemoteHttp::reset();

        parent::tear_down();

        $this->assertSame(
            [],
            $attempts,
            'The test attempted outbound HTTP. Tests must not depend on the network.'
        );
    }

    /**
     * Create an attachment from a fixture file by driving WordPress' real upload
     * path, so every hook the plugin registers actually fires.
     */
    protected function uploadFixture(string $fixture): int
    {
        $path = FixturePath::to($fixture);

        $id = $this->factory()->attachment->create_upload_object($path);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id, "Failed to create an attachment from {$fixture}");

        return $id;
    }
}
