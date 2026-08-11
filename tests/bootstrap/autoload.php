<?php

/**
 * Shared autoloading for every test suite.
 *
 * Load order matters: the plugin's own vendor/ comes first so plugin code runs
 * against exactly the dependency versions it ships, then the test toolchain,
 * then the tests themselves.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

// The plugin's production vendor/. Overridable so Nix check derivations can
// point at the already-built pluginPackage instead of a working-tree install.
$pluginVendor = getenv('WPCF_VENDOR') ?: $root . '/vendor';
if (!is_file($pluginVendor . '/autoload.php')) {
    fwrite(STDERR, <<<TXT
        Plugin dependencies are not installed.
        Expected: {$pluginVendor}/autoload.php
        Run `composer install` (or enter the devenv shell and run `setup-tests`).

        TXT);
    exit(1);
}
require_once $pluginVendor . '/autoload.php';

// The test toolchain (PHPUnit, Brain Monkey, Mockery, Polyfills).
$toolsVendor = $root . '/tests/tools/vendor';
if (!is_file($toolsVendor . '/autoload.php')) {
    fwrite(STDERR, <<<TXT
        Test toolchain is not installed.
        Expected: {$toolsVendor}/autoload.php
        Run `composer install --working-dir=tests/tools`.

        TXT);
    exit(1);
}
require_once $toolsVendor . '/autoload.php';

/*
 * Test classes get a hand-rolled autoloader rather than an `autoload-dev` entry
 * in the root composer.json. The plugin sets config.classmap-authoritative, which
 * makes Composer's autoloader classmap-only -- a PSR-4 rule for tests/ would then
 * need a `composer dump-autoload` after every new test file.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Avunu\\WPCloudFiles\\Tests\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/../' . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
});
