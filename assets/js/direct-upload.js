// @ts-check
/**
 * WP Cloud Files — direct-to-S3 uploads.
 *
 * Intercepts the wp.media / Plupload uploader and, instead of POSTing files
 * through the web server (and any upload-size-limited proxy in front of it),
 * uploads them straight to S3 with a short-lived presigned PUT URL. The
 * attachment is then registered via REST and optimized in the background.
 *
 * Why this works: wp.Uploader calls self.init() (this script's hook) *before*
 * it binds its own FilesAdded/FileUploaded handlers, and plupload dispatches
 * events in priority order. We bind FilesAdded at a high priority so we can
 * claim files (splice them out of the array + removeFile) before core ever
 * tries to upload them — while still reusing core's FileUploaded/UploadProgress
 * handlers to drive the existing media UI.
 *
 * This file ships as plain JavaScript; it is type-checked (not compiled) via
 * `tsc --noEmit` against types/wp-globals.d.ts. See README / tsconfig.json.
 */
( function () {
	'use strict';

	var config = window.wpcfDirectUpload || {};
	var wp = window.wp;

	if (
		! config.enabled ||
		! wp ||
		! wp.Uploader ||
		! wp.Uploader.prototype ||
		! wp.apiFetch ||
		! wp.media
	) {
		return;
	}

	// Capture stable, non-optional references so the narrowed types flow into the
	// nested helper closures below.
	var apiFetch = wp.apiFetch;
	var media = wp.media;
	var WpUploader = wp.Uploader;
	var _ = window._;
	var plupload = window.plupload;
	var minSize = parseInt( String( config.minSize ), 10 ) || 0;

	var originalInit = WpUploader.prototype.init;

	/** @this {WpUploaderInstance} */
	WpUploader.prototype.init = function () {
		var self = this;
		var up = self.uploader;

		if ( up && up.bind ) {
			// High priority so this runs before core's FilesAdded handler.
			up.bind( 'FilesAdded', function ( uploader, files ) {
				claimFiles( self, uploader, /** @type {WpcfPluploadFile[]} */ ( files ) );
			}, null, 1000 );
		}

		return originalInit.call( this );
	};

	/**
	 * Pull files we want to handle out of the batch so plupload's own transport
	 * never touches them, then drive each through the direct-upload pipeline.
	 *
	 * @param {WpUploaderInstance}   self
	 * @param {WpcfPluploadUploader} up
	 * @param {WpcfPluploadFile[]}   files
	 */
	function claimFiles( self, up, files ) {
		var claimed = [];

		for ( var i = files.length - 1; i >= 0; i-- ) {
			var file = files[ i ];

			if ( plupload.FAILED === file.status ) {
				continue;
			}

			// Below the threshold (default 0 = everything) stays on the normal path.
			if ( minSize > 0 && file.size < minSize ) {
				continue;
			}

			files.splice( i, 1 ); // hide from core's FilesAdded (no duplicate model)
			claimed.push( file );
		}

		claimed.forEach( function ( file ) {
			handleFile( self, up, file );
		} );
	}

	/**
	 * @param {WpUploaderInstance}   self
	 * @param {WpcfPluploadUploader} up
	 * @param {WpcfPluploadFile}     file
	 */
	function handleFile( self, up, file ) {
		createPlaceholder( self, file );

		// Capture the native File before removeFile(), which destroys the source.
		var native = file.getNative ? file.getNative() : null;

		// Remove from plupload's queue so it won't POST the file itself.
		up.removeFile( file );

		if ( ! native ) {
			failFile( self, file, new Error( 'Could not read the selected file.' ) );
			return;
		}

		var postId = ( media.model.settings.post && media.model.settings.post.id ) || 0;

		apiFetch( {
			path: '/wp-cloud-files/v1/presign',
			method: 'POST',
			data: { filename: file.name, type: file.type, size: file.size }
		} ).then( function ( presign ) {
			return putToS3( up, file, /** @type {File} */ ( native ), presign );
		} ).then( function ( presign ) {
			return apiFetch( {
				path: '/wp-cloud-files/v1/attachment',
				method: 'POST',
				data: {
					key: presign.key,
					name: presign.name,
					type: presign.type,
					size: file.size,
					title: file.name,
					post: postId
				}
			} );
		} ).then( function ( attachment ) {
			// Reuse core's FileUploaded handler (same shape as async-upload.php).
			up.trigger( 'FileUploaded', file, {
				response: JSON.stringify( { success: true, data: attachment } )
			} );
		} ).catch( function ( err ) {
			failFile( self, file, err );
		} );
	}

	/**
	 * PUT the raw file directly to S3, mirroring progress into the media UI.
	 *
	 * @param {WpcfPluploadUploader} up
	 * @param {WpcfPluploadFile}     file
	 * @param {File}                 native
	 * @param {WpcfPresign}          presign
	 * @returns {Promise<WpcfPresign>}
	 */
	function putToS3( up, file, native, presign ) {
		return new Promise( function ( resolve, reject ) {
			var xhr = new XMLHttpRequest();
			xhr.open( 'PUT', presign.uploadUrl, true );

			if ( presign.type ) {
				xhr.setRequestHeader( 'Content-Type', presign.type );
			}

			xhr.upload.onprogress = function ( e ) {
				if ( e.lengthComputable ) {
					file.loaded = e.loaded;
					file.size = e.total;
					file.percent = Math.round( ( e.loaded / e.total ) * 100 );
					up.trigger( 'UploadProgress', file );
				}
			};

			xhr.onload = function () {
				if ( xhr.status >= 200 && xhr.status < 300 ) {
					resolve( presign );
				} else {
					reject( new Error( 'Upload failed (HTTP ' + xhr.status + ').' ) );
				}
			};

			xhr.onerror = function () {
				reject( new Error( 'Network error during upload. Check the bucket CORS configuration.' ) );
			};

			xhr.send( native );
		} );
	}

	/**
	 * Create the same "uploading" Attachment model core would, so progress and
	 * completion render in the existing media views.
	 *
	 * @param {WpUploaderInstance} self
	 * @param {WpcfPluploadFile}   file
	 */
	function createPlaceholder( self, file ) {
		var attributes = _.extend( {
			file: file,
			uploading: true,
			date: new Date(),
			filename: file.name,
			menuOrder: 0,
			uploadedTo: media.model.settings.post.id
		}, _.pick( file, 'loaded', 'size', 'percent' ) );

		var image = /(?:jpe?g|png|gif)$/i.exec( file.name );
		if ( image ) {
			attributes.type = 'image';
			attributes.subtype = ( 'jpg' === image[ 0 ] ) ? 'jpeg' : image[ 0 ];
		}

		file.attachment = media.model.Attachment.create( attributes );
		WpUploader.queue.add( file.attachment );
		self.added( file.attachment );
	}

	/**
	 * Surface an upload error the same way core's internal handler does.
	 *
	 * @param {WpUploaderInstance} self
	 * @param {WpcfPluploadFile}   file
	 * @param {unknown}            err
	 */
	function failFile( self, file, err ) {
		var message = ( err instanceof Error && err.message ) ? err.message : 'Upload failed.';

		if ( file.attachment ) {
			file.attachment.destroy();
		}

		WpUploader.errors.unshift( { message: message, data: {}, file: file } );
		self.error( message, {}, file );
	}
} )();
