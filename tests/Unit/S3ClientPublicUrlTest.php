<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Unit;

use Avunu\WPCloudFiles\S3Client;
use Avunu\WPCloudFiles\Tests\Support\Profile;
use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;

/**
 * getPublicUrl() performs no I/O -- the AWS client is only built lazily inside
 * getClient() -- so the whole thing is pure string math over S3_PUBLIC_URL and
 * S3_ROOT and needs no double at all.
 *
 * @covers \Avunu\WPCloudFiles\S3Client::getPublicUrl
 * @covers \Avunu\WPCloudFiles\S3Client::getInstance
 */
final class S3ClientPublicUrlTest extends UnitTestCase
{
    public function testGetInstanceReturnsTheSameObject(): void
    {
        $this->assertSame(S3Client::getInstance(), S3Client::getInstance());
    }

    public function testResetHelperReplacesTheSingleton(): void
    {
        $first = S3Client::getInstance();
        $this->resetS3Singleton();

        $this->assertNotSame($first, S3Client::getInstance(), 'tearDown must be able to clear leaked configuration');
    }

    public function testBuildsPublicUrlFromRelativePath(): void
    {
        $this->assertSame(
            $this->expected('2026/08/photo.jpg'),
            S3Client::getInstance()->getPublicUrl('2026/08/photo.jpg')
        );
    }

    public function testLeadingSlashIsNormalised(): void
    {
        $this->assertSame(
            $this->expected('2026/08/photo.jpg'),
            S3Client::getInstance()->getPublicUrl('/2026/08/photo.jpg'),
            'a leading slash must not produce a doubled separator'
        );
    }

    /**
     * Pinned because CLI::migrate_urls() builds a search-replace pattern from
     * getPublicUrl('') and depends on the trailing slash being present.
     */
    public function testEmptyPathYieldsTheBaseWithTrailingSlash(): void
    {
        $this->assertSame($this->expected(''), S3Client::getInstance()->getPublicUrl(''));
    }

    public function testNestedPathsArePreserved(): void
    {
        $this->assertSame(
            $this->expected('2026/08/sub/dir/photo-150x150.jpg'),
            S3Client::getInstance()->getPublicUrl('2026/08/sub/dir/photo-150x150.jpg')
        );
    }

    /**
     * The S3_ROOT prefix is what separates the "root" profile from "default";
     * asserting it here is the cheap half of the check that
     * UrlRewriterEndToEndTest proves against a real bucket.
     */
    private function expected(string $path): string
    {
        $base = 'https://cdn.test';

        if (Profile::is(Profile::ROOT)) {
            $base .= '/uploads';
        }

        return $base . '/' . $path;
    }
}
