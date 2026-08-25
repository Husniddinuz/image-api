<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\ImageBlob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesImageUploads;
use Tests\TestCase;

class ImageRetrievalTest extends TestCase
{
    use MakesImageUploads, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('images.disk'));
    }

    public function test_a_user_can_read_the_metadata_of_their_image(): void
    {
        $this->actingAsApiUser();
        $id = $this->postJson('/api/images', ['image' => $this->pngUpload('sunset.png')])->json('data.id');

        $this->getJson("/api/images/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'sunset.png')
            ->assertJsonPath('data.status', 'ready');
    }

    public function test_a_user_can_download_the_bytes(): void
    {
        $this->actingAsApiUser();
        $id = $this->postJson('/api/images', ['image' => $this->pngUpload('sunset.png')])->json('data.id');

        $response = $this->get("/api/images/{$id}/content")->assertOk();

        $response->assertHeader('content-type', 'image/webp');
        $response->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('inline; filename=sunset.webp', $response->headers->get('content-disposition'));

        $bytes = $response->streamedContent();
        $this->assertSame('image/webp', getimagesizefromstring($bytes)['mime']);
        $this->assertSame(ImageBlob::sole()->bytes, strlen($bytes));
    }

    public function test_the_bytes_can_be_requested_as_a_download(): void
    {
        $this->actingAsApiUser();
        $id = $this->postJson('/api/images', ['image' => $this->pngUpload('sunset.png')])->json('data.id');

        $this->get("/api/images/{$id}/content?download=1")
            ->assertOk()
            ->assertDownload('sunset.webp');
    }

    public function test_an_unchanged_image_is_answered_with_a_304(): void
    {
        $this->actingAsApiUser();
        $id = $this->postJson('/api/images', ['image' => $this->pngUpload()])->json('data.id');

        $etag = $this->get("/api/images/{$id}/content")->headers->get('etag');
        $this->assertNotNull($etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->get("/api/images/{$id}/content")
            ->assertStatus(304);
    }

    public function test_the_cache_headers_keep_the_image_private(): void
    {
        $this->actingAsApiUser();
        $id = $this->postJson('/api/images', ['image' => $this->pngUpload()])->json('data.id');

        $cacheControl = $this->get("/api/images/{$id}/content")->headers->get('cache-control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    public function test_another_users_image_is_indistinguishable_from_a_missing_one(): void
    {
        $ada = User::factory()->create();
        $grace = User::factory()->create();
        $theirs = Image::factory()->create(['user_id' => $grace->id]);

        $this->actingAsApiUser($ada);

        $foreign = $this->getJson("/api/images/{$theirs->id}")->assertNotFound();
        $missing = $this->getJson('/api/images/01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();

        $this->assertSame($missing->json(), $foreign->json());
        $this->assertSame(['message' => 'Resource not found.'], $foreign->json());

        $this->get("/api/images/{$theirs->id}/content")->assertNotFound();
    }

    public function test_the_content_url_in_the_payload_actually_works(): void
    {
        $this->actingAsApiUser();
        $url = $this->postJson('/api/images', ['image' => $this->pngUpload()])->json('data.content_url');

        $this->get($url)->assertOk()->assertHeader('content-type', 'image/webp');
    }
}
