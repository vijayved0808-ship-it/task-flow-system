<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Task\Models\Task;
use App\Domain\User\Models\User;
use App\Domain\Task\Models\ApixScore;

class AIController extends Controller
{
    public function insights()
    {
        $insights = [];

        // 1. INACTIVE USERS — no task activity in 18+ hours
        $inactive = User::where('is_active', true)
            ->where('role', '!=', 'admin')
            ->where(function ($q) {
                $q->where('last_seen_at', '<', now()->subHours(18))
                    ->orWhereNull('last_seen_at');
            })
            ->whereHas('assignedTasks', function ($q) {
                $q->whereNotIn('status', ['completed', 'verified', 'rejected']);
            })
            ->first();

        if ($inactive) {
            $overdueCount = Task::where('assigned_to', $inactive->id)
                ->where('due_date', '<', now())
                ->whereNotIn('status', ['completed', 'verified'])->count();
            $insights[] = [
                'type' => 'alert',
                'icon' => '⚠️',
                'title' => "{$inactive->name} inactive",
                'desc'  => "No task activity in 18+ hours. {$overdueCount} task" . ($overdueCount > 1 ? 's' : '') . " overdue.",
                'action' => 'Send nudge',
                'user_id' => $inactive->id,
            ];
        }

        // 2. AT-RISK TASKS — overdue critical tasks
        $atRisk = Task::with('assignedTo:id,name')
            ->where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'verified', 'rejected'])
            ->where('priority', '!=', 'low')
            ->orderByDesc('due_date')
            ->first();

        if ($atRisk) {
            $hoursOverdue = $atRisk->due_date ? now()->diffInHours($atRisk->due_date) : 0;
            $insights[] = [
                'type' => 'risk',
                'icon' => '🚨',
                'title' => "T" . substr($atRisk->id, 0, 4) . " at risk of missing SLA",
                'desc'  => "\"{$atRisk->title}\" overdue by {$hoursOverdue} hours. Assignee: {$atRisk->assignedTo?->name}",
                'action' => 'Escalate',
                'task_id' => $atRisk->id,
            ];
        }

        // 3. TOP PERFORMER — highest APIX
        $topUser = User::where('is_active', true)
            ->where('role', '!=', 'admin')
            ->get()
            ->map(function ($u) {
                $latest = ApixScore::where('user_id', $u->id)->latest('score_date')->first();
                return ['user' => $u, 'apix' => $latest?->apix_score ?? 0];
            })
            ->sortByDesc('apix')
            ->first();

        if ($topUser && $topUser['apix'] > 0) {
            $u = $topUser['user'];
            $weeklyDone = Task::where('assigned_to', $u->id)
                ->whereIn('status', ['completed', 'verified'])
                ->where('completed_at', '>=', now()->subDays(7))->count();
            $insights[] = [
                'type' => 'star',
                'icon' => '🏆',
                'title' => "{$u->name} top performer",
                'desc'  => round($topUser['apix'], 1) . " APIX score. {$weeklyDone} tasks done this week. Consider recognition.",
                'action' => 'Send praise',
                'user_id' => $u->id,
            ];
        }

        // 4. SALES TEAM PERFORMANCE INSIGHT — if any team underperforming
        $teamStats = User::where('is_active', true)
            ->whereNotNull('department')
            ->get()
            ->groupBy('department')
            ->map(function ($users) {
                $totalCompleted = Task::whereIn('assigned_to', $users->pluck('id'))
                    ->whereIn('status', ['completed', 'verified'])
                    ->where('completed_at', '>=', now()->subDays(7))->count();
                return ['users' => $users, 'completed' => $totalCompleted];
            });

        if ($teamStats->isNotEmpty()) {
            $weakestTeam = $teamStats->sortBy('completed')->keys()->first();
            $weakestCount = $teamStats[$weakestTeam]['completed'] ?? 0;
            if ($weakestTeam && $weakestCount < 5) {
                $insights[] = [
                    'type' => 'insight',
                    'icon' => '📊',
                    'title' => "{$weakestTeam} team below target",
                    'desc'  => "Only {$weakestCount} tasks completed in last 7 days. Review workload allocation.",
                    'action' => 'View report',
                ];
            }
        }

        // If nothing found, give a positive message
        if (empty($insights)) {
            $insights[] = [
                'type' => 'star',
                'icon' => '✅',
                'title' => 'All systems healthy',
                'desc'  => 'No critical issues detected. Team performing well.',
                'action' => 'View details',
            ];
        }

        return response()->json($insights);
    }

    public function report(string $type)
    {
        // Simple AI report — for now structured summary
        $today = today();
        $assigned = Task::whereDate('created_at', $today)->count();
        $completed = Task::whereIn('status', ['completed', 'verified'])
            ->whereDate('completed_at', $today)->count();
        $overdue = Task::where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'verified'])->count();
        $escalated = Task::where('status', 'escalated')->count();

        $topUser = User::where('is_active', true)
            ->where('role', '!=', 'admin')
            ->get()
            ->map(function ($u) {
                $latest = ApixScore::where('user_id', $u->id)->latest('score_date')->first();
                return ['user' => $u, 'apix' => $latest?->apix_score ?? 0];
            })
            ->sortByDesc('apix')
            ->first();

        $atRiskUser = User::where('is_active', true)
            ->where('role', '!=', 'admin')
            ->get()
            ->map(function ($u) {
                $latest = ApixScore::where('user_id', $u->id)->latest('score_date')->first();
                return ['user' => $u, 'apix' => $latest?->apix_score ?? 999];
            })
            ->sortBy('apix')
            ->first();

        return response()->json([
            'tasks_line'     => "{$assigned} assigned · {$completed} completed · {$overdue} overdue · {$escalated} escalated",
            'top_performer'  => $topUser
                ? $topUser['user']->name . ' (APIX ' . round($topUser['apix'], 1) . ')'
                : 'No data',
            'at_risk'        => $atRiskUser && $atRiskUser['apix'] < 50
                ? $atRiskUser['user']->name . ' (APIX ' . round($atRiskUser['apix'], 1) . ')'
                : 'No critical risk',
            'action_needed'  => $overdue > 0
                ? "{$overdue} overdue task(s) need attention"
                : 'No urgent actions',
            'suggestion'     => $completed < $assigned * 0.7
                ? 'Schedule morning stand-up to improve completion rate'
                : 'Team performing on track',
        ]);
    }
}
