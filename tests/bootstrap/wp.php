<?php

/**
 * Bootstrap for the WordPress integration suite.
 *
 * Loads the real WordPress test framework and the plugin through its actual
 * entry point (index.php), so the constant gate, the update checker and
 * Plugin::boot() all run exactly as they do on a real site.
 */

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

$testsDir = getenv('WP_TESTS_DIR');

if (!is_string($testsDir) || !is_file($testsDir . '/includes/functions.php')) {
    fwrite(STDERR, <<<TXT
        WordPress test framework not found.
        WP_TESTS_DIR={$testsDir}

        Run the suite through tests/bin/run-integration.sh, which downloads and
        caches wordpress-develop for the requested WP_VERSION.

        TXT);
    exit(1);
}

/*
 * The core bootstrap resolves its config with `defined('WP_TESTS_CONFIG_FILE_PATH')`,
 * NOT getenv() -- an environment variable alone is silently ignored and it falls
 * back to looking for wp-tests-config.php next to the downloaded core.
 */
if (!defined('WP_TESTS_CONFIG_FILE_PATH')) {
    $configPath = getenv('WP_TESTS_CONFIG_FILE_PATH') ?: __DIR__ . '/wp-tests-config.php';

    if (!is_file($configPath)) {
        fwrite(STDERR, "WordPress test config not found at {$configPath}\n");
        exit(1);
    }

    define('WP_TESTS_CONFIG_FILE_PATH', $configPath);
}

require_once $testsDir . '/includes/functions.php';

// The polyfills live in the test toolchain, not in WordPress' own vendor dir.
if (!defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__DIR__) . '/tools/vendor/yoast/phpunit-polyfills');
}

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/index.php';
});

/*
 * Fail loudly on any outbound HTTP other than our own MinIO. WP_HTTP_BLOCK_EXTERNAL
 * already blocks it, but a blocked request returns a WP_Error that plugin code
 * may swallow -- this records the attempt so the test itself fails.
 */
tests_add_filter(
    'pre_http_request',
    [\Avunu\WPCloudFiles\Tests\Support\NoRemoteHttp::class, 'intercept'],
    0,
    3
);

require $testsDir . '/includes/bootstrap.php';
