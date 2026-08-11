<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Integration;

use Avunu\WPCloudFiles\S3Client;
use Avunu\WPCloudFiles\Tests\Support\WPTestCase;

/**
 * S3Client against a real S3 implementation.
 *
 * @covers \Avunu\WPCloudFiles\S3Client
 */
final class S3ClientTest extends WPTestCase
{
    private function client(): S3Client
    {
        return S3Client::getInstance();
    }

    public function testUploadFileStoresTheObject(): void
    {
        $local = tempnam(sys_get_temp_dir(), 'wpcf');
        file_put_contents($local, 'hello wp-cloud-files');

        $this->assertTrue($this->client()->uploadFile($local, '2026/08/hello.txt'));
        $this->assertTrue($this->objectExists('2026/08/hello.txt'));

        unlink($local);
    }

    public function testUploadFileReturnsFalseForAMissingLocalFile(): void
    {
        $this->assertFalse($this->client()->uploadFile('/nonexistent/nope.txt', '2026/08/nope.txt'));
    }

    public function testDownloadFileRoundTrips(): void
    {
        $this->putObject('2026/08/round.txt', 'round trip payload');

        $local = tempnam(sys_get_temp_dir(), 'wpcf');
        $this->assertTrue($this->client()->downloadFile('2026/08/round.txt', $local));
        $this->assertSame('round trip payload', file_get_contents($local));

        unlink($local);
    }

    public function testDownloadFileReturnsFalseWhenTheObjectIsMissing(): void
    {
        $local = tempnam(sys_get_temp_dir(), 'wpcf');

        $this->assertFalse($this->client()->downloadFile('2026/08/absent.txt', $local));

        unlink($local);
    }

    /**
     * The interesting one. Flysystem's delete() returns void, so returning it
     * from a `: bool` method yields null -- which S3Client's own catch block then
     * swallows into a false. Deleting a file that exists therefore reported
     * failure (and logged a spurious "S3 Delete error"), while deleting one that
     * did not exist reported success. Exactly inverted.
     */
    public function testDeleteFileReportsSuccessWhenTheObjectExisted(): void
    {
        $this->putObject('2026/08/doomed.txt', 'delete me');
        $this->assertTrue($this->objectExists('2026/08/doomed.txt'));

        $this->assertTrue(
            $this->client()->deleteFile('2026/08/doomed.txt'),
            'deleting an existing object must report success'
        );
        $this->assertFalse($this->objectExists('2026/08/doomed.txt'), 'and must actually remove it');
    }

    public function testDeleteFileIsIdempotent(): void
    {
        $this->assertTrue(
            $this->client()->deleteFile('2026/08/never-existed.txt'),
            'deleting a missing object is not an error'
        );
    }

    public function testPresignedPutUrlIsUsable(): void
    {
        $url = $this->client()->createPresignedPutUrl('2026/08/presigned.txt', 'text/plain');

        $this->assertStringStartsWith(rtrim(S3_ENDPOINT, '/'), $url);

        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'body'    => 'uploaded via presigned url',
            'headers' => ['Content-Type' => 'text/plain'],
        ]);

        $this->assertNotWPError($response);
        $this->assertSame(200, wp_remote_retrieve_response_code($response), 'the presigned signature was rejected');
        $this->assertTrue($this->objectExists('2026/08/presigned.txt'));
    }

    /**
     * createPresignedPutUrl() signs the Content-Type, but enforcement is up to
     * the provider and cannot be relied on: MinIO accepts a PUT whose
     * Content-Type differs from the signed one (verified here -- it returns 200).
     *
     * This is documented rather than asserted as a rejection, because the plugin
     * cannot control it. It matters for the presign flow's threat model: the
     * extension allowlist in RestController::presign() constrains the *key*, not
     * the bytes or the type the browser actually sends.
     */
    public function testSignedContentTypeIsNotNecessarilyEnforcedByTheProvider(): void
    {
        $url = $this->client()->createPresignedPutUrl('2026/08/typed.txt', 'text/plain');

        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'body'    => 'wrong type',
            'headers' => ['Content-Type' => 'application/octet-stream'],
        ]);

        $this->assertNotWPError($response);

        $code = wp_remote_retrieve_response_code($response);
        $this->assertContains(
            $code,
            [200, 403],
            'either the provider enforces the signed Content-Type (403) or it does not (200)'
        );

        if ($code === 200) {
            $this->assertTrue(
                $this->objectExists('2026/08/typed.txt'),
                'if the PUT was accepted the object must exist'
            );
        }
    }
}
