<?php

declare(strict_types=1);

namespace Avunu\WPCloudFiles\Tests\Integration;

use Avunu\WPCloudFiles\Tests\Support\WPTestCase;
use WP_REST_Request;

/**
 * The direct-upload endpoints, driven through the real REST server.
 *
 * @covers \Avunu\WPCloudFiles\DirectUpload\RestController
 */
final class RestDirectUploadTest extends WPTestCase
{
    private int $authorId;

    public function set_up(): void
    {
        parent::set_up();

        $this->authorId = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($this->authorId);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $route, array $body): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/wp-cloud-files/v1' . $route);
        foreach ($body as $name => $value) {
            $request->set_param($name, $value);
        }

        return rest_do_request($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function presign(string $filename = 'photo.jpg'): array
    {
        $response = $this->post('/presign', ['filename' => $filename]);

        $this->assertSame(200, $response->get_status(), 'presign should succeed for an author');

        /** @var array<string, mixed> $data */
        $data = $response->get_data();

        return $data;
    }

    // ---------------------------------------------------------------- //
    // presign                                                          //
    // ---------------------------------------------------------------- //

    public function testPresignRequiresUploadCapability(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertSame(403, $this->post('/presign', ['filename' => 'photo.jpg'])->get_status());
    }

    public function testPresignRejectsAnonymousRequests(): void
    {
        wp_set_current_user(0);

        $this->assertSame(401, $this->post('/presign', ['filename' => 'photo.jpg'])->get_status());
    }

    public function testPresignRejectsAnEmptyFilename(): void
    {
        $response = $this->post('/presign', ['filename' => '']);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('wpcf_bad_filename', $response->get_data()['code']);
    }

    public function testPresignRejectsADisallowedExtension(): void
    {
        $response = $this->post('/presign', ['filename' => 'shell.exe']);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('wpcf_disallowed_type', $response->get_data()['code']);
    }

    public function testPresignReturnsAKeyInTheUploadsLayout(): void
    {
        $data = $this->presign();

        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/photo\.jpg$#', $data['key']);
        $this->assertSame('image/jpeg', $data['type']);
        $this->assertNotSame('', $data['token']);
        $this->assertGreaterThan(time(), $data['expires']);
    }

    // ---------------------------------------------------------------- //
    // The security gap this endpoint pair used to have                 //
    // ---------------------------------------------------------------- //

    /**
     * The regression test for the whole change.
     *
     * Before the token existed, an author could name ANY pre-existing object in
     * the bucket and have it registered as their own attachment -- exposing it
     * on a public URL, and (because deleting an attachment deletes the object)
     * handing them an arbitrary-delete primitive over the bucket.
     */
    public function testCannotClaimAnObjectThisUserWasNeverPresignedFor(): void
    {
        $this->putObject('2026/08/someone-elses.pdf', 'confidential');

        $response = $this->post('/attachment', ['key' => '2026/08/someone-elses.pdf']);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('wpcf_bad_token', $response->get_data()['code']);
        $this->assertSame(
            [],
            get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids']),
            'no attachment may be created'
        );
    }

    public function testAForgedTokenIsRejected(): void
    {
        $this->putObject('2026/08/someone-elses.pdf', 'confidential');

        $response = $this->post('/attachment', [
            'key'     => '2026/08/someone-elses.pdf',
            'token'   => str_repeat('a', 64),
            'expires' => time() + 900,
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('wpcf_bad_token', $response->get_data()['code']);
    }

    /**
     * A token is bound to one key, so it cannot be lifted from a legitimate
     * upload and reused to claim a different object.
     */
    public function testATokenIssuedForOneKeyDoesNotUnlockAnother(): void
    {
        $presign = $this->presign();
        $this->putObject('2026/08/someone-elses.pdf', 'confidential');

        $response = $this->post('/attachment', [
            'key'     => '2026/08/someone-elses.pdf',
            'token'   => $presign['token'],
            'expires' => $presign['expires'],
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('wpcf_bad_token', $response->get_data()['code']);
    }

    /**
     * The token is bound to the user, so it is useless to anyone else even
     * within its lifetime.
     */
    public function testAnotherUsersTokenIsRejected(): void
    {
        $presign = $this->presign();
        $this->putObject($presign['key'], 'payload');

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));

        $response = $this->post('/attachment', [
            'key'     => $presign['key'],
            'token'   => $presign['token'],
            'expires' => $presign['expires'],
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('wpcf_bad_token', $response->get_data()['code']);
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $presign = $this->presign();
        $this->putObject($presign['key'], 'payload');

        $response = $this->post('/attachment', [
            'key'     => $presign['key'],
            'token'   => $presign['token'],
            'expires' => time() - 1,
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('wpcf_bad_token', $response->get_data()['code']);
    }

    public function testKeysOutsideTheUploadsLayoutAreRejected(): void
    {
        foreach (['backups/db.zip', 'a/b/c/d.jpg', '2026/08/../../etc/passwd'] as $key) {
            $response = $this->post('/attachment', ['key' => $key]);

            $this->assertSame(400, $response->get_status(), "key '{$key}' should be rejected");
            $this->assertSame('wpcf_bad_key', $response->get_data()['code']);
        }
    }

    // ---------------------------------------------------------------- //
    // The happy path                                                   //
    // ---------------------------------------------------------------- //

    public function testPresignThenUploadThenAttach(): void
    {
        $presign = $this->presign();

        // The browser's PUT, for real.
        $put = wp_remote_request($presign['uploadUrl'], [
            'method'  => 'PUT',
            'body'    => (string) file_get_contents(\Avunu\WPCloudFiles\Tests\Support\FixturePath::to('small.jpg')),
            'headers' => ['Content-Type' => $presign['type']],
        ]);

        $this->assertNotWPError($put);
        $this->assertSame(200, wp_remote_retrieve_response_code($put), 'the presigned PUT was rejected');

        $response = $this->post('/attachment', [
            'key'     => $presign['key'],
            'token'   => $presign['token'],
            'expires' => $presign['expires'],
            'title'   => 'A photo',
        ]);

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertSame('A photo', $data['title']);

        $id = (int) $data['id'];
        $this->assertSame($presign['key'], get_post_meta($id, '_wp_attached_file', true));
        $this->assertSame($presign['key'], get_post_meta($id, '_wpcf_direct_upload_key', true));
    }

    /**
     * filesize must come from S3, not from whatever the browser claimed.
     */
    public function testFilesizeIsReadFromTheObject(): void
    {
        $presign = $this->presign();
        $bytes = (string) file_get_contents(\Avunu\WPCloudFiles\Tests\Support\FixturePath::to('small.jpg'));
        $this->putObject($presign['key'], $bytes);

        $response = $this->post('/attachment', [
            'key'     => $presign['key'],
            'token'   => $presign['token'],
            'expires' => $presign['expires'],
            'size'    => 999999999,
        ]);

        $this->assertSame(200, $response->get_status());

        $meta = wp_get_attachment_metadata((int) $response->get_data()['id']);
        $this->assertSame(strlen($bytes), $meta['filesize'], 'a client-supplied size must be ignored');
    }

    public function testMissingObjectIsReported(): void
    {
        $presign = $this->presign();

        $response = $this->post('/attachment', [
            'key'     => $presign['key'],
            'token'   => $presign['token'],
            'expires' => $presign['expires'],
        ]);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('wpcf_missing_object', $response->get_data()['code']);
    }

    /**
     * Being allowed to upload does not imply being allowed to attach media to
     * someone else's post -- the same check core's attachment controller makes.
     */
    public function testCannotAttachToAPostThisUserCannotEdit(): void
    {
        $otherAuthor = self::factory()->user->create(['role' => 'author']);
        $draft = self::factory()->post->create([
            'post_author' => $otherAuthor,
            'post_status' => 'draft',
        ]);

        $presign = $this->presign();
        $this->putObject($presign['key'], 'payload');

        $response = $this->post('/attachment', [
            'key'     => $presign['key'],
            'token'   => $presign['token'],
            'expires' => $presign['expires'],
            'post'    => $draft,
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('rest_cannot_edit', $response->get_data()['code']);
    }

    public function testCanAttachToOwnPost(): void
    {
        $own = self::factory()->post->create([
            'post_author' => $this->authorId,
            'post_status' => 'draft',
        ]);

        $presign = $this->presign();
        $this->putObject($presign['key'], 'payload');

        $response = $this->post('/attachment', [
            'key'     => $presign['key'],
            'token'   => $presign['token'],
            'expires' => $presign['expires'],
            'post'    => $own,
        ]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame($own, (int) get_post((int) $response->get_data()['id'])->post_parent);
    }

    // ---------------------------------------------------------------- //
    // Collision handling                                               //
    // ---------------------------------------------------------------- //

    public function testCollidingKeysGetASuffix(): void
    {
        $first = $this->presign();
        $this->putObject($first['key'], 'one');

        $second = $this->presign();

        $this->assertNotSame($first['key'], $second['key'], 'an occupied key must not be handed out again');
        $this->assertStringContainsString('photo-1.jpg', $second['key']);
    }

    public function testCollisionSearchIsBounded(): void
    {
        // Occupy the plain name and the first 5 suffixes.
        $base = gmdate('Y/m') . '/photo';
        $this->putObject($base . '.jpg', 'x');
        for ($i = 1; $i <= 5; $i++) {
            $this->putObject("{$base}-{$i}.jpg", 'x');
        }

        $key = $this->presign()['key'];

        $this->assertSame("{$base}-6.jpg", $key, 'the search should walk to the first free suffix');
    }
}
