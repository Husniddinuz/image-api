<?php

namespace App\Jobs;

use App\Enums\BlobStatus;
use App\Models\ImageBlob;
use App\Services\Images\ImageOptimizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Compression is CPU bound and takes far longer than the upload itself, so it
 * runs off the request. Until it finishes the original bytes are already
 * stored and fully servable, which is what keeps the API responsive when
 * uploads arrive faster than they can be re-encoded.
 */
class OptimizeImageBlob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** One optimization per blob, no matter how many uploads reference it. */
    public int $uniqueFor = 3600;

    public function __construct(public int $blobId)
    {
        $this->onQueue('images');
    }

    public function uniqueId(): string
    {
        return (string) $this->blobId;
    }

    /** @return array<int, int> seconds to wait before each retry */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ImageOptimizer $optimizer): void
    {
        $blob = ImageBlob::find($this->blobId);

        if (! $blob) {
            return; // Pruned before we got to it.
        }

        $optimizer->optimize($blob);
    }

    /**
     * A failed re-encode is not a failed upload: the untouched original stays
     * in place and keeps being served, we simply stop retrying.
     */
    public function failed(?Throwable $exception): void
    {
        ImageBlob::whereKey($this->blobId)
            ->where('status', BlobStatus::Pending->value)
            ->update([
                'status' => BlobStatus::Failed->value,
                'failure_reason' => mb_substr((string) $exception?->getMessage(), 0, 1000),
            ]);
    }
}
