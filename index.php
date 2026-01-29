<?php
/**
 * Plugin Name: WP Cloud Files
 * Plugin URI: https://avunu.io/
 * Description: Use S3 for WordPress uploads. This plugin moves uploaded files to S3 and redirects request to them from there.
 * Version: 0.0.2
 * Author: Avunu
 * Author URI: https://avunu.io/
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Load Composer autoloader
$composerAutoloader = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoloader)) {
    require_once $composerAutoloader;
} else {
    // Log or notify that Composer dependencies are missing
    add_action('admin_notices', function() {
        echo '<div class="error"><p>';
        echo 'WP Cloud Files plugin requires Composer dependencies to be installed. Please run "composer install" in the plugin directory.';
        echo '</p></div>';
    });
    return; // Stop plugin initialization if dependencies are missing
}

// Load autoloader
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'Avunu\\WPCloudFiles\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 15)) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

// Register CLI commands if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('wp-cloud-files', 'Avunu\\WPCloudFiles\\CLI');
}

// Bootstrap the plugin
add_action('plugins_loaded', function() {
    if (
        defined('S3_KEY') && 
        defined('S3_SECRET') && 
        defined('S3_BUCKET') && 
        defined('S3_ENDPOINT') && 
        defined('S3_PUBLIC_URL')
    ) {
        Avunu\WPCloudFiles\Plugin::boot();
    } else {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>';
            echo 'WP Cloud Files plugin requires S3_KEY, S3_SECRET, S3_BUCKET, S3_ENDPOINT, and S3_PUBLIC_URL constants to be defined.';
            echo '</p></div>';
        });
    }
});

// Register activation hook to check requirements
register_activation_hook(__FILE__, function() {
    if (version_compare(PHP_VERSION, '8.1', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('WP Cloud Files requires PHP 8.1 or higher.');
    }
});
