<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Unit;

use Avunu\WPCloudFiles\CLI;
use Avunu\WPCloudFiles\Exclusions;
use Avunu\WPCloudFiles\Tests\Support\UnitTestCase;

/**
 * The search-replace patterns behind `wp wp-cloud-files migrate-urls`.
 *
 * Applied here exactly as `wp search-replace --regex --regex-delimiter=#`
 * applies them -- preg_replace('#' . $search . '#', $replace, $value) -- to
 * the shapes the database actually holds: plain URLs in post content, and
 * JSON-escaped URLs in block attributes and wp_font_face posts.
 *
 * @covers \Avunu\WPCloudFiles\CLI::urlReplacements
 */
final class MigrateUrlsReplacementTest extends UnitTestCase
{
    private const FROM = 'https://example.org/app/uploads/';
    private const TO = 'https://media.example.org/';

    /**
     * @param list<string> $prefixes
     */
    private function migrate(string $content, array $prefixes): string
    {
        foreach (CLI::urlReplacements(self::FROM, self::TO, $prefixes) as $replacement) {
            $content = $replacement['regex']
                ? (string) preg_replace('#' . $replacement['search'] . '#', $replacement['replace'], $content)
                : str_replace($replacement['search'], $replacement['replace'], $content);
        }

        return $content;
    }

    /** @return list<string> */
    private function prefixes(): array
    {
        // What a site would pass: S3_EXCLUDE_PREFIXES plus the non-attachment
        // directories `inventory` reports.
        return array_merge(Exclusions::prefixes(), ['cwicly/', 'fonts/']);
    }

    public function testAttachmentUrlsMoveToTheBucket(): void
    {
        $this->assertSame(
            '<img src="https://media.example.org/2026/08/photo.jpg">',
            $this->migrate('<img src="https://example.org/app/uploads/2026/08/photo.jpg">', $this->prefixes())
        );
    }

    public function testJsonEscapedAttachmentUrlsMoveToo(): void
    {
        $this->assertSame(
            '{"url":"https:\/\/media.example.org\/2026\/08\/photo.jpg"}',
            $this->migrate('{"url":"https:\/\/example.org\/app\/uploads\/2026\/08\/photo.jpg"}', $this->prefixes())
        );
    }

    /**
     * wp_font_face posts store their src as JSON (wp_json_encode escapes "/"),
     * and the font files stay on the local volume.
     */
    public function testFontLibraryUrlsStayLocal(): void
    {
        $fontFace = '{"fontFamily":"Inter","fontWeight":"400","src":"https:\/\/example.org\/app\/uploads\/fonts\/inter-400.woff2"}';

        $this->assertSame($fontFace, $this->migrate($fontFace, $this->prefixes()));

        $plain = '@font-face{src:url(https://example.org/app/uploads/fonts/inter-400.woff2)}';
        $this->assertSame($plain, $this->migrate($plain, $this->prefixes()));
    }

    public function testCwiclyStylesheetUrlsStayLocal(): void
    {
        $content = '<link href="https://example.org/app/uploads/cwicly/css/cc-post-12.css">';

        $this->assertSame($content, $this->migrate($content, $this->prefixes()));
    }

    public function testPrivateFileUrlsStayLocal(): void
    {
        $content = 'https://example.org/app/uploads/woocommerce_uploads/2026/08/album.zip '
            . 'https:\/\/example.org\/app\/uploads\/wpo_wcpdf_8c1f\/invoice.pdf';

        $this->assertSame($content, $this->migrate($content, $this->prefixes()));
    }

    public function testMixedContentIsSplitCorrectly(): void
    {
        $before = 'a https://example.org/app/uploads/2026/08/a.jpg '
            . 'b https://example.org/app/uploads/fonts/b.woff2 '
            . 'c https://example.org/app/uploads/fontsmith/c.jpg';

        $this->assertSame(
            'a https://media.example.org/2026/08/a.jpg '
            . 'b https://example.org/app/uploads/fonts/b.woff2 '
            . 'c https://media.example.org/fontsmith/c.jpg',
            $this->migrate($before, $this->prefixes()),
            '"fonts/" excludes the fonts directory, not every name starting with "fonts"'
        );
    }

    public function testTwoPassesPlainAndJsonEscaped(): void
    {
        $replacements = CLI::urlReplacements(self::FROM, self::TO, ['fonts/']);

        $this->assertCount(2, $replacements);
        $this->assertTrue($replacements[0]['regex']);
        $this->assertSame('https\://example\.org/app/uploads/(?!fonts/)', $replacements[0]['search']);
        $this->assertSame('https\:\\\\/\\\\/example\.org\\\\/app\\\\/uploads\\\\/(?!fonts\\\\/)', $replacements[1]['search']);
        $this->assertSame(
            'https:\\\\/\\\\/media.example.org\\\\/',
            $replacements[1]['replace'],
            'backslashes are escaped for preg_replace'
        );
    }

    public function testWithoutExclusionsTheReplacementIsLiteral(): void
    {
        $replacements = CLI::urlReplacements(self::FROM, self::TO, []);

        $this->assertSame(
            [
                ['search' => self::FROM, 'replace' => self::TO, 'regex' => false],
                ['search' => 'https:\/\/example.org\/app\/uploads\/', 'replace' => 'https:\/\/media.example.org\/', 'regex' => false],
            ],
            $replacements
        );
    }

    public function testADollarSignInTheTargetIsNotABackreference(): void
    {
        $replacements = CLI::urlReplacements(self::FROM, 'https://cdn.example.org/$1/', ['fonts/']);

        $this->assertSame(
            'https://cdn.example.org/$1/2026/a.jpg',
            (string) preg_replace(
                '#' . $replacements[0]['search'] . '#',
                $replacements[0]['replace'],
                'https://example.org/app/uploads/2026/a.jpg'
            )
        );
    }
}
