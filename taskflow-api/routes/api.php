<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\LogsController;

Route::get('/health', function () {
    try { \DB::connection()->getPdo(); $db = 'connected'; }
    catch (\Exception $e) { $db = 'error: ' . $e->getMessage(); }
    return response()->json(['status' => 'ok', 'db' => $db, 'version' => '2.0.0']);
});

Route::get('/webhooks/whatsapp', [WebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WebhookController::class, 'handle']);

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth:sanctum', 'throttle:300,1'])->group(function () {
    Route::apiResource('tasks', TaskController::class);
    Route::put('tasks/{task}/status', [TaskController::class, 'updateStatus']);
    Route::get('tasks/{task}/updates', [TaskController::class, 'updates']);

    Route::get('users/tree', [UserController::class, 'tree']);
    Route::apiResource('users', UserController::class);
    Route::get('users/{user}/tasks', [UserController::class, 'tasks']);
    Route::get('users/{user}/scores', [UserController::class, 'scores']);

    Route::apiResource('teams', TeamController::class);
    Route::post('teams/{team}/members', [TeamController::class, 'addMember']);

    Route::prefix('analytics')->group(function () {
        Route::get('/overview', [AnalyticsController::class, 'overview']);
        Route::get('/leaderboard', [AnalyticsController::class, 'leaderboard']);
        Route::get('/apix/{user}', [AnalyticsController::class, 'apix']);
        Route::get('/apix-trend', [AnalyticsController::class, 'apixTrend']);
        Route::get('/reports', [AnalyticsController::class, 'reports']);
    });

    Route::prefix('ai')->group(function () {
        Route::get('/insights', [AIController::class, 'insights']);
        Route::get('/reports/{type}', [AIController::class, 'report']);
    });

    Route::get('/logs', [LogsController::class, 'index']);
    Route::delete('/logs', [LogsController::class, 'destroy']);
});
