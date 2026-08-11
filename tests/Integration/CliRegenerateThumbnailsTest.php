<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Integration;

use Avunu\WPCloudFiles\CLI;
use Avunu\WPCloudFiles\Tests\Support\WPTestCase;
use WP_CLI;

/**
 * `wp wp-cloud-files regenerate-thumbnails` re-renders document previews and
 * pushes them to S3.
 *
 * @covers \Avunu\WPCloudFiles\CLI::regenerate_thumbnails
 */
final class CliRegenerateThumbnailsTest extends WPTestCase
{
    private CLI $cli;

    public function set_up(): void
    {
        parent::set_up();

        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('document thumbnails need the imagick extension');
        }

        WP_CLI::reset();
        $this->cli = new CLI();
    }

    public function testSkipsAttachmentsWithAnUnsupportedMimeType(): void
    {
        $id = $this->uploadFixture('small.jpg');

        $this->cli->regenerate_thumbnails([$id], []);

        $this->assertNotEmpty(
            WP_CLI::$messages['log'],
            'a targeted run reports what it did'
        );
        $this->assertStringContainsString(
            'Unsupported mime type',
            implode("\n", WP_CLI::$messages['log']),
            'an image is not a document, so there is nothing to regenerate'
        );
    }

    public function testRegeneratesThumbnailsForAPdfAndUploadsThem(): void
    {
        $id = $this->uploadFixture('sample.pdf');

        $key = (string) get_post_meta($id, '_wp_attached_file', true);

        // Clear the previews the upload hooks already produced, so the assertions
        // cannot pass on those -- but keep the source PDF on S3, because by this
        // point the local copy has been uploaded and deleted and the command has
        // to pull it back down to render from.
        wp_update_attachment_metadata($id, ['file' => $key]);
        $this->emptyBucket();
        $this->putObject($key, (string) file_get_contents(\Avunu\WPCloudFiles\Tests\Support\FixturePath::to('sample.pdf')));

        $this->cli->regenerate_thumbnails([$id], ['force' => true]);

        $meta = wp_get_attachment_metadata($id);

        $this->assertIsArray($meta);
        $this->assertNotEmpty($meta['sizes'] ?? [], 'the PDF should have gained preview sizes');

        $objects = $this->listObjects();
        $dir = dirname((string) get_post_meta($id, '_wp_attached_file', true));

        foreach ($meta['sizes'] as $name => $size) {
            $this->assertSame('image/jpeg', $size['mime-type'], "size '{$name}' should be a JPEG preview");
            $this->assertContains(
                $dir . '/' . $size['file'],
                $objects,
                "regenerated size '{$name}' was never uploaded"
            );
        }
    }

    public function testReportsASummaryForABulkRun(): void
    {
        $this->uploadFixture('sample.pdf');

        $this->cli->regenerate_thumbnails([], ['force' => true]);

        $reported = implode("\n", array_merge(WP_CLI::$messages['success'], WP_CLI::$messages['log']));

        $this->assertNotSame('', $reported, 'a bulk run must report what it processed');
    }
}
