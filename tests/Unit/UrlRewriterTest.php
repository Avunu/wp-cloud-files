<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Unit;

use Avunu\WPCloudFiles\Tests\Support\Profile;
use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;
use Avunu\WPCloudFiles\UrlRewriter;
use Brain\Monkey;

/**
 * @covers \Avunu\WPCloudFiles\UrlRewriter
 */
final class UrlRewriterTest extends UnitTestCase
{
    private UrlRewriter $rewriter;

    protected function set_up(): void
    {
        parent::set_up();
        $this->stubUploadDir();
        $this->rewriter = new UrlRewriter();
    }

    private function cdn(string $path): string
    {
        $base = Profile::is(Profile::ROOT) ? 'https://cdn.test/uploads' : 'https://cdn.test';

        return $base . '/' . $path;
    }

    // ---------------------------------------------------------------- //
    // rewriteAttachmentUrl                                             //
    // ---------------------------------------------------------------- //

    public function testRewritesAnUploadsUrl(): void
    {
        $this->assertSame(
            $this->cdn('2026/08/photo.jpg'),
            $this->rewriter->rewriteAttachmentUrl('http://example.org/wp-content/uploads/2026/08/photo.jpg', 1)
        );
    }

    public function testLeavesNonUploadsUrlsAlone(): void
    {
        $url = 'http://example.org/wp-content/themes/x/img/logo.png';

        $this->assertSame($url, $this->rewriter->rewriteAttachmentUrl($url, 1));
    }

    public function testLeavesAnExternalUrlAlone(): void
    {
        $url = 'https://other.example.com/2026/08/photo.jpg';

        $this->assertSame($url, $this->rewriter->rewriteAttachmentUrl($url, 1));
    }

    /**
     * A sibling directory whose name merely starts with the uploads base must
     * not be treated as being inside it. Without a separator check,
     * `str_starts_with` matches and `substr($url, strlen($baseurl) + 1)` eats
     * the "-" instead of a "/", silently producing a 404ing CDN URL.
     *
     * MediaHandler::getOriginalFilePath() already does the equivalent job
     * correctly, against a trailingslashit()'d base and with no "+ 1".
     */
    public function testDoesNotRewriteASiblingDirectoryWithTheSamePrefix(): void
    {
        $url = 'http://example.org/wp-content/uploads-old/2026/08/photo.jpg';

        $this->assertSame($url, $this->rewriter->rewriteAttachmentUrl($url, 1));
    }

    public function testDoesNotRewriteThePrefixWhenItIsOnlyPartOfAFilename(): void
    {
        $url = 'http://example.org/wp-content/uploadsomething.jpg';

        $this->assertSame($url, $this->rewriter->rewriteAttachmentUrl($url, 1));
    }

    public function testTheBareBaseUrlIsNotRewrittenIntoAnEmptyKey(): void
    {
        $url = 'http://example.org/wp-content/uploads';

        $this->assertSame($url, $this->rewriter->rewriteAttachmentUrl($url, 1));
    }

    public function testTheBaseUrlWithATrailingSlashMapsToTheCdnRoot(): void
    {
        $this->assertSame(
            $this->cdn(''),
            $this->rewriter->rewriteAttachmentUrl('http://example.org/wp-content/uploads/', 1)
        );
    }

    /**
     * WordPress runs set_url_scheme() before this filter, so behind a proxy that
     * WP mis-detects, the incoming URL can be https while baseurl is http. The
     * prefix check then fails and the URL is silently left un-rewritten.
     * Documented rather than "fixed": changing it needs a scheme-normalisation
     * decision, not a one-line edit.
     */
    public function testSchemeMismatchIsNotRewritten(): void
    {
        $url = 'https://example.org/wp-content/uploads/2026/08/photo.jpg';

        $this->assertSame(
            $url,
            $this->rewriter->rewriteAttachmentUrl($url, 1),
            'known limitation: a scheme mismatch against baseurl bypasses rewriting'
        );
    }

    // ---------------------------------------------------------------- //
    // rewriteSrcsetUrls                                                //
    // ---------------------------------------------------------------- //

    public function testEmptySrcsetShortCircuitsBeforeTouchingUploadDir(): void
    {
        Monkey\Functions\expect('wp_upload_dir')->never();

        $this->assertSame([], $this->rewriter->rewriteSrcsetUrls([], [], '', [], 1));
    }

    public function testRewritesOnlyMatchingSrcsetEntriesAndPreservesShape(): void
    {
        $sources = [
            150 => ['url' => 'http://example.org/wp-content/uploads/2026/08/photo-150x150.jpg', 'descriptor' => 'w', 'value' => 150],
            300 => ['url' => 'http://example.org/wp-content/uploads/2026/08/photo-300x300.jpg', 'descriptor' => 'w', 'value' => 300],
            600 => ['url' => 'https://other.example.com/photo-600x600.jpg', 'descriptor' => 'w', 'value' => 600],
        ];

        $result = $this->rewriter->rewriteSrcsetUrls($sources, [], '', [], 1);

        $this->assertSame($this->cdn('2026/08/photo-150x150.jpg'), $result[150]['url']);
        $this->assertSame($this->cdn('2026/08/photo-300x300.jpg'), $result[300]['url']);
        $this->assertSame('https://other.example.com/photo-600x600.jpg', $result[600]['url'], 'external sources are untouched');

        $this->assertSame([150, 300, 600], array_keys($result), 'keys and order must be preserved');
        $this->assertSame('w', $result[150]['descriptor']);
        $this->assertSame(150, $result[150]['value']);
    }

    public function testSrcsetRespectsThePrefixBoundaryToo(): void
    {
        $sources = [
            150 => ['url' => 'http://example.org/wp-content/uploads-old/2026/08/photo-150x150.jpg', 'descriptor' => 'w', 'value' => 150],
        ];

        $result = $this->rewriter->rewriteSrcsetUrls($sources, [], '', [], 1);

        $this->assertSame($sources[150]['url'], $result[150]['url']);
    }

    // ---------------------------------------------------------------- //
    // rewriteContentUrls                                               //
    // ---------------------------------------------------------------- //

    public function testContentWithoutAnImgTagIsReturnedUnchanged(): void
    {
        $content = '<a href="http://example.org/wp-content/uploads/2026/08/report.pdf">report</a>';

        $this->assertSame(
            $content,
            $this->rewriter->rewriteContentUrls($content),
            'known limitation: the <img guard means links, <video> and <source> are never rewritten'
        );
    }

    public function testEmptyContentIsReturnedUnchanged(): void
    {
        $this->assertSame('', $this->rewriter->rewriteContentUrls(''));
    }
}
