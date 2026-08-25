<?php

namespace App\Models;

use App\Enums\BlobStatus;
use Database\Factories\ImageBlobFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A deduplicated, content addressed binary. Never exposed directly by the API:
 * users only ever see their own {@see Image} records pointing at one of these.
 */
#[Fillable([
    'content_hash', 'disk', 'path', 'format', 'mime_type', 'width', 'height',
    'bytes', 'original_format', 'original_mime_type', 'original_bytes', 'status',
])]
class ImageBlob extends Model
{
    /** @use HasFactory<ImageBlobFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => BlobStatus::class,
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'original_bytes' => 'integer',
            'reference_count' => 'integer',
        ];
    }

    /** @return HasMany<Image, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    public function filesystem(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /** Bytes saved by re-encoding, as a 0..1 ratio of the original upload. */
    public function savedRatio(): float
    {
        if ($this->original_bytes <= 0) {
            return 0.0;
        }

        return round(max(0, $this->original_bytes - $this->bytes) / $this->original_bytes, 4);
    }
}
