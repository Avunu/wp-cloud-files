<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Unit;

use Avunu\WPCloudFiles\Exclusions;
use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;
use Brain\Monkey\Filters;

/**
 * @covers \Avunu\WPCloudFiles\Exclusions
 */
final class ExclusionsTest extends UnitTestCase
{
    protected function tear_down(): void
    {
        putenv('S3_EXCLUDE_PREFIXES');
        parent::tear_down();
    }

    public function testTheDefaultListCoversThePrivatePluginDirectories(): void
    {
        $this->assertSame(
            ['woocommerce_uploads/', 'fluentform/', 'gravity_forms/', 'wpo_wcpdf', 'wc-logs/'],
            Exclusions::prefixes()
        );
    }

    /**
     * @dataProvider privatePaths
     */
    public function testPrivatePathsAreExcludedByDefault(string $path): void
    {
        $this->assertTrue(Exclusions::isExcluded($path));
        $this->assertFalse(Exclusions::shouldOffload($path, 12));
    }

    /** @return array<string, array{string}> */
    public static function privatePaths(): array
    {
        return [
            'woocommerce download' => ['woocommerce_uploads/2026/08/album.zip'],
            'fluent forms entry'   => ['fluentform/ff-abc123-upload.pdf'],
            'gravity forms entry'  => ['gravity_forms/3-abc/2026/08/cv.pdf'],
            'pdf invoice'          => ['wpo_wcpdf_8c1f/attachments/invoice-1001.pdf'],
            'woocommerce log'      => ['wc-logs/fatal-errors-2026-08-01.log'],
            'leading slash'        => ['/woocommerce_uploads/2026/08/album.zip'],
        ];
    }

    /**
     * @dataProvider publicPaths
     */
    public function testOrdinaryMediaIsOffloaded(string $path): void
    {
        $this->assertFalse(Exclusions::isExcluded($path));
        $this->assertTrue(Exclusions::shouldOffload($path, 12));
    }

    /** @return array<string, array{string}> */
    public static function publicPaths(): array
    {
        return [
            'year folder'          => ['2026/08/photo.jpg'],
            'no year folder'       => ['photo.jpg'],
            'similar name'         => ['woocommerce_uploads-old/photo.jpg'],
            'name inside a folder' => ['2026/08/woocommerce_uploads/photo.jpg'],
        ];
    }

    public function testTheEnvironmentReplacesTheDefaultList(): void
    {
        putenv('S3_EXCLUDE_PREFIXES= private/ , /secret/,,private/');

        $this->assertSame(['private/', 'secret/'], Exclusions::prefixes());
        $this->assertTrue(Exclusions::isExcluded('secret/a.pdf'));
        $this->assertFalse(Exclusions::isExcluded('woocommerce_uploads/a.zip'), 'the default list is replaced, not extended');
    }

    public function testAListOfOnlyCommasExcludesNothing(): void
    {
        putenv('S3_EXCLUDE_PREFIXES=,');

        $this->assertSame([], Exclusions::prefixes());
        $this->assertFalse(Exclusions::isExcluded('woocommerce_uploads/a.zip'));
    }

    public function testTheFilterHasTheLastWord(): void
    {
        Filters\expectApplied('wpcf_should_offload')
            ->once()
            ->with(true, '2026/08/members-only.pdf', 42)
            ->andReturn(false);

        $this->assertFalse(Exclusions::shouldOffload('2026/08/members-only.pdf', 42));
    }

    public function testTheFilterCanOffloadAnExcludedPath(): void
    {
        Filters\expectApplied('wpcf_should_offload')
            ->once()
            ->with(false, 'woocommerce_uploads/2026/08/free.zip', 0)
            ->andReturn(true);

        $this->assertTrue(Exclusions::shouldOffload('woocommerce_uploads/2026/08/free.zip'));
    }
}
