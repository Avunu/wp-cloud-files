<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Unit;

use Avunu\WPCloudFiles\Plugin;
use Avunu\WPCloudFiles\Tests\Support\Profile;
use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;

/**
 * @covers \Avunu\WPCloudFiles\Plugin::filterUploadSizeLimit
 */
final class PluginUploadSizeLimitTest extends UnitTestCase
{
    private const DEFAULT_CEILING = 5 * 1024 * 1024 * 1024;
    private const MAXSIZE_CEILING = 10 * 1024 * 1024 * 1024;

    private Plugin $plugin;

    protected function set_up(): void
    {
        parent::set_up();
        $this->plugin = new Plugin();
    }

    private function ceiling(): int
    {
        return Profile::is(Profile::MAXSIZE) ? self::MAXSIZE_CEILING : self::DEFAULT_CEILING;
    }

    public function testRaisesASmallPhpLimitToTheS3Ceiling(): void
    {
        $this->assertSame($this->ceiling(), $this->plugin->filterUploadSizeLimit(2 * 1024 * 1024));
    }

    public function testNeverLowersAnAlreadyHigherLimit(): void
    {
        $higher = $this->ceiling() * 2;

        $this->assertSame($higher, $this->plugin->filterUploadSizeLimit($higher));
    }

    /**
     * WordPress passes this filter the result of wp_max_upload_size(), which
     * several plugins coerce to a numeric string. The int cast must hold.
     */
    public function testNumericStringInputIsCoercedToInt(): void
    {
        $result = $this->plugin->filterUploadSizeLimit('2097152');

        $this->assertIsInt($result);
        $this->assertSame($this->ceiling(), $result);
    }

    public function testZeroAndNegativeInputStillYieldTheCeiling(): void
    {
        $this->assertSame($this->ceiling(), $this->plugin->filterUploadSizeLimit(0));
        $this->assertSame($this->ceiling(), $this->plugin->filterUploadSizeLimit(-1));
    }
}
