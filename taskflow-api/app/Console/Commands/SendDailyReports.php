<?php

namespace App\Console\Commands;

use App\Domain\Logs\Models\ActivityLog;
use App\Domain\Task\Models\Task;
use App\Domain\User\Models\User;
use App\Domain\WhatsApp\Services\WhatsAppService;
use Illuminate\Console\Command;

class SendDailyReports extends Command
{
    protected $signature = 'reports:daily {--dry-run : Print messages but do not send}';
    protected $description = 'Send daily WhatsApp summary to employees, managers, and admins';

    public function handle(WhatsAppService $wa): int
    {
        $dryRun = $this->option('dry-run');
        $today  = today();

        $users = User::where('is_active', true)
            ->whereNotNull('phone')
            ->get();

        $this->info("Daily report run for " . $today->format('d M, Y') . " — {$users->count()} active users.");

        $sent = 0; $skipped = 0; $failed = 0;

        foreach ($users as $user) {
            $msg = null;
            if ($user->role === 'admin') {
                $msg = $this->buildAdminReport($user, $today);
            } elseif ($user->directReports()->where('is_active', true)->exists()) {
                $msg = $this->buildManagerReport($user, $today);
            } else {
                $msg = $this->buildEmployeeReport($user, $today);
            }

            if ($msg === null) { $skipped++; continue; }

            if ($dryRun) {
                $this->line("→ {$user->name} ({$user->role})");
                $this->line($msg);
                $this->line(str_repeat('-', 50));
                continue;
            }

            if ($wa->sendMessage($user->phone, $msg)) {
                $this->info("✅ {$user->name} ({$user->role})");
                $sent++;
                ActivityLog::record('report', 'daily_summary', 'success',
                    "📊 Daily report sent to {$user->name}",
                    ['user_id' => $user->id, 'role' => $user->role]
                );
            } else {
                $this->warn("⚠️ Send failed for {$user->name}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done. Sent: {$sent} · Skipped: {$skipped} · Failed: {$failed}");
        return Command::SUCCESS;
    }

    private function buildEmployeeReport(User $emp, $today): ?string
    {
        $completedToday = Task::where('assigned_to', $emp->id)
            ->whereIn('status', ['completed', 'verified'])
            ->whereDate('completed_at', $today)->count();
        $assignedToday = Task::where('assigned_to', $emp->id)
            ->whereDate('created_at', $today)->count();
        $pending = Task::where('assigned_to', $emp->id)
            ->whereIn('status', ['assigned', 'accepted', 'in_progress', 'waiting'])->count();
        $overdue = Task::where('assigned_to', $emp->id)
            ->where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'verified', 'cancelled'])->count();

        if ($assignedToday === 0 && $completedToday === 0 && $pending === 0) return null;

        $msg  = "📊 *Daily Report — " . $today->format('d M, Y') . "*\n";
        $msg .= "👤 {$emp->name}\n\n";
        if ($completedToday > 0) $msg .= "✅ Completed today: *{$completedToday}*\n";
        if ($assignedToday > 0)  $msg .= "🆕 New assignments: *{$assignedToday}*\n";
        $msg .= "📋 Total pending: *{$pending}*\n";
        if ($overdue > 0)        $msg .= "⏰ *Overdue: {$overdue}*\n";
        $msg .= "\nSee you tomorrow! 🌙\n";
        $msg .= "_Reply STATUS to view your tasks._";
        return $msg;
    }

    private function buildManagerReport(User $manager, $today): ?string
    {
        $reportIds = User::where('is_active', true)
            ->where('id', '!=', $manager->id)
            ->get()
            ->filter(fn($u) => $manager->canAssignTo($u))
            ->pluck('id')->toArray();

        if (empty($reportIds)) return $this->buildEmployeeReport($manager, $today);

        $teamCompleted = Task::whereIn('assigned_to', $reportIds)
            ->whereIn('status', ['completed', 'verified'])
            ->whereDate('completed_at', $today)->count();
        $teamPending = Task::whereIn('assigned_to', $reportIds)
            ->whereIn('status', ['assigned', 'accepted', 'in_progress', 'waiting'])->count();
        $teamOverdue = Task::whereIn('assigned_to', $reportIds)
            ->where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'verified', 'cancelled'])->count();
        $teamAssigned = Task::whereIn('assigned_to', $reportIds)
            ->whereDate('created_at', $today)->count();

        $msg  = "📊 *Team Report — " . $today->format('d M, Y') . "*\n";
        $msg .= "👔 {$manager->name}\n\n";
        $msg .= "*Team Stats:*\n";
        $msg .= "✅ Completed today: *{$teamCompleted}*\n";
        $msg .= "🆕 New assignments today: *{$teamAssigned}*\n";
        $msg .= "📋 Total pending: *{$teamPending}*\n";
        if ($teamOverdue > 0) $msg .= "⏰ *Overdue: {$teamOverdue}*\n";

        $directReports = $manager->directReports()->where('is_active', true)->get();
        if ($directReports->isNotEmpty()) {
            $msg .= "\n*Per Direct Report:*\n";
            foreach ($directReports as $dr) {
                $active = Task::where('assigned_to', $dr->id)
                    ->whereIn('status', ['assigned', 'accepted', 'in_progress', 'waiting'])->count();
                $done = Task::where('assigned_to', $dr->id)
                    ->whereIn('status', ['completed', 'verified'])
                    ->whereDate('completed_at', $today)->count();
                $od = Task::where('assigned_to', $dr->id)
                    ->where('due_date', '<', now())
                    ->whereNotIn('status', ['completed', 'verified', 'cancelled'])->count();
                $msg .= "👤 {$dr->name} — 📋{$active} ✅{$done}";
                if ($od > 0) $msg .= " ⏰{$od}";
                $msg .= "\n";
            }
        }

        $top = Task::whereIn('assigned_to', $reportIds)
            ->whereIn('status', ['completed', 'verified'])
            ->whereDate('completed_at', $today)
            ->selectRaw('assigned_to, COUNT(*) as cnt')
            ->groupBy('assigned_to')
            ->orderByDesc('cnt')->first();
        if ($top && $top->cnt > 0) {
            $topUser = User::find($top->assigned_to);
            if ($topUser) {
                $msg .= "\n🏆 *Top today:* {$topUser->name} ({$top->cnt})\n";
            }
        }

        $msg .= "\n_Reply ALL for team overview · TEAM for tree view._";
        return $msg;
    }

    private function buildAdminReport(User $admin, $today): ?string
    {
        $totalCompleted = Task::whereIn('status', ['completed', 'verified'])
            ->whereDate('completed_at', $today)->count();
        $totalPending = Task::whereIn('status', ['assigned', 'accepted', 'in_progress', 'waiting'])->count();
        $totalOverdue = Task::where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'verified', 'cancelled'])->count();
        $totalAssigned = Task::whereDate('created_at', $today)->count();
        $activeUsers = User::where('is_active', true)->where('role', '!=', 'admin')->count();

        $msg  = "📊 *Company Report — " . $today->format('d M, Y') . "*\n";
        $msg .= "👑 {$admin->name}\n\n";
        $msg .= "*Company Stats:*\n";
        $msg .= "👥 Active users: *{$activeUsers}*\n";
        $msg .= "✅ Completed today: *{$totalCompleted}*\n";
        $msg .= "🆕 New today: *{$totalAssigned}*\n";
        $msg .= "📋 Total pending: *{$totalPending}*\n";
        if ($totalOverdue > 0) $msg .= "⏰ *Overdue: {$totalOverdue}*\n";

        $top = Task::whereIn('status', ['completed', 'verified'])
            ->whereDate('completed_at', $today)
            ->selectRaw('assigned_to, COUNT(*) as cnt')
            ->groupBy('assigned_to')
            ->orderByDesc('cnt')->limit(3)->get();
        if ($top->isNotEmpty()) {
            $msg .= "\n🏆 *Top performers today:*\n";
            foreach ($top as $i => $t) {
                $u = User::find($t->assigned_to);
                if ($u) {
                    $rank = $i + 1;
                    $msg .= "{$rank}. {$u->name} ({$t->cnt})\n";
                }
            }
        }

        $msg .= "\n_Reply ALL for team overview · STATUS <name> for details._";
        return $msg;
    }
}
