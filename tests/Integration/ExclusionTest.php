<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Integration;

use Avunu\WPCloudFiles\CLI;
use Avunu\WPCloudFiles\DirectUpload\DirectUploadProcessor;
use Avunu\WPCloudFiles\Tests\Support\WPTestCase;
use WP_CLI;

/**
 * Private files under S3_EXCLUDE_PREFIXES never reach the bucket.
 *
 * The WooCommerce case is reproduced the way WooCommerce does it: its
 * downloadable-product uploader filters `upload_dir` so the file lands in
 * uploads/woocommerce_uploads/, and the attachment's stored path starts there.
 *
 * @covers \Avunu\WPCloudFiles\Exclusions
 * @covers \Avunu\WPCloudFiles\MediaHandler::processMedia
 * @covers \Avunu\WPCloudFiles\UrlRewriter
 */
final class ExclusionTest extends WPTestCase
{
    /**
     * @param array<string, mixed> $dirs
     * @return array<string, mixed>
     */
    public static function wooUploadDir(array $dirs): array
    {
        $dirs['subdir'] = '/woocommerce_uploads' . $dirs['subdir'];
        $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
        $dirs['url'] = $dirs['baseurl'] . $dirs['subdir'];

        return $dirs;
    }

    private function uploadDownloadableProduct(string $fixture = 'small.jpg'): int
    {
        add_filter('upload_dir', [self::class, 'wooUploadDir']);

        try {
            return $this->uploadFixture($fixture);
        } finally {
            remove_filter('upload_dir', [self::class, 'wooUploadDir']);
        }
    }

    private function localPathFor(int $id): string
    {
        return trailingslashit(wp_upload_dir()['basedir']) . (string) get_post_meta($id, '_wp_attached_file', true);
    }

    public function testAWooCommerceDownloadStaysLocalAndOutOfTheBucket(): void
    {
        $id = $this->uploadDownloadableProduct();
        $key = (string) get_post_meta($id, '_wp_attached_file', true);

        $this->assertStringStartsWith('woocommerce_uploads/', $key, 'precondition: stored like WooCommerce stores it');
        $this->assertSame([], $this->listObjects(), 'no object may be created for a private file');
        $this->assertFileExists($this->localPathFor($id), 'the local file is the only copy and must stay');
    }

    public function testAWooCommerceDownloadKeepsItsLocalUrl(): void
    {
        $id = $this->uploadDownloadableProduct();

        $this->assertStringStartsWith(
            trailingslashit(wp_upload_dir()['baseurl']) . 'woocommerce_uploads/',
            (string) wp_get_attachment_url($id)
        );
    }

    public function testMigrateLeavesAPrivateFileAlone(): void
    {
        remove_all_filters('wp_update_attachment_metadata');
        $id = $this->uploadDownloadableProduct();
        $this->emptyBucket();

        WP_CLI::reset();
        (new CLI())->migrate([], ['force' => true]);

        $this->assertSame([], $this->listObjects(), '--force must not override an exclusion');
        $this->assertFileExists($this->localPathFor($id));
        $this->assertStringContainsString('Excluded: 1', WP_CLI::$messages['success'][0]);
    }

    public function testTheFilterCanKeepAnyUploadLocal(): void
    {
        add_filter('wpcf_should_offload', '__return_false');

        $id = $this->uploadFixture('small.jpg');

        $this->assertSame([], $this->listObjects());
        $this->assertFileExists($this->localPathFor($id));
        $this->assertStringStartsWith(wp_upload_dir()['baseurl'], (string) wp_get_attachment_url($id));
    }

    public function testTheBackgroundOptimizerSkipsAnExcludedKey(): void
    {
        $id = self::factory()->attachment->create([
            'post_mime_type' => 'image/jpeg',
            'file'           => 'woocommerce_uploads/2026/08/cover.jpg',
        ]);
        update_post_meta($id, '_wp_attached_file', 'woocommerce_uploads/2026/08/cover.jpg');
        update_post_meta($id, '_wpcf_direct_upload_key', 'woocommerce_uploads/2026/08/cover.jpg');
        update_post_meta($id, '_wpcf_pending_optimization', 1);

        (new DirectUploadProcessor())->process($id);

        $this->assertFileDoesNotExist(
            trailingslashit(wp_upload_dir()['basedir']) . 'woocommerce_uploads/2026/08/cover.jpg',
            'nothing may be downloaded for an excluded key'
        );
        $this->assertSame('', get_post_meta($id, '_wpcf_pending_optimization', true));
        $this->assertSame([], $this->listObjects());
    }
}
