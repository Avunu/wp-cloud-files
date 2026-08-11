<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Integration;

use Avunu\WPCloudFiles\DirectUpload\DirectUploadProcessor;
use Avunu\WPCloudFiles\DirectUpload\RestController;
use Avunu\WPCloudFiles\MediaHandler;
use Avunu\WPCloudFiles\Plugin;
use Avunu\WPCloudFiles\Tests\Support\WPTestCase;
use Avunu\WPCloudFiles\ThumbnailHandler;
use Avunu\WPCloudFiles\UrlRewriter;

/**
 * Proves the plugin actually booted through its real entry point, with the
 * hooks and priorities the rest of the suite depends on.
 *
 * @covers \Avunu\WPCloudFiles\Plugin
 */
final class PluginBootTest extends WPTestCase
{
    /**
     * The single most important assertion in the suite: nothing here may talk to
     * real S3. AWS SDK traffic goes through Guzzle and bypasses WP_Http, so
     * WP_HTTP_BLOCK_EXTERNAL and the NoRemoteHttp filter cannot see it -- this is
     * the only guard against a misconfigured run writing to a real bucket.
     */
    public function testS3EndpointIsLoopback(): void
    {
        $host = parse_url(S3_ENDPOINT, PHP_URL_HOST);

        $this->assertContains(
            $host,
            ['127.0.0.1', 'localhost', '::1'],
            'S3_ENDPOINT must point at a local test server, never a real provider'
        );
    }

    public function testRequiredConstantsAreDefinedSoPluginBootRuns(): void
    {
        foreach (['S3_KEY', 'S3_SECRET', 'S3_BUCKET', 'S3_ENDPOINT', 'S3_PUBLIC_URL'] as $constant) {
            $this->assertTrue(defined($constant), "{$constant} must be defined or Plugin::boot() is skipped");
        }
    }

    public function testUrlRewritingHooksAreRegistered(): void
    {
        $this->assertHookedAt(10, 'wp_get_attachment_url', UrlRewriter::class, 'rewriteAttachmentUrl');
        $this->assertHookedAt(10, 'wp_calculate_image_srcset', UrlRewriter::class, 'rewriteSrcsetUrls');
    }

    /**
     * MediaHandler runs at 999 on purpose: every other plugin that mutates
     * attachment metadata must be finished before files are shipped to S3 and
     * the local copies deleted.
     */
    public function testMediaHandlerRunsLast(): void
    {
        $this->assertHookedAt(
            999,
            'wp_update_attachment_metadata',
            MediaHandler::class,
            'processMedia',
            'lowering this priority would upload files before other plugins finish with them'
        );
    }

    public function testDeletionAndThumbnailHooksAreRegistered(): void
    {
        $this->assertHookedAt(10, 'delete_attachment', MediaHandler::class, 'handleDeletion');
        $this->assertHookedAt(10, 'wp_generate_attachment_metadata', ThumbnailHandler::class, 'handleDocumentThumbnails');
        $this->assertHookedAt(10, 'wp_prepare_attachment_for_js', ThumbnailHandler::class, 'prepareAttachmentForJs');
    }

    public function testDirectUploadCronHookIsAlwaysBound(): void
    {
        $this->assertHookedAt(
            10,
            'wpcf_process_direct_upload',
            DirectUploadProcessor::class,
            'process',
            'bound unconditionally so already-queued events still run if the feature is disabled'
        );
    }

    public function testDirectUploadHooksAreRegisteredWhenEnabled(): void
    {
        $this->assertTrue(defined('S3_DIRECT_UPLOADS') && S3_DIRECT_UPLOADS);

        $this->assertHookedAt(10, 'rest_api_init', RestController::class, 'register');
        $this->assertHookedAt(100, 'admin_enqueue_scripts', Plugin::class, 'enqueueDirectUploadScript');
        $this->assertHookedAt(10, 'upload_size_limit', Plugin::class, 'filterUploadSizeLimit');
    }

    public function testRestRoutesAreRegistered(): void
    {
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/wp-cloud-files/v1/presign', $routes);
        $this->assertArrayHasKey('/wp-cloud-files/v1/attachment', $routes);
    }
}
