<?php

namespace App\Console\Commands;

use App\Domain\Logs\Models\ActivityLog;
use App\Domain\Task\Models\Task;
use App\Domain\WhatsApp\Services\WhatsAppService;
use App\Jobs\RecalculateApixScore;
use Illuminate\Console\Command;

/**
 * Auto-verify tasks that have been in 'completed' status for >= 2 hours
 * without the manager taking action. Implements the "manager silence = approval" rule.
 *
 * Usage:
 *   php artisan tasks:auto-verify             # run now
 *   php artisan tasks:auto-verify --dry-run   # preview, no DB change
 *
 * Scheduled to run every 10 minutes via bootstrap/app.php withSchedule().
 */
class AutoVerifyCompletedTasks extends Command
{
    protected $signature = 'tasks:auto-verify {--dry-run : Show what would happen without changing DB}';
    protected $description = 'Auto-verify completed tasks after 2 hours of manager inaction';

    public function handle(WhatsAppService $wa): int
    {
        $dryRun = $this->option('dry-run');
        $threshold = now()->subHours(2);

        $tasks = Task::where('status', 'completed')
            ->where('completed_at', '<=', $threshold)
            ->with(['assignedBy', 'assignedTo'])
            ->get();

        $this->info("Found {$tasks->count()} task(s) eligible for auto-verification.");

        $count = 0;
        foreach ($tasks as $task) {
            $assignee   = $task->assignedTo;
            $manager    = $task->assignedBy;
            $hoursAge   = $task->completed_at ? round(now()->diffInMinutes($task->completed_at) / 60, 1) : 'unknown';

            $this->line("• T-" . substr($task->id, 0, 6) . " — \"{$task->title}\" (completed {$hoursAge}h ago)");

            if ($dryRun) {
                continue;
            }

            $task->update(['status' => 'verified']);

            ActivityLog::record(
                'task', 'auto_verify', 'success',
                "🤖 Auto-verified after 2hr inaction: \"{$task->title}\"",
                ['task_id' => $task->id, 'completed_at' => $task->completed_at?->toIso8601String()]
            );

            // Notify assignee (they get the win)
            if ($assignee && $assignee->phone) {
                $wa->sendMessage($assignee->phone,
                    "🎯 *Task Auto-Verified*\n\n"
                    . "📋 " . $task->title . "\n"
                    . "🆔 T-" . substr($task->id, 0, 6) . "\n\n"
                    . "Manager ne 2 ghante me verify nahi kiya — system ne approve kar diya.\n"
                    . "⭐ Reward points: {$task->reward_points}"
                );
            }

            // Inform manager (so they know the rule fired)
            if ($manager && $manager->phone) {
                $wa->sendMessage($manager->phone,
                    "🤖 *Task Auto-Verified (2hr rule)*\n\n"
                    . "📋 " . $task->title . "\n"
                    . "👤 By: " . ($assignee?->name ?? 'Unknown') . "\n"
                    . "Aapne 2 ghante me verify nahi kiya. System ne approve kar diya.\n\n"
                    . "Agar galat hua hai to *REOPEN T-" . substr($task->id, 0, 6) . "* karke wapas khol sakte ho."
                );
            }

            // Re-calculate APIX (dispatch as before; will run when queue worker is set up)
            if ($assignee) {
                RecalculateApixScore::dispatch($assignee->id);
            }

            $count++;
        }

        $this->newLine();
        if ($dryRun) {
            $this->info("Dry run — no changes made. Would have verified {$tasks->count()} task(s).");
        } else {
            $this->info("Auto-verified {$count} task(s).");
        }

        return Command::SUCCESS;
    }
}
