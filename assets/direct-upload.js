(function($) {
    'use strict';
    
    // Only intercept if we have the necessary configuration
    if (typeof wpCloudFiles === 'undefined') {
        return;
    }
    
    // Store original plupload settings
    let originalPluploadInit = null;
    
    // Override WordPress media uploader
    if (wp && wp.Uploader && wp.Uploader.prototype) {
        const originalInit = wp.Uploader.prototype.init;
        
        wp.Uploader.prototype.init = function() {
            originalInit.apply(this, arguments);
            
            const uploader = this.uploader;
            
            if (!uploader) {
                return;
            }
            
            // Override the BeforeUpload event
            uploader.bind('BeforeUpload', function(up, file) {
                // Get pre-signed URL from server
                $.ajax({
                    url: wpCloudFiles.ajax_url,
                    type: 'POST',
                    async: false,
                    data: {
                        action: 'wp_cloud_files_get_presigned_url',
                        nonce: wpCloudFiles.nonce,
                        filename: file.name,
                        fileType: file.type
                    },
                    success: function(response) {
                        if (response.success) {
                            // Store the data we'll need later
                            file.s3Data = response.data;
                            
                            // Change upload URL to S3 pre-signed URL
                            up.settings.url = response.data.upload_url;
                            up.settings.multipart = false;
                        }
                    },
                    error: function() {
                        // Fall back to normal WordPress upload
                        console.warn('Failed to get pre-signed URL, falling back to normal upload');
                    }
                });
            });
            
            // Handle successful upload to S3
            uploader.bind('FileUploaded', function(up, file, response) {
                if (file.s3Data) {
                    // File was uploaded to S3, now create WordPress attachment
                    $.ajax({
                        url: wpCloudFiles.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'wp_cloud_files_create_attachment',
                            nonce: wpCloudFiles.nonce,
                            filename: file.s3Data.filename,
                            s3_path: file.s3Data.s3_path,
                            file_type: file.type,
                            file_size: file.size
                        },
                        success: function(attachmentResponse) {
                            if (attachmentResponse.success) {
                                // Trigger WordPress attachment created event
                                const attachment = attachmentResponse.data.attachment;
                                
                                // Update file status in uploader
                                file.attachment = attachment;
                                file.status = plupload.DONE;
                                
                                // Notify WordPress that upload is complete
                                if (wp.media && wp.media.frame) {
                                    const frame = wp.media.frame;
                                    if (frame.content && frame.content.get()) {
                                        const library = frame.content.get().collection;
                                        if (library) {
                                            library.add(attachment);
                                        }
                                    }
                                }
                            } else {
                                console.error('Failed to create WordPress attachment:', attachmentResponse);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Error creating WordPress attachment:', error);
                        }
                    });
                }
            });
        };
    }
    
})(jQuery);
