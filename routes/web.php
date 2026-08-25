<?php

use Illuminate\Support\Facades\Route;

/*
 * There is no web UI: this is an API-only application. The root route is a
 * pointer to the documented surface rather than a stray 404.
 */
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'documentation' => 'See README.md and openapi.yaml in the repository.',
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
