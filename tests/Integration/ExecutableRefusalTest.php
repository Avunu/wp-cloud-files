<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Integration;

use Avunu\WPCloudFiles\S3Client;
use Avunu\WPCloudFiles\Tests\Support\WPTestCase;
use WP_REST_Request;

/**
 * Executables are refused on every path into uploads and the bucket, even on
 * a site whose `upload_mimes` filter allows them.
 *
 * @covers \Avunu\WPCloudFiles\ExecutableGuard
 * @covers \Avunu\WPCloudFiles\S3Client::uploadFile
 * @covers \Avunu\WPCloudFiles\DirectUpload\RestController::presign
 */
final class ExecutableRefusalTest extends WPTestCase
{
    /** @var list<string> */
    private array $files = [];

    public function set_up(): void
    {
        parent::set_up();

        // The misconfiguration this guards against.
        add_filter('upload_mimes', static function (array $mimes): array {
            $mimes['php'] = 'text/x-php';
            $mimes['phtml'] = 'text/x-php';
            return $mimes;
        });
    }

    public function tear_down(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];

        parent::tear_down();
    }

    private function tempFile(string $contents): string
    {
        $file = (string) wp_tempnam('wpcf-exec');
        file_put_contents($file, $contents);
        $this->files[] = $file;

        return $file;
    }

    public function testASideloadedPhpFileIsRefused(): void
    {
        $tmp = $this->tempFile("<?php echo 'pwned';\n");

        $file = ['name' => 'shell.php', 'type' => 'text/x-php', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)];
        $result = wp_handle_sideload($file, ['test_form' => false]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('executable', (string) $result['error']);
        $this->assertFileDoesNotExist(trailingslashit(wp_upload_dir()['path']) . 'shell.php');
    }

    public function testAScriptDisguisedAsAnImageIsRefused(): void
    {
        $tmp = $this->tempFile("<?php system(\$_GET['c']);\n");

        $file = ['name' => 'avatar.php.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)];
        $result = wp_handle_sideload($file, ['test_form' => false]);

        $this->assertArrayHasKey('error', $result);
    }

    public function testAPresignIsNeverIssuedForAnExecutable(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $request = new WP_REST_Request('POST', '/wp-cloud-files/v1/presign');
        $request->set_param('filename', 'shell.php');
        $response = rest_do_request($request);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('wpcf_executable', $response->get_data()['code']);
    }

    /**
     * The last line of defence covers the CLI too: migrate and
     * regenerate-thumbnails both upload through S3Client::uploadFile().
     */
    public function testTheClientRefusesToStoreAnExecutable(): void
    {
        $client = S3Client::getInstance();

        $this->assertFalse($client->uploadFile($this->tempFile('<?php echo 1;'), '2026/08/shell.php'));
        $this->assertFalse(
            $client->uploadFile($this->tempFile("<?php\necho 1;\n"), '2026/08/notes.txt'),
            'PHP code is refused by its content, whatever the extension'
        );
        $this->assertSame([], $this->listObjects());
    }

    public function testOrdinaryFilesStillUpload(): void
    {
        $this->assertTrue(S3Client::getInstance()->uploadFile($this->tempFile('plain text'), '2026/08/notes.txt'));
        $this->assertTrue($this->objectExists('2026/08/notes.txt'));
    }
}
