<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Integration;

use Avunu\WPCloudFiles\CLI;
use Avunu\WPCloudFiles\Tests\Support\WPTestCase;
use WP_CLI;

/**
 * `wp wp-cloud-files migrate` uploads every file belonging to an attachment and
 * then deletes the local copies. It is the most destructive code in the plugin
 * and had no coverage at all.
 *
 * @covers \Avunu\WPCloudFiles\CLI
 */
final class CliMigrateTest extends WPTestCase
{
    private CLI $cli;

    public function set_up(): void
    {
        parent::set_up();

        WP_CLI::reset();
        $this->cli = new CLI();
    }

    /**
     * Create an attachment whose files exist locally but are NOT yet on S3 --
     * i.e. the pre-migration state. The plugin's own hooks would upload and
     * delete them on creation, so they are suspended for the setup.
     */
    private function legacyAttachment(string $fixture = 'large.jpg'): int
    {
        remove_all_filters('wp_update_attachment_metadata');

        $id = $this->uploadFixture($fixture);

        $this->emptyBucket();

        return $id;
    }

    private function localPathFor(int $id): string
    {
        $uploads = wp_upload_dir();

        return trailingslashit($uploads['basedir']) . (string) get_post_meta($id, '_wp_attached_file', true);
    }

    // ---------------------------------------------------------------- //

    public function testMigrateUploadsFilesAndRemovesTheLocalCopies(): void
    {
        $id = $this->legacyAttachment();
        $key = (string) get_post_meta($id, '_wp_attached_file', true);
        $local = $this->localPathFor($id);

        $this->assertFileExists($local, 'precondition: the file starts out local only');
        $this->assertFalse($this->objectExists($key), 'precondition: nothing on S3 yet');

        $this->cli->migrate([], []);

        $this->assertTrue($this->objectExists($key), 'migrate must upload the main file');
        $this->assertFileDoesNotExist($local, 'and remove the local copy by default');
    }

    public function testMigrateUploadsEverySizeNotJustTheOriginal(): void
    {
        $id = $this->legacyAttachment();

        $meta = wp_get_attachment_metadata($id);
        $this->assertNotEmpty($meta['sizes'], 'precondition: the fixture generates resized versions');

        $this->cli->migrate([], []);

        $dir = dirname((string) $meta['file']);
        $objects = $this->listObjects();

        foreach ($meta['sizes'] as $name => $size) {
            $this->assertContains($dir . '/' . $size['file'], $objects, "size '{$name}' was not migrated");
        }
    }

    public function testKeepLocalLeavesTheOriginalInPlace(): void
    {
        $id = $this->legacyAttachment();
        $key = (string) get_post_meta($id, '_wp_attached_file', true);
        $local = $this->localPathFor($id);

        $this->cli->migrate([], ['keep-local' => true]);

        $this->assertTrue($this->objectExists($key), 'the file is still uploaded');
        $this->assertFileExists($local, '--keep-local must not delete anything');
    }

    /**
     * The safety property that matters most: if the upload fails, the only copy
     * of the user's file is the local one, and it must survive.
     *
     * Driven by making the local file unreadable, so S3Client::uploadFile()
     * returns false without the object ever being written.
     */
    public function testAFailedUploadDoesNotDeleteTheLocalFile(): void
    {
        $id = $this->legacyAttachment('small.jpg');
        $key = (string) get_post_meta($id, '_wp_attached_file', true);
        $local = $this->localPathFor($id);

        chmod($local, 0o000);

        try {
            if (is_readable($local)) {
                // Running as root ignores the mode bits; there is nothing to prove.
                $this->markTestSkipped('cannot make a file unreadable as this user');
            }

            $this->cli->migrate([], []);

            $this->assertFileExists($local, 'a failed upload must never delete the local original');
            $this->assertFalse($this->objectExists($key), 'and nothing should have reached S3');
        } finally {
            chmod($local, 0o644);
        }
    }

    public function testFilesAlreadyOnS3AreSkipped(): void
    {
        $id = $this->legacyAttachment();
        $key = (string) get_post_meta($id, '_wp_attached_file', true);
        $local = $this->localPathFor($id);

        // Pre-seed the bucket with different content, then confirm migrate leaves
        // it alone rather than re-uploading.
        $this->putObject($key, 'pre-existing content');

        $this->cli->migrate([], []);

        $this->assertFileExists($local, 'a skipped attachment keeps its local file');
        $this->assertSame(
            'pre-existing content',
            $this->readObject($key),
            'an object already on S3 must not be overwritten without --force'
        );
    }

    public function testForceReUploadsFilesAlreadyOnS3(): void
    {
        $id = $this->legacyAttachment();
        $key = (string) get_post_meta($id, '_wp_attached_file', true);

        $this->putObject($key, 'pre-existing content');

        $this->cli->migrate([], ['force' => true]);

        $this->assertNotSame(
            'pre-existing content',
            $this->readObject($key),
            '--force must overwrite the stale object'
        );
    }

    public function testMigrateWarnsWhenThereIsNothingToDo(): void
    {
        $this->cli->migrate([], []);

        $this->assertNotEmpty(WP_CLI::$messages['warning'], 'an empty library should warn, not fail');
        $this->assertStringContainsString('No media items found', WP_CLI::$messages['warning'][0]);
    }

    public function testMigrateReportsASummary(): void
    {
        $this->legacyAttachment();

        $this->cli->migrate([], []);

        $this->assertNotEmpty(WP_CLI::$messages['success']);
        $this->assertStringContainsString('Migration completed', WP_CLI::$messages['success'][0]);
    }

    public function testLimitBoundsHowMuchIsProcessed(): void
    {
        $first = $this->legacyAttachment('small.jpg');
        $this->legacyAttachment('small.jpg');

        $this->cli->migrate([], ['limit' => 1]);

        $migrated = array_filter(
            [$first],
            fn(int $id): bool => $this->objectExists((string) get_post_meta($id, '_wp_attached_file', true))
        );

        $this->assertCount(1, $migrated, '--limit=1 must migrate exactly one attachment');
    }
}
