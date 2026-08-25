<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Extension and Content-Type are attacker controlled, and PHP's mime guess only
 * sniffs a handful of magic bytes. This rule actually asks the image decoder
 * what the file is, and refuses anything that is not a real PNG/JPEG or that
 * would blow up memory when decoded (a "decompression bomb").
 */
class SafeRasterImage implements ValidationRule
{
    private const TYPES = [
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_JPEG => 'image/jpeg',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The :attribute failed to upload.');

            return;
        }

        $info = @getimagesize($value->getRealPath());

        if ($info === false) {
            $fail('The :attribute is not a readable image.');

            return;
        }

        [$width, $height] = $info;
        $type = $info[2] ?? null;

        if (! isset(self::TYPES[$type]) || ! in_array(self::TYPES[$type], config('images.allowed_mime_types'), true)) {
            $fail('The :attribute must be a PNG or JPEG image.');

            return;
        }

        if ($width < 1 || $height < 1) {
            $fail('The :attribute has invalid dimensions.');

            return;
        }

        $maxSide = (int) config('images.max_side');
        $maxPixels = (int) config('images.max_pixels');

        if ($width > $maxSide || $height > $maxSide) {
            $fail("The :attribute may not be larger than {$maxSide}px on any side.");

            return;
        }

        if ($width * $height > $maxPixels) {
            $fail('The :attribute contains too many pixels to be processed safely.');
        }
    }
}
