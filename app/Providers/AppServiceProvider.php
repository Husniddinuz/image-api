<?php

namespace App\Providers;

use App\Services\Images\BlobPathResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BlobPathResolver::class);

        // Imagick handles large images with less memory and better resampling;
        // GD is the fallback so the project runs on a stock PHP install.
        $this->app->singleton(ImageManager::class, fn () => new ImageManager(
            extension_loaded('imagick') ? new ImagickDriver : new GdDriver,
        ));
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // Per authenticated token, falling back to IP for anonymous calls.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            (int) config('images.rate_limits.api')
        )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Uploads are the expensive path: bound them per user so one client
        // cannot monopolise the encoders.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(
            (int) config('images.rate_limits.uploads')
        )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Credential stuffing protection: throttle per IP and per account.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute((int) config('images.rate_limits.auth_ip'))->by($request->ip()),
            Limit::perMinute((int) config('images.rate_limits.auth_account'))
                ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);
    }
}
