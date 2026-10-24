<?php

use App\Http\Controllers\Api\V1\AIController;
use App\Http\Controllers\Api\V1\AiKnowledgeController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\EmailController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IntegrationController;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1 (see bootstrap/app.php).
Route::get('/health', HealthController::class);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:auth-mail');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');

    // Opened from an email link in a browser; protected by the URL signature.
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:20,1'])
        ->name('verification.verify');

    // Browser redirects, not XHR: need the session/cookie "web" group for OAuth state.
    Route::middleware('web')->prefix('google')->group(function () {
        Route::get('/redirect', [GoogleAuthController::class, 'redirect']);
        Route::get('/callback', [GoogleAuthController::class, 'callback']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
        Route::put('/password', [AuthController::class, 'changePassword']);
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:auth-mail');
    });
});

Route::middleware('auth:sanctum')->prefix('email')->group(function () {
    Route::post('/test', [EmailController::class, 'test'])->middleware('throttle:email');
    Route::get('/logs', [EmailController::class, 'logs']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('ai')->middleware('throttle:ai')->group(function () {
        Route::post('/generate', [AIController::class, 'generate']);
        Route::get('/status', [AIController::class, 'status']);
        Route::get('/knowledge', [AiKnowledgeController::class, 'show']);
        Route::put('/knowledge', [AiKnowledgeController::class, 'update']);
        Route::delete('/knowledge', [AiKnowledgeController::class, 'destroy']);
    });

    Route::prefix('chat')->group(function () {
        Route::apiResource('conversations', ChatController::class);
        Route::post('conversations/{conversation}/messages', [ChatController::class, 'sendMessage'])
            ->middleware('throttle:chat');
    });
});

Route::middleware('auth:sanctum')->prefix('integrations')->group(function () {
    Route::get('/status', [IntegrationController::class, 'status']);
    Route::post('/{integration}/test', [IntegrationController::class, 'test'])->middleware('throttle:20,1');
});
