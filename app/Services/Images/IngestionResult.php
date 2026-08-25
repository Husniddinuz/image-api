<?php

namespace App\Services\Images;

use App\Models\Image;

readonly class IngestionResult
{
    public function __construct(
        public Image $image,
        /** False when the bytes were already on disk (deduplicated). */
        public bool $storedNewBytes,
        /** False when this user had already uploaded the very same image. */
        public bool $createdRecord,
    ) {}

    public function statusCode(): int
    {
        return $this->createdRecord ? 201 : 200;
    }
}
