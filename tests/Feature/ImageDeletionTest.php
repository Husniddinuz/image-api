<?php

namespace Tests\Feature;

use App\Jobs\PruneImageBlob;
use App\Models\Image;
use App\Models\ImageBlob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesImageUploads;
use Tests\TestCase;

class ImageDeletionTest extends TestCase
{
    use MakesImageUploads, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('images.disk'));
    }

    public function test_a_user_can_delete_their_image_and_the_file_goes_with_it(): void
    {
        $this->actingAsApiUser();
        $id = $this->postJson('/api/images', ['image' => $this->pngUpload()])->json('data.id');
        $blob = ImageBlob::sole();

        $this->deleteJson("/api/images/{$id}")->assertNoContent();

        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('image_blobs', 0);
        Storage::disk($blob->disk)->assertMissing($blob->path);
    }

    public function test_a_deleted_image_is_gone_from_every_endpoint(): void
    {
        $this->actingAsApiUser();
        $id = $this->postJson('/api/images', ['image' => $this->pngUpload()])->json('data.id');

        $this->deleteJson("/api/images/{$id}")->assertNoContent();

        $this->getJson("/api/images/{$id}")->assertNotFound();
        $this->get("/api/images/{$id}/content")->assertNotFound();
        $this->getJson('/api/images')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/images/{$id}")->assertNotFound();
    }

    public function test_deleting_one_users_copy_leaves_the_others_intact(): void
    {
        $ada = User::factory()->create();
        $grace = User::factory()->create();

        $this->actingAsApiUser($ada);
        $adasId = $this->postJson('/api/images', ['image' => $this->pngUpload()])->json('data.id');

        $this->forgetResolvedUser()->actingAsApiUser($grace);
        $gracesId = $this->postJson('/api/images', ['image' => $this->pngUpload()])->json('data.id');

        $blob = ImageBlob::sole();
        $this->assertSame(2, $blob->reference_count);

        $this->forgetResolvedUser()->actingAsApiUser($ada);
        $this->deleteJson("/api/images/{$adasId}")->assertNoContent();

        // The shared bytes must survive: Grace still owns a copy.
        $this->assertDatabaseCount('image_blobs', 1);
        $this->assertSame(1, $blob->fresh()->reference_count);
        Storage::disk($blob->disk)->assertExists($blob->path);

        $this->forgetResolvedUser()->actingAsApiUser($grace);
        $this->getJson("/api/images/{$gracesId}")->assertOk();

        // ...and disappear once the last owner lets go.
        $this->deleteJson("/api/images/{$gracesId}")->assertNoContent();
        $this->assertDatabaseCount('image_blobs', 0);
        Storage::disk($blob->disk)->assertMissing($blob->path);
    }

    public function test_a_user_cannot_delete_another_users_image(): void
    {
        $ada = User::factory()->create();
        $grace = User::factory()->create();
        $theirs = Image::factory()->create(['user_id' => $grace->id]);

        $this->actingAsApiUser($ada);

        $this->deleteJson("/api/images/{$theirs->id}")->assertNotFound();

        $this->assertDatabaseHas('images', ['id' => $theirs->id]);
    }

    public function test_a_re_upload_after_deletion_stores_the_file_again(): void
    {
        $this->actingAsApiUser();

        $id = $this->postJson('/api/images', ['image' => $this->pngUpload()])->json('data.id');
        $this->deleteJson("/api/images/{$id}")->assertNoContent();

        $this->postJson('/api/images', ['image' => $this->pngUpload()])->assertCreated();

        $blob = ImageBlob::sole();
        $this->assertSame(1, $blob->reference_count);
        Storage::disk($blob->disk)->assertExists($blob->path);
    }

    public function test_the_pruner_never_deletes_a_blob_that_is_still_referenced(): void
    {
        $image = Image::factory()->create();
        $blob = $image->blob;

        (new PruneImageBlob($blob->getKey()))->handle();

        $this->assertDatabaseHas('image_blobs', ['id' => $blob->getKey()]);
    }

    public function test_the_sweeper_queues_blobs_that_lost_their_last_reference(): void
    {
        $orphan = ImageBlob::factory()->create([
            'reference_count' => 0,
            'updated_at' => now()->subDay(),
        ]);
        $referenced = Image::factory()->create()->blob;

        $this->artisan('images:prune', ['--minutes' => 60])
            ->expectsOutputToContain('Queued 1 orphaned blob(s)')
            ->assertSuccessful();

        $this->assertDatabaseMissing('image_blobs', ['id' => $orphan->getKey()]);
        $this->assertDatabaseHas('image_blobs', ['id' => $referenced->getKey()]);
    }

    public function test_the_sweeper_ignores_recently_touched_blobs(): void
    {
        $fresh = ImageBlob::factory()->create(['reference_count' => 0, 'updated_at' => now()]);

        $this->artisan('images:prune', ['--minutes' => 60])->assertSuccessful();

        $this->assertDatabaseHas('image_blobs', ['id' => $fresh->getKey()]);
    }
}
