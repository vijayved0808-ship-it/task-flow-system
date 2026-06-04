<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskUpdate;
use App\Domain\User\Models\User;
use App\Domain\Task\Models\ApixScore;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function overview()
    {
        $totalTasks = Task::count();
        $openTasks = Task::whereNotIn('status', ['completed', 'verified', 'rejected'])->count();
        $completedTasks = Task::whereIn('status', ['completed', 'verified'])->count();
        $overdueTasks = Task::where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'verified'])->count();
        $activeEmployees = User::where('is_active', true)->count();
        $completedToday = Task::whereIn('status', ['completed', 'verified'])
            ->whereDate('completed_at', today())->count();

        // Team APIX (average of all active users' latest APIX)
        $teamApix = ApixScore::whereDate('score_date', today())
            ->avg('apix_score') ?? 0;

        // Compare with last week
        $lastWeekApix = ApixScore::whereDate('score_date', today()->subDays(7))
            ->avg('apix_score') ?? 0;
        $apixDelta = round($teamApix - $lastWeekApix, 1);

        // Weekly trend (last 7 days completed counts)
        $weeklyTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $count = Task::whereIn('status', ['completed', 'verified'])
                ->whereDate('completed_at', $date)
                ->count();
            $weeklyTrend[] = $count;
        }

        // Task status breakdown
        $statusBreakdown = [
            'in_progress' => Task::where('status', 'in_progress')->count(),
            'completed'   => Task::whereIn('status', ['completed', 'verified'])->count(),
            'overdue'     => $overdueTasks,
            'assigned'    => Task::where('status', 'assigned')->count(),
        ];

        // Recent WhatsApp activity (last 4 task updates)
        $recentActivity = TaskUpdate::with(['user:id,name', 'task:id,title'])
            ->latest()
            ->take(4)
            ->get()
            ->map(function ($update) {
                return [
                    'user_name'  => $update->user?->name ?? 'Unknown',
                    'user_initials' => $update->user ? collect(explode(' ', $update->user->name))->map(fn($w) => $w[0] ?? '')->take(2)->join('') : 'U',
                    'action'     => 'sent ' . strtoupper($update->command) . ' for',
                    'task_title' => $update->task?->title ?? 'Unknown task',
                    'time'       => $update->created_at->diffForHumans(),
                    'color'      => match($update->command) {
                        'complete' => '#10b981',
                        'update'   => '#3b82f6',
                        'delay'    => '#f59e0b',
                        'escalate' => '#ef4444',
                        'start'    => '#06b6d4',
                        default    => '#64748b',
                    },
                ];
            });

        return response()->json([
            'total_tasks'       => $totalTasks,
            'open_tasks'        => $openTasks,
            'completed_tasks'   => $completedTasks,
            'overdue_tasks'     => $overdueTasks,
            'completed_today'   => $completedToday,
            'active_employees'  => $activeEmployees,
            'team_apix'         => round($teamApix, 1),
            'apix_delta'        => $apixDelta,
            'weekly_trend'      => $weeklyTrend,
            'status_breakdown'  => $statusBreakdown,
            'recent_activity'   => $recentActivity,
        ]);
    }

    public function leaderboard()
    {
        $users = User::where('is_active', true)
            ->where('role', '!=', 'admin')
            ->get()
            ->map(function ($user) {
                $latestScore = ApixScore::where('user_id', $user->id)
                    ->orderByDesc('score_date')->first();
                $apix = $latestScore?->apix_score ?? 0;

                return [
                    'id'        => $user->id,
                    'name'      => $user->name,
                    'first_name' => explode(' ', $user->name)[0],
                    'initials'  => collect(explode(' ', $user->name))->map(fn($w) => $w[0] ?? '')->take(2)->join(''),
                    'apix'      => round($apix, 1),
                    'phone'     => $user->phone,
                    'role'      => $user->role,
                    'designation' => $user->designation,
                ];
            })
            ->sortByDesc('apix')
            ->values()
            ->take(10);

        return response()->json($users);
    }

    public function reports(Request $request)
    {
        $type = $request->input('type', 'daily');

        if ($type === 'daily') {
            $start = today();
            $assigned = Task::whereDate('created_at', $start)->count();
            $completed = Task::whereIn('status', ['completed', 'verified'])->whereDate('completed_at', $start)->count();
            $overdue = Task::where('due_date', '<', now())->whereNotIn('status', ['completed', 'verified'])->count();
            $teamApix = ApixScore::whereDate('score_date', $start)->avg('apix_score') ?? 0;

            return response()->json([
                'type' => 'daily',
                'label' => $start->format('M d, Y'),
                'stats' => [
                    'tasks_assigned'   => $assigned,
                    'tasks_completed'  => $completed,
                    'overdue'          => $overdue,
                    'team_apix'        => round($teamApix, 1),
                ],
            ]);
        }

        if ($type === 'weekly') {
            $start = now()->startOfWeek();
            $assigned = Task::whereBetween('created_at', [$start, now()])->count();
            $completed = Task::whereIn('status', ['completed', 'verified'])
                ->whereBetween('completed_at', [$start, now()])->count();
            $onTime = Task::whereIn('status', ['completed', 'verified'])
                ->whereBetween('completed_at', [$start, now()])
                ->whereColumn('completed_at', '<=', 'due_date')
                ->count();
            $onTimeRate = $completed > 0 ? round(($onTime / $completed) * 100, 0) : 0;
            $avgApix = ApixScore::whereBetween('score_date', [$start, now()])->avg('apix_score') ?? 0;
            $waResponses = TaskUpdate::whereBetween('created_at', [$start, now()])->count();
            $waRequests = Task::whereBetween('created_at', [$start, now()])->count();
            $waRate = $waRequests > 0 ? round(($waResponses / max($waRequests, 1)) * 100, 0) : 0;

            return response()->json([
                'type' => 'weekly',
                'label' => $start->format('M d') . ' - ' . now()->format('M d'),
                'stats' => [
                    'total_completed' => $completed,
                    'on_time_rate'    => $onTimeRate,
                    'avg_apix'        => round($avgApix, 1),
                    'wa_response_rate' => $waRate,
                ],
            ]);
        }

        if ($type === 'monthly') {
            $start = now()->startOfMonth();
            $completed = Task::whereIn('status', ['completed', 'verified'])
                ->whereBetween('completed_at', [$start, now()])->count();
            $assigned = Task::whereBetween('created_at', [$start, now()])->count();
            $kpi = $assigned > 0 ? round(($completed / $assigned) * 100, 0) : 0;

            // Best team — find team with most completed
            $bestTeam = User::where('is_active', true)
                ->whereNotNull('department')
                ->whereIn('id', function ($q) use ($start) {
                    $q->select('assigned_to')->from('tasks')
                        ->whereIn('status', ['completed', 'verified'])
                        ->whereBetween('completed_at', [$start, now()]);
                })
                ->groupBy('department')
                ->selectRaw('department, COUNT(*) as cnt')
                ->orderByDesc('cnt')
                ->first()?->department ?? 'Sales';

            $lastMonth = now()->subMonth()->startOfMonth();
            $lastMonthEnd = now()->subMonth()->endOfMonth();
            $lastMonthCompleted = Task::whereIn('status', ['completed', 'verified'])
                ->whereBetween('completed_at', [$lastMonth, $lastMonthEnd])->count();
            $improvement = $lastMonthCompleted > 0
                ? round((($completed - $lastMonthCompleted) / $lastMonthCompleted) * 100, 0)
                : 0;

            return response()->json([
                'type' => 'monthly',
                'label' => $start->format('M Y'),
                'stats' => [
                    'kpi_achievement'  => $kpi,
                    'tasks_completed'  => $completed,
                    'best_team'        => $bestTeam,
                    'improvement'      => $improvement,
                ],
            ]);
        }

        return response()->json(['error' => 'Invalid type'], 400);
    }

    public function apixTrend()
    {
        // Get top 3 performers' APIX history (last 7 days)
        $topUsers = User::where('is_active', true)
            ->where('role', '!=', 'admin')
            ->get()
            ->map(function ($u) {
                $latest = ApixScore::where('user_id', $u->id)->latest('score_date')->first();
                return ['user' => $u, 'apix' => $latest?->apix_score ?? 0];
            })
            ->sortByDesc('apix')
            ->take(3);

        $result = [];
        foreach ($topUsers as $entry) {
            $user = $entry['user'];
            $history = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = today()->subDays($i);
                $score = ApixScore::where('user_id', $user->id)
                    ->whereDate('score_date', $date)->first();
                $history[] = $score?->apix_score ?? 0;
            }
            $result[] = [
                'name'       => $user->name,
                'first_name' => explode(' ', $user->name)[0],
                'history'    => $history,
            ];
        }

        return response()->json($result);
    }

    public function apix(User $user)
    {
        return response()->json(
            $user->apixScores()->orderByDesc('score_date')->take(30)->get()
        );
    }
}
