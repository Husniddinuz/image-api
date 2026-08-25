<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage disk
    |--------------------------------------------------------------------------
    |
    | Disk that holds every uploaded blob. Local by default; point it at "s3"
    | (or any S3 compatible service such as MinIO) for a horizontally scalable
    | deployment. Nothing else in the code has to change.
    |
    */

    'disk' => env('IMAGES_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Path prefixes
    |--------------------------------------------------------------------------
    |
    | Blobs are content addressed and stored in a two level fan out derived
    | from the sha-256 digest (ab/cd/<hash>.webp) so a single directory never
    | holds more than a few thousand entries, even at 100k+ uploads per day.
    |
    */

    'paths' => [
        'originals' => 'images/originals',
        'optimized' => 'images/blobs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload constraints
    |--------------------------------------------------------------------------
    */

    'max_upload_kilobytes' => (int) env('IMAGES_MAX_UPLOAD_KB', 5 * 1024),

    'allowed_mime_types' => ['image/png', 'image/jpeg'],

    'allowed_extensions' => ['png', 'jpg', 'jpeg'],

    // Guards against decompression bombs: a 20 KB PNG can decode to gigabytes.
    'max_pixels' => (int) env('IMAGES_MAX_PIXELS', 50_000_000),

    'max_side' => (int) env('IMAGES_MAX_SIDE', 20_000),

    /*
    |--------------------------------------------------------------------------
    | Optimization
    |--------------------------------------------------------------------------
    |
    | Uploads are re-encoded to WebP, which is typically 25-35% smaller than
    | JPEG and far smaller than PNG at a visually indistinguishable quality.
    | If the re-encode ever comes out larger than the source (already tuned
    | JPEGs, tiny PNGs) the original bytes are kept instead.
    |
    */

    'optimize' => [
        'format' => env('IMAGES_FORMAT', 'webp'), // webp | avif | jpeg | png

        'quality' => (int) env('IMAGES_QUALITY', 82),

        // Optional downscale cap for the longest side. Null keeps the full
        // resolution, which is the default: "no significant quality loss".
        'max_dimension' => env('IMAGES_MAX_DIMENSION') ? (int) env('IMAGES_MAX_DIMENSION') : null,

        // Keep the source bytes when re-encoding does not actually save space.
        'keep_original_if_larger' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | When the disk can sign URLs (S3) the API redirects to a short lived
    | signed URL instead of proxying bytes through PHP, which keeps the app
    | servers free at high volume. Local disks always stream.
    |
    */

    'delivery' => [
        'use_temporary_urls' => (bool) env('IMAGES_USE_TEMPORARY_URLS', false),

        'temporary_url_ttl' => (int) env('IMAGES_TEMPORARY_URL_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (requests per minute)
    |--------------------------------------------------------------------------
    |
    | Sized for the "100k uploads per day" target: 240 uploads/min per user is
    | ~14k/hour from a single account, comfortably above any real client while
    | still capping a runaway script.
    |
    */

    'rate_limits' => [
        'api' => (int) env('IMAGES_RATE_API', 300),
        'uploads' => (int) env('IMAGES_RATE_UPLOADS', 240),
        'auth_ip' => (int) env('IMAGES_RATE_AUTH_IP', 20),
        'auth_account' => (int) env('IMAGES_RATE_AUTH_ACCOUNT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    'per_page' => (int) env('IMAGES_PER_PAGE', 25),

    'max_per_page' => (int) env('IMAGES_MAX_PER_PAGE', 100),

];
