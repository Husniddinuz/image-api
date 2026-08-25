<?php

namespace App\Jobs;

use App\Models\Image;
use App\Models\ImageBlob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the bytes behind a blob once nobody references them any more.
 *
 * The row is locked and the reference count re-checked inside the transaction,
 * so an upload racing the delete either re-uses the blob (and blocks the prune)
 * or arrives after it and stores the file again. Neither order loses data.
 */
class PruneImageBlob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $blobId)
    {
        $this->onQueue('images');
    }

    public function handle(): void
    {
        $deleted = DB::transaction(function () {
            $blob = ImageBlob::whereKey($this->blobId)->lockForUpdate()->first();

            if (! $blob || $blob->reference_count > 0) {
                return null;
            }

            // Belt and braces: trust the rows, not just the counter.
            if (Image::where('image_blob_id', $blob->getKey())->exists()) {
                return null;
            }

            $blob->delete();

            return $blob;
        });

        if ($deleted) {
            Storage::disk($deleted->disk)->delete($deleted->path);
        }
    }
}
