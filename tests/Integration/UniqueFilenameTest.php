<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Integration;

use Avunu\WPCloudFiles\Tests\Support\WPTestCase;

/**
 * Once an upload is offloaded its local copy is gone, so WordPress' own
 * unique-name check no longer sees it. A second upload of the same name must
 * still get a new key instead of overwriting the first attachment's object.
 *
 * @covers \Avunu\WPCloudFiles\MediaHandler::uniqueFilename
 */
final class UniqueFilenameTest extends WPTestCase
{
    public function testASecondUploadWithTheSameNameDoesNotOverwriteTheFirst(): void
    {
        $first = $this->uploadFixture('small.jpg');
        $firstKey = (string) get_post_meta($first, '_wp_attached_file', true);
        $this->assertTrue($this->objectExists($firstKey));
        $this->assertFileDoesNotExist(
            trailingslashit(wp_upload_dir()['basedir']) . $firstKey,
            'precondition: the local copy was removed after offloading'
        );

        // Mark the first object so an overwrite would be visible.
        $this->putObject($firstKey, 'first attachment');

        $second = $this->uploadFixture('small.jpg');
        $secondKey = (string) get_post_meta($second, '_wp_attached_file', true);

        $this->assertNotSame($firstKey, $secondKey);
        $this->assertSame('first attachment', $this->readObject($firstKey), 'the first object must be untouched');
        $this->assertTrue($this->objectExists($secondKey));
    }

    public function testAFreeNameIsLeftAlone(): void
    {
        $dir = wp_upload_dir()['path'];

        $this->assertSame('never-uploaded.jpg', wp_unique_filename($dir, 'never-uploaded.jpg'));
    }

    public function testDirectoriesOutsideUploadsAreNotChecked(): void
    {
        $this->putObject('wpcf-elsewhere.txt', 'x');

        $this->assertSame('wpcf-elsewhere.txt', wp_unique_filename(sys_get_temp_dir(), 'wpcf-elsewhere.txt'));
    }
}
