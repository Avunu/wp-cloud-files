<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Integration;

use Avunu\WPCloudFiles\Config;
use Avunu\WPCloudFiles\DocumentThumbnailer;
use Avunu\WPCloudFiles\Plugin;
use Avunu\WPCloudFiles\Tests\Support\Profile;
use Avunu\WPCloudFiles\Tests\Support\WPTestCase;
use PhpOffice\PhpWord\Settings as WordSettings;

/**
 * The plugin installed by Composer into a Bedrock project.
 *
 * The bedrock profile runs the WHOLE integration suite in that layout: content
 * dir "app", the plugin directory without a vendor/ of its own, every
 * dependency from the project autoloader, DISALLOW_FILE_MODS, and all S3_*
 * settings from the environment with no constants at all. The tests here pin
 * down that the profile really is that shape, so the rest of the suite passing
 * under it means something.
 *
 * @covers \Avunu\WPCloudFiles\Config
 * @covers \Avunu\WPCloudFiles\DocumentThumbnailer::domPdfPath
 */
final class BedrockLayoutTest extends WPTestCase
{
    private const UPDATE_CHECK_HOOK = 'puc_cron_check_updates-wp-cloud-files';

    private function pluginDir(): string
    {
        return WP_PLUGIN_DIR . '/wp-cloud-files';
    }

    public function testTheBedrockFixtureHasTheBedrockShape(): void
    {
        if (!Profile::is(Profile::BEDROCK)) {
            $this->markTestSkipped('only meaningful under the bedrock profile');
        }

        $this->assertSame('app', basename(WP_CONTENT_DIR));
        $this->assertStringEndsWith('/app/uploads', wp_upload_dir()['baseurl']);
        $this->assertFileExists($this->pluginDir() . '/index.php');
        $this->assertFileDoesNotExist($this->pluginDir() . '/vendor', 'Composer installs dependencies into the project, not the plugin');
        $this->assertTrue(defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS);
    }

    public function testPluginCodeRunsFromThePluginDirectory(): void
    {
        if (!Profile::is(Profile::BEDROCK)) {
            $this->markTestSkipped('only meaningful under the bedrock profile');
        }

        $file = (string) (new \ReflectionClass(Plugin::class))->getFileName();

        $this->assertStringStartsWith(
            (string) realpath($this->pluginDir()),
            (string) realpath($file),
            'the project autoloader maps the plugin namespace into the installed copy'
        );
    }

    public function testConfigurationComesFromTheEnvironmentAlone(): void
    {
        if (!Profile::is(Profile::BEDROCK)) {
            $this->markTestSkipped('only meaningful under the bedrock profile');
        }

        foreach (['S3_KEY', 'S3_SECRET', 'S3_BUCKET', 'S3_ENDPOINT', 'S3_PUBLIC_URL', 'S3_DIRECT_UPLOADS'] as $name) {
            $this->assertFalse(defined($name), "{$name} must not be a constant in this profile");
            $this->assertSame('env', Config::source($name), "{$name} must come from the environment");
        }

        $this->assertSame(Plugin::MODE_FULL, Plugin::configuredMode());
    }

    /**
     * Composer updates the plugin, and DISALLOW_FILE_MODS forbids installing
     * anything, so the GitHub update checker is never loaded here. In the
     * classic layout (the plugin's own vendor/, file mods allowed) it is.
     */
    public function testTheUpdateCheckerOnlyLoadsForTheReleaseZip(): void
    {
        if (Profile::is(Profile::BEDROCK)) {
            $this->assertFalse(has_action(self::UPDATE_CHECK_HOOK), 'no update checker under Composer');
            return;
        }

        $this->assertNotFalse(has_action(self::UPDATE_CHECK_HOOK), 'the release-zip layout keeps self-updates');
    }

    /**
     * Word thumbnails need DomPDF's path. In this layout a path relative to
     * the plugin (plugins/wp-cloud-files/vendor/dompdf) does not exist; the
     * project's vendor/ does.
     */
    public function testDomPdfIsFoundInTheProjectVendorDirectory(): void
    {
        $path = DocumentThumbnailer::domPdfPath();

        $this->assertNotNull($path, 'DomPDF must be found in every layout');
        $this->assertFileExists($path . '/src/Dompdf.php');

        new DocumentThumbnailer();
        $this->assertSame($path, WordSettings::getPdfRendererPath());

        if (Profile::is(Profile::BEDROCK)) {
            $this->assertStringStartsNotWith((string) realpath($this->pluginDir()), $path);
        }
    }

    /**
     * The end-to-end proof that the layout works: an upload lands in the
     * bucket and its URL is rewritten, under whichever profile is running.
     */
    public function testAnUploadIsOffloadedAndRewritten(): void
    {
        $id = $this->uploadFixture('small.jpg');
        $key = (string) get_post_meta($id, '_wp_attached_file', true);

        $this->assertTrue($this->objectExists($key));
        $this->assertStringStartsWith(
            rtrim(Config::string('S3_PUBLIC_URL'), '/'),
            (string) wp_get_attachment_url($id)
        );
    }
}
