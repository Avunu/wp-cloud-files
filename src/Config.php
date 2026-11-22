<?php

namespace Avunu\WPCloudFiles;

/**
 * One place that reads the plugin's S3_* settings.
 *
 * A constant wins when it is defined, as it always has. Otherwise the value
 * comes from the process environment, so a Bedrock site (or any container that
 * injects S3_* as environment variables) needs no wp-config.php bridge.
 *
 * Environment values are strings. An empty string counts as unset, so
 * `S3_ROOT=` in a .env file behaves like leaving the variable out.
 */
final class Config
{
    /**
     * Every setting the plugin reads, in documentation order.
     */
    public const NAMES = [
        'S3_KEY',
        'S3_SECRET',
        'S3_BUCKET',
        'S3_ENDPOINT',
        'S3_PUBLIC_URL',
        'S3_REGION',
        'S3_PATH_STYLE',
        'S3_ROOT',
        'S3_UPLOAD_ACL',
        'S3_DIRECT_UPLOADS',
        'S3_DIRECT_UPLOAD_MIN_SIZE',
        'S3_MAX_UPLOAD_SIZE',
        'S3_PRESIGN_EXPIRES',
        'S3_EXCLUDE_PREFIXES',
    ];

    /**
     * Settings that must never be printed.
     */
    public const SECRETS = ['S3_KEY', 'S3_SECRET'];

    /**
     * The value of a setting, or $default when it is neither a constant nor a
     * non-empty environment variable.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $name, $default = null)
    {
        if (defined($name)) {
            return constant($name);
        }

        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $default;
    }

    /**
     * Whether the setting has a value from either source.
     */
    public static function has(string $name): bool
    {
        return self::source($name) !== null;
    }

    /**
     * Where a setting comes from: "constant", "env", or null when unset.
     */
    public static function source(string $name): ?string
    {
        if (defined($name)) {
            return 'constant';
        }

        $value = getenv($name);

        return is_string($value) && $value !== '' ? 'env' : null;
    }

    public static function string(string $name, string $default = ''): string
    {
        $value = self::get($name);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    /**
     * A boolean setting. Constants keep PHP truthiness; strings accept the
     * usual spellings ("true", "1", "yes", "on" / "false", "0", "no", "off"),
     * because an environment variable can only ever be a string and "false"
     * is truthy in PHP.
     */
    public static function bool(string $name, bool $default = false): bool
    {
        $value = self::get($name);

        if ($value === null) {
            return $default;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $parsed ?? $default;
        }

        return (bool) $value;
    }

    public static function int(string $name, int $default = 0): int
    {
        $value = self::get($name);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
