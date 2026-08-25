<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:auth')
        ->name('auth.register');

    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:auth')
        ->name('auth.login');
});

/*
|--------------------------------------------------------------------------
| Private routes -- a valid bearer token is required for every one of them
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

    Route::get('images', [ImageController::class, 'index'])->name('images.index');

    Route::post('images', [ImageController::class, 'store'])
        ->middleware('throttle:uploads')
        ->name('images.store');

    Route::get('images/{image}', [ImageController::class, 'show'])->name('images.show');
    Route::get('images/{image}/content', [ImageController::class, 'content'])->name('images.content');
    Route::delete('images/{image}', [ImageController::class, 'destroy'])->name('images.destroy');
});
