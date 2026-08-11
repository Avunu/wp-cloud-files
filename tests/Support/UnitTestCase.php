<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Support;

use Brain\Monkey;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfilledTestCase;

/**
 * Base class for suites that stub WordPress with Brain Monkey rather than
 * loading it.
 *
 * Extends the Polyfills TestCase so unit and integration suites share one
 * set_up()/tear_down() spelling -- the WordPress core suite requires the
 * polyfills anyway, so this costs nothing and avoids two conventions.
 */
abstract class UnitTestCase extends PolyfilledTestCase
{
    use ResetsS3Singleton;

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        // Used by almost every path through the plugin, and harmless to stub
        // unconditionally: WordPress' own implementation just appends a slash.
        Monkey\Functions\when('trailingslashit')->alias(
            static fn(string $value): string => rtrim($value, "/\\") . '/'
        );
        Monkey\Functions\when('untrailingslashit')->alias(
            static fn(string $value): string => rtrim($value, "/\\")
        );
        Monkey\Functions\when('wp_basename')->alias(
            static fn(string $path, string $suffix = ''): string => urldecode(basename(str_replace(['\\', '://'], '/', $path), $suffix))
        );
    }

    protected function tear_down(): void
    {
        $this->resetS3Singleton();
        Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * The upload-directory shape WordPress returns, with the values the whole
     * unit suite asserts against.
     *
     * @return array{path: string, url: string, subdir: string, basedir: string, baseurl: string, error: false}
     */
    protected function uploadDir(): array
    {
        return [
            'path'    => '/srv/wp-content/uploads/2026/08',
            'url'     => 'http://example.org/wp-content/uploads/2026/08',
            'subdir'  => '/2026/08',
            'basedir' => '/srv/wp-content/uploads',
            'baseurl' => 'http://example.org/wp-content/uploads',
            'error'   => false,
        ];
    }

    protected function stubUploadDir(): void
    {
        Monkey\Functions\when('wp_upload_dir')->justReturn($this->uploadDir());
    }
}
