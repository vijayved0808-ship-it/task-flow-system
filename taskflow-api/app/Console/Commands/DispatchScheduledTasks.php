<?php

namespace App\Console\Commands;

use App\Domain\Logs\Models\ActivityLog;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskSchedule;
use App\Domain\WhatsApp\Services\WhatsAppService;
use Illuminate\Console\Command;

/**
 * Reads task_schedules table and creates real tasks for any schedule
 * that should run today. Idempotent — uses last_dispatched_at to avoid
 * double-creating on the same day.
 *
 * Trigger via cron-job.org daily at 8 AM IST (or whenever you want tasks created).
 */
class DispatchScheduledTasks extends Command
{
    protected $signature = 'schedules:dispatch {--dry-run : Show what would happen}';
    protected $description = 'Create real tasks for active schedules matching today';

    public function handle(WhatsAppService $wa): int
    {
        $dryRun = $this->option('dry-run');
        $today  = strtolower(now()->format('D')); // mon, tue, wed, ...

        $schedules = TaskSchedule::where('is_active', true)
            ->with(['assignedTo', 'assignedBy'])
            ->get();

        $this->info("Found {$schedules->count()} active schedule(s). Today is *{$today}*.");

        $dispatched = 0;
        $skipped    = 0;

        foreach ($schedules as $sched) {
            // Idempotency: skip if already dispatched today
            if ($sched->last_dispatched_at && $sched->last_dispatched_at->isToday()) {
                $skipped++;
                continue;
            }

            $shouldRun = false;
            if ($sched->schedule_type === 'daily') {
                $shouldRun = true;
            } elseif ($sched->schedule_type === 'weekly') {
                $days = $sched->days_of_week ?? [];
                if (in_array($today, $days, true)) {
                    $shouldRun = true;
                }
            }

            if (!$shouldRun) {
                $skipped++;
                continue;
            }

            if (!$sched->assignedTo || !$sched->assignedTo->is_active) {
                $this->warn("→ Skipping S-" . substr($sched->id, 0, 6) . " — employee inactive");
                $skipped++;
                continue;
            }

            $this->line("→ Dispatching S-" . substr($sched->id, 0, 6) . " — \"{$sched->title}\" → {$sched->assignedTo->name}");

            if ($dryRun) continue;

            $task = Task::create([
                'tenant_id'     => $sched->tenant_id,
                'title'         => $sched->title,
                'assigned_by'   => $sched->assigned_by,
                'assigned_to'   => $sched->assigned_to,
                'status'        => 'assigned',
                'priority'      => $sched->priority,
                'due_date'      => now()->endOfDay(),
                'reward_points' => $sched->reward_points,
            ]);

            $sched->update(['last_dispatched_at' => now()]);

            ActivityLog::record(
                'task', 'scheduled_dispatch', 'success',
                "📅 Auto-created task from schedule S-" . substr($sched->id, 0, 6) . ": \"{$sched->title}\"",
                ['task_id' => $task->id, 'schedule_id' => $sched->id]
            );

            // Notify employee via WhatsApp
            $task->load(['assignedTo', 'assignedBy']);
            $wa->sendTaskAssignment($task);

            // Brief notice to the schedule's creator
            if ($sched->assignedBy && $sched->assignedBy->phone) {
                $wa->sendMessage($sched->assignedBy->phone,
                    "📅 *Scheduled Task Auto-Created*\n\n"
                    . "📋 " . $sched->title . "\n"
                    . "👤 To: " . $sched->assignedTo->name . "\n"
                    . "🆔 T-" . substr($task->id, 0, 6)
                );
            }

            $dispatched++;
        }

        $this->newLine();
        $this->info("Done. Dispatched: {$dispatched} · Skipped: {$skipped}");
        return Command::SUCCESS;
    }
}
