<?php

namespace App\Services\Images;

/**
 * Content addressed paths with a two level fan out.
 *
 *   images/blobs/3f/a9/3fa9c1...e7.webp
 *
 * At 100k uploads/day a flat directory would hold 36M entries within a year,
 * which most filesystems handle badly and most object browsers handle worse.
 * Two hex levels spread the same volume over 65k directories.
 */
class BlobPathResolver
{
    public function original(string $hash, string $extension): string
    {
        return $this->build((string) config('images.paths.originals'), $hash, $extension);
    }

    public function optimized(string $hash, string $extension): string
    {
        return $this->build((string) config('images.paths.optimized'), $hash, $extension);
    }

    public function directory(string $prefix, string $hash): string
    {
        return sprintf('%s/%s/%s', trim($prefix, '/'), substr($hash, 0, 2), substr($hash, 2, 2));
    }

    private function build(string $prefix, string $hash, string $extension): string
    {
        return sprintf('%s/%s.%s', $this->directory($prefix, $hash), $hash, ltrim($extension, '.'));
    }
}
