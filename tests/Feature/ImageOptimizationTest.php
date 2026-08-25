<?php

namespace Tests\Feature;

use App\Enums\BlobStatus;
use App\Jobs\OptimizeImageBlob;
use App\Models\ImageBlob;
use App\Services\Images\ImageOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesImageUploads;
use Tests\TestCase;

/**
 * The queue runs synchronously under phpunit, so these exercise the real
 * encoder end to end rather than asserting a job was dispatched.
 */
class ImageOptimizationTest extends TestCase
{
    use MakesImageUploads, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('images.disk'));
    }

    public function test_an_uploaded_png_is_re_encoded_to_webp_and_shrinks(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/images', ['image' => $this->pngUpload('noise.png', 640, 480)])->assertCreated();

        $blob = ImageBlob::sole();

        $this->assertSame(BlobStatus::Ready, $blob->status);
        $this->assertSame('webp', $blob->format);
        $this->assertSame('image/webp', $blob->mime_type);
        $this->assertSame('png', $blob->original_format);
        $this->assertLessThan($blob->original_bytes, $blob->bytes);
        $this->assertGreaterThan(0, $blob->savedRatio());
    }

    public function test_the_original_file_is_removed_once_the_optimized_one_exists(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/images', ['image' => $this->pngUpload('noise.png', 640, 480)])->assertCreated();

        $disk = Storage::disk(config('images.disk'));
        $files = $disk->allFiles();

        $this->assertCount(1, $files);
        $this->assertStringStartsWith(config('images.paths.optimized'), $files[0]);
        $this->assertSame($files[0], ImageBlob::sole()->path);
    }

    public function test_dimensions_survive_the_re_encode(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/images', ['image' => $this->pngUpload('noise.png', 321, 213)])->assertCreated();

        $blob = ImageBlob::sole();
        $this->assertSame(321, $blob->width);
        $this->assertSame(213, $blob->height);

        $decoded = getimagesizefromstring(Storage::disk($blob->disk)->get($blob->path));
        $this->assertSame([321, 213], [$decoded[0], $decoded[1]]);
    }

    public function test_the_original_is_kept_when_re_encoding_would_make_it_bigger(): void
    {
        // A noisy JPEG re-encoded as PNG is always larger, which is exactly the
        // case the optimizer has to refuse.
        config()->set('images.optimize.format', 'png');
        $this->actingAsApiUser();

        $this->postJson('/api/images', ['image' => $this->jpegUpload('noise.jpg', 640, 480)])->assertCreated();

        $blob = ImageBlob::sole();

        $this->assertSame(BlobStatus::Ready, $blob->status);
        $this->assertSame('jpg', $blob->format);
        $this->assertSame($blob->original_bytes, $blob->bytes);
        Storage::disk($blob->disk)->assertExists($blob->path);
        $this->assertCount(1, Storage::disk($blob->disk)->allFiles());
    }

    public function test_a_failed_optimization_leaves_the_original_servable(): void
    {
        $this->actingAsApiUser();
        $this->postJson('/api/images', ['image' => $this->pngUpload()])->assertCreated();

        $blob = ImageBlob::sole();

        // Simulate a worker that keeps dying on this blob.
        (new OptimizeImageBlob($blob->getKey()))->failed(new \RuntimeException('encoder exploded'));

        $blob->refresh();
        $this->assertSame(BlobStatus::Ready, $blob->status); // already optimized, untouched
        Storage::disk($blob->disk)->assertExists($blob->path);
    }

    public function test_a_pending_blob_that_never_optimizes_is_marked_failed_but_stays_readable(): void
    {
        $this->actingAsApiUser();
        $blob = ImageBlob::factory()->create(['status' => BlobStatus::Pending]);

        (new OptimizeImageBlob($blob->getKey()))->failed(new \RuntimeException('encoder exploded'));

        $blob->refresh();
        $this->assertSame(BlobStatus::Failed, $blob->status);
        $this->assertStringContainsString('encoder exploded', $blob->failure_reason);
        $this->assertSame($blob->original_format, 'png');
    }

    public function test_optimizing_a_pruned_blob_is_a_no_op(): void
    {
        $this->assertNull((new OptimizeImageBlob(999999))->handle(app(ImageOptimizer::class)));
    }
}
