<?php

use App\Http\Middleware\EnsureJsonBodyIsParsable;
use App\Http\Middleware\RejectOversizedUpload;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PostTooLargeException;

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

        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A body larger than post_max_size still reaches us as an exception on
        // some SAPIs; answer with the same honest status either way.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'The uploaded file is too large.',
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
