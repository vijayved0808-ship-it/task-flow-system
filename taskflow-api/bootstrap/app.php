<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
        $middleware->alias([
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        // Daily report to all employees at 7 PM IST
        $schedule->command('reports:daily')
            ->dailyAt('19:00')
            ->timezone('Asia/Kolkata')
            ->withoutOverlapping();

        // Auto-verify completed tasks every 10 min (2-hour rule)
        $schedule->command('tasks:auto-verify')
            ->everyTenMinutes()
            ->withoutOverlapping();

        // Cleanup expired WhatsApp media files every 30 min (2-hour TTL)
        $schedule->command('media:cleanup')
            ->everyThirtyMinutes()
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {})->create();
