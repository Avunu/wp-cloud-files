<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Unit;

use Avunu\WPCloudFiles\MediaHandler;
use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;
use Brain\Monkey;
use ReflectionMethod;

/**
 * MediaHandler decides an attachment is "complete" and therefore safe to ship to
 * S3 -- at which point uploadFile() deletes the local original. If it calls
 * complete too early, WordPress is still generating subsizes from a file that no
 * longer exists, and those derivatives are lost.
 *
 * So the plugin's model of "which sizes will WordPress generate?" has to match
 * image_resize_dimensions() exactly.
 *
 * @covers \Avunu\WPCloudFiles\MediaHandler
 */
final class MediaHandlerSubsizeRuleTest extends UnitTestCase
{
    private MediaHandler $handler;

    protected function set_up(): void
    {
        parent::set_up();
        $this->handler = new MediaHandler();

        // Stock WordPress defaults.
        Monkey\Functions\when('get_option')->alias(static function (string $name, $default = false) {
            return match ($name) {
                'thumbnail_size_w', 'thumbnail_size_h' => 150,
                'thumbnail_crop' => 1,
                'medium_size_w', 'medium_size_h' => 300,
                'medium_large_size_w' => 768,
                'medium_large_size_h' => 0,
                'large_size_w', 'large_size_h' => 1024,
                'image_editor_output_format' => [],
                default => $default,
            };
        });
    }

    /**
     * The registered-subsize table on a stock install, in the shape
     * wp_get_registered_image_subsizes() returns.
     *
     * @return array<string, array{width: int, height: int, crop: bool}>
     */
    private function stockSubsizes(): array
    {
        return [
            'thumbnail'    => ['width' => 150, 'height' => 150, 'crop' => true],
            'medium'       => ['width' => 300, 'height' => 300, 'crop' => false],
            'medium_large' => ['width' => 768, 'height' => 0, 'crop' => false],
            'large'        => ['width' => 1024, 'height' => 1024, 'crop' => false],
            '1536x1536'    => ['width' => 1536, 'height' => 1536, 'crop' => false],
            '2048x2048'    => ['width' => 2048, 'height' => 2048, 'crop' => false],
        ];
    }

    private function stubSubsizes(): void
    {
        Monkey\Functions\when('wp_get_registered_image_subsizes')->justReturn($this->stockSubsizes());
        Monkey\Functions\when('wp_get_additional_image_sizes')->justReturn([
            '1536x1536' => ['width' => 1536, 'height' => 1536, 'crop' => false],
            '2048x2048' => ['width' => 2048, 'height' => 2048, 'crop' => false],
        ]);
        Monkey\Functions\when('wp_image_editor_supports')->justReturn(false);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function isComplete(array $metadata): bool
    {
        $method = new ReflectionMethod(MediaHandler::class, 'isImageMetadataComplete');
        $method->setAccessible(true);

        return (bool) $method->invoke($this->handler, $metadata, 42, 'image/jpeg');
    }

    /**
     * A 2000x500 panorama. WordPress skips a size only when the original is
     * smaller in BOTH dimensions, so it still generates `large` (1024x256) and
     * `1536x1536` (1536x384). Treating 500 < 1024 as "too small" declares the
     * attachment complete while those two are still being written.
     */
    public function testPanoramaStillNeedsTheWideSizes(): void
    {
        $this->stubSubsizes();

        $complete = $this->isComplete([
            'file'   => '2026/08/pano.jpg',
            'width'  => 2000,
            'height' => 500,
            'sizes'  => [
                'thumbnail'    => ['file' => 'pano-150x150.jpg'],
                'medium'       => ['file' => 'pano-300x75.jpg'],
                'medium_large' => ['file' => 'pano-768x192.jpg'],
            ],
        ]);

        $this->assertFalse(
            $complete,
            'large (1024x256) and 1536x1536 (1536x384) are still generated for a 2000x500 original'
        );
    }

    public function testPanoramaIsCompleteOnceTheWideSizesArePresent(): void
    {
        $this->stubSubsizes();

        $complete = $this->isComplete([
            'file'   => '2026/08/pano.jpg',
            'width'  => 2000,
            'height' => 500,
            'sizes'  => [
                'thumbnail'    => ['file' => 'pano-150x150.jpg'],
                'medium'       => ['file' => 'pano-300x75.jpg'],
                'medium_large' => ['file' => 'pano-768x192.jpg'],
                'large'        => ['file' => 'pano-1024x256.jpg'],
                '1536x1536'    => ['file' => 'pano-1536x384.jpg'],
            ],
        ]);

        $this->assertTrue($complete, '2048x2048 is correctly skipped: 2000 < 2048 AND 500 < 2048');
    }

    /**
     * 800x100 with a *cropped* thumbnail. WordPress clamps to
     * min(150,800) x min(150,100) and produces a 150x100 file. crop does not
     * affect whether the size is generated at all -- that branch runs after the
     * early return -- so the rule cannot be "use && only when not cropped".
     */
    public function testCroppedThumbnailIsStillGeneratedForAShortImage(): void
    {
        $this->stubSubsizes();

        $complete = $this->isComplete([
            'file'   => '2026/08/strip.jpg',
            'width'  => 800,
            'height' => 100,
            'sizes'  => [
                'medium'       => ['file' => 'strip-300x38.jpg'],
                'medium_large' => ['file' => 'strip-768x96.jpg'],
            ],
        ]);

        $this->assertFalse($complete, 'a cropped 150x150 thumbnail is still produced (as 150x100)');
    }

    /**
     * medium_large is 768x0. With a zero height only the width is compared, which
     * is why the original `||` happened to be correct for this one size.
     */
    public function testZeroHeightSizeComparesWidthOnly(): void
    {
        $this->stubSubsizes();

        $complete = $this->isComplete([
            'file'   => '2026/08/tall.jpg',
            'width'  => 500,
            'height' => 3000,
            'sizes'  => [
                'thumbnail' => ['file' => 'tall-150x150.jpg'],
                'medium'    => ['file' => 'tall-300x1800.jpg'],
                'large'     => ['file' => 'tall-1024x1024.jpg'],
                '1536x1536' => ['file' => 'tall-1536x1536.jpg'],
                '2048x2048' => ['file' => 'tall-2048x2048.jpg'],
            ],
        ]);

        $this->assertTrue(
            $complete,
            'medium_large (768x0) is skipped because 500 < 768 on width alone'
        );
    }

    public function testGenuinelyTinyImageNeedsNothing(): void
    {
        $this->stubSubsizes();

        $complete = $this->isComplete([
            'file'   => '2026/08/tiny.jpg',
            'width'  => 80,
            'height' => 80,
            'sizes'  => ['thumbnail' => ['file' => 'tiny-80x80.jpg']],
        ]);

        $this->assertTrue($complete, 'an 80x80 original is smaller than every size in both dimensions');
    }
}
