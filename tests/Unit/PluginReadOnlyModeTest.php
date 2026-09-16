<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Unit;

use Avunu\WPCloudFiles\Plugin;
use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Read-only mode: the URL rewriters and nothing that writes to the bucket.
 *
 * The unit bootstrap defines every S3 constant, so configuredMode() can only
 * be observed in its full-mode branch here; the read-only branch is exercised
 * through boot() directly.
 *
 * @covers \Avunu\WPCloudFiles\Plugin::boot
 * @covers \Avunu\WPCloudFiles\Plugin::configuredMode
 * @covers \Avunu\WPCloudFiles\Plugin::readOnlyNotice
 */
final class PluginReadOnlyModeTest extends UnitTestCase
{
    public function testFullCredentialsMeanFullMode(): void
    {
        Functions\when('wp_get_environment_type')->justReturn('development');
        $this->assertSame(Plugin::MODE_FULL, Plugin::configuredMode());
    }

    public function testReadOnlyBootHooksOnlyTheUrlRewriters(): void
    {
        Plugin::boot(Plugin::MODE_READ_ONLY);

        $this->assertTrue(Filters\has('wp_get_attachment_url'));
        $this->assertTrue(Filters\has('wp_calculate_image_srcset'));
        $this->assertTrue(Actions\has('admin_notices'));

        $this->assertFalse(Filters\has('wp_update_attachment_metadata'), 'nothing is stored to the bucket');
        $this->assertFalse(Filters\has('wp_generate_attachment_metadata'));
        $this->assertFalse(Filters\has('wp_prepare_attachment_for_js'));
        $this->assertFalse(Actions\has('delete_attachment'), 'nothing is deleted from the bucket');
        $this->assertFalse(Actions\has('wpcf_process_direct_upload'));
        $this->assertFalse(Actions\has('rest_api_init'));
    }

    public function testFullBootHooksTheWriters(): void
    {
        Plugin::boot();

        $this->assertTrue(Filters\has('wp_get_attachment_url'));
        $this->assertTrue(Filters\has('wp_update_attachment_metadata'));
        $this->assertTrue(Actions\has('delete_attachment'));
        $this->assertFalse(Actions\has('admin_notices'));
    }

    public function testTheNoticeIsForAdministratorsOnly(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        ob_start();
        (new Plugin())->readOnlyNotice();
        $this->assertSame('', ob_get_clean());

        Functions\when('current_user_can')->justReturn(true);
        Functions\when('esc_html__')->returnArg();
        ob_start();
        (new Plugin())->readOnlyNotice();
        $this->assertStringContainsString('read-only in this environment', (string) ob_get_clean());
    }
}
