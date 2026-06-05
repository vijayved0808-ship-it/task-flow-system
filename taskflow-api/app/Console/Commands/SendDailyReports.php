<?php

namespace App\Console\Commands;

use App\Domain\Logs\Models\ActivityLog;
use App\Domain\Task\Models\Task;
use App\Domain\User\Models\User;
use App\Domain\WhatsApp\Services\WhatsAppService;
use Illuminate\Console\Command;

/**
 * Sends a daily WhatsApp summary to each active employee at end-of-day.
 *
 * Usage:
 *   php artisan reports:daily             # send for today
 *   php artisan reports:daily --dry-run   # preview, don't send
 *
 * Scheduled to run at 19:00 IST via bootstrap/app.php withSchedule().
 * Requires `php artisan schedule:work` (or external cron hitting Artisan) to actually fire.
 */
class SendDailyReports extends Command
{
    protected $signature = 'reports:daily {--dry-run : Print messages but do not send}';
    protected $description = 'Send daily task summary to each employee via WhatsApp';

    public function handle(WhatsAppService $wa): int
    {
        $dryRun = $this->option('dry-run');
        $today  = today();

        $employees = User::where('is_active', true)
            ->where('role', '!=', 'admin')
            ->whereNotNull('phone')
            ->get();

        $this->info("Found {$employees->count()} active employees with phone numbers.");

        $sentCount   = 0;
        $skipCount   = 0;
        $failedCount = 0;

        foreach ($employees as $emp) {
            // Compute today's stats per employee
            $assignedToday = Task::where('assigned_to', $emp->id)
                ->whereDate('created_at', $today)->count();

            $completedToday = Task::where('assigned_to', $emp->id)
                ->whereIn('status', ['completed', 'verified'])
                ->whereDate('completed_at', $today)->count();

            $verifiedToday = Task::where('assigned_to', $emp->id)
                ->where('status', 'verified')
                ->whereDate('updated_at', $today)->count();

            $pendingTotal = Task::where('assigned_to', $emp->id)
                ->whereIn('status', ['assigned', 'accepted', 'in_progress', 'waiting'])
                ->count();

            $overdueTotal = Task::where('assigned_to', $emp->id)
                ->where('due_date', '<', now())
                ->whereNotIn('status', ['completed', 'verified', 'cancelled'])
                ->count();

            // Skip employees with no activity / no pending tasks (zero-noise)
            if ($assignedToday === 0 && $completedToday === 0 && $pendingTotal === 0) {
                $skipCount++;
                continue;
            }

            // Build message
            $msg = "📊 *Aaj ka Report — " . $today->format('d M, Y') . "*\n\n";
            $msg .= "👤 {$emp->name}\n\n";
            if ($completedToday > 0) {
                $msg .= "✅ Completed today: *{$completedToday}*\n";
            }
            if ($verifiedToday > 0) {
                $msg .= "🎯 Verified today: *{$verifiedToday}*\n";
            }
            if ($assignedToday > 0) {
                $msg .= "🆕 New assignments: *{$assignedToday}*\n";
            }
            $msg .= "📋 Total pending: *{$pendingTotal}*\n";
            if ($overdueTotal > 0) {
                $msg .= "⏰ *Overdue: {$overdueTotal}*\n";
            }
            $msg .= "\nKal milte hain! 🌙\n";
            $msg .= "_Reply STATUS to see all your tasks._";

            if ($dryRun) {
                $this->line("→ {$emp->name} ({$emp->phone}):");
                $this->line($msg);
                $this->line(str_repeat('-', 50));
                continue;
            }

            if ($wa->sendMessage($emp->phone, $msg)) {
                $this->info("✅ Sent to {$emp->name}");
                $sentCount++;
                ActivityLog::record(
                    'report', 'daily_summary', 'success',
                    "📊 Daily report sent to {$emp->name}",
                    ['user_id' => $emp->id, 'pending' => $pendingTotal, 'completed' => $completedToday]
                );
            } else {
                $this->warn("⚠️ Send failed for {$emp->name}");
                $failedCount++;
            }
        }

        $this->newLine();
        $this->info("Summary — sent: {$sentCount}, skipped: {$skipCount}, failed: {$failedCount}");

        return Command::SUCCESS;
    }
}
