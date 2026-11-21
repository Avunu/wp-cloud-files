<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Unit;

use Avunu\WPCloudFiles\DocumentThumbnailer;
use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;
use Brain\Monkey\Functions;
use Composer\InstalledVersions;

/**
 * Where the thumbnailer finds DomPDF and writes its temporary files.
 *
 * @covers \Avunu\WPCloudFiles\DocumentThumbnailer::domPdfPath
 * @covers \Avunu\WPCloudFiles\DocumentThumbnailer::tempDir
 */
final class DocumentThumbnailerPathsTest extends UnitTestCase
{
    /**
     * Composer knows where dompdf is, whether that is the plugin's own vendor/
     * (the release zip) or a Bedrock project's vendor/.
     */
    public function testDomPdfIsResolvedThroughComposer(): void
    {
        $path = DocumentThumbnailer::domPdfPath();

        $this->assertNotNull($path);
        $this->assertDirectoryExists($path);
        $this->assertFileExists($path . '/src/Dompdf.php');
        $this->assertSame(
            realpath((string) InstalledVersions::getInstallPath('dompdf/dompdf')),
            $path
        );
    }

    public function testTempFilesGoWhereWordPressSays(): void
    {
        Functions\when('get_temp_dir')->justReturn('/srv/tmp/wp/');

        $this->assertSame('/srv/tmp/wp', DocumentThumbnailer::tempDir());
    }
}
