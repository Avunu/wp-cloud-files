<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Integration;

use Avunu\WPCloudFiles\CLI;
use Avunu\WPCloudFiles\Config;
use Avunu\WPCloudFiles\Tests\Support\WPTestCase;
use WP_CLI;
use WpCliHalt;

/**
 * `status`, `verify`, `inventory` and `migrate-urls`.
 *
 * @covers \Avunu\WPCloudFiles\CLI
 */
final class CliToolsTest extends WPTestCase
{
    private CLI $cli;

    /** @var list<string> */
    private array $created = [];

    public function set_up(): void
    {
        parent::set_up();

        WP_CLI::reset();
        $this->cli = new CLI();
    }

    public function tear_down(): void
    {
        foreach (array_reverse($this->created) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        $this->created = [];

        parent::tear_down();
    }

    /**
     * @return array<string, mixed>
     */
    private function lastJson(): array
    {
        $this->assertNotEmpty(WP_CLI::$lines, 'the command printed nothing');

        $data = json_decode((string) end(WP_CLI::$lines), true);
        $this->assertIsArray($data);

        return $data;
    }

    // ---------------------------------------------------------------- //
    // status                                                           //
    // ---------------------------------------------------------------- //

    public function testStatusReportsTheModeAndNeverTheCredentials(): void
    {
        $this->cli->status([], ['format' => 'json']);
        $data = $this->lastJson();

        $this->assertSame('full', $data['mode']);
        $this->assertTrue($data['bucket_reachable']);
        $this->assertSame(Config::string('S3_BUCKET'), $data['settings']['S3_BUCKET']['value']);
        $this->assertSame('(set)', $data['settings']['S3_SECRET']['value']);
        $this->assertStringNotContainsString(Config::string('S3_SECRET'), (string) end(WP_CLI::$lines));
        $this->assertContains('woocommerce_uploads/', $data['exclude_prefixes']);
        $this->assertContains($data['settings']['S3_KEY']['source'], ['constant', 'env']);
    }

    // ---------------------------------------------------------------- //
    // verify                                                           //
    // ---------------------------------------------------------------- //

    public function testVerifyPassesWhenEveryObjectIsThere(): void
    {
        $this->uploadFixture('large.jpg');

        $this->cli->verify([], ['all' => true, 'format' => 'json']);

        $data = $this->lastJson();
        $this->assertSame(1, $data['attachments']);
        $this->assertGreaterThan(1, $data['objects'], 'every size is checked, not just the original');
        $this->assertSame([], $data['missing']);
        $this->assertNotEmpty(WP_CLI::$messages['success']);
    }

    public function testVerifyFailsWhenASizeIsMissing(): void
    {
        $id = $this->uploadFixture('large.jpg');
        $meta = wp_get_attachment_metadata($id);
        $size = reset($meta['sizes']);
        $missing = dirname((string) $meta['file']) . '/' . $size['file'];

        \Avunu\WPCloudFiles\S3Client::getInstance()->deleteFile($missing);

        try {
            $this->cli->verify([], ['all' => true, 'format' => 'json']);
            $this->fail('verify must exit non-zero when an object is missing');
        } catch (WpCliHalt $e) {
            $this->assertStringContainsString('1 missing', $e->getMessage());
        }

        $this->assertSame([['attachment' => $id, 'key' => $missing]], $this->lastJson()['missing']);
    }

    public function testVerifyFlagsAPrivateFileFoundInTheBucket(): void
    {
        add_filter('upload_dir', [ExclusionTest::class, 'wooUploadDir']);
        try {
            $id = $this->uploadFixture('small.jpg');
        } finally {
            remove_filter('upload_dir', [ExclusionTest::class, 'wooUploadDir']);
        }

        $key = (string) get_post_meta($id, '_wp_attached_file', true);
        $this->putObject($key, 'leaked by an older version');

        try {
            $this->cli->verify([], ['all' => true, 'format' => 'json']);
            $this->fail('a private file in the bucket must fail verification');
        } catch (WpCliHalt $e) {
            $this->assertStringContainsString('1 private files in the bucket', $e->getMessage());
        }

        $this->assertSame([['attachment' => $id, 'key' => $key]], $this->lastJson()['leaked']);
    }

    // ---------------------------------------------------------------- //
    // inventory                                                        //
    // ---------------------------------------------------------------- //

    private function makeUploadsFile(string $relative): void
    {
        $basedir = wp_upload_dir()['basedir'];
        $dir = $basedir;

        foreach (explode('/', dirname($relative)) as $segment) {
            $dir .= '/' . $segment;
            if (!is_dir($dir)) {
                mkdir($dir);
                $this->created[] = $dir;
            }
        }

        file_put_contents($basedir . '/' . $relative, 'x');
        $this->created[] = $basedir . '/' . $relative;
    }

    public function testInventoryClassifiesTheUploadsDirectory(): void
    {
        $this->makeUploadsFile('cwicly/css/cc-post-1.css');
        $this->makeUploadsFile('wpcf-test-mystery/blob.bin');
        $this->makeUploadsFile('woocommerce_uploads/wpcf-test/album.zip');

        $this->cli->inventory([], ['json' => true]);
        $data = $this->lastJson();

        $classes = array_column($data['entries'], 'class', 'path');

        $this->assertSame('public-generated', $classes['cwicly/']);
        $this->assertSame('unknown', $classes['wpcf-test-mystery/']);
        $this->assertSame('private', $classes['woocommerce_uploads/']);
        $this->assertContains('cwicly/', $data['non_attachment_prefixes']);
        $this->assertNotContains(gmdate('Y') . '/', $data['non_attachment_prefixes']);
        $this->assertSame(wp_upload_dir()['basedir'], $data['basedir']);
    }

    // ---------------------------------------------------------------- //
    // migrate-urls                                                     //
    // ---------------------------------------------------------------- //

    public function testMigrateUrlsRunsAPlainAndAJsonEscapedPassThatSkipExclusions(): void
    {
        $this->cli->migrate_urls(['wptests_posts'], ['exclude-prefix' => 'cwicly/,fonts/', 'dry-run' => true]);

        $this->assertCount(2, WP_CLI::$runs);

        $base = preg_quote(trailingslashit(wp_upload_dir()['baseurl']), '#');

        foreach (WP_CLI::$runs as $run) {
            $this->assertSame('search-replace', $run['args'][0]);
            $this->assertSame('wptests_posts', $run['args'][3], 'tables are passed through');
            $this->assertTrue($run['assoc_args']['regex']);
            $this->assertSame('#', $run['assoc_args']['regex-delimiter']);
            $this->assertTrue($run['assoc_args']['dry-run']);
            $this->assertArrayNotHasKey('exclude-prefix', $run['assoc_args'], 'not a search-replace flag');
            $this->assertStringContainsString('fonts', $run['args'][1]);
            $this->assertStringContainsString('woocommerce_uploads', $run['args'][1], 'S3_EXCLUDE_PREFIXES are always excluded');
        }

        $this->assertStringStartsWith($base, WP_CLI::$runs[0]['args'][1]);
        $this->assertStringContainsString('\\\\/', WP_CLI::$runs[1]['args'][1], 'the second pass matches JSON-escaped URLs');
    }

    /**
     * The real database content the M2 exit criterion names: a wp_font_face
     * post and Cwicly/private URLs keep the local uploads URL, attachments move.
     * The patterns are applied exactly as `wp search-replace --regex` applies
     * them.
     */
    public function testMigrateUrlsPatternsAgainstStoredContent(): void
    {
        $base = trailingslashit(wp_upload_dir()['baseurl']);
        $json = static fn(string $url): string => str_replace('/', '\\/', $url);

        $fontFace = (string) wp_json_encode([
            'fontFamily' => 'Inter',
            'src'        => $base . 'fonts/inter/inter-400.woff2',
        ]);
        $post = "<img src=\"{$base}2026/08/a.jpg\"><link href=\"{$base}cwicly/css/cc-post-1.css\">"
            . "<a href=\"{$base}woocommerce_uploads/2026/08/album.zip\">";

        $this->cli->migrate_urls([], ['exclude-prefix' => 'cwicly/,fonts/', 'dry-run' => true]);

        foreach (WP_CLI::$runs as $run) {
            $pattern = '#' . $run['args'][1] . '#';
            $fontFace = (string) preg_replace($pattern, $run['args'][2], $fontFace);
            $post = (string) preg_replace($pattern, $run['args'][2], $post);
        }

        $media = \Avunu\WPCloudFiles\S3Client::getInstance()->getPublicUrl('');

        $this->assertStringContainsString($json($base . 'fonts/inter/inter-400.woff2'), $fontFace);
        $this->assertStringContainsString("{$media}2026/08/a.jpg", $post);
        $this->assertStringContainsString("{$base}cwicly/css/cc-post-1.css", $post);
        $this->assertStringContainsString("{$base}woocommerce_uploads/2026/08/album.zip", $post);
    }
}
