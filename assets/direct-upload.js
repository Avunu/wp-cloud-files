(function($) {
    'use strict';
    
    // Only intercept if we have the necessary configuration
    if (typeof wpCloudFiles === 'undefined') {
        return;
    }
    
    // Override WordPress media uploader
    if (wp && wp.Uploader && wp.Uploader.prototype) {
        const originalInit = wp.Uploader.prototype.init;
        
        wp.Uploader.prototype.init = function() {
            originalInit.apply(this, arguments);
            
            const uploader = this.uploader;
            
            if (!uploader) {
                return;
            }
            
            // Store original upload function
            const originalUploadFile = uploader.uploadFile;
            
            // Override the uploadFile method to use S3 direct upload
            uploader.uploadFile = function(file) {
                const up = this;
                
                // Get pre-signed URL from server
                $.ajax({
                    url: wpCloudFiles.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wp_cloud_files_get_presigned_url',
                        nonce: wpCloudFiles.nonce,
                        filename: file.name,
                        fileType: file.type
                    },
                    success: function(response) {
                        if (response.success && response.data.upload_url) {
                            // Store S3 data for later use
                            file.s3Data = response.data;
                            
                            // Upload directly to S3
                            const xhr = new XMLHttpRequest();
                            
                            // Track upload progress
                            xhr.upload.addEventListener('progress', function(e) {
                                if (e.lengthComputable) {
                                    file.loaded = e.loaded;
                                    file.percent = Math.round((e.loaded / e.total) * 100);
                                    up.trigger('UploadProgress', file);
                                }
                            });
                            
                            xhr.addEventListener('load', function() {
                                if (xhr.status === 200 || xhr.status === 204) {
                                    // Upload to S3 succeeded, create WordPress attachment
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
                                                // Simulate WordPress response format
                                                file.status = plupload.DONE;
                                                file.loaded = file.size;
                                                file.percent = 100;
                                                
                                                // Trigger file uploaded event
                                                up.trigger('FileUploaded', file, {
                                                    response: JSON.stringify(attachmentResponse.data)
                                                });
                                            } else {
                                                file.status = plupload.FAILED;
                                                up.trigger('Error', {
                                                    code: plupload.HTTP_ERROR,
                                                    message: attachmentResponse.data.message || 'Failed to create attachment',
                                                    file: file
                                                });
                                            }
                                        },
                                        error: function(jqXHR, textStatus, errorThrown) {
                                            file.status = plupload.FAILED;
                                            up.trigger('Error', {
                                                code: plupload.HTTP_ERROR,
                                                message: 'Failed to create WordPress attachment',
                                                file: file
                                            });
                                        }
                                    });
                                } else {
                                    // S3 upload failed
                                    file.status = plupload.FAILED;
                                    up.trigger('Error', {
                                        code: plupload.HTTP_ERROR,
                                        message: 'Failed to upload to S3',
                                        file: file
                                    });
                                }
                            });
                            
                            xhr.addEventListener('error', function() {
                                file.status = plupload.FAILED;
                                up.trigger('Error', {
                                    code: plupload.HTTP_ERROR,
                                    message: 'Network error during S3 upload',
                                    file: file
                                });
                            });
                            
                            // Open connection and send file
                            xhr.open('PUT', response.data.upload_url, true);
                            xhr.setRequestHeader('Content-Type', file.type);
                            
                            // Get file blob
                            if (file.getSource) {
                                const source = file.getSource();
                                xhr.send(source);
                            } else {
                                xhr.send(file.getNative());
                            }
                        } else {
                            // Fall back to normal WordPress upload
                            console.warn('Failed to get pre-signed URL, falling back to normal upload');
                            originalUploadFile.call(up, file);
                        }
                    },
                    error: function() {
                        // Fall back to normal WordPress upload
                        console.warn('Error getting pre-signed URL, falling back to normal upload');
                        originalUploadFile.call(up, file);
                    }
                });
            };
        };
    }
    
})(jQuery);
