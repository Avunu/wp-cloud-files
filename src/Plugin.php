<?php

namespace Avunu\WPCloudFiles;

class Plugin
{
    public static function boot(): void
    {
        $instance = new self();
        $instance->registerHooks();
    }
    
    private function registerHooks(): void
    {
        // Initialize our handlers
        $mediaHandler = new MediaHandler();
        $urlRewriter = new UrlRewriter();
        $thumbnailHandler = new ThumbnailHandler();
        
        // URL rewriting for attachments
        add_filter('wp_get_attachment_url', [$urlRewriter, 'rewriteAttachmentUrl'], 10, 2);
        
        // Handle image srcset URLs
        add_filter('wp_calculate_image_srcset', [$urlRewriter, 'rewriteSrcsetUrls'], 10, 5);
        
        // Process complete media after WordPress is done with it
        add_filter('wp_update_attachment_metadata', [$mediaHandler, 'processMedia'], 999, 2);
        
        // Handle deletions
        add_action('delete_attachment', [$mediaHandler, 'handleDeletion'], 10);
        
        // Handle document thumbnail generation
        add_filter('wp_generate_attachment_metadata', [$thumbnailHandler, 'handleDocumentThumbnails'], 10, 2);
        
        // Filter to show thumbnails in admin
        add_filter('wp_prepare_attachment_for_js', [$thumbnailHandler, 'prepareAttachmentForJs'], 10, 3);
        
        // // Handle image editing (in case it bypasses normal upload flow)
        // add_action('wp_ajax_image-editor', function() use ($mediaHandler) {
        //     add_filter('wp_update_attachment_metadata', function($metadata, $attachment_id) use ($mediaHandler) {
        //         return $mediaHandler->processMedia($metadata, $attachment_id);
        //     }, 999, 2);
        // }, 1);
        
        // Handle content URL rewrites
        // add_filter('the_content', [$urlRewriter, 'rewriteContentUrls'], 10);
    }
}
