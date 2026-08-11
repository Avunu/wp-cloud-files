# Findings from building the test suite

Bugs confirmed by reading the source and, where noted, reproduced by a test. The first four are **already fixed** with regression coverage. The rest are written up here for filing as issues — they need design decisions, not one-line edits, so they were left out of the test-suite change.

* * *

## Fixed (with regression tests)

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

## Open — recommended for filing

### 7\. PowerPoint thumbnails have never worked

`src/DocumentThumbnailer.php`, `processPresentation()`

The method writes a `.pptx` and hands it to `Imagick::readImage($path . '[0]')`. **ImageMagick has no PPTX decoder**, so this always throws and returns `null`. Pinned by `DocumentThumbnailerTest::testPresentationThumbnailsAreNotSupported` (`@group known-defect`, excluded from normal runs).

_Fix:_ route presentations through the same PhpPresentation → PDF → raster path the Word branch uses, rather than handing Office XML to ImageMagick.

### 8\. Any upload-capable user can claim any existing bucket object

`src/DirectUpload/RestController.php`, `createAttachment()`

Nothing ties the submitted `key` to a presign that _this_ user requested. A user with `upload_files` can post an arbitrary key and mint a WordPress attachment — with a public GUID — for any pre-existing object in the bucket whose extension is an allowed upload type.

_Fix:_ record issued presigns (user ID + key + expiry) in a transient and require a match in `createAttachment()`, or sign the key into an opaque token returned by `presign()`.

### 9\. Unbounded collision loop in a synchronous request

`src/DirectUpload/RestController.php`, `uniqueKey()`

The `while` over `fileExists()` has no attempt cap. N colliding objects means N sequential HEAD requests inside a REST request, and a provider that erroneously reports "exists" pins a PHP-FPM worker until `max_execution_time`.

_Fix:_ cap at ~100 attempts and fall back to a `uniqid()` suffix.

### 10\. Over-eager size elision can skip uploads

`src/MediaHandler.php`, `shouldHaveModernFormats()` / size-requirement logic

A required size is dropped when the original is smaller in **either** dimension, but WordPress generates a non-cropped size when the original is larger in **either** dimension. So `isMetadataComplete()` can return `true` while derivatives WordPress did generate never reach S3. Usually masked by the priority-999 hook re-running, which is why it has gone unnoticed.

### 11\. `rewriteContentUrls()` is dead code that would break `S3_ROOT` sites

`src/Plugin.php:64` (hook commented out), `src/UrlRewriter.php`

It uses raw `S3_PUBLIC_URL` instead of `getPublicUrl()`, so it ignores `S3_ROOT` and would emit 404ing URLs on any site that sets it. It also early-returns unless the content contains `<img`, so links, `<video>` and `<source>` would never be rewritten. Either fix all three issues before enabling it, or delete it.

### 12\. Document thumbnails do 4× the necessary work

`src/ThumbnailHandler.php`, `handleDocumentThumbnails()` — and the same pattern in `src/CLI.php`, `regenerate_thumbnails()`.

The loop calls `generateThumbnail()` once per size, so a `.docx` is fully re-converted to PDF and re-rasterised four times per upload. Converting once and scaling the result four times would be roughly 4× faster.

* * *

## Observations worth knowing

### Signed `Content-Type` is not necessarily enforced

`createPresignedPutUrl()` signs the Content-Type, but enforcement is the provider's choice. MinIO accepts a PUT whose Content-Type differs from the signed one and returns 200 (verified in `S3ClientTest`). The extension allowlist in `RestController::presign()` therefore constrains the *key*, not the bytes or the type the browser actually sends — which compounds finding 8.

* * *

## Known coverage gaps

-   **`assets/js/direct-upload.js` is untested.** It is an IIFE with no exports, so it needs either a small refactor or a browser-driven test. `npm run check:types` runs in CI, which is the cheap half.
-   **`CLI::migrate_urls()` is uncovered.** It ends in `WP_CLI::runcommand('search-replace …')`, so testing it meaningfully means asserting on the generated command string rather than its effect.
-   **PHPStan runs at level 5**, with 7 baselined entries. Level 6 is a reasonable follow-up; most of the delta is one shared attachment-metadata array shape.
