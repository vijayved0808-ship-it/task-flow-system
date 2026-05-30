<?php

namespace App\Domain\AI\Services;

use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\ApixScore;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key');
        $this->model  = config('services.anthropic.model', 'claude-sonnet-4-20250514');
    }

    public function analyzeTaskUpdate(string $taskTitle, string $updateMessage, bool $hasMedia = false): array
    {
        $prompt = "Task: {$taskTitle}\nEmployee update: {$updateMessage}\nMedia attached: " . ($hasMedia ? 'Yes' : 'No') . "\n\n"
                . "Rate this update 0-100 on quality. Return JSON only with keys: quality_score (int), feedback (string max 20 words), risk_flags (array of strings).";

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model'      => $this->model,
                'max_tokens' => 300,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
                'system'     => 'You are an AI task supervisor. Return only valid JSON, no markdown, no explanation.',
            ]);

            if ($response->successful()) {
                $text = $response->json('content.0.text', '{}');
                return json_decode($text, true) ?? ['quality_score' => 70, 'feedback' => 'Update received', 'risk_flags' => []];
            }
        } catch (\Exception $e) {
            Log::error('AI analysis failed', ['error' => $e->getMessage()]);
        }

        return ['quality_score' => 70, 'feedback' => 'AI analysis unavailable', 'risk_flags' => []];
    }

    public function generateReport(string $type): array
    {
        $period = match ($type) {
            'daily'   => ['start' => today(), 'end' => today()],
            'weekly'  => ['start' => now()->startOfWeek(), 'end' => now()->endOfWeek()],
            'monthly' => ['start' => now()->startOfMonth(), 'end' => now()->endOfMonth()],
        };

        $stats = [
            'assigned'  => Task::whereBetween('created_at', [$period['start'], $period['end']])->count(),
            'completed' => Task::whereBetween('completed_at', [$period['start'], $period['end']])->whereIn('status', ['completed', 'verified'])->count(),
            'overdue'   => Task::where('due_date', '<', now())->whereNotIn('status', ['completed', 'verified'])->count(),
            'avg_apix'  => round(ApixScore::whereBetween('score_date', [$period['start'], $period['end']])->avg('apix_score') ?? 0, 1),
        ];

        $prompt = "Generate a {$type} workforce performance report. Data: " . json_encode($stats)
                . "\n\nReturn JSON with: summary (string 2 sentences), insights (array of 3 strings), recommendations (array of 2 strings).";

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model'      => $this->model,
                'max_tokens' => 500,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
                'system'     => 'You are a workforce analytics AI. Return only valid JSON.',
            ]);

            if ($response->successful()) {
                $text   = $response->json('content.0.text', '{}');
                $report = json_decode($text, true) ?? [];
                return array_merge(['stats' => $stats, 'period' => $type, 'generated_at' => now()], $report);
            }
        } catch (\Exception $e) {
            Log::error('AI report failed', ['error' => $e->getMessage()]);
        }

        return ['stats' => $stats, 'period' => $type, 'generated_at' => now(), 'summary' => 'Report generated from data.'];
    }

    // APIX Score: CR×0.30 + TS×0.25 + QS×0.20 + CS×0.15 + MR×0.10
    public function calculateApix(array $components): float
    {
        return round(
            ($components['completion_rate']   * 0.30) +
            ($components['timeliness_score']  * 0.25) +
            ($components['quality_score']     * 0.20) +
            ($components['consistency_score'] * 0.15) +
            ($components['manager_rating']    * 0.10),
            2
        );
    }
}
