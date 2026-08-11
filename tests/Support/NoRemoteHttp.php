<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Support;

use WP_Error;

/**
 * Records (and blocks) any HTTP request leaving the machine.
 *
 * index.php constructs a plugin-update-checker pointed at github.com on every
 * load, before the S3 constant gate. On a developer machine with network access
 * that would silently succeed and make the suite slow and non-deterministic;
 * WPTestCase asserts in tear_down that nothing was attempted.
 *
 * Note this cannot see AWS SDK traffic -- that goes through Guzzle directly, not
 * WP_Http. PluginBootTest asserts separately that S3_ENDPOINT is a loopback
 * address, which is what actually prevents tests hitting real S3.
 */
final class NoRemoteHttp
{
    /** @var list<string> */
    private static array $attempts = [];

    private const ALLOWED_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    /**
     * @param false|array<string, mixed>|WP_Error $preempt
     * @param array<string, mixed>                $args
     * @return false|array<string, mixed>|WP_Error
     */
    public static function intercept($preempt, $args, $url)
    {
        $host = is_string($url) ? (string) parse_url($url, PHP_URL_HOST) : '';

        if (in_array($host, self::ALLOWED_HOSTS, true)) {
            return $preempt;
        }

        self::$attempts[] = is_string($url) ? $url : '(unknown url)';

        return new WP_Error(
            'wpcf_test_blocked_http',
            sprintf('Blocked outbound HTTP to %s during tests.', $host !== '' ? $host : 'an unknown host')
        );
    }

    /** @return list<string> */
    public static function attempts(): array
    {
        return self::$attempts;
    }

    public static function reset(): void
    {
        self::$attempts = [];
    }
}
