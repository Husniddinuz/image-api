<?php

use App\Http\Controllers\DocsController;
use Illuminate\Support\Facades\Route;

/*
 * This is an API-only application; the only pages it serves are its own docs.
 * Swagger UI at /docs reads openapi.yaml straight from the repository root, and
 * the root route is a pointer to the surface rather than a stray 404.
 */
if (config('docs.enabled')) {
    $path = trim((string) config('docs.path'), '/');

    Route::get($path, [DocsController::class, 'index'])->name('docs.index');
    Route::get($path.'/openapi.yaml', [DocsController::class, 'spec'])->name('docs.spec');
}

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'documentation' => 'Swagger UI at /docs; the raw contract is openapi.yaml.',
    'documentation_ui' => config('docs.enabled') ? url(config('docs.path')) : null,
    'health' => url('/up'),
    'endpoints' => [
        'POST /api/auth/register',
        'POST /api/auth/login',
        'POST /api/auth/logout',
        'GET /api/auth/me',
        'POST /api/images',
        'GET /api/images',
        'GET /api/images/{id}',
        'GET /api/images/{id}/content',
        'DELETE /api/images/{id}',
    ],
]));
