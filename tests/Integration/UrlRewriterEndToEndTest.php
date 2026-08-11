<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Integration;

use Avunu\WPCloudFiles\Tests\Support\Profile;
use Avunu\WPCloudFiles\Tests\Support\WPTestCase;

/**
 * The decisive test in the suite.
 *
 * Uploads a real image through WordPress' real pipeline, then proves the URL
 * WordPress hands out is (a) the object storage URL, and (b) actually resolves
 * to the bytes that were uploaded. A mocked filesystem can assert the first half
 * but never the second, and the second is what catches prefix/boundary and
 * S3_ROOT mistakes.
 *
 * @covers \Avunu\WPCloudFiles\UrlRewriter
 * @covers \Avunu\WPCloudFiles\MediaHandler
 */
final class UrlRewriterEndToEndTest extends WPTestCase
{
    private function expectedBase(): string
    {
        return Profile::is(Profile::ROOT)
            ? rtrim(S3_PUBLIC_URL, '/') . '/uploads'
            : rtrim(S3_PUBLIC_URL, '/');
    }

    public function testAttachmentUrlPointsAtObjectStorageAndResolves(): void
    {
        $id = $this->uploadFixture('large.jpg');

        $url = wp_get_attachment_url($id);

        $this->assertIsString($url);
        $this->assertStringStartsWith(
            $this->expectedBase(),
            $url,
            'the attachment URL must be rewritten to object storage'
        );
        $this->assertStringNotContainsString(
            '/wp-content/uploads/',
            $url,
            'a local uploads path means the rewrite did not happen'
        );

        // The half no mock can prove: the URL is real.
        $response = wp_remote_get($url);

        $this->assertNotWPError($response, 'the rewritten URL must be fetchable');
        $this->assertSame(200, wp_remote_retrieve_response_code($response), "GET {$url} did not return 200");

        $body = wp_remote_retrieve_body($response);
        $this->assertNotSame('', $body);

        $info = getimagesizefromstring($body);
        $this->assertIsArray($info, 'the fetched bytes must be a real image');
        $this->assertSame('image/jpeg', $info['mime']);
    }

    public function testTheOriginalIsRemovedLocallyAndPresentInTheBucket(): void
    {
        $id = $this->uploadFixture('large.jpg');

        $key = (string) get_post_meta($id, '_wp_attached_file', true);
        $this->assertNotSame('', $key);

        $this->assertTrue(
            $this->objectExists($key),
            "the uploaded file should be in the bucket at {$key}"
        );

        $uploads = wp_upload_dir();
        $this->assertFileDoesNotExist(
            trailingslashit($uploads['basedir']) . $key,
            'the local copy must be deleted once the file is on S3'
        );
    }

    public function testEveryGeneratedSizeReachesTheBucket(): void
    {
        $id = $this->uploadFixture('large.jpg');

        $meta = wp_get_attachment_metadata($id);
        $this->assertIsArray($meta);
        $this->assertNotEmpty($meta['sizes'], 'a 3000x2000 fixture must generate resized versions');

        $dir = dirname((string) $meta['file']);
        $objects = $this->listObjects();

        foreach ($meta['sizes'] as $name => $size) {
            $key = $dir . '/' . $size['file'];
            $this->assertContains($key, $objects, "size '{$name}' was never uploaded ({$key})");
        }
    }

    public function testSrcsetUrlsAreAllRewritten(): void
    {
        $id = $this->uploadFixture('large.jpg');

        $srcset = wp_get_attachment_image_srcset($id, 'medium');

        if (!is_string($srcset) || $srcset === '') {
            $this->markTestSkipped('WordPress generated no srcset for this fixture');
        }

        $this->assertStringNotContainsString(
            '/wp-content/uploads/',
            $srcset,
            'every srcset candidate must point at object storage'
        );
        $this->assertStringContainsString($this->expectedBase(), $srcset);
    }

    /**
     * A file too small for any registered size still has to make it to S3 --
     * this is the branch where metadata carries no 'sizes' at all.
     */
    public function testASmallImageWithNoGeneratedSizesStillUploads(): void
    {
        $id = $this->uploadFixture('small.jpg');

        $key = (string) get_post_meta($id, '_wp_attached_file', true);

        $this->assertTrue($this->objectExists($key), "small image missing from the bucket at {$key}");
        $this->assertStringStartsWith($this->expectedBase(), (string) wp_get_attachment_url($id));
    }
}
