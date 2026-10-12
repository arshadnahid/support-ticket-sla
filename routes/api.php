<?php

use App\Http\Controllers\Api\v1\Auth\AuthController;
use App\Http\Controllers\Api\v1\Ticket\CategoryController;
use App\Http\Controllers\Api\v1\Ticket\TicketAssignmentController;
use App\Http\Controllers\Api\v1\Ticket\TicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    // Not behind auth:api: an expired token can still be refreshed within jwt.refresh_ttl.
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth');

    Route::middleware(['auth:api', 'active'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

// Everything below needs a logged-in, enabled user. What each role may do with a ticket is
// decided per ticket by TicketPolicy, so no role: middleware is needed here.
Route::middleware(['auth:api', 'active'])->group(function () {
    Route::get('categories', [CategoryController::class, 'index']);

    Route::prefix('tickets')->controller(TicketController::class)->group(function () {
        // Fixed paths first, otherwise {ticket} would swallow "active" and "resolved".
        Route::get('active', 'active');
        Route::get('resolved', 'resolved');

        Route::get('/', 'index');
        Route::post('/', 'store');

        Route::get('{ticket}', 'show');
        Route::patch('{ticket}', 'update');
        Route::post('{ticket}/status', 'changeStatus');
        Route::post('{ticket}/resolve', 'resolve');
        Route::post('{ticket}/close', 'close');

        Route::post('{ticket}/assign', [TicketAssignmentController::class, 'assign']);
        Route::post('{ticket}/accept', [TicketAssignmentController::class, 'accept']);
    });
});
