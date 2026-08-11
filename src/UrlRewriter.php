<?php

namespace Avunu\WPCloudFiles;

class UrlRewriter
{
    /**
     * Rewrite attachment URL to point to S3
     */
    public function rewriteAttachmentUrl(string $url, int $attachmentId): string
    {
        // Simple URL replacement: replace WP uploads URL with S3 public URL
        $uploads = wp_upload_dir();
        // Match against the trailing slash so a sibling directory that merely
        // starts with the same characters (".../uploads-old/") is not treated as
        // being inside the uploads dir. Matching the separator also means the
        // relative path starts right after it, with no off-by-one correction.
        $baseurl = trailingslashit($uploads['baseurl']);

        // Only rewrite URLs that point to uploads directory
        if (str_starts_with($url, $baseurl)) {
            $relativePath = substr($url, strlen($baseurl));
            return S3Client::getInstance()->getPublicUrl($relativePath);
        }

        return $url;
    }

    /**
     * Rewrite srcset URLs to point to S3
     */
    public function rewriteSrcsetUrls(array $sources, array $sizeArray, string $imageSrc, array $imageMeta, int $attachmentId): array
    {
        if (empty($sources)) {
            return $sources;
        }

        $uploads = wp_upload_dir();
        // See rewriteAttachmentUrl(): match the separator too.
        $baseurl = trailingslashit($uploads['baseurl']);

        foreach ($sources as $width => $source) {
            // Skip URLs that don't point to uploads directory
            if (!str_starts_with($source['url'], $baseurl)) {
                continue;
            }

            $relativePath = substr($source['url'], strlen($baseurl));
            $sources[$width]['url'] = S3Client::getInstance()->getPublicUrl($relativePath);
        }

        return $sources;
    }
}
