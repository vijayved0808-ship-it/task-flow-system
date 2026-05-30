<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\AI\Services\AIService;
use App\Domain\Task\Models\Task;
use App\Domain\User\Models\User;

class AIController extends Controller
{
    public function __construct(private AIService $ai) {}

    public function insights()
    {
        $overdueTasks = Task::where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'verified'])
            ->with('assignedTo')
            ->limit(5)->get();

        $inactiveUsers = User::where('role', 'employee')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('last_seen_at')
                  ->orWhere('last_seen_at', '<', now()->subHours(24));
            })
            ->limit(5)->get();

        return response()->json([
            'overdue_tasks'    => $overdueTasks,
            'inactive_users'   => $inactiveUsers,
            'generated_at'     => now(),
        ]);
    }

    public function report(string $type)
    {
        if (!in_array($type, ['daily', 'weekly', 'monthly'])) {
            return response()->json(['error' => 'Invalid report type'], 422);
        }

        $report = $this->ai->generateReport($type);
        return response()->json($report);
    }
}
