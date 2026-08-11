# Findings from building the test suite

Bugs found while building the test suite, all confirmed by reading the source and reproduced by a test. **All twelve are now fixed**, each with regression coverage.

Items 1-6 were fixed as they were found. Items 7-12 needed design decisions rather than one-line edits, so they were filed first and resolved in a follow-up.

* * *

## Fixed while building the suite

### 1\. `UrlRewriter` rewrote sibling directories — `src/UrlRewriter.php`

`str_starts_with($url, $baseurl)` with `substr($url, strlen($baseurl) + 1)` had no separator check, so any path merely _starting_ with the uploads base was treated as being inside it, and the `+ 1` then ate a character that was not a slash.

Reproduced before the fix:

```
http://example.org/wp-content/uploads-old/2026/08/photo.jpg
  -> https://cdn.test/old/2026/08/photo.jpg          (404s)
http://example.org/wp-content/uploadsomething.jpg
  -> https://cdn.test/mething.jpg                    (404s)
```

Fixed by matching against a `trailingslashit()`'d base with no offset correction — the same shape `MediaHandler::getOriginalFilePath()` already used correctly. Covers both `rewriteAttachmentUrl()` and `rewriteSrcsetUrls()`. Tests: `tests/Unit/UrlRewriterTest.php`.

### 2\. Broken media-library icons — `src/ThumbnailHandler.php`

`wp_get_attachment_url()` returns `string|false`, and `false` reached `dirname()`, which coerced to `dirname('') === ''`. Every document thumbnail URL then came out site-root-relative (`/doc-150x150.jpg`). The `false` case is exactly what a failed direct upload leaves behind.

Now returns the response untouched when the URL cannot be resolved, so WordPress falls back to its generic document icon. Test: `tests/Unit/ThumbnailHandlerPrepareForJsTest.php`.

### 3\. DomPDF only worked in one directory name — `src/DocumentThumbnailer.php`

`setupDomPdf()` resolved `WP_PLUGIN_DIR . '/wp-cloud-files/vendor/dompdf/dompdf'`. Installed under any other folder name — or symlinked, as most dev setups and the test harness do — DomPDF was silently unconfigured and _every_ Word/Excel thumbnail failed, with only a `WP_DEBUG` log line as evidence.

Now resolved as `dirname(__DIR__) . '/vendor/dompdf/dompdf'`. Test: `DocumentThumbnailerTest::testConstructorWiresUpDomPdf`, which passes inside a Nix sandbox that has no `wp-cloud-files` directory at all.

### 4\. Activation hook allowed unsupported PHP — `index.php`

The hook checked `8.1` while the header and `composer.json` require `^8.3`. A PHP 8.2 site activated cleanly and then fatalled at runtime. Now checks `8.3`.

### 5\. `deleteFile()` reported failure whenever the object existed — `src/S3Client.php`

Flysystem's `Filesystem::delete()` returns `void`. Returning it straight from `deleteFile(): bool` yielded `null`, PHP raised a TypeError on the return type, and the method's own `catch (\Throwable)` swallowed it into `false` while logging an error.

Exactly inverted: deleting an object that existed reported failure, deleting one that did not exist reported success. Found by PHPStan, then reproduced:

```
S3 Delete error: S3Client::deleteFile(): Return value must be of type bool, null returned
```

Tests: `tests/Integration/S3ClientTest.php`.

### 6\. `enableReleaseAssets()` called on an untyped API — `index.php`

`getVcsApi()` is typed as the base `Api`; `enableReleaseAssets()` only exists on the GitHub/GitLab implementations. Now behind an `instanceof` rather than assuming the repository URL never changes host.

* * *

## Resolved

### 7\. PowerPoint thumbnails now render

`src/DocumentThumbnailer.php`

`processPresentation()` wrote a `.pptx` and handed it to `Imagick::readImage()`, which has no PPTX decoder — so this always threw. Presentations now go through the same PhpPresentation → DomPDF → Imagick path Word and Excel use; `phpoffice/phppresentation` ships a working `Writer\PDF\DomPDF` adapter and `dompdf/dompdf` was already a dependency. `processPresentation()` is deleted.

Only slide 1 is ever rasterized, but the HTML writer renders the whole deck and base64-inlines every image first, so `trimToFirstSlide()` drops the rest before conversion.

Upstream fidelity limits, documented rather than worked around: slide backgrounds, layout/master graphics and charts are dropped by the HTML writer, and the adapter hardcodes A4 landscape so 16:9 decks are clipped on the right. Legacy `.ppt` parses but throws `FeatureNotImplementedException` on some real-world files, which degrades to `null`.

Tests: `tests/Thumbnails/DocumentThumbnailerTest.php` renders committed `sample.pptx` and `sample.odp` fixtures produced by LibreOffice.

### 8\. Direct uploads are bound to the presign that issued them

`src/DirectUpload/RestController.php`

Nothing tied the `key` submitted to `/attachment` back to a presign, so any user with `upload_files` could name an arbitrary bucket object. Worse than first written up: because deleting an attachment deletes the underlying object, this was an **arbitrary-delete primitive** over everything under `S3_ROOT` — and `S3_ROOT` unset, which the README documents, means the whole bucket. It also served as an existence oracle and pulled arbitrary bucket content onto the web server for ImageMagick/Ghostscript to parse.

`presign()` now returns an HMAC token binding key + user + expiry, and `/attachment` rejects anything else with `wpcf_bad_token`. Stateless on purpose: a transient can be evicted mid-upload under an external object cache, and a nonce lives 12–24h against a 15-minute presign.

Three further parity gaps closed in the same change: `current_user_can('edit_post', $post)` before attaching to a post, `filesize` read from S3 rather than from the browser, and the key constrained to the uploads layout.

Tests: `tests/Integration/RestDirectUploadTest.php`. Verified against the pre-fix controller — 12 of them fail there, including the arbitrary-object claim.

### 9\. The collision loop is bounded

`RestController::uniqueKey()` walked S3 collisions with no iteration cap, each step a network HEAD. Capped at 100, then falls back to a random suffix. The `catch` also used to swallow every throwable and return the bare name, silently overwriting an existing object whenever S3 was briefly unhealthy; it now takes the random suffix too.

Still true, and commented as such: the loop reserves nothing, so concurrent presigns for the same filename can agree on a key. Closing that needs a reservation record.

### 10\. Subsize elision matches WordPress

`src/MediaHandler.php`

The plugin dropped a required size when the original was smaller in **either** dimension; `image_resize_dimensions()` skips only when it is smaller in **both** (and compares one dimension only when the other is zero). A 2000×500 panorama really does get a 1024×256 `large`. Since `uploadFile()` deletes the local original once metadata looks complete, calling it early could lose derivatives that WordPress was still writing.

`getRegisteredImageSizes()` now defers to core's `wp_get_registered_image_subsizes()`, which also honours the `intermediate_image_sizes` filter and resolves crop flags.

Tests: `tests/Unit/MediaHandlerSubsizeRuleTest.php`, including the cropped-thumbnail case that proves the rule cannot be conditional on `crop`.

### 11\. `rewriteContentUrls()` deleted

Commented out since the initial commit and never enabled. It ignored `S3_ROOT`, only fired when the content contained an `<img`, and lacked the prefix-boundary fix the other rewriters received. `wp wp-cloud-files migrate-urls` covers the use case with a one-time search-replace and no per-request cost.

### 12\. Documents render once, not once per size

`src/DocumentThumbnailer.php`, `src/ThumbnailHandler.php`, `src/CLI.php`

Four sizes meant four document loads, four PDF conversions and four Ghostscript rasterizations. `processToPdf()` is split into `renderToPdf()` and `rasterizePdf()`; the page is read and normalised once and each size is a clone-and-scale of that master. `ThumbnailHandler` and `CLI::regenerate_thumbnails()` now share one size table instead of keeping separate copies.

Also fixed: `mergeImageLayers()` returns a new handle and the old one was never cleared, leaking a full-resolution buffer per call.

Tests assert the render count is exactly 1 for a four-size run, rather than timing anything.

## Observations worth knowing

### Signed `Content-Type` is not necessarily enforced

`createPresignedPutUrl()` signs the Content-Type, but enforcement is the provider's choice. MinIO accepts a PUT whose Content-Type differs from the signed one and returns 200 (verified in `S3ClientTest`). The extension allowlist in `RestController::presign()` therefore constrains the *key*, not the bytes or the type the browser actually sends — which compounds finding 8.

* * *

## Known coverage gaps

-   **`assets/js/direct-upload.js` is untested.** It is an IIFE with no exports, so it needs either a small refactor or a browser-driven test. `npm run check:types` runs in CI, which is the cheap half.
-   **`CLI::migrate_urls()` is uncovered.** It ends in `WP_CLI::runcommand('search-replace …')`, so testing it meaningfully means asserting on the generated command string rather than its effect.
-   **PHPStan runs at level 5**, with 4 baselined entries. Level 6 is a reasonable follow-up; most of the delta is one shared attachment-metadata array shape.
