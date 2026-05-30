<?php

namespace App\Jobs;

use App\Domain\AI\Services\AIService;
use App\Domain\Task\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeTaskWithAI implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private Task $task) {}

    public function handle(AIService $ai): void
    {
        $updates = $this->task->updates()->latest()->limit(5)->get();
        if ($updates->isEmpty()) return;

        $combinedText = $updates->pluck('message')->implode(' | ');
        $analysis     = $ai->analyzeTaskUpdate($this->task->title, $combinedText);

        $this->task->update([
            'ai_score'   => $analysis['quality_score'] ?? 70,
            'ai_summary' => $analysis['feedback'] ?? null,
        ]);

        // Update each update's ai_analysis
        foreach ($updates as $update) {
            $update->update(['ai_analysis' => $analysis]);
        }
    }
}
