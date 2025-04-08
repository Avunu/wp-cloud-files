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
        $baseurl = $uploads['baseurl'];
        
        // Only rewrite URLs that point to uploads directory
        if (str_starts_with($url, $baseurl)) {
            $relativePath = substr($url, strlen($baseurl) + 1);
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
        $baseurl = $uploads['baseurl'];
        
        foreach ($sources as $width => $source) {
            // Skip URLs that don't point to uploads directory
            if (!str_starts_with($source['url'], $baseurl)) {
                continue;
            }
            
            $relativePath = substr($source['url'], strlen($baseurl) + 1);
            $sources[$width]['url'] = S3Client::getInstance()->getPublicUrl($relativePath);
        }
        
        return $sources;
    }
    
    /**
     * Rewrite image URLs in content to point to S3
     */
    public function rewriteContentUrls(string $content): string
    {
        if (empty($content) || !str_contains($content, '<img')) {
            return $content;
        }
        
        $uploads = wp_upload_dir();
        $baseurl = $uploads['baseurl'];
        
        // Simplistic approach: just replace all occurrences of upload URL with S3 URL
        // For a more robust implementation, a proper HTML parser should be used
        return str_replace($baseurl, rtrim(S3_PUBLIC_URL, '/'), $content);
    }
}
