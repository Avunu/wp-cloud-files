<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Thumbnails;

use Avunu\WPCloudFiles\DocumentThumbnailer;
use Avunu\WPCloudFiles\Tests\Support\FixturePath;
use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;
use Imagick;
use PhpOffice\PhpWord\Settings as WordSettings;

/**
 * Exercises the real rendering stack: Imagick, its Ghostscript delegate, and
 * DomPDF wiring. getFormatInfo() is protected precisely so it can be reached
 * from a subclass, which is the seam this uses.
 *
 * @covers \Avunu\WPCloudFiles\DocumentThumbnailer
 */
final class DocumentThumbnailerTest extends UnitTestCase
{
    private ?string $pdfPath = null;
    private ?string $pdfName = null;

    protected function set_up(): void
    {
        parent::set_up();

        // PhpWord's settings are process-global statics that the constructor
        // mutates; snapshot them so one test cannot leak into the next.
        $this->pdfPath = WordSettings::getPdfRendererPath();
        $this->pdfName = WordSettings::getPdfRendererName();
    }

    protected function tear_down(): void
    {
        if (is_string($this->pdfPath) && $this->pdfPath !== '') {
            WordSettings::setPdfRendererPath($this->pdfPath);
        }
        if (is_string($this->pdfName) && $this->pdfName !== '') {
            WordSettings::setPdfRendererName($this->pdfName);
        }

        parent::tear_down();
    }

    private function thumbnailer(): DocumentThumbnailer
    {
        return new class () extends DocumentThumbnailer {
            /** @return array{type: string, format: string}|null */
            public function formatInfo(string $mimeType, string $filePath): ?array
            {
                return $this->getFormatInfo($mimeType, $filePath);
            }
        };
    }

    // ---------------------------------------------------------------- //
    // Environment                                                      //
    // ---------------------------------------------------------------- //

    public function testImagickIsAvailable(): void
    {
        $this->assertTrue(extension_loaded('imagick'), 'the imagick extension is required for document thumbnails');
    }

    /**
     * Regression guard for the hardcoded WP_PLUGIN_DIR . '/wp-cloud-files' path:
     * DomPDF must be found no matter what directory the plugin is installed in.
     */
    public function testConstructorWiresUpDomPdf(): void
    {
        $this->thumbnailer();

        $this->assertSame(
            WordSettings::PDF_RENDERER_DOMPDF,
            WordSettings::getPdfRendererName(),
            'DomPDF was not configured, so Word/Excel thumbnails would silently fail'
        );
        $this->assertDirectoryExists((string) WordSettings::getPdfRendererPath());
    }

    // ---------------------------------------------------------------- //
    // Format dispatch                                                  //
    // ---------------------------------------------------------------- //

    /**
     * @dataProvider formats
     * @param array{type: string, format: string}|null $expected
     */
    public function testFormatDispatch(string $mime, string $path, ?array $expected, string $why): void
    {
        $this->assertSame($expected, $this->thumbnailer()->formatInfo($mime, $path), $why);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array{type: string, format: string}|null, 3: string}>
     */
    public static function formats(): array
    {
        return [
            'pdf by mime' => ['application/pdf', '/x/report.pdf', ['type' => 'pdf', 'format' => 'PDF'], 'mime wins'],
            'docx by mime' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                '/x/report.docx',
                ['type' => 'word', 'format' => 'Word2007'],
                'mime wins',
            ],
            'xlsx by mime' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                '/x/sheet.xlsx',
                ['type' => 'spreadsheet', 'format' => 'Xlsx'],
                'mime wins',
            ],
            'csv by mime' => ['text/csv', '/x/data.csv', ['type' => 'spreadsheet', 'format' => 'Csv'], 'mime wins'],

            // Browsers and wp_check_filetype frequently produce octet-stream for
            // Office files, so the extension fallback is load-bearing.
            'octet-stream docx' => [
                'application/octet-stream',
                '/x/report.docx',
                ['type' => 'word', 'format' => 'Word2007'],
                'unknown mime falls back to the extension map',
            ],
            'octet-stream pdf' => [
                'application/octet-stream',
                '/x/report.pdf',
                ['type' => 'pdf', 'format' => 'PDF'],
                'unknown mime falls back to the extension map',
            ],
            'uppercase extension' => [
                'application/octet-stream',
                '/x/REPORT.PDF',
                ['type' => 'pdf', 'format' => 'PDF'],
                'the extension lookup lowercases',
            ],

            'unknown both' => ['application/zip', '/x/archive.zip', null, 'no mime and no extension match'],
            'no extension' => ['application/zip', '/x/archive', null, 'no extension to fall back to'],
        ];
    }

    // ---------------------------------------------------------------- //
    // Real rasterization                                               //
    // ---------------------------------------------------------------- //

    public function testGeneratesAJpegThumbnailFromARealPdf(): void
    {
        $result = $this->thumbnailer()->generateThumbnail(
            FixturePath::to('sample.pdf'),
            'application/pdf',
            300,
            300
        );

        $this->assertIsString($result, 'PDF rasterization returned null; is the Ghostscript delegate available?');
        $this->assertFileExists($result);

        $info = getimagesize($result);
        $this->assertIsArray($info);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertLessThanOrEqual(300, $info[0], 'the thumbnail must respect the requested width');
        $this->assertGreaterThan(0, $info[1]);

        unlink($result);
    }

    /**
     * Both the mime AND the extension have to miss: the extension map is a
     * deliberate fallback for the octet-stream mime browsers often send, so a
     * .pdf file still renders even when the mime is wrong.
     */
    public function testUnsupportedMimeAndExtensionReturnsNull(): void
    {
        $this->assertNull(
            $this->thumbnailer()->generateThumbnail(FixturePath::to('small.jpg'), 'application/zip', 300, 300)
        );
    }

    public function testWrongMimeStillRendersWhenTheExtensionIsKnown(): void
    {
        $result = $this->thumbnailer()->generateThumbnail(
            FixturePath::to('sample.pdf'),
            'application/octet-stream',
            200,
            200
        );

        $this->assertIsString($result, 'the extension fallback should have rescued this');
        $this->assertFileExists($result);
        unlink($result);
    }

    public function testMissingFileReturnsNull(): void
    {
        $this->assertNull(
            $this->thumbnailer()->generateThumbnail('/nonexistent/nope.pdf', 'application/pdf', 300, 300)
        );
    }

    /**
     * Presentations used to be handed straight to Imagick as .pptx, which has no
     * decoder — so this never produced anything. They now go through the same
     * PhpPresentation → DomPDF → Imagick path Word and Excel use.
     *
     * @dataProvider presentations
     */
    public function testRendersPresentations(string $fixture, string $mime): void
    {
        $result = $this->thumbnailer()->generateThumbnail(FixturePath::to($fixture), $mime, 300, 300);

        $this->assertIsString($result, "{$fixture} produced no thumbnail");
        $this->assertFileExists($result);

        $info = getimagesize($result);
        $this->assertIsArray($info);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertLessThanOrEqual(300, $info[0]);
        $this->assertLessThanOrEqual(300, $info[1]);

        unlink($result);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function presentations(): array
    {
        return [
            'pptx' => [
                'sample.pptx',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ],
            'odp' => [
                'sample.odp',
                'application/vnd.oasis.opendocument.presentation',
            ],
        ];
    }

    /**
     * ImageMagick still cannot read Office XML directly — which is exactly why
     * the conversion step exists. If this ever changes the shortcut is worth
     * revisiting.
     */
    public function testImagickStillCannotDecodePresentationsDirectly(): void
    {
        $this->assertFalse(
            in_array('PPTX', Imagick::queryFormats('PPT*'), true),
            'ImageMagick gained a PPTX decoder; the PDF conversion may no longer be needed'
        );
    }

    // ---------------------------------------------------------------- //
    // Render once, scale many                                          //
    // ---------------------------------------------------------------- //

    public function testGenerateThumbnailsReturnsEveryRequestedSize(): void
    {
        $sizes = [
            'full'      => ['width' => 1500, 'height' => 1500],
            'thumbnail' => ['width' => 150, 'height' => 150],
            'medium'    => ['width' => 300, 'height' => 300],
            'large'     => ['width' => 1024, 'height' => 1024],
        ];

        $results = $this->thumbnailer()->generateThumbnails(
            FixturePath::to('sample.pdf'),
            'application/pdf',
            $sizes
        );

        $this->assertSame(array_keys($sizes), array_keys($results));

        foreach ($results as $name => $path) {
            $info = getimagesize($path);
            $this->assertIsArray($info, "size '{$name}' is not an image");
            $this->assertSame('image/jpeg', $info['mime']);
            $this->assertLessThanOrEqual($sizes[$name]['width'], $info[0], "size '{$name}' is too wide");
            unlink($path);
        }
    }

    /**
     * The point of the change: four sizes must cost one conversion, not four.
     * Counting renders rather than timing avoids a flaky wall-clock assertion.
     */
    public function testAllSizesComeFromASingleRender(): void
    {
        $thumbnailer = new class () extends DocumentThumbnailer {
            public int $renders = 0;

            protected function renderToPdf(string $filePath, array $formatInfo): ?array
            {
                $this->renders++;

                return parent::renderToPdf($filePath, $formatInfo);
            }
        };

        $results = $thumbnailer->generateThumbnails(
            FixturePath::to('sample.pptx'),
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            [
                'full'      => ['width' => 1500, 'height' => 1500],
                'thumbnail' => ['width' => 150, 'height' => 150],
                'medium'    => ['width' => 300, 'height' => 300],
                'large'     => ['width' => 1024, 'height' => 1024],
            ]
        );

        $this->assertCount(4, $results);
        $this->assertSame(1, $thumbnailer->renders, 'four sizes must share one document conversion');

        foreach ($results as $path) {
            unlink($path);
        }
    }

    public function testIntermediatePdfIsCleanedUp(): void
    {
        $before = glob(sys_get_temp_dir() . '/docthumb_*.pdf') ?: [];

        $results = $this->thumbnailer()->generateThumbnails(
            FixturePath::to('sample.pptx'),
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ['medium' => ['width' => 300, 'height' => 300]]
        );

        foreach ($results as $path) {
            unlink($path);
        }

        $after = glob(sys_get_temp_dir() . '/docthumb_*.pdf') ?: [];

        $this->assertSame($before, $after, 'the converted PDF must not be left behind');
    }

    /**
     * A PDF input is not a temporary file, so it must survive.
     */
    public function testPdfInputIsNotDeleted(): void
    {
        $source = FixturePath::to('sample.pdf');

        $results = $this->thumbnailer()->generateThumbnails($source, 'application/pdf', [
            'medium' => ['width' => 300, 'height' => 300],
        ]);

        foreach ($results as $path) {
            unlink($path);
        }

        $this->assertFileExists($source, 'the source PDF must never be deleted');
    }
}
