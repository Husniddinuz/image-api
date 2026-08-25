<?php

namespace Tests\Feature;

use App\Enums\BlobStatus;
use App\Jobs\OptimizeImageBlob;
use App\Models\Image;
use App\Models\ImageBlob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesImageUploads;
use Tests\TestCase;

class ImageUploadTest extends TestCase
{
    use MakesImageUploads, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('images.disk'));
    }

    public function test_a_user_can_upload_a_png(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/images', ['image' => $this->pngUpload('sunset.png')]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'sunset.png')
            ->assertJsonPath('data.original.mime_type', 'image/png')
            ->assertJsonPath('meta.deduplicated', false)
            ->assertJsonStructure(['data' => [
                'id', 'name', 'content_url', 'status', 'format', 'mime_type',
                'width', 'height', 'bytes', 'original', 'compression', 'checksum', 'created_at',
            ]]);

        $blob = ImageBlob::sole();
        Storage::disk($blob->disk)->assertExists($blob->path);
        $this->assertSame(1, $blob->reference_count);
    }

    public function test_a_user_can_upload_a_jpeg(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/images', ['image' => $this->jpegUpload('beach.jpg')])
            ->assertCreated()
            ->assertJsonPath('data.original.mime_type', 'image/jpeg');
    }

    public function test_the_stored_path_is_derived_from_the_content_hash(): void
    {
        $this->actingAsApiUser();
        $upload = $this->pngUpload();
        $hash = hash_file('sha256', $upload->getRealPath());

        $this->postJson('/api/images', ['image' => $upload])->assertCreated();

        $blob = ImageBlob::sole();
        $this->assertSame($hash, $blob->content_hash);
        $this->assertStringContainsString(substr($hash, 0, 2).'/'.substr($hash, 2, 2), $blob->path);
    }

    public function test_it_rejects_a_file_type_other_than_png_or_jpeg(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/images', ['image' => $this->gifUpload()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->postJson('/api/images', ['image' => $this->upload('notes.txt', 'text/plain', 'hello')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->postJson('/api/images', ['image' => $this->upload('vector.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('image_blobs', 0);
    }

    public function test_it_rejects_a_non_image_disguised_as_a_png(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/images', ['image' => $this->spoofedUpload('payload.png')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('image_blobs', 0);
        $this->assertEmpty(Storage::disk(config('images.disk'))->allFiles());
    }

    public function test_it_rejects_a_file_larger_than_five_megabytes(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/images', ['image' => $this->oversizedUpload(5 * 1024 + 64)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('image_blobs', 0);
    }

    public function test_a_body_far_over_the_limit_is_refused_before_validation(): void
    {
        $this->actingAsApiUser();

        $this->call('POST', '/api/images', server: [
            'CONTENT_LENGTH' => (string) (20 * 1024 * 1024),
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(413);
    }

    public function test_the_upload_field_is_required(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/images', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_uploading_the_same_image_twice_reuses_one_record(): void
    {
        $this->actingAsApiUser();

        $first = $this->postJson('/api/images', ['image' => $this->pngUpload('first.png')])->assertCreated();
        $second = $this->postJson('/api/images', ['image' => $this->pngUpload('second.png')])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertTrue($second->json('meta.deduplicated'));
        $this->assertTrue($second->json('meta.already_owned'));

        $this->assertDatabaseCount('images', 1);
        $this->assertDatabaseCount('image_blobs', 1);
        $this->assertCount(1, Storage::disk(config('images.disk'))->allFiles());
        $this->assertSame(1, ImageBlob::sole()->reference_count);
    }

    public function test_two_users_uploading_the_same_image_share_a_single_file(): void
    {
        $ada = User::factory()->create();
        $grace = User::factory()->create();

        $this->actingAsApiUser($ada);
        $this->postJson('/api/images', ['image' => $this->pngUpload()])->assertCreated();

        $this->forgetResolvedUser()->actingAsApiUser($grace);
        $this->postJson('/api/images', ['image' => $this->pngUpload()])->assertCreated();

        $this->assertDatabaseCount('images', 2);
        $this->assertDatabaseCount('image_blobs', 1);
        $this->assertCount(1, Storage::disk(config('images.disk'))->allFiles());
        $this->assertSame(2, ImageBlob::sole()->reference_count);
    }

    public function test_different_images_are_stored_separately(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/images', ['image' => $this->pngUpload('a.png', seed: 1)])->assertCreated();
        $this->postJson('/api/images', ['image' => $this->pngUpload('b.png', seed: 99)])->assertCreated();

        $this->assertDatabaseCount('images', 2);
        $this->assertDatabaseCount('image_blobs', 2);
    }

    public function test_optimization_is_queued_rather_than_run_in_the_request(): void
    {
        Queue::fake();
        $this->actingAsApiUser();

        $this->postJson('/api/images', ['image' => $this->pngUpload()])
            ->assertCreated()
            ->assertJsonPath('data.status', BlobStatus::Pending->value);

        Queue::assertPushed(OptimizeImageBlob::class, 1);
    }

    public function test_a_deduplicated_upload_does_not_queue_another_optimization(): void
    {
        Queue::fake();
        $this->actingAsApiUser();

        $this->postJson('/api/images', ['image' => $this->pngUpload()])->assertCreated();
        $this->postJson('/api/images', ['image' => $this->pngUpload()])->assertOk();

        Queue::assertPushed(OptimizeImageBlob::class, 1);
    }

    public function test_an_explicit_name_overrides_the_client_filename(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/images', [
            'image' => $this->pngUpload('IMG_0042.png'),
            'name' => 'Sunset over Lisbon',
        ])->assertCreated()->assertJsonPath('data.name', 'Sunset over Lisbon');

        $this->assertSame('Sunset over Lisbon', Image::sole()->name);
    }
}
