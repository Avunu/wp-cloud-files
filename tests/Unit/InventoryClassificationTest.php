<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Unit;

use Avunu\WPCloudFiles\CLI;
use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;

/**
 * How `wp wp-cloud-files inventory` sorts the uploads directory.
 *
 * @covers \Avunu\WPCloudFiles\CLI::classifyUploads
 */
final class InventoryClassificationTest extends UnitTestCase
{
    private string $dir = '';

    protected function set_up(): void
    {
        parent::set_up();

        $this->dir = sys_get_temp_dir() . '/wpcf-inventory-' . bin2hex(random_bytes(4));

        $files = [
            '2026/08/photo.jpg'                    => 'jpeg',
            '2026/08/photo-150x150.jpg'            => 'jpg',
            'sites-legacy/2019/01/old.jpg'         => 'jpg',
            'woocommerce_uploads/2026/08/album.zip' => 'zip-bytes',
            'wpo_wcpdf_8c1f/invoice.pdf'           => 'pdf',
            'cwicly/css/cc-post-12.css'            => 'css',
            'fonts/inter-400.woff2'                => 'font',
            'mystery-plugin/cache.bin'             => 'bin',
            'loose.jpg'                            => 'jpg',
        ];

        foreach ($files as $path => $contents) {
            $file = $this->dir . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0o777, true);
            }
            file_put_contents($file, $contents);
        }
    }

    protected function tear_down(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);

        parent::tear_down();
    }

    public function testEveryTopLevelEntryIsClassified(): void
    {
        $entries = CLI::classifyUploads($this->dir, ['dirs' => ['2026', 'sites-legacy'], 'root' => true]);

        $classes = [];
        foreach ($entries as $entry) {
            $classes[$entry['path']] = $entry['class'];
        }

        $this->assertSame(
            [
                '2026/'                 => 'attachment',
                'cwicly/'               => 'public-generated',
                'fonts/'                => 'public-generated',
                'mystery-plugin/'       => 'unknown',
                'sites-legacy/'         => 'attachment',
                'woocommerce_uploads/'  => 'private',
                'wpo_wcpdf_8c1f/'       => 'private',
                ''                      => 'attachment',
            ],
            $classes
        );
    }

    public function testFilesAndBytesAreCountedRecursively(): void
    {
        $entries = CLI::classifyUploads($this->dir, ['dirs' => [], 'root' => false]);

        $byPath = [];
        foreach ($entries as $entry) {
            $byPath[$entry['path']] = $entry;
        }

        $this->assertSame(2, $byPath['2026/']['files']);
        $this->assertSame(7, $byPath['2026/']['bytes']);
        $this->assertSame('attachment', $byPath['2026/']['class'], 'year folders count even without a database row');
        $this->assertSame('unknown', $byPath['']['class'], 'loose files with no root-level attachment are unknown');
    }

    public function testAMissingDirectoryIsEmpty(): void
    {
        $this->assertSame([], CLI::classifyUploads($this->dir . '/nope', ['dirs' => [], 'root' => false]));
    }
}
