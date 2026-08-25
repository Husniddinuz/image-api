<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * PHP marks an upload invalid for reasons that have nothing to do with the file
 * the user picked -- most often a server limit set below the one this API
 * advertises. "Failed to upload" sends people looking at their image; naming
 * the directive sends them to the actual problem.
 */
class UploadFailure
{
    /**
     * One condition, one sentence: a body can be refused either by PHP (before
     * the framework boots) or by the Content-Length guard, and a client should
     * not have to tell those apart.
     */
    public static function tooLarge(): string
    {
        return sprintf(
            'The uploaded file may not be larger than %s MB.',
            round(((int) config('images.max_upload_kilobytes')) / 1024, 2),
        );
    }

    public static function describe(UploadedFile $file): string
    {
        $limit = (int) config('images.max_upload_kilobytes');

        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE => sprintf(
                "The :attribute exceeds this server's upload_max_filesize (%s). Raise it to at least %s in php.ini to accept the %s MB this API allows.",
                ini_get('upload_max_filesize'),
                self::human($limit),
                round($limit / 1024, 2),
            ),
            UPLOAD_ERR_FORM_SIZE => 'The :attribute exceeds the MAX_FILE_SIZE limit sent with the form.',
            UPLOAD_ERR_PARTIAL => 'The :attribute was only partially uploaded; please retry.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded for the :attribute.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary directory to receive uploads.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the :attribute to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension rejected the :attribute.',
            default => 'The :attribute failed to upload.',
        };
    }

    private static function human(int $kilobytes): string
    {
        return $kilobytes >= 1024
            ? rtrim(rtrim(number_format($kilobytes / 1024, 1), '0'), '.').'M'
            : $kilobytes.'K';
    }
}
