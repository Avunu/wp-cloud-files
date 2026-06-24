<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles;

use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\Settings as WordSettings;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpPresentation\Writer\PowerPoint2007;

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
        $domPdfPath = WP_PLUGIN_DIR . '/wp-cloud-files/vendor/dompdf/dompdf';
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
        $formatInfo = $this->getFormatInfo($mimeType, $filePath);
        if (!$formatInfo) {
            $this->log("Unsupported file type. MIME: {$mimeType}");
            return null;
        }

        try {
            switch ($formatInfo['type']) {
                case 'word':
                    return $this->processToPdf(
                        $filePath,
                        $formatInfo['format'],
                        fn($fmt) => WordIOFactory::createReader($fmt),
                        fn($doc, $type) => WordIOFactory::createWriter($doc, $type),
                        'PDF',
                        $width,
                        $height
                    );

                case 'spreadsheet':
                    return $this->processToPdf(
                        $filePath,
                        $formatInfo['format'],
                        fn($fmt) => SpreadsheetIOFactory::createReader($fmt),
                        fn($doc, $type) => SpreadsheetIOFactory::createWriter($doc, $type),
                        'Mpdf',
                        $width,
                        $height
                    );

                case 'presentation':
                    return $this->processPresentation($filePath, $formatInfo['format'], $width, $height);

                case 'pdf':
                    return $this->generateThumbnailFromPdf($filePath, $width, $height);

                default:
                    $this->log("Unknown document type: {$formatInfo['type']}");
                    return null;
            }
        } catch (\Exception $e) {
            $this->log("Error processing document: {$e->getMessage()}");
            return null;
        }
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
     * Generic helper that processes a document by converting it to PDF and generating a thumbnail.
     */
    private function processToPdf(
        string $filePath,
        string $format,
        callable $readerCreator,
        callable $writerCreator,
        string $writerType,
        int $width,
        int $height
    ): ?string {
        try {
            $reader = $readerCreator($format);
            $document = $reader->load($filePath);
            $pdfWriter = $writerCreator($document, $writerType);
            $pdfPath = $this->getTemporaryPath();
            $pdfWriter->save($pdfPath);
            $thumbnailPath = $this->generateThumbnailFromPdf($pdfPath, $width, $height);
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }
            return $thumbnailPath;
        } catch (\Exception $e) {
            $this->log("Error processing document to PDF: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Process presentation files.
     */
    protected function processPresentation(string $filePath, string $format, int $width, int $height): ?string
    {
        try {
            $reader = PresentationIOFactory::createReader($format);
            $presentation = $reader->load($filePath);
            $slide = $presentation->getSlide(0);
            if (!$slide) {
                return null;
            }
            $tempPptx = sys_get_temp_dir() . '/' . uniqid('presentation_', true) . '.pptx';
            $writer = new PowerPoint2007($presentation);
            $writer->save($tempPptx);

            try {
                $imagick = new \Imagick();
                $imagick->setResolution(300, 300);
                $imagick->readImage($tempPptx . '[0]');
                $imagick->setImageFormat('jpg');
                $imagick->thumbnailImage($width, $height, true, true);
                $thumbnailPath = sys_get_temp_dir() . '/' . uniqid('thumbnail_', true) . '.jpg';
                $imagick->writeImage($thumbnailPath);
                $imagick->clear();
                unlink($tempPptx);
                return $thumbnailPath;
            } catch (\ImagickException $e) {
                $this->log("Imagick error processing presentation: {$e->getMessage()}");
                unlink($tempPptx);
                return null;
            }
        } catch (\Exception $e) {
            $this->log("Error processing presentation: {$e->getMessage()}");
            return null;
        }
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
        try {
            $imagick = new \Imagick();
            $imagick->setResolution(300, 300);
            $imagick->readImage($pdfPath . '[0]');
            
            // Set compression quality
            $imagick->setCompressionQuality(80);
            
            // Scale image (do this before format conversion for better quality)
            $imagick->scaleImage($width, $height, true);
            
            // Set format and background
            $imagick->setImageFormat('jpg');
            $imagick->setImageBackgroundColor('white');
            
            // Remove alpha channel - this fixes inverted color issues
            if (method_exists($imagick, 'setImageAlphaChannel')) {
                if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                    $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                } else {
                    // Fallback constant value for ALPHACHANNEL_REMOVE
                    $imagick->setImageAlphaChannel(11);
                }
            }
            
            // Flatten layers - crucial for PDFs with transparency
            if (method_exists($imagick, 'mergeImageLayers')) {
                $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            } else {
                $imagick = $imagick->flattenImages();
            }
            
            // Strip metadata
            $imagick->stripImage();
            
            $thumbnailPath = sys_get_temp_dir() . '/' . uniqid('thumbnail_', true) . '.jpg';
            $imagick->writeImage($thumbnailPath);
            $imagick->clear();
            return $thumbnailPath;
        } catch (\ImagickException $e) {
            $this->log("Imagick error: {$e->getMessage()}");
            return null;
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
