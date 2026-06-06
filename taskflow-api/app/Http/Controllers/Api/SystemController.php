<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * SystemController — triggers scheduled Artisan commands via HTTP.
 *
 * Designed for Render free tier (no shell, no cron service available).
 * Use an external free cron service (e.g., cron-job.org) to hit these
 * URLs on schedule. Protected by a shared secret in the X-System-Secret
 * header or ?secret= query parameter.
 *
 * Set SYSTEM_SECRET env var on Render (any random string).
 */
class SystemController extends Controller
{
    private function authorize(Request $request): ?JsonResponse
    {
        $expected = env('SYSTEM_SECRET', '');
        if (empty($expected)) {
            return response()->json([
                'error' => 'SYSTEM_SECRET env var is not configured on the server.',
            ], 503);
        }

        $provided = $request->header('X-System-Secret') ?? $request->query('secret', '');
        if (!hash_equals($expected, (string) $provided)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        return null;
    }

    /**
     * POST /api/system/run/reports-daily
     */
    public function runReportsDaily(Request $request): JsonResponse
    {
        if ($r = $this->authorize($request)) return $r;

        $exitCode = Artisan::call('reports:daily');
        return response()->json([
            'command'   => 'reports:daily',
            'exit_code' => $exitCode,
            'output'    => trim(Artisan::output()),
            'ran_at'    => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/system/run/auto-verify
     */
    public function runAutoVerify(Request $request): JsonResponse
    {
        if ($r = $this->authorize($request)) return $r;

        $exitCode = Artisan::call('tasks:auto-verify');
        return response()->json([
            'command'   => 'tasks:auto-verify',
            'exit_code' => $exitCode,
            'output'    => trim(Artisan::output()),
            'ran_at'    => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/system/run/media-cleanup
     */
    public function runMediaCleanup(Request $request): JsonResponse
    {
        if ($r = $this->authorize($request)) return $r;

        $exitCode = Artisan::call('media:cleanup');
        return response()->json([
            'command'   => 'media:cleanup',
            'exit_code' => $exitCode,
            'output'    => trim(Artisan::output()),
            'ran_at'    => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/system/run/finalize-stale-batches
     */
    public function runFinalizeStaleBatches(Request $request): JsonResponse
    {
        if ($r = $this->authorize($request)) return $r;

        $exitCode = Artisan::call('batches:finalize-stale');
        return response()->json([
            'command'   => 'batches:finalize-stale',
            'exit_code' => $exitCode,
            'output'    => trim(Artisan::output()),
            'ran_at'    => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/system/run/dispatch-schedules
     */
    public function runDispatchSchedules(Request $request): JsonResponse
    {
        if ($r = $this->authorize($request)) return $r;

        $exitCode = Artisan::call('schedules:dispatch');
        return response()->json([
            'command'   => 'schedules:dispatch',
            'exit_code' => $exitCode,
            'output'    => trim(Artisan::output()),
            'ran_at'    => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/system/health — public, no secret required.
     * Returns DB status + media + task counts so external monitoring works.
     */
    public function health(): JsonResponse
    {
        $checks = [];
        try {
            \DB::connection()->getPdo();
            $checks['db'] = 'ok';
        } catch (\Exception $e) {
            $checks['db'] = 'error: ' . $e->getMessage();
        }

        try {
            $checks['wa_media_table'] = \Schema::hasTable('wa_media') ? 'exists' : 'missing';
        } catch (\Exception $e) {
            $checks['wa_media_table'] = 'unknown';
        }

        $checks['secret_configured'] = !empty(env('SYSTEM_SECRET', ''));

        return response()->json([
            'status'  => 'ok',
            'version' => '4.0.0',
            'checks'  => $checks,
            'time'    => now()->toIso8601String(),
        ]);
    }
}
