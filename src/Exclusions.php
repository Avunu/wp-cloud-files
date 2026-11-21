<?php

namespace Avunu\WPCloudFiles;

/**
 * Upload paths that must stay on the local filesystem.
 *
 * Some plugins keep private files under uploads/ and protect them themselves:
 * WooCommerce downloadable products, Fluent Forms and Gravity Forms entries,
 * PDF invoices, WooCommerce logs. Copying them to a public bucket would publish
 * them. An excluded file is not uploaded, not deleted locally and its URL is
 * not rewritten, anywhere in the plugin.
 *
 * Prefixes are compared against the uploads-relative path ("2026/08/a.jpg",
 * "woocommerce_uploads/2026/08/song.mp3") as plain string prefixes. End one
 * with "/" to match a directory exactly; leave the slash off to match every
 * directory that starts with it, as "wpo_wcpdf" does for the per-site
 * "wpo_wcpdf_<hash>/" directory.
 */
final class Exclusions
{
    public const DEFAULT_PREFIXES = 'woocommerce_uploads/,fluentform/,gravity_forms/,wpo_wcpdf,wc-logs/';

    /**
     * The configured prefixes, S3_EXCLUDE_PREFIXES or the default list.
     *
     * Set S3_EXCLUDE_PREFIXES to a comma-separated list to replace the default.
     * An explicit empty list is not possible through the environment (empty
     * means unset); use the `wpcf_should_offload` filter to offload everything.
     *
     * @return list<string>
     */
    public static function prefixes(): array
    {
        return self::parse(Config::string('S3_EXCLUDE_PREFIXES', self::DEFAULT_PREFIXES));
    }

    /**
     * Split a comma-separated prefix list, dropping blanks and leading slashes.
     *
     * @return list<string>
     */
    public static function parse(string $list): array
    {
        $prefixes = [];

        foreach (explode(',', $list) as $prefix) {
            $prefix = ltrim(trim($prefix), '/');
            if ($prefix !== '' && !in_array($prefix, $prefixes, true)) {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }

    /**
     * Whether an uploads-relative path matches a configured prefix.
     */
    public static function isExcluded(string $relativePath): bool
    {
        $relativePath = ltrim($relativePath, '/');

        foreach (self::prefixes() as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a file may be offloaded: not excluded by prefix, then the
     * `wpcf_should_offload` filter has the last word.
     *
     * @param string $relativePath Uploads-relative path of the file.
     * @param int    $attachmentId 0 when there is no attachment yet (a presign).
     */
    public static function shouldOffload(string $relativePath, int $attachmentId = 0): bool
    {
        $relativePath = ltrim($relativePath, '/');

        /**
         * Filters whether a file is copied to the bucket, deleted locally and
         * served from S3_PUBLIC_URL.
         *
         * @param bool   $shouldOffload False when the path matches S3_EXCLUDE_PREFIXES.
         * @param string $relativePath  Uploads-relative path of the file (the
         *                              main file for attachment-level checks).
         * @param int    $attachmentId  Attachment ID, or 0 before one exists.
         */
        return (bool) apply_filters(
            'wpcf_should_offload',
            !self::isExcluded($relativePath),
            $relativePath,
            $attachmentId
        );
    }
}
