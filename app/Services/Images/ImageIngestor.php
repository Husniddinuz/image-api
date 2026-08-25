<?php

namespace App\Services\Images;

use App\Enums\BlobStatus;
use App\Jobs\OptimizeImageBlob;
use App\Jobs\PruneImageBlob;
use App\Models\Image;
use App\Models\ImageBlob;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Turns an upload into (at most) one file on disk and exactly one owned record.
 *
 * The hot path is deliberately cheap: hash the temp file, one indexed lookup,
 * one insert. A repeat upload of known bytes never touches storage at all, and
 * the expensive re-encode is handed to a queue so the request returns fast
 * enough to absorb bursts well beyond 100k uploads/day.
 */
class ImageIngestor
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private BlobPathResolver $paths) {}

    public function ingest(User $user, UploadedFile $file, ?string $name = null): IngestionResult
    {
        // sha-256 is streamed off disk by PHP, so a 5 MB upload costs a few ms
        // and no meaningful memory.
        $hash = hash_file('sha256', $file->getRealPath());

        if ($hash === false) {
            throw new RuntimeException('Unable to hash the uploaded file.');
        }

        // The loop covers one narrow race: a pruner erasing this very blob
        // between our lookup and our claim. Losing that race just means storing
        // the bytes again on the next pass.
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            [$blob, $blobIsNew] = $this->resolveBlob($file, $hash);

            $image = $this->claim($user, $blob, $this->resolveName($name, $file));

            if (! $image) {
                continue;
            }

            if ($blobIsNew) {
                OptimizeImageBlob::dispatch($blob->getKey());
            }

            return new IngestionResult(
                image: $image->setRelation('blob', $blob->refresh()),
                storedNewBytes: $blobIsNew,
                createdRecord: $image->wasRecentlyCreated,
            );
        }

        throw new RuntimeException('Unable to store the image after repeated contention.');
    }

    /**
     * Registers this user as an owner of the blob.
     *
     * The blob row is locked for the duration, which is what serialises a claim
     * against a concurrent prune of the same bytes: whichever transaction gets
     * the lock first wins, and the other sees a consistent world. Returns null
     * when the blob was pruned first.
     */
    private function claim(User $user, ImageBlob $blob, string $name): ?Image
    {
        return DB::transaction(function () use ($user, $blob, $name) {
            if (! ImageBlob::whereKey($blob->getKey())->lockForUpdate()->exists()) {
                return null;
            }

            $image = Image::firstOrCreate(
                ['user_id' => $user->getKey(), 'image_blob_id' => $blob->getKey()],
                ['name' => $name],
            );

            if ($image->wasRecentlyCreated) {
                ImageBlob::whereKey($blob->getKey())->increment('reference_count');
            }

            return $image;
        });
    }

    /**
     * @return array{0: ImageBlob, 1: bool} the blob and whether we just stored its bytes
     */
    private function resolveBlob(UploadedFile $file, string $hash): array
    {
        if ($blob = ImageBlob::where('content_hash', $hash)->first()) {
            return [$blob, false]; // Deduplicated: these bytes already exist.
        }

        $disk = (string) config('images.disk');
        $extension = $this->canonicalExtension($file);
        $path = $this->paths->original($hash, $extension);

        // Writing before the insert is safe because the destination is derived
        // from the content itself: two racing uploads write identical bytes to
        // the same path, so whichever lands last is still correct.
        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        [$width, $height] = $this->dimensions($file);
        $now = Carbon::now();

        // insertOrIgnore lets the unique index arbitrate the race instead of a
        // lock, which matters when many workers ingest concurrently.
        $inserted = ImageBlob::query()->insertOrIgnore([
            'content_hash' => $hash,
            'disk' => $disk,
            'path' => $path,
            'format' => $extension,
            'mime_type' => (string) $file->getMimeType(),
            'width' => $width,
            'height' => $height,
            'bytes' => $file->getSize(),
            'original_format' => $extension,
            'original_mime_type' => (string) $file->getMimeType(),
            'original_bytes' => $file->getSize(),
            'status' => BlobStatus::Pending->value,
            'reference_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $blob = ImageBlob::where('content_hash', $hash)->firstOrFail();

        return [$blob, $inserted > 0];
    }

    /** Normalises jpg/JPEG/etc. to the extension implied by the real mime type. */
    private function canonicalExtension(UploadedFile $file): string
    {
        return match ($file->getMimeType()) {
            'image/png' => 'png',
            default => 'jpg',
        };
    }

    /** @return array{0: int, 1: int} */
    private function dimensions(UploadedFile $file): array
    {
        $info = @getimagesize($file->getRealPath());

        return [(int) ($info[0] ?? 0), (int) ($info[1] ?? 0)];
    }

    private function resolveName(?string $name, UploadedFile $file): string
    {
        $name = trim((string) ($name ?? $file->getClientOriginalName()));

        return mb_substr($name !== '' ? $name : 'image', 0, 255);
    }

    /**
     * Drops a user's claim on a blob and hands the now possibly orphaned bytes
     * to the pruner. The image row and the reference count move together so a
     * crash can never leave a blob referenced by nothing but its own counter.
     */
    public function forget(Image $image): void
    {
        $blobId = $image->image_blob_id;

        DB::transaction(function () use ($image, $blobId) {
            $image->delete();

            ImageBlob::whereKey($blobId)
                ->where('reference_count', '>', 0)
                ->decrement('reference_count');
        });

        PruneImageBlob::dispatch($blobId);
    }
}
