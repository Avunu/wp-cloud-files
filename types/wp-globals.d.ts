/**
 * Ambient type declarations for the browser globals the WP Cloud Files uploader
 * relies on.
 *
 * Only the members we actually use are declared. The legacy Backbone-based
 * `wp.media` framework and the `wp.Uploader` (wp-plupload) bridge have no
 * maintained `@types` packages, and `@types/plupload` predates the
 * priority argument on `bind()`, so we hand-roll the small surface here to keep
 * full control over method arity. Underscore comes from `@types/underscore`.
 *
 * This file is intentionally kept in script (non-module) scope so the `Window`
 * augmentations below merge into the global lib.dom `Window` without an explicit
 * `declare global`.
 */

/** Plupload's per-file object (the subset we touch). */
interface WpcfPluploadFile {
	id: string;
	name: string;
	type: string;
	size: number;
	status: number;
	loaded?: number;
	percent?: number;
	/** Set by us / by wp.Uploader: the Backbone model backing the UI item. */
	attachment?: WpcfBackboneModel;
	/** Underlying browser File, or null on non-HTML5 runtimes. */
	getNative(): File | null;
}

/** The plupload.Uploader instance exposed as `wp.Uploader#uploader`. */
interface WpcfPluploadUploader {
	bind(
		name: string,
		fn: (up: WpcfPluploadUploader, ...args: any[]) => void,
		scope?: unknown,
		priority?: number
	): void;
	trigger(name: string, ...args: unknown[]): void;
	removeFile(file: WpcfPluploadFile): void;
}

/** Minimal Backbone model surface used for the upload-queue placeholders. */
interface WpcfBackboneModel {
	set(attrs: Record<string, unknown>): WpcfBackboneModel;
	unset(key: string): WpcfBackboneModel;
	destroy(): void;
}

/** A `wp.Uploader` instance (the WordPress bridge, not the plupload engine). */
interface WpUploaderInstance {
	uploader: WpcfPluploadUploader;
	init(): void;
	added(attachment: WpcfBackboneModel): void;
	error(message: string, data: unknown, file: WpcfPluploadFile): void;
	success(attachment: WpcfBackboneModel): void;
}

interface WpUploaderStatic {
	prototype: WpUploaderInstance;
	queue: { add(model: WpcfBackboneModel): void };
	errors: {
		unshift(err: { message: string; data: unknown; file: WpcfPluploadFile }): void;
	};
}

interface WpMediaAttachmentStatic {
	create(attrs: Record<string, unknown>): WpcfBackboneModel;
	get(id: unknown, model: WpcfBackboneModel): WpcfBackboneModel;
}

interface WpApiFetchOptions {
	path: string;
	method?: string;
	data?: unknown;
}

type WpApiFetch = <T = any>(options: WpApiFetchOptions) => Promise<T>;

interface Wp {
	Uploader: WpUploaderStatic;
	apiFetch: WpApiFetch;
	media: {
		model: {
			Attachment: WpMediaAttachmentStatic;
			settings: { post: { id: number } };
		};
	};
}

/** Response from POST /wp-cloud-files/v1/presign. */
interface WpcfPresign {
	uploadUrl: string;
	key: string;
	name: string;
	type: string;
}

/** Localized config printed by Plugin::enqueueDirectUploadScript(). */
interface WpcfDirectUploadConfig {
	enabled?: boolean;
	minSize?: number | string;
}

interface Window {
	wp?: Wp;
	_: import('underscore').UnderscoreStatic;
	plupload: { FAILED: number; [key: string]: unknown };
	wpcfDirectUpload?: WpcfDirectUploadConfig;
}
