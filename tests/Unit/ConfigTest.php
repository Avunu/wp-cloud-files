<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Unit;

use Avunu\WPCloudFiles\Config;
use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;

/**
 * Constant first, environment second.
 *
 * The unit bootstrap defines the core S3_* constants, so the environment path
 * is exercised with names it leaves undefined.
 *
 * @covers \Avunu\WPCloudFiles\Config
 */
final class ConfigTest extends UnitTestCase
{
    /** @var list<string> */
    private array $env = [];

    private function setEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $this->env[] = $name;
    }

    protected function tear_down(): void
    {
        foreach ($this->env as $name) {
            putenv($name);
        }
        $this->env = [];

        parent::tear_down();
    }

    public function testAConstantWinsOverTheEnvironment(): void
    {
        $this->setEnv('S3_BUCKET', 'from-env');

        $this->assertSame('wpcf-unit', Config::get('S3_BUCKET'));
        $this->assertSame('constant', Config::source('S3_BUCKET'));
    }

    public function testTheEnvironmentIsReadWhenNoConstantIsDefined(): void
    {
        $this->setEnv('S3_PRESIGN_EXPIRES', '+5 minutes');

        $this->assertSame('+5 minutes', Config::get('S3_PRESIGN_EXPIRES'));
        $this->assertSame('+5 minutes', Config::string('S3_PRESIGN_EXPIRES', '+15 minutes'));
        $this->assertSame('env', Config::source('S3_PRESIGN_EXPIRES'));
        $this->assertTrue(Config::has('S3_PRESIGN_EXPIRES'));
    }

    public function testAnEmptyEnvironmentVariableCountsAsUnset(): void
    {
        $this->setEnv('S3_UPLOAD_ACL', '');

        $this->assertNull(Config::get('S3_UPLOAD_ACL'));
        $this->assertFalse(Config::has('S3_UPLOAD_ACL'));
        $this->assertNull(Config::source('S3_UPLOAD_ACL'));
        $this->assertSame('fallback', Config::string('S3_UPLOAD_ACL', 'fallback'));
    }

    public function testAnUnsetSettingReturnsTheDefault(): void
    {
        $this->assertSame('default', Config::get('WPCF_UNIT_NEVER_SET', 'default'));
        $this->assertFalse(Config::bool('WPCF_UNIT_NEVER_SET'));
        $this->assertTrue(Config::bool('WPCF_UNIT_NEVER_SET', true));
        $this->assertSame(7, Config::int('WPCF_UNIT_NEVER_SET', 7));
    }

    /**
     * An environment variable is always a string, and "false" is truthy in
     * PHP. S3_PATH_STYLE=false must turn path-style addressing off.
     *
     * @dataProvider booleanSpellings
     */
    public function testBooleanStringsAreParsed(string $value, bool $expected): void
    {
        $this->setEnv('S3_DIRECT_UPLOADS', $value);

        $this->assertSame($expected, Config::bool('S3_DIRECT_UPLOADS', !$expected));
    }

    /** @return array<string, array{string, bool}> */
    public static function booleanSpellings(): array
    {
        return [
            'true'  => ['true', true],
            '1'     => ['1', true],
            'yes'   => ['yes', true],
            'on'    => ['on', true],
            'false' => ['false', false],
            '0'     => ['0', false],
            'no'    => ['no', false],
            'off'   => ['off', false],
        ];
    }

    public function testAnUnparseableBooleanFallsBackToTheDefault(): void
    {
        $this->setEnv('S3_DIRECT_UPLOADS', 'maybe');

        $this->assertTrue(Config::bool('S3_DIRECT_UPLOADS', true));
        $this->assertFalse(Config::bool('S3_DIRECT_UPLOADS', false));
    }

    public function testABooleanConstantKeepsItsValue(): void
    {
        $this->assertTrue(Config::bool('S3_PATH_STYLE', false));
    }

    public function testIntegersAreParsedFromStrings(): void
    {
        $this->setEnv('S3_DIRECT_UPLOAD_MIN_SIZE', '1048576');

        $this->assertSame(1048576, Config::int('S3_DIRECT_UPLOAD_MIN_SIZE'));
    }

    public function testANonNumericIntegerFallsBackToTheDefault(): void
    {
        $this->setEnv('S3_DIRECT_UPLOAD_MIN_SIZE', 'lots');

        $this->assertSame(3, Config::int('S3_DIRECT_UPLOAD_MIN_SIZE', 3));
    }
}
