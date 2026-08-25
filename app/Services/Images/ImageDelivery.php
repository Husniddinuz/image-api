<?php

namespace App\Services\Images;

use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands the actual bytes back to an authorised owner.
 *
 * Content addressing gives us a perfect validator for free: the digest cannot
 * change without the file changing, so responses are immutable and repeat
 * fetches cost a 304. On S3 the app steps out of the way entirely and redirects
 * to a short lived signed URL.
 */
class ImageDelivery
{
    public function respond(Request $request, Image $image): Response
    {
        $blob = $image->blob;
        $disk = Storage::disk($blob->disk);
        $filename = $this->filename($image->name, $blob->format);
        $download = $request->boolean('download');

        if (config('images.delivery.use_temporary_urls') && $this->supportsTemporaryUrls($blob->disk)) {
            return redirect()->away($disk->temporaryUrl(
                $blob->path,
                now()->addSeconds((int) config('images.delivery.temporary_url_ttl')),
                [
                    'ResponseContentType' => $blob->mime_type,
                    'ResponseContentDisposition' => $this->disposition($filename, $download),
                ],
            ));
        }

        $response = new StreamedResponse(function () use ($disk, $blob) {
            $stream = $disk->readStream($blob->path);

            abort_if(! is_resource($stream), 404, 'The stored image is no longer available.');

            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $blob->mime_type,
            'Content-Length' => (string) $blob->bytes,
            'Content-Disposition' => $this->disposition($filename, $download),
            // Never let a browser reinterpret user supplied bytes as markup.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);

        $response->setEtag($blob->content_hash.'-'.$blob->format);
        $response->setPrivate();          // Owner-only: no shared cache may keep it.
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');
        $response->isNotModified($request);

        return $response;
    }

    private function disposition(string $filename, bool $download): string
    {
        return HeaderUtils::makeDisposition(
            $download ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
            $filename,
            preg_replace('/[^\x20-\x7e]/', '_', $filename) ?: 'image',
        );
    }

    /** Re-extensions the stored name so it matches what we actually serve. */
    private function filename(string $name, string $format): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME) ?: 'image';

        return mb_substr($base, 0, 200).'.'.$format;
    }

    private function supportsTemporaryUrls(string $disk): bool
    {
        return method_exists(Storage::disk($disk), 'temporaryUrl')
            && config("filesystems.disks.{$disk}.driver") === 's3';
    }
}
