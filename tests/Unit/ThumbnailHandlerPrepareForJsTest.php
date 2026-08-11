<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Unit;

use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;
use Avunu\WPCloudFiles\ThumbnailHandler;
use Brain\Monkey;
use stdClass;

/**
 * @covers \Avunu\WPCloudFiles\ThumbnailHandler::prepareAttachmentForJs
 */
final class ThumbnailHandlerPrepareForJsTest extends UnitTestCase
{
    private ThumbnailHandler $handler;

    protected function set_up(): void
    {
        parent::set_up();
        $this->handler = new ThumbnailHandler();
    }

    private function attachment(string $mime, int $id = 42): stdClass
    {
        $attachment = new stdClass();
        $attachment->ID = $id;
        $attachment->post_mime_type = $mime;

        return $attachment;
    }

    /**
     * @return array{sizes: array<string, array{file: string, width: int, height: int}>}
     */
    private function pdfMeta(): array
    {
        return [
            'sizes' => [
                'full'      => ['file' => 'doc-full.jpg', 'width' => 1500, 'height' => 1940],
                'thumbnail' => ['file' => 'doc-150x150.jpg', 'width' => 150, 'height' => 150],
                'medium'    => ['file' => 'doc-300x300.jpg', 'width' => 300, 'height' => 300],
                'large'     => ['file' => 'doc-1024x1024.jpg', 'width' => 1024, 'height' => 1024],
            ],
        ];
    }

    public function testNonDocumentMimeIsReturnedUnchanged(): void
    {
        $response = ['id' => 42];

        $this->assertSame(
            $response,
            $this->handler->prepareAttachmentForJs($response, $this->attachment('image/jpeg'), $this->pdfMeta())
        );
    }

    public function testMissingSizesIsReturnedUnchanged(): void
    {
        $response = ['id' => 42];

        $this->assertSame(
            $response,
            $this->handler->prepareAttachmentForJs($response, $this->attachment('application/pdf'), [])
        );
    }

    public function testBuildsSizeUrlsAndIconFromTheAttachmentUrl(): void
    {
        Monkey\Functions\when('wp_get_attachment_url')
            ->justReturn('https://cdn.test/2026/08/report.pdf');

        $result = $this->handler->prepareAttachmentForJs(
            ['id' => 42],
            $this->attachment('application/pdf'),
            $this->pdfMeta()
        );

        $this->assertSame('https://cdn.test/2026/08/doc-full.jpg', $result['sizes']['full']['url']);
        $this->assertSame('https://cdn.test/2026/08/doc-150x150.jpg', $result['sizes']['thumbnail']['url']);
        $this->assertSame('https://cdn.test/2026/08/doc-1024x1024.jpg', $result['sizes']['large']['url']);
        $this->assertSame(150, $result['sizes']['thumbnail']['width']);

        $this->assertSame(
            'https://cdn.test/2026/08/doc-150x150.jpg',
            $result['icon'],
            'the icon tracks the thumbnail size'
        );
    }

    public function testOnlyPresentSizesAreEmitted(): void
    {
        Monkey\Functions\when('wp_get_attachment_url')
            ->justReturn('https://cdn.test/2026/08/report.pdf');

        $result = $this->handler->prepareAttachmentForJs(
            ['id' => 42],
            $this->attachment('application/pdf'),
            ['sizes' => ['thumbnail' => ['file' => 'doc-150x150.jpg', 'width' => 150, 'height' => 150]]]
        );

        $this->assertSame(['thumbnail'], array_keys($result['sizes']));
    }

    /**
     * wp_get_attachment_url() returns string|false -- false whenever the
     * attachment has no _wp_attached_file, which is exactly the state a failed
     * direct upload leaves behind. dirname(false) coerces to dirname('') === ''
     * and every URL then comes out as a site-root-relative "/doc-150x150.jpg",
     * so the media library renders broken thumbnails instead of falling back to
     * the generic document icon.
     */
    public function testUnresolvableAttachmentUrlLeavesTheResponseUntouched(): void
    {
        Monkey\Functions\when('wp_get_attachment_url')->justReturn(false);

        $response = ['id' => 42, 'icon' => 'https://example.org/wp-includes/images/media/document.png'];

        $result = $this->handler->prepareAttachmentForJs(
            $response,
            $this->attachment('application/pdf'),
            $this->pdfMeta()
        );

        $this->assertSame($response, $result, 'without a base URL there is nothing valid to build');
    }
}
