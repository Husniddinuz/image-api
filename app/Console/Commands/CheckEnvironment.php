<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * php.ini decides whether this API can honour its own advertised limits, and it
 * fails in ways that look like application bugs: a 5 MB upload rejected as
 * "failed to upload", or a JSON response prefixed with an HTML warning. This
 * turns those into one readable table before anyone hits them.
 */
class CheckEnvironment extends Command
{
    protected $signature = 'images:doctor';

    protected $description = 'Verify PHP and storage are configured to serve the API as advertised';

    private array $rows = [];

    private bool $failed = false;

    public function handle(): int
    {
        $this->checkImageEncoders();
        $this->checkExtensions();
        $this->checkUploadLimits();
        $this->checkErrorOutput();
        $this->checkMemory();
        $this->checkStorage();
        $this->checkQueue();

        $this->newLine();
        $this->table(['', 'Check', 'Detail'], $this->rows);
        $this->newLine();

        if ($this->failed) {
            $this->error('Some checks failed. The API will not behave as documented until they are fixed.');

            return self::FAILURE;
        }

        $this->info('Environment looks good.');

        return self::SUCCESS;
    }

    private function checkImageEncoders(): void
    {
        if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
            $this->problem('Image encoder', 'Neither gd nor imagick is loaded; uploads cannot be compressed.');

            return;
        }

        $format = (string) config('images.optimize.format');
        $driver = extension_loaded('imagick') ? 'imagick' : 'gd';

        if ($driver === 'gd') {
            $support = gd_info();
            $key = match ($format) {
                'webp' => 'WebP Support',
                'avif' => 'AVIF Support',
                'jpeg', 'jpg' => 'JPEG Support',
                default => 'PNG Support',
            };

            if (empty($support[$key])) {
                $this->problem('Image encoder', "gd is loaded but has no {$key}; IMAGES_FORMAT={$format} cannot be produced.");

                return;
            }
        }

        $this->ok('Image encoder', "{$driver}, can encode {$format}");
    }

    private function checkExtensions(): void
    {
        foreach (['fileinfo' => 'mime detection', 'exif' => 'orientation handling'] as $extension => $purpose) {
            extension_loaded($extension)
                ? $this->ok("ext-{$extension}", $purpose)
                : $this->caution("ext-{$extension}", "missing; {$purpose} degrades");
        }
    }

    private function checkUploadLimits(): void
    {
        $required = ((int) config('images.max_upload_kilobytes')) * 1024;
        $upload = $this->bytes(ini_get('upload_max_filesize'));
        $post = $this->bytes(ini_get('post_max_size'));

        $upload >= $required
            ? $this->ok('upload_max_filesize', ini_get('upload_max_filesize'))
            : $this->problem('upload_max_filesize', sprintf(
                '%s is below the %s this API accepts; larger uploads fail before validation.',
                ini_get('upload_max_filesize'),
                $this->human($required),
            ));

        $post > $upload
            ? $this->ok('post_max_size', ini_get('post_max_size'))
            : $this->problem('post_max_size', sprintf(
                '%s must exceed upload_max_filesize (%s) to leave room for multipart framing.',
                ini_get('post_max_size'),
                ini_get('upload_max_filesize'),
            ));
    }

    private function checkErrorOutput(): void
    {
        // Read the php.ini value, not the runtime one: Laravel turns display
        // errors off while booting, but the warning that corrupts a response is
        // emitted at request startup, before any of that has happened.
        $display = strtolower((string) get_cfg_var('display_errors'));

        in_array($display, ['', '0', 'off', 'stderr'], true)
            ? $this->ok('display_errors', $display === 'stderr' ? 'stderr' : 'off')
            : $this->caution('display_errors', 'on; PHP warnings are printed into response bodies and break JSON parsing.');
    }

    private function checkMemory(): void
    {
        $limit = $this->bytes(ini_get('memory_limit'));

        $limit === -1 || $limit >= 256 * 1024 * 1024
            ? $this->ok('memory_limit', (string) ini_get('memory_limit'))
            : $this->caution('memory_limit', ini_get('memory_limit').' may be tight: decoding a large image costs width * height * 4 bytes.');
    }

    private function checkStorage(): void
    {
        $disk = (string) config('images.disk');

        try {
            $probe = 'images/.doctor-'.bin2hex(random_bytes(4));
            Storage::disk($disk)->put($probe, 'ok');
            $readable = Storage::disk($disk)->get($probe) === 'ok';
            Storage::disk($disk)->delete($probe);

            $readable
                ? $this->ok("disk [{$disk}]", 'writable and readable')
                : $this->problem("disk [{$disk}]", 'wrote a probe file but could not read it back.');
        } catch (Throwable $e) {
            $this->problem("disk [{$disk}]", $e->getMessage());
        }
    }

    private function checkQueue(): void
    {
        $connection = (string) config('queue.default');

        $connection === 'sync'
            ? $this->caution('queue', 'sync: compression runs inside the upload request, which will not keep up under load.')
            : $this->ok('queue', $connection.' (remember to run a worker)');
    }

    private function ok(string $check, string $detail): void
    {
        $this->rows[] = ['<fg=green>PASS</>', $check, $detail];
    }

    private function caution(string $check, string $detail): void
    {
        $this->rows[] = ['<fg=yellow>WARN</>', $check, $detail];
    }

    private function problem(string $check, string $detail): void
    {
        $this->failed = true;
        $this->rows[] = ['<fg=red>FAIL</>', $check, $detail];
    }

    private function bytes(string|false $value): int
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function human(int $bytes): string
    {
        return round($bytes / 1048576, 2).'M';
    }
}
