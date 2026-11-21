<?php

namespace Avunu\WPCloudFiles;

/**
 * Keeps server-executable files out of uploads and out of the bucket.
 *
 * WordPress' own allowlist already refuses .php, but a site can widen it with
 * the `upload_mimes` filter, and the bucket is a public origin for every key the
 * plugin writes. This check runs regardless of that filter: on the upload
 * prefilters, before a presign is issued, and inside S3Client::uploadFile(), so
 * CLI commands are covered too.
 *
 * Interpreter extensions count anywhere in the name, not just at the end:
 * Apache's AddHandler runs "shell.php.jpg" as PHP.
 */
final class ExecutableGuard
{
    /**
     * Extensions a web server may hand to an interpreter. Checked in every
     * position of the name, because Apache's AddHandler runs "shell.php.jpg"
     * as PHP.
     */
    private const HANDLER_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phtml', 'phar', 'phps',
        'cgi', 'fcgi', 'shtml', 'asp', 'aspx', 'ashx', 'asmx', 'jsp', 'jspx', 'cfm',
    ];

    /**
     * Scripts, programs and server config. Checked as the final extension
     * only, so names such as "brochure.pl.pdf" or "logo.example.com.png" pass.
     */
    private const FINAL_EXTENSIONS = [
        'inc', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'ksh', 'csh',
        'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr', 'vbs', 'ps1', 'so', 'elf',
        'htaccess', 'htpasswd', 'ini',
    ];

    /**
     * MIME types that mark the same, whether declared by WordPress or sniffed.
     */
    private const MIME_TYPES = [
        'application/x-httpd-php',
        'application/x-httpd-php-source',
        'application/x-php',
        'text/x-php',
        'text/php',
        'application/php',
        'application/x-sh',
        'application/x-shellscript',
        'text/x-shellscript',
        'text/x-sh',
        'application/x-csh',
        'text/x-perl',
        'application/x-perl',
        'text/x-python',
        'application/x-python-code',
        'text/x-ruby',
        'application/x-executable',
        'application/x-pie-executable',
        'application/x-sharedlib',
        'application/x-mach-binary',
        'application/x-dosexec',
        'application/x-msdownload',
        'application/x-msdos-program',
        'application/x-ms-installer',
        'application/x-bat',
        'application/x-elf',
    ];

    /**
     * Whether the file name carries an executable extension.
     */
    public static function hasExecutableExtension(string $path): bool
    {
        $name = strtolower(basename(str_replace('\\', '/', $path)));

        // Dotfiles (".htaccess") have no name part; everything is an extension.
        $parts = array_map('trim', explode('.', $name));
        array_shift($parts);

        if ($parts === []) {
            return false;
        }

        if (in_array(end($parts), self::FINAL_EXTENSIONS, true)) {
            return true;
        }

        return array_intersect($parts, self::HANDLER_EXTENSIONS) !== [];
    }

    public static function isExecutableMime(?string $mime): bool
    {
        if ($mime === null || $mime === '') {
            return false;
        }

        $mime = strtolower(trim(explode(';', $mime)[0]));

        return in_array($mime, self::MIME_TYPES, true);
    }

    /**
     * The extension check, the declared type, and (for a readable local file)
     * the type libmagic sniffs from its bytes.
     */
    public static function isExecutable(string $path, ?string $declaredMime = null, ?string $localFile = null): bool
    {
        if (self::hasExecutableExtension($path) || self::isExecutableMime($declaredMime)) {
            return true;
        }

        if ($localFile !== null && is_file($localFile) && is_readable($localFile) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $sniffed = finfo_file($finfo, $localFile);
                finfo_close($finfo);

                if (is_string($sniffed) && self::isExecutableMime($sniffed)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * `wp_handle_upload_prefilter` / `wp_handle_sideload_prefilter`: refuse
     * before WordPress moves the file into uploads.
     *
     * @param array<string, mixed> $file An entry from $_FILES.
     * @return array<string, mixed>
     */
    public static function filterUpload($file): array
    {
        if (!is_array($file) || !empty($file['error'])) {
            return is_array($file) ? $file : [];
        }

        $name = isset($file['name']) ? (string) $file['name'] : '';
        $type = isset($file['type']) ? (string) $file['type'] : null;
        $tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : null;

        if (self::isExecutable($name, $type, $tmp)) {
            $file['error'] = __('WP Cloud Files refuses executable files (scripts and programs) in the media library.', 'wp-cloud-files');
        }

        return $file;
    }
}
