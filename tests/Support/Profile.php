<?php

/**
 * The plugin reads its configuration from `define()`d constants, which are
 * process-global and immutable. "with S3_ROOT set" and "without" therefore
 * cannot be covered in the same PHPUnit process -- the suite is run once per
 * profile instead, selected by the WPCF_TEST_PROFILE environment variable.
 */

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Support;

final class Profile
{
    public const DEFAULT = 'default';

    /** S3_ROOT set, so every key is prefixed inside the bucket. */
    public const ROOT = 'root';

    /** S3_MAX_UPLOAD_SIZE set to a non-default value. */
    public const MAXSIZE = 'maxsize';

    /** S3_ENDPOINT points at a closed port, so every S3 call throws. */
    public const BROKEN_S3 = 'broken-s3';

    public static function current(): string
    {
        $profile = getenv('WPCF_TEST_PROFILE');

        return is_string($profile) && $profile !== '' ? $profile : self::DEFAULT;
    }

    public static function is(string $profile): bool
    {
        return self::current() === $profile;
    }
}
