<?php

namespace App\Services\Images;

use App\Enums\BlobStatus;
use App\Models\ImageBlob;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncoderInterface;
use RuntimeException;
use Throwable;

/**
 * Re-encodes a stored blob into a modern, much smaller format.
 *
 * WebP at quality 82 is visually indistinguishable from the source for photos
 * and screenshots while typically cutting 25-35% off a JPEG and far more off a
 * PNG. EXIF is stripped (smaller, and it stops GPS coordinates from leaking).
 * If the re-encode does not actually win, the original bytes are kept.
 */
class ImageOptimizer
{
    public function __construct(
        private ImageManager $manager,
        private BlobPathResolver $paths,
    ) {}

    public function optimize(ImageBlob $blob): void
    {
        if ($blob->status === BlobStatus::Ready) {
            return;
        }

        $disk = Storage::disk($blob->disk);
        $source = $this->pullToTemporaryFile($disk, $blob->path);

        try {
            $this->rewrite($blob, $disk, $source);
        } finally {
            @unlink($source);
        }
    }

    private function rewrite(ImageBlob $blob, Filesystem $disk, string $source): void
    {
        $image = $this->manager->read($source);

        try {
            $image = $image->orient(); // Bake in the EXIF rotation before stripping it.
        } catch (Throwable) {
            // Not all sources carry orientation data; nothing to do.
        }

        if ($max = config('images.optimize.max_dimension')) {
            $image = $image->scaleDown(width: (int) $max, height: (int) $max);
        }

        $format = (string) config('images.optimize.format');
        $encoded = $image->encode($this->encoder($format));

        $target = tempnam(sys_get_temp_dir(), 'opt_');
        $encoded->save($target);

        try {
            $keepOriginal = config('images.optimize.keep_original_if_larger')
                && filesize($target) >= $blob->original_bytes;

            $keepOriginal
                ? $this->promoteOriginal($blob, $disk)
                : $this->promoteEncoded($blob, $disk, $target, $format, $image->width(), $image->height());
        } finally {
            @unlink($target);
        }
    }

    /** The re-encode did not pay off: keep the source bytes, just relocate them. */
    private function promoteOriginal(ImageBlob $blob, Filesystem $disk): void
    {
        $destination = $this->paths->optimized($blob->content_hash, $blob->original_format);

        if ($blob->path !== $destination) {
            $disk->delete($destination);
            $disk->move($blob->path, $destination);
        }

        $this->commit($blob, $disk, $destination, [
            'path' => $destination,
            'format' => $blob->original_format,
            'mime_type' => $blob->original_mime_type,
            'bytes' => $blob->original_bytes,
            'status' => BlobStatus::Ready->value,
            'failure_reason' => null,
        ]);
    }

    private function promoteEncoded(
        ImageBlob $blob,
        Filesystem $disk,
        string $localFile,
        string $format,
        int $width,
        int $height,
    ): void {
        $destination = $this->paths->optimized($blob->content_hash, $format);
        $previous = $blob->path;

        $stream = fopen($localFile, 'rb');

        try {
            $disk->writeStream($destination, $stream);
        } finally {
            is_resource($stream) && fclose($stream);
        }

        $this->commit($blob, $disk, $destination, [
            'path' => $destination,
            'format' => $format,
            'mime_type' => $this->mimeFor($format),
            'width' => $width,
            'height' => $height,
            'bytes' => (int) filesize($localFile),
            'status' => BlobStatus::Ready->value,
            'failure_reason' => null,
        ]);

        if ($previous !== $destination) {
            $disk->delete($previous); // Original is no longer referenced.
        }
    }

    /**
     * Applies the update only if the blob still exists. A delete that landed
     * mid-optimization would otherwise resurrect a row, or leave the freshly
     * written file behind forever.
     */
    private function commit(ImageBlob $blob, Filesystem $disk, string $written, array $attributes): void
    {
        $survived = DB::transaction(function () use ($blob, $attributes) {
            $current = ImageBlob::whereKey($blob->getKey())->lockForUpdate()->first();

            if (! $current) {
                return false;
            }

            $current->forceFill($attributes)->save();
            $blob->forceFill($attributes)->syncOriginal();

            return true;
        });

        if (! $survived) {
            $disk->delete($written);
        }
    }

    private function pullToTemporaryFile(Filesystem $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read blob at [{$path}].");
        }

        $temp = tempnam(sys_get_temp_dir(), 'src_');
        $handle = fopen($temp, 'wb');

        try {
            stream_copy_to_stream($stream, $handle);
        } finally {
            fclose($handle);
            is_resource($stream) && fclose($stream);
        }

        return $temp;
    }

    private function encoder(string $format): EncoderInterface
    {
        $quality = (int) config('images.optimize.quality');

        return match ($format) {
            'webp' => new WebpEncoder(quality: $quality, strip: true),
            'avif' => new AvifEncoder(quality: $quality, strip: true),
            'jpeg', 'jpg' => new JpegEncoder(quality: $quality, strip: true),
            'png' => new PngEncoder,
            default => throw new RuntimeException("Unsupported optimization format [{$format}]."),
        };
    }

    private function mimeFor(string $format): string
    {
        return match ($format) {
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'jpeg', 'jpg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }
}
