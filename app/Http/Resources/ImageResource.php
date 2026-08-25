<?php

namespace App\Http\Resources;

use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Image */
class ImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $blob = $this->blob;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'content_url' => route('images.content', $this->id),

            // "pending" simply means the compressor has not run yet; the image
            // is downloadable either way.
            'status' => $blob->status->value,

            'format' => $blob->format,
            'mime_type' => $blob->mime_type,
            'width' => $blob->width,
            'height' => $blob->height,
            'bytes' => $blob->bytes,

            'original' => [
                'format' => $blob->original_format,
                'mime_type' => $blob->original_mime_type,
                'bytes' => $blob->original_bytes,
            ],

            'compression' => [
                'saved_bytes' => max(0, $blob->original_bytes - $blob->bytes),
                'saved_ratio' => $blob->savedRatio(),
            ],

            'checksum' => 'sha256:'.$blob->content_hash,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
