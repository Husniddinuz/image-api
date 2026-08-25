<?php

namespace Database\Factories;

use App\Enums\BlobStatus;
use App\Models\ImageBlob;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImageBlob> */
class ImageBlobFactory extends Factory
{
    protected $model = ImageBlob::class;

    public function definition(): array
    {
        $hash = hash('sha256', $this->faker->unique()->uuid());

        return [
            'content_hash' => $hash,
            'disk' => config('images.disk'),
            'path' => sprintf('images/blobs/%s/%s/%s.webp', substr($hash, 0, 2), substr($hash, 2, 2), $hash),
            'format' => 'webp',
            'mime_type' => 'image/webp',
            'width' => 800,
            'height' => 600,
            'bytes' => 40_000,
            'original_format' => 'png',
            'original_mime_type' => 'image/png',
            'original_bytes' => 120_000,
            'status' => BlobStatus::Ready,
            'reference_count' => 1,
        ];
    }
}
