<?php

namespace App\Jobs;

use App\Domain\AI\Services\AIService;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\ApixScore;
use App\Domain\User\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateApixScore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private string $userId) {}

    public function handle(AIService $ai): void
    {
        $user = User::find($this->userId);
        if (!$user) return;

        $today  = today();
        $tasks  = Task::where('assigned_to', $this->userId)
            ->whereDate('created_at', $today)->get();

        $assigned  = $tasks->count();
        $completed = $tasks->whereIn('status', ['completed', 'verified'])->count();
        $onTime    = $tasks->filter(fn($t) => $t->completed_at && $t->due_date && $t->completed_at <= $t->due_date)->count();
        $late      = $tasks->filter(fn($t) => $t->completed_at && $t->due_date && $t->completed_at > $t->due_date)->count();

        $completionRate = $assigned > 0 ? round(($completed / $assigned) * 100, 2) : 0;
        $timeliness     = $completed > 0 ? round((($completed - $late) / $completed) * 100, 2) : 100;

        // Quality: avg of AI scores on updates today
        $avgQuality = $user->assignedTasks()
            ->whereDate('created_at', $today)
            ->with('updates')
            ->get()
            ->flatMap->updates
            ->filter(fn($u) => isset($u->ai_analysis['quality_score']))
            ->avg(fn($u) => $u->ai_analysis['quality_score']) ?? 70;

        // Consistency: has user been active today?
        $consistency = $user->last_seen_at?->isToday() ? 100 : 0;

        $components = [
            'completion_rate'   => $completionRate,
            'timeliness_score'  => $timeliness,
            'quality_score'     => round($avgQuality, 2),
            'consistency_score' => $consistency,
            'manager_rating'    => 75, // Default until rated
        ];

        $apix = $ai->calculateApix($components);

        ApixScore::updateOrCreate(
            ['user_id' => $this->userId, 'score_date' => $today],
            array_merge($components, [
                'apix_score'         => $apix,
                'tasks_assigned'     => $assigned,
                'tasks_completed'    => $completed,
                'tasks_on_time'      => $onTime,
                'tasks_late'         => $late,
            ])
        );
    }
}
