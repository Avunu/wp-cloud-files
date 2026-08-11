<?php

/**
 * WordPress test-suite configuration.
 *
 * Loaded by the core bootstrap via WP_TESTS_CONFIG_FILE_PATH. Everything comes
 * from the environment so the same file works under devenv locally, in GitHub
 * Actions, and against plain service containers if devenv is ever swapped out.
 *
 * This file IS the S3 double: S3Client already feeds S3_ENDPOINT and
 * S3_PATH_STYLE straight to the AWS SDK, so pointing them at MinIO is all that
 * is needed -- no mock, no reflection, no production seam.
 */

declare(strict_types=1);

$wpcfEnv = static function (string $name, ?string $default = null): string {
    $value = getenv($name);

    if (!is_string($value) || $value === '') {
        if ($default === null) {
            fwrite(STDERR, "Required environment variable {$name} is not set.\n");
            exit(1);
        }

        return $default;
    }

    return $value;
};

// ------------------------------------------------------------------ //
// WordPress core                                                      //
// ------------------------------------------------------------------ //
define('ABSPATH', rtrim($wpcfEnv('WP_CORE_DIR'), '/') . '/');

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'WP Cloud Files Test Suite');
define('WP_PHP_BINARY', PHP_BINARY);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', false);

// ------------------------------------------------------------------ //
// Database                                                            //
// ------------------------------------------------------------------ //
define('DB_NAME', $wpcfEnv('WP_TESTS_DB_NAME', 'wordpress_test'));
define('DB_USER', $wpcfEnv('WP_TESTS_DB_USER', 'root'));
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASS') ?: '');
define('DB_HOST', $wpcfEnv('WP_TESTS_DB_HOST', 'localhost'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

// ------------------------------------------------------------------ //
// Writable content dir, with the plugin symlinked in. Must be outside //
// ABSPATH because core lives in a read-only-ish download cache.       //
// ------------------------------------------------------------------ //
$wpcfContentDir = rtrim($wpcfEnv('WPCF_CONTENT_DIR'), '/');

define('WP_CONTENT_DIR', $wpcfContentDir);
define('WP_CONTENT_URL', 'http://example.org/wp-content');
define('WP_PLUGIN_DIR', $wpcfContentDir . '/plugins');
define('WP_PLUGIN_URL', 'http://example.org/wp-content/plugins');
define('WP_TEMP_DIR', sys_get_temp_dir());

// ------------------------------------------------------------------ //
// Keep the suite off the network.                                     //
//                                                                     //
// index.php builds a plugin-update-checker pointed at github.com      //
// unconditionally, before the S3 gate. WP_HTTP_BLOCK_EXTERNAL stops   //
// it; note that block_request() only auto-allows the literal host     //
// "localhost" and the site host, so 127.0.0.1 must be listed to let   //
// the MinIO public-URL assertions through.                            //
// ------------------------------------------------------------------ //
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_ACCESSIBLE_HOSTS', '127.0.0.1,localhost');

// ------------------------------------------------------------------ //
// The plugin under test                                               //
// ------------------------------------------------------------------ //
$wpcfProfile = getenv('WPCF_TEST_PROFILE') ?: 'default';
$wpcfBucket = $wpcfEnv('WPCF_TEST_BUCKET', 'wp-cloud-files-test');

define('S3_KEY', $wpcfEnv('S3_TEST_KEY'));
define('S3_SECRET', $wpcfEnv('S3_TEST_SECRET'));
define('S3_BUCKET', $wpcfBucket);
define('S3_REGION', $wpcfEnv('S3_TEST_REGION', 'us-east-1'));
define('S3_PATH_STYLE', true);
define('S3_DIRECT_UPLOADS', true);

if ($wpcfProfile === 'broken-s3') {
    // A closed port fails instantly, which is what drives Flysystem to *throw*
    // rather than return false -- the only way to reach the wpcf_s3_error path.
    define('S3_ENDPOINT', 'http://127.0.0.1:1');
    define('S3_PUBLIC_URL', 'http://127.0.0.1:1/' . $wpcfBucket);
} else {
    define('S3_ENDPOINT', $wpcfEnv('S3_TEST_ENDPOINT'));
    define('S3_PUBLIC_URL', $wpcfEnv('S3_TEST_PUBLIC_URL'));
}

if ($wpcfProfile === 'root') {
    define('S3_ROOT', 'uploads');
}

unset($wpcfEnv, $wpcfProfile, $wpcfBucket, $wpcfContentDir);
