<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Unit;

use Avunu\WPCloudFiles\ExecutableGuard;
use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;
use Brain\Monkey\Functions;

/**
 * @covers \Avunu\WPCloudFiles\ExecutableGuard
 */
final class ExecutableGuardTest extends UnitTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tear_down(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];

        parent::tear_down();
    }

    private function tempFile(string $contents): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'wpcf-guard');
        file_put_contents($file, $contents);
        $this->files[] = $file;

        return $file;
    }

    /**
     * @dataProvider executableNames
     */
    public function testExecutableNamesAreRefused(string $name): void
    {
        $this->assertTrue(ExecutableGuard::hasExecutableExtension($name));
        $this->assertTrue(ExecutableGuard::isExecutable($name));
    }

    /** @return array<string, array{string}> */
    public static function executableNames(): array
    {
        return [
            'php'                  => ['shell.php'],
            'upper case'           => ['SHELL.PHP'],
            'php variant'          => ['shell.phtml'],
            'phar'                 => ['archive.phar'],
            'handler in the middle' => ['shell.php.jpg'],
            'cgi in the middle'    => ['run.cgi.png'],
            'shell script'         => ['install.sh'],
            'windows program'      => ['setup.exe'],
            'htaccess'             => ['.htaccess'],
            'user ini'             => ['.user.ini'],
            'with a directory'     => ['2026/08/shell.php'],
        ];
    }

    /**
     * @dataProvider ordinaryNames
     */
    public function testOrdinaryNamesPass(string $name): void
    {
        $this->assertFalse(ExecutableGuard::hasExecutableExtension($name));
    }

    /** @return array<string, array{string}> */
    public static function ordinaryNames(): array
    {
        return [
            'image'                      => ['photo.jpg'],
            'document'                   => ['report.pdf'],
            'no extension'               => ['README'],
            'script-like word mid-name'  => ['brochure.pl.pdf'],
            'domain in the name'         => ['logo.example.com.png'],
            'php in the base name'       => ['php-logo.png'],
        ];
    }

    public function testExecutableMimeTypesAreRefused(): void
    {
        $this->assertTrue(ExecutableGuard::isExecutableMime('application/x-httpd-php'));
        $this->assertTrue(ExecutableGuard::isExecutableMime('text/x-php; charset=us-ascii'));
        $this->assertTrue(ExecutableGuard::isExecutableMime('application/x-msdownload'));
        $this->assertFalse(ExecutableGuard::isExecutableMime('image/jpeg'));
        $this->assertFalse(ExecutableGuard::isExecutableMime(null));
        $this->assertTrue(ExecutableGuard::isExecutable('photo.jpg', 'application/x-php'));
    }

    /**
     * A script renamed to an image extension is caught by its bytes.
     */
    public function testSniffedPhpIsRefusedWhateverTheName(): void
    {
        if (!function_exists('finfo_open')) {
            $this->markTestSkipped('fileinfo is not available');
        }

        $file = $this->tempFile("<?php\nsystem(\$_GET['c']);\n");

        $this->assertTrue(ExecutableGuard::isExecutable('photo.jpg', 'image/jpeg', $file));
    }

    public function testARealImageIsNotSniffedAsExecutable(): void
    {
        $file = dirname(__DIR__) . '/fixtures/small.jpg';

        $this->assertFalse(ExecutableGuard::isExecutable('small.jpg', 'image/jpeg', $file));
    }

    public function testTheUploadPrefilterSetsAnError(): void
    {
        Functions\when('__')->returnArg();

        $refused = ExecutableGuard::filterUpload([
            'name'     => 'shell.php',
            'type'     => 'application/x-php',
            'tmp_name' => $this->tempFile('<?php echo 1;'),
            'error'    => 0,
            'size'     => 13,
        ]);
        $this->assertNotEmpty($refused['error']);

        $allowed = ExecutableGuard::filterUpload([
            'name'     => 'small.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => dirname(__DIR__) . '/fixtures/small.jpg',
            'error'    => 0,
            'size'     => 100,
        ]);
        $this->assertSame(0, $allowed['error']);
    }
}
