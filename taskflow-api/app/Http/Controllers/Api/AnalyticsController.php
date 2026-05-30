<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Task\Models\Task;
use App\Domain\User\Models\User;
use App\Domain\Task\Models\ApixScore;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function overview()
    {
        return response()->json([
            'total_tasks'        => Task::count(),
            'open_tasks'         => Task::whereNotIn('status', ['completed', 'verified'])->count(),
            'completed_tasks'    => Task::whereIn('status', ['completed', 'verified'])->count(),
            'overdue_tasks'      => Task::where('due_date', '<', now())->whereNotIn('status', ['completed', 'verified'])->count(),
            'team_productivity'  => round(ApixScore::where('score_date', today())->avg('apix_score') ?? 0, 1),
            'active_employees'   => User::where('is_active', true)->where('role', 'employee')->count(),
        ]);
    }

    public function leaderboard()
    {
        $scores = DB::table('apix_scores as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.score_date', '>=', now()->subDays(7))
            ->where('u.is_active', true)
            ->select('u.id', 'u.name', 'u.department', DB::raw('AVG(s.apix_score) as avg_score'), DB::raw('SUM(s.tasks_completed) as total_completed'))
            ->groupBy('u.id', 'u.name', 'u.department')
            ->orderByDesc('avg_score')
            ->limit(20)
            ->get();

        return response()->json($scores->map(function ($row, $i) {
            $row->rank = $i + 1;
            $row->avg_score = round($row->avg_score, 1);
            return $row;
        }));
    }

    public function apix(User $user)
    {
        return response()->json([
            'user'    => $user->only(['id', 'name', 'department']),
            'today'   => ApixScore::where('user_id', $user->id)->where('score_date', today())->first(),
            'history' => ApixScore::where('user_id', $user->id)->orderByDesc('score_date')->take(30)->get(),
        ]);
    }
}
