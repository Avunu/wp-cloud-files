<?php

/**
 * Configuration constants for static analysis.
 *
 * PHPStan has no stub mechanism for constants (stubs cover classes and
 * functions), so this file is listed in bootstrapFiles and actually executed.
 * Values are representative; only their types matter.
 *
 * Every name here is also listed in dynamicConstantNames, so PHPStan does not
 * narrow `defined('S3_ROOT')` to always-true and start reporting the
 * unconfigured branches as dead code.
 */

declare(strict_types=1);

define('S3_KEY', 'analysis-key');
define('S3_SECRET', 'analysis-secret');
define('S3_BUCKET', 'analysis-bucket');
define('S3_ENDPOINT', 'https://s3.example.com');
define('S3_PUBLIC_URL', 'https://cdn.example.com');
define('S3_REGION', 'us-east-1');
define('S3_ROOT', 'uploads');
define('S3_PATH_STYLE', true);
define('S3_UPLOAD_ACL', 'public-read');
define('S3_MAX_UPLOAD_SIZE', 5368709120);
define('S3_DIRECT_UPLOADS', true);
define('S3_DIRECT_UPLOAD_MIN_SIZE', 0);
define('S3_PRESIGN_EXPIRES', '+15 minutes');

// WordPress core constants the plugin reads. The wordpress-stubs file declares
// functions and classes but not these.
define('ABSPATH', '/srv/wordpress/');
define('WP_PLUGIN_DIR', '/srv/wordpress/wp-content/plugins');
define('WP_DEBUG', true);
