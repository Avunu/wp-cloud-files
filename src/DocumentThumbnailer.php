<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles;

use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Writer\PDF\DomPDF as PresentationPdfWriter;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\Settings as WordSettings;

class DocumentThumbnailer
{
    protected array $documentFormats = [
        // Word documents
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['type' => 'word', 'format' => 'Word2007'],
        'application/msword' => ['type' => 'word', 'format' => 'MsDoc'],
        'application/vnd.oasis.opendocument.text' => ['type' => 'word', 'format' => 'ODText'],
        'application/rtf' => ['type' => 'word', 'format' => 'RTF'],
        'text/rtf' => ['type' => 'word', 'format' => 'RTF'],

        // Spreadsheet files
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['type' => 'spreadsheet', 'format' => 'Xlsx'],
        'application/vnd.ms-excel' => ['type' => 'spreadsheet', 'format' => 'Xls'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['type' => 'spreadsheet', 'format' => 'Ods'],
        'text/csv' => ['type' => 'spreadsheet', 'format' => 'Csv'],

        // Presentation files
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['type' => 'presentation', 'format' => 'PowerPoint2007'],
        'application/vnd.ms-powerpoint' => ['type' => 'presentation', 'format' => 'PowerPoint97'],
        'application/vnd.oasis.opendocument.presentation' => ['type' => 'presentation', 'format' => 'ODPresentation'],

        // PDF files
        'application/pdf' => ['type' => 'pdf', 'format' => 'PDF']
    ];

    protected array $extensionMap = [
        // Word documents
        'docx' => ['type' => 'word', 'format' => 'Word2007'],
        'doc'  => ['type' => 'word', 'format' => 'MsDoc'],
        'odt'  => ['type' => 'word', 'format' => 'ODText'],
        'rtf'  => ['type' => 'word', 'format' => 'RTF'],

        // Spreadsheet files
        'xlsx' => ['type' => 'spreadsheet', 'format' => 'Xlsx'],
        'xls'  => ['type' => 'spreadsheet', 'format' => 'Xls'],
        'ods'  => ['type' => 'spreadsheet', 'format' => 'Ods'],
        'csv'  => ['type' => 'spreadsheet', 'format' => 'Csv'],

        // Presentation files
        'pptx' => ['type' => 'presentation', 'format' => 'PowerPoint2007'],
        'ppt'  => ['type' => 'presentation', 'format' => 'PowerPoint97'],
        'odp'  => ['type' => 'presentation', 'format' => 'ODPresentation'],

        // PDF files
        'pdf'  => ['type' => 'pdf', 'format' => 'PDF']
    ];

    public function __construct()
    {
        $this->setupDomPdf();
    }

    private function setupDomPdf(): void
    {
        // Resolve relative to this file, not to WP_PLUGIN_DIR plus a hardcoded
        // directory name: installing the plugin under any other folder name (or
        // symlinking it, as the test harness and most dev setups do) left DomPDF
        // silently unconfigured, and every Word/Excel thumbnail then failed with
        // nothing but a WP_DEBUG log line to show for it.
        $domPdfPath = dirname(__DIR__) . '/vendor/dompdf/dompdf';
        if (file_exists($domPdfPath)) {
            WordSettings::setPdfRendererPath($domPdfPath);
            WordSettings::setPdfRendererName(WordSettings::PDF_RENDERER_DOMPDF);
        } else {
            $this->log("DomPDF path not found at {$domPdfPath}");
        }
    }

    private function log(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("DocumentThumbnailer: {$message}");
        }
    }

    /**
     * Generate a thumbnail image from a document file.
     *
     * @param string $filePath File system path to the document
     * @param string $mimeType MIME type of the document
     * @param int    $width    Desired thumbnail width
     * @param int    $height   Desired thumbnail height
     *
     * @return string|null Path to the thumbnail image or null on failure
     */
    public function generateThumbnail(string $filePath, string $mimeType, int $width, int $height): ?string
    {
        $thumbnails = $this->generateThumbnails($filePath, $mimeType, [
            'default' => ['width' => $width, 'height' => $height],
        ]);

        return $thumbnails['default'] ?? null;
    }

    /**
     * Render a document once and produce every requested size from that render.
     *
     * Callers want four sizes per document. Doing that through generateThumbnail()
     * meant loading the document, converting it to PDF and running the Ghostscript
     * rasterizer four separate times; the conversion is by far the expensive part
     * and its result is identical every time.
     *
     * @param array<string, array{width: int, height: int}> $sizes
     * @return array<string, string> size name => path to a temporary JPEG
     */
    public function generateThumbnails(string $filePath, string $mimeType, array $sizes): array
    {
        if ($sizes === []) {
            return [];
        }

        $formatInfo = $this->getFormatInfo($mimeType, $filePath);
        if (!$formatInfo) {
            $this->log("Unsupported file type. MIME: {$mimeType}");
            return [];
        }

        $pdf = $this->renderToPdf($filePath, $formatInfo);
        if ($pdf === null) {
            return [];
        }

        try {
            return $this->rasterizePdf($pdf['path'], $sizes);
        } finally {
            if ($pdf['temporary'] && file_exists($pdf['path'])) {
                unlink($pdf['path']);
            }
        }
    }

    /**
     * Convert a document to a PDF that can be rasterized.
     *
     * @param array{type: string, format: string} $formatInfo
     * @return array{path: string, temporary: bool}|null `temporary` is false when
     *         the input was already a PDF, so the caller must not delete it.
     */
    protected function renderToPdf(string $filePath, array $formatInfo): ?array
    {
        if ($formatInfo['type'] === 'pdf') {
            return ['path' => $filePath, 'temporary' => false];
        }

        try {
            switch ($formatInfo['type']) {
                case 'word':
                    $document = WordIOFactory::createReader($formatInfo['format'])->load($filePath);
                    $writer = WordIOFactory::createWriter($document, 'PDF');
                    break;

                case 'spreadsheet':
                    $document = SpreadsheetIOFactory::createReader($formatInfo['format'])->load($filePath);
                    $writer = SpreadsheetIOFactory::createWriter($document, 'Mpdf');
                    break;

                case 'presentation':
                    // PhpPresentation's PDF writer is its HTML writer driven
                    // through DomPDF, which is already a dependency. Slide 1
                    // becomes page 1, which is what the rasterizer reads.
                    $document = PresentationIOFactory::createReader($formatInfo['format'])->load($filePath);
                    $writer = new PresentationPdfWriter($this->trimToFirstSlide($document));
                    break;

                default:
                    $this->log("Unknown document type: {$formatInfo['type']}");
                    return null;
            }

            $pdfPath = $this->getTemporaryPath();
            $writer->save($pdfPath);

            if (!file_exists($pdfPath)) {
                $this->log('Converter produced no PDF');
                return null;
            }

            return ['path' => $pdfPath, 'temporary' => true];
        } catch (\Throwable $e) {
            $this->log("Error processing document to PDF: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Drop every slide but the first.
     *
     * The HTML writer renders the whole deck and base64-inlines every image
     * before DomPDF runs, yet only page 1 is ever rasterized. On a large deck
     * that is a great deal of wasted memory and time.
     */
    private function trimToFirstSlide(PhpPresentation $presentation): PhpPresentation
    {
        while (count($presentation->getAllSlides()) > 1) {
            $presentation->removeSlideByIndex(1);
        }

        return $presentation;
    }

    /**
     * Retrieve document format info based on MIME type or file extension.
     */
    protected function getFormatInfo(string $mimeType, string $filePath): ?array
    {
        if (isset($this->documentFormats[$mimeType])) {
            return $this->documentFormats[$mimeType];
        }
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (isset($this->extensionMap[$extension])) {
            $this->log("Using extension fallback for unrecognized MIME type: {$mimeType}, extension: {$extension}");
            return $this->extensionMap[$extension];
        }
        return null;
    }

    /**
     * Generate a temporary PDF file path.
     */
    protected function getTemporaryPath(): string
    {
        return sys_get_temp_dir() . '/' . uniqid('docthumb_', true) . '.pdf';
    }

    /**
     * Generate a thumbnail image from a PDF file.
     */
    protected function generateThumbnailFromPdf(string $pdfPath, int $width, int $height): ?string
    {
        $thumbnails = $this->rasterizePdf($pdfPath, [
            'default' => ['width' => $width, 'height' => $height],
        ]);

        return $thumbnails['default'] ?? null;
    }

    /**
     * Rasterize page 1 of a PDF into one JPEG per requested size.
     *
     * The page is read and normalised exactly once — reading it forks the
     * Ghostscript delegate at 300 DPI, which dominates the cost — and each size
     * is then a cheap clone-and-scale of that master.
     *
     * @param array<string, array{width: int, height: int}> $sizes
     * @return array<string, string> size name => path to a temporary JPEG
     */
    protected function rasterizePdf(string $pdfPath, array $sizes): array
    {
        $master = null;
        $thumbnails = [];

        try {
            $master = new \Imagick();
            $master->setResolution(300, 300);
            $master->readImage($pdfPath . '[0]');

            $master->setImageFormat('jpg');
            $master->setImageBackgroundColor('white');
            $master->setCompressionQuality(80);

            // Remove alpha, then flatten. Done once on the master rather than
            // per size; PDFs with transparency come out with inverted colours
            // otherwise.
            $master->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);

            $flattened = $master->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            // mergeImageLayers returns a NEW handle; the original has to be
            // released explicitly or its full-resolution buffer leaks.
            $master->clear();
            $master = $flattened;

            $master->stripImage();

            foreach ($sizes as $name => $dimensions) {
                $frame = clone $master;

                try {
                    // Bestfit, so the aspect ratio is preserved and the result
                    // never exceeds the requested box.
                    $frame->scaleImage($dimensions['width'], $dimensions['height'], true);

                    $path = sys_get_temp_dir() . '/' . uniqid('thumbnail_', true) . '.jpg';
                    $frame->writeImage($path);
                    $thumbnails[$name] = $path;
                } finally {
                    $frame->clear();
                }
            }

            return $thumbnails;
        } catch (\ImagickException $e) {
            $this->log("Imagick error: {$e->getMessage()}");

            // Do not leave half a set of sizes behind for the caller to reason about.
            foreach ($thumbnails as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }

            return [];
        } finally {
            if ($master instanceof \Imagick) {
                $master->clear();
            }
        }
    }

    /**
     * Generate a thumbnail for a WordPress attachment.
     */
    public function generateThumbnailForAttachment(int $attachmentId, int $width, int $height): ?string
    {
        $filePath = get_attached_file($attachmentId);
        if (empty($filePath) || !file_exists($filePath)) {
            $this->log("File not found for attachment {$attachmentId}");
            return null;
        }
        $mimeType = get_post_mime_type($attachmentId);
        if (empty($mimeType)) {
            $this->log("MIME type not found for attachment {$attachmentId}");
            return null;
        }
        return $this->generateThumbnail($filePath, $mimeType, $width, $height);
    }
}
