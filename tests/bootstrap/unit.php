<?php

/**
 * Bootstrap for the pure-unit suites (tests/Unit, tests/Thumbnails).
 *
 * WordPress is deliberately NOT loaded here: Brain Monkey works by defining the
 * WordPress functions itself, so they must be undefined when a test starts.
 * That is why this suite cannot share a PHPUnit config with the integration
 * suite -- PHPUnit has one bootstrap per config file, not per testsuite.
 */

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use Avunu\WPCloudFiles\Tests\Support\Profile;

/*
 * Fixed, deterministic values so unit tests can assert exact URLs. The
 * integration bootstrap reads the equivalents from the environment instead,
 * because there they must point at a live MinIO.
 */
define('S3_KEY', 'unit-test-key');
define('S3_SECRET', 'unit-test-secret');
define('S3_BUCKET', 'wpcf-unit');
define('S3_ENDPOINT', 'http://127.0.0.1:9000');
define('S3_PUBLIC_URL', 'https://cdn.test');
define('S3_REGION', 'us-east-1');
define('S3_PATH_STYLE', true);

switch (Profile::current()) {
    case Profile::ROOT:
        define('S3_ROOT', 'uploads');
        break;

    case Profile::MAXSIZE:
        // 10 GiB, deliberately different from Plugin's 5 GiB default.
        define('S3_MAX_UPLOAD_SIZE', 10 * 1024 * 1024 * 1024);
        break;
}

// DocumentThumbnailer::setupDomPdf() reads this at construction time.
define('WP_PLUGIN_DIR', dirname(__DIR__, 2) . '/tests/.plugin-dir');

// Keep temp-file litter inside the test tree. DocumentThumbnailer writes
// docthumb_*.pdf / thumbnail_*.jpg into sys_get_temp_dir() and does not always
// clean up on failure paths; PHP honours TMPDIR on Linux.
$tmp = getenv('TMPDIR');
if (!is_string($tmp) || $tmp === '') {
    $tmp = sys_get_temp_dir() . '/wpcf-test-tmp';
    putenv('TMPDIR=' . $tmp);
}
if (!is_dir($tmp)) {
    mkdir($tmp, 0o777, true);
}
