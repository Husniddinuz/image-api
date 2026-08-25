<?php

use App\Http\Middleware\EnsureJsonBodyIsParsable;
use App\Http\Middleware\RejectOversizedUpload;
use App\Support\UploadFailure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            RejectOversizedUpload::class,
            EnsureJsonBodyIsParsable::class,
        ]);

        // There is no login page to bounce a guest to. The skeleton's default
        // is redirectGuestsTo(route('login')), which the auth middleware
        // evaluates before the exception handler ever sees the request -- so
        // any caller that does not announce Accept: application/json (a browser
        // opening an image URL, say) got a 500 naming a route that will never
        // exist, instead of a 401.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // PHP discards a body larger than post_max_size before the framework
        // boots, and ValidatePostSize turns that into this exception. Note the
        // class is Laravel's own, not Symfony's -- they are unrelated types,
        // and catching the wrong one leaks a stack trace instead of a 413.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => UploadFailure::tooLarge(),
                ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }
        });

        // The default message names the Eloquent model and the missing id.
        // Ours must not: "not found" and "belongs to somebody else" have to be
        // indistinguishable, otherwise the API confirms that a foreign image
        // exists.
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Resource not found.',
                ], Response::HTTP_NOT_FOUND);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], Response::HTTP_UNAUTHORIZED);
            }
        });
    })->create();
