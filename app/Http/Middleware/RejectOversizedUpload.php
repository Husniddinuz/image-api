<?php

namespace App\Http\Middleware;

use App\Support\UploadFailure;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a body exceeds PHP's post_max_size, PHP throws away $_POST and $_FILES
 * before the framework boots, so validation would report a baffling "image is
 * required". Checking Content-Length up front turns that into an honest 413.
 */
class RejectOversizedUpload
{
    public function handle(Request $request, Closure $next): Response
    {
        $limitBytes = ((int) config('images.max_upload_kilobytes')) * 1024;

        // Multipart framing plus any extra fields; a small allowance keeps a
        // legitimate 5 MB file from being rejected for its envelope.
        $ceiling = $limitBytes + 1024 * 512;

        $length = (int) $request->server('CONTENT_LENGTH', 0);

        if ($length > $ceiling) {
            return response()->json([
                'message' => UploadFailure::tooLarge(),
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return $next($request);
    }
}
