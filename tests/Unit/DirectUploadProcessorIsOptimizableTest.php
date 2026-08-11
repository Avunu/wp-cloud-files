<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Unit;

use Avunu\WPCloudFiles\DirectUpload\DirectUploadProcessor;
use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;

/**
 * @covers \Avunu\WPCloudFiles\DirectUpload\DirectUploadProcessor::isOptimizable
 */
final class DirectUploadProcessorIsOptimizableTest extends UnitTestCase
{
    /**
     * @dataProvider mimeTypes
     */
    public function testIsOptimizable(string $mime, bool $expected, string $why): void
    {
        $this->assertSame($expected, DirectUploadProcessor::isOptimizable($mime), $why);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function mimeTypes(): array
    {
        return [
            'jpeg' => ['image/jpeg', true, 'images are matched by prefix'],
            'png'  => ['image/png', true, 'images are matched by prefix'],
            'webp' => ['image/webp', true, 'images are matched by prefix'],

            // Pinned deliberately: SVGs match the image/ prefix, so they get
            // handed to wp_generate_attachment_metadata like a raster image.
            'svg' => ['image/svg+xml', true, 'matches the image/ prefix, so SVGs are queued for optimization'],

            'pdf'  => ['application/pdf', true, 'PDF is in DOCUMENT_TYPES'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', true, 'in DOCUMENT_TYPES'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', true, 'in DOCUMENT_TYPES'],
            'csv'  => ['text/csv', true, 'in DOCUMENT_TYPES'],

            'mp4' => ['video/mp4', false, 'video has no derivatives to generate'],
            'zip' => ['application/zip', false, 'not a renderable document'],
            'txt' => ['text/plain', false, 'text/plain is not in DOCUMENT_TYPES'],
            'empty' => ['', false, 'an empty mime must not match'],

            // strpos(...) === 0 is case-sensitive, and DOCUMENT_TYPES uses a
            // strict in_array. WordPress lower-cases mime types before this is
            // reached, so uppercase input means something upstream is wrong.
            'uppercase image' => ['IMAGE/JPEG', false, 'matching is case-sensitive by design'],
            'uppercase pdf'   => ['APPLICATION/PDF', false, 'strict in_array is case-sensitive'],

            // Guards against a loosened prefix check.
            'image in the middle' => ['application/x-image/jpeg', false, 'image/ must be a prefix, not a substring'],
        ];
    }
}
