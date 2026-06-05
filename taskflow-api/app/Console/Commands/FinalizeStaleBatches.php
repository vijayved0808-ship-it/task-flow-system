<?php

namespace App\Console\Commands;

use App\Domain\Logs\Models\ActivityLog;
use App\Domain\Task\Models\Task;
use App\Domain\User\Models\User;
use App\Domain\WhatsApp\Models\WaMedia;
use App\Domain\WhatsApp\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Finalize task batches that have been idle for >= 2 minutes.
 * Hit this from cron-job.org every minute (or via Laravel scheduler).
 *
 * Logic mirrors CommandHandler::finalizeBatchedTask() — we directly act
 * on user.wa_session_state rows to avoid coupling.
 */
class FinalizeStaleBatches extends Command
{
    protected $signature = 'batches:finalize-stale {--dry-run : Show what would be finalized}';
    protected $description = 'Auto-finalize task batches idle for 2+ minutes';

    private const IDLE_MINUTES = 2;

    public function handle(WhatsAppService $wa): int
    {
        $dryRun = $this->option('dry-run');

        // Postgres JSON path operator: wa_session_state->>'awaiting' = 'task_batch'
        $candidates = User::where('wa_session_state->awaiting', 'task_batch')->get();

        $this->info("Found {$candidates->count()} user(s) with open batches.");

        $finalized = 0;
        $skipped   = 0;

        foreach ($candidates as $manager) {
            $state = $manager->wa_session_state;
            if (!is_array($state)) continue;

            $lastActivity = $state['last_activity_at'] ?? $state['started_at'] ?? null;
            if (!$lastActivity) {
                $skipped++;
                continue;
            }

            try {
                $idleMin = Carbon::parse($lastActivity)->diffInMinutes(now());
            } catch (\Exception $e) {
                $skipped++;
                continue;
            }

            if ($idleMin < self::IDLE_MINUTES) {
                $skipped++;
                continue;
            }

            $this->line("→ {$manager->name} — batch idle {$idleMin} min");

            if ($dryRun) {
                $finalized++;
                continue;
            }

            $ok = $this->finalizeBatchFor($manager, $wa);
            if ($ok) $finalized++;
        }

        $this->newLine();
        $this->info("Done. Finalized: {$finalized} · Skipped: {$skipped}");
        return Command::SUCCESS;
    }

    /**
     * Mirror of CommandHandler::finalizeBatchedTask but invokable from Artisan
     * without a CommandHandler instance.
     */
    private function finalizeBatchFor(User $manager, WhatsAppService $wa): bool
    {
        $state = is_array($manager->wa_session_state) ? $manager->wa_session_state : [];
        $employeeId = $state['task_for_user_id'] ?? null;
        $textLines = $state['buffered_text'] ?? [];
        $mediaIds  = $state['buffered_media'] ?? [];

        // Clear batch state on the manager
        foreach (['awaiting', 'task_for_user_id', 'task_for_user_name',
                  'buffered_text', 'buffered_media', 'started_at',
                  'last_activity_at', 'expires_at'] as $k) {
            unset($state[$k]);
        }
        $manager->wa_session_state = empty($state) ? null : $state;
        $manager->save();

        if (!$employeeId) return false;
        if (empty($textLines) && empty($mediaIds)) {
            $this->warn("  empty batch — discarding");
            return false;
        }

        $employee = User::find($employeeId);
        if (!$employee || !$employee->is_active) {
            $this->warn("  employee unavailable — discarding");
            return false;
        }

        if (!$manager->canAssignTo($employee)) {
            $this->warn("  hierarchy block — discarding");
            return false;
        }

        // Build title from first non-empty text line
        $title = '';
        foreach ($textLines as $line) {
            if (trim($line) !== '') { $title = $line; break; }
        }
        if ($title === '') {
            $title = count($mediaIds) > 0 ? "Task with " . count($mediaIds) . " attachments" : "Multi-message task";
        }
        if (strlen($title) > 480) $title = substr($title, 0, 477) . '...';

        // Create task
        $task = Task::create([
            'tenant_id'     => 'default',
            'title'         => $title,
            'assigned_by'   => $manager->id,
            'assigned_to'   => $employee->id,
            'status'        => 'assigned',
            'priority'      => 'medium',
            'due_date'      => null,
            'reward_points' => 50,
        ]);

        ActivityLog::record(
            'task', 'assign_auto_finalized', 'success',
            "🤖 Auto-finalized stale batch — {$manager->name} → {$employee->name}: \"{$title}\"",
            ['task_id' => $task->id, 'text_count' => count($textLines), 'media_count' => count($mediaIds)]
        );

        // Notify the manager
        if ($manager->phone) {
            $wa->sendMessage($manager->phone,
                "⏰ *Batch auto-finalized*\n\n"
                . "Aapne 2+ min me DONE nahi bheja — system ne {$employee->name} ko task forward kar diya.\n\n"
                . "📋 " . substr($title, 0, 100) . "\n"
                . "🆔 T-" . substr($task->id, 0, 6) . "\n"
                . "📝 " . count($textLines) . " text · 📎 " . count($mediaIds) . " files"
            );
        }

        // Notify assignee + forward all items
        $task->load(['assignedTo', 'assignedBy']);
        $wa->sendTaskAssignment($task);

        foreach ($textLines as $line) {
            if (trim($line) === '') continue;
            $wa->sendMessage($employee->phone,
                "📋 *Task T-" . substr($task->id, 0, 6) . " — additional info:*\n\n" . $line
            );
        }

        foreach ($mediaIds as $mediaId) {
            $waMedia = WaMedia::find($mediaId);
            if (!$waMedia || !is_readable($waMedia->file_path)) continue;
            $waMedia->update(['task_id' => $task->id]);
            $wa->sendMedia(
                $employee->phone,
                $waMedia->file_path,
                $waMedia->mime_type ?? 'application/octet-stream',
                "📎 For task T-" . substr($task->id, 0, 6),
                $waMedia->filename
            );
        }

        $this->info("  ✅ finalized → {$employee->name} (T-" . substr($task->id, 0, 6) . ")");
        return true;
    }
}
