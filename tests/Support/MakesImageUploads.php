<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Real encoded pixels, not Laravel's UploadedFile::fake()->image(), because the
 * upload path deliberately decodes what it is given -- a placeholder would not
 * survive validation, let alone compression.
 */
trait MakesImageUploads
{
    protected function pngUpload(string $name = 'photo.png', int $width = 320, int $height = 240, int $seed = 1): UploadedFile
    {
        return $this->upload($name, 'image/png', $this->encode('png', $width, $height, $seed));
    }

    protected function jpegUpload(string $name = 'photo.jpg', int $width = 320, int $height = 240, int $seed = 1): UploadedFile
    {
        return $this->upload($name, 'image/jpeg', $this->encode('jpeg', $width, $height, $seed));
    }

    protected function gifUpload(string $name = 'animation.gif'): UploadedFile
    {
        $canvas = imagecreatetruecolor(20, 20);
        ob_start();
        imagegif($canvas);
        $binary = (string) ob_get_clean();
        imagedestroy($canvas);

        return $this->upload($name, 'image/gif', $binary);
    }

    /** A text file wearing a .png extension and an image Content-Type. */
    protected function spoofedUpload(string $name = 'evil.png'): UploadedFile
    {
        return $this->upload($name, 'image/png', "<?php echo 'not an image'; ?>\n".str_repeat('A', 512));
    }

    protected function oversizedUpload(int $kilobytes): UploadedFile
    {
        return $this->upload('huge.png', 'image/png', random_bytes($kilobytes * 1024));
    }

    protected function upload(string $name, string $mime, string $binary): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl_');
        file_put_contents($path, $binary);

        // test mode = true skips the is_uploaded_file() check, and keeps the
        // real client-provided name and mime type we want to exercise.
        return new UploadedFile($path, $name, $mime, null, true);
    }

    /**
     * A smooth gradient with per-pixel noise: compresses like a photograph
     * rather than like a flat graphic, so the optimizer is measured against a
     * realistic worst case instead of an artificially tiny PNG.
     */
    protected function encode(string $format, int $width, int $height, int $seed): string
    {
        $canvas = imagecreatetruecolor($width, $height);
        mt_srand($seed);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $r = min(255, max(0, intdiv($x * 255, $width) + mt_rand(-14, 14)));
                $g = min(255, max(0, intdiv($y * 255, $height) + mt_rand(-14, 14)));
                $b = min(255, max(0, (($x + $y + $seed) % 256) + mt_rand(-14, 14)));

                imagesetpixel($canvas, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }

        ob_start();
        $format === 'png' ? imagepng($canvas, null, 9) : imagejpeg($canvas, null, 92);
        $binary = (string) ob_get_clean();
        imagedestroy($canvas);

        return $binary;
    }
}
