<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Support;

use RuntimeException;

final class FixturePath
{
    public static function dir(): string
    {
        return dirname(__DIR__) . '/fixtures';
    }

    public static function to(string $name): string
    {
        $path = self::dir() . '/' . $name;

        if (!is_file($path)) {
            throw new RuntimeException("Missing test fixture: {$path}");
        }

        return $path;
    }

    /**
     * Copy a fixture into a scratch directory, because WordPress' upload path
     * moves the file it is given.
     */
    public static function copyToTemp(string $name): string
    {
        $target = sys_get_temp_dir() . '/wpcf-fixture-' . bin2hex(random_bytes(6)) . '-' . $name;

        if (!copy(self::to($name), $target)) {
            throw new RuntimeException("Could not copy fixture {$name} to {$target}");
        }

        return $target;
    }
}
