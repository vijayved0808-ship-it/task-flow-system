<?php

namespace App\Domain\WhatsApp\Handlers;

use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskSchedule;
use App\Domain\Task\Models\TaskUpdate;
use App\Domain\User\Models\User;
use App\Domain\WhatsApp\Models\WaMedia;
use App\Domain\WhatsApp\Services\WhatsAppService;
use App\Domain\Logs\Models\ActivityLog;
use App\Jobs\RecalculateApixScore;
use Illuminate\Support\Facades\Hash;

class CommandHandler
{
    public function __construct(private WhatsAppService $wa) {}

    public function handle(User $user, string $command, string $fullMessage, ?string $waMessageId = null, ?WaMedia $waMedia = null): string
    {
        $user->update(['last_seen_at' => now()]);
        $textTrimmed = trim($fullMessage);

        // ──────────────────────────────────────────────────────────────
        // PHASE 3 — CHAT SESSION MODE
        // ──────────────────────────────────────────────────────────────
        $stateNow = $user->wa_session_state;
        if (is_array($stateNow) && !empty($stateNow['in_chat_with_id'])) {
            $upperFirst = strtoupper(explode(' ', $textTrimmed)[0] ?? '');
            if (in_array($upperFirst, ['CLOSE', 'END', 'BYE', 'EXIT'], true)) {
                return $this->closeChatSession($user, false);
            }
            // If media came in chat mode, forward as media instead of text
            if ($waMedia) {
                return $this->forwardChatMedia($user, $waMedia, $textTrimmed);
            }
            return $this->forwardChatMessage($user, $textTrimmed);
        }

        // ──────────────────────────────────────────────────────────────
        // PHASE 4 — BATCHED TASK BUILDING MODE
        // After "ASSIGN <name>" (without description), user collects
        // multiple text/media messages. "DONE" finalizes, "CANCEL" aborts.
        // ──────────────────────────────────────────────────────────────
        if (is_array($stateNow) && ($stateNow['awaiting'] ?? null) === 'task_batch') {
            $upperFirst = strtoupper(explode(' ', $textTrimmed)[0] ?? '');

            // Explicit finalize / abort markers
            if (in_array($upperFirst, ['DONE', 'FINISH', 'SEND'], true)) {
                return $this->finalizeBatchedTask($user);
            }
            if (in_array($upperFirst, ['CANCEL', 'ABORT'], true)) {
                return $this->cancelBatchedTask($user);
            }

            // ── VALIDATION 1: Auto-finalize after 2 min of idle ──
            $lastActivity = $stateNow['last_activity_at'] ?? $stateNow['started_at'] ?? null;
            if ($lastActivity) {
                try {
                    $idleMinutes = \Carbon\Carbon::parse($lastActivity)->diffInMinutes(now());
                    if ($idleMinutes >= 2) {
                        $autoReply = $this->finalizeBatchedTask($user);
                        // After finalize, state is cleared. Re-process current message normally.
                        $followup = $this->handle($user, $command, $fullMessage, $waMessageId, $waMedia);
                        return "⏰ *Auto-finalized — batch was idle for {$idleMinutes} min.*\n\n"
                             . $autoReply
                             . "\n\n———\n\n" . $followup;
                    }
                } catch (\Exception $e) {
                    // Bad timestamp — ignore, continue normally
                }
            }

            // ── VALIDATION 2: If user uses another command, auto-finalize first ──
            // Only triggers on pure command messages (no media attached). Media in batch = content.
            $cmdUpper = strtoupper(trim($command));
            $isCommand = !empty($cmdUpper)
                && !in_array($cmdUpper, ['DONE', 'FINISH', 'SEND', 'CANCEL', 'ABORT'], true)
                && !$waMedia;
            if ($isCommand) {
                $autoReply = $this->finalizeBatchedTask($user);
                $followup = $this->handle($user, $command, $fullMessage, $waMessageId, $waMedia);
                return "⏱ *Auto-finalized — you started a new command.*\n\n"
                     . $autoReply
                     . "\n\n———\n\n" . $followup;
            }

            // Normal append (text or media) — and bump activity timestamp
            return $this->appendToBatch($user, $textTrimmed, $waMedia);
        }

        // ── If user replies with JUST a number, treat as task selection from previous list ──
        if (preg_match('/^(\d+)$/', $textTrimmed, $numMatch)) {
            $reply = $this->resolveNumericReply($user, (int) $numMatch[1], $waMessageId);
            if ($reply !== null) {
                return $reply;
            }
        }

        $cmd = strtoupper(trim($command));

        if ($user->isManager()) {
            $managerResult = $this->tryManagerCommand($user, $cmd, $fullMessage, $waMedia);
            if ($managerResult !== null) return $managerResult;
        }

        return match ($cmd) {
            'START'    => $this->handleStart($user, $fullMessage),
            'UPDATE'   => $this->handleUpdate($user, $fullMessage, $waMessageId),
            'COMPLETE' => $this->handleComplete($user, $fullMessage, $waMessageId),
            'DELAY'    => $this->handleDelay($user, $fullMessage),
            'ESCALATE' => $this->handleEscalate($user, $fullMessage),
            'SCORE'    => $this->handleScore($user),
            'STATUS'   => $this->handleStatus($user),
            'MY TASKS' => $this->handleStatus($user),
            'HELP'     => $this->helpMessage($user),
            // Phase 1 — query commands (any role)
            'URGENT'   => $this->handleQuery($user, 'urgent'),
            'HIGH'     => $this->handleQuery($user, 'high'),
            'TODAY'    => $this->handleQuery($user, 'today'),
            'OVERDUE'  => $this->handleQuery($user, 'overdue'),
            'PENDING'  => $this->handleQuery($user, 'pending'),
            // Phase 1 — admin actions
            'CANCEL'   => $this->handleCancel($user, $fullMessage),
            'REASSIGN' => $this->handleReassign($user, $fullMessage),
            'FORWARD'  => $this->handleReassign($user, $fullMessage),
            'REOPEN'   => $this->handleReopen($user, $fullMessage),
            // Phase 2/3 — inter-user chat
            'CHAT'     => $this->handleChat($user, $fullMessage),
            'DM'       => $this->handleChat($user, $fullMessage),
            'REPLY'    => $this->handleReply($user, $fullMessage),
            // Phase 3 — CLOSE when NOT in chat: just inform user
            'CLOSE'    => "ℹ️ You're not in a chat session.\n\nReply *HELP* for commands.",
            'END'      => "ℹ️ You're not in a chat session.\n\nReply *HELP* for commands.",
            default    => $this->handleUnknown($user, $fullMessage, $waMessageId),
        };
    }

    private function tryManagerCommand(User $manager, string $cmd, string $fullMessage, ?WaMedia $waMedia = null): ?string
    {
        if ($cmd === 'ASSIGN') return $this->handleAssign($manager, $fullMessage, $waMedia);
        if ($cmd === 'ADD EMPLOYEE') return $this->handleAddEmployee($manager, $fullMessage);
        if ($cmd === 'LIST' || $cmd === 'LIST EMPLOYEES') return $this->handleListEmployees($manager);
        if ($cmd === 'ALL') return $this->handleAll($manager);
        if ($cmd === 'TEAM') return $this->handleTeamTree($manager);
        if ($cmd === 'REPORT TODAY' || $cmd === 'REPORT WEEK' || $cmd === 'REPORT') return $this->handleReport($manager, $cmd);
        if ($cmd === 'VERIFY') return $this->handleVerify($manager, $fullMessage);
        if ($cmd === 'REJECT') return $this->handleReject($manager, $fullMessage);
        if ($cmd === 'SCHEDULE')    return $this->handleSchedule($manager, $fullMessage);
        if ($cmd === 'SCHEDULES')   return $this->handleSchedules($manager);
        if ($cmd === 'UNSCHEDULE')  return $this->handleUnschedule($manager, $fullMessage);
        if ($cmd === 'STATUS') {
            $parts = preg_split('/\s+/', trim($fullMessage), 2);
            if (count($parts) === 2 && !empty($parts[1])) {
                return $this->handleEmployeeStatus($manager, $fullMessage);
            }
        }
        return null;
    }

    private function handleAssign(User $manager, string $message, ?WaMedia $waMedia = null): string
    {
        $parts = preg_split('/\s+/', trim($message), 3);
        if (count($parts) < 2) {
            return "❌ *Invalid format*\n\nUse: *ASSIGN <name> [optional task text]*\n\nExample:\nASSIGN Parag fix the homepage\n\nSend any files (images/PDFs/Excel/Word/PPT) and they will be added to this task. Send *DONE* at the end to finalize.";
        }

        $employeeName = $parts[1];
        $initialText  = $parts[2] ?? null;  // optional first-line task text

        ActivityLog::record(
            'task', 'assign_attempt', 'info',
            "🔍 {$manager->name} attempting to assign to \"{$employeeName}\"",
            ['manager_id' => $manager->id]
        );

        // Find candidates by name (case insensitive)
        $candidates = User::where('is_active', true)
            ->where('id', '!=', $manager->id)
            ->where(function ($q) use ($employeeName) {
                $term = strtolower($employeeName);
                $q->whereRaw('LOWER(name) LIKE ?', ['%' . $term . '%']);
            })
            ->get();

        if ($candidates->isEmpty()) {
            return "❌ *Employee \"{$employeeName}\" not found*\n\nReply *LIST* to see your team.";
        }

        $assignable = $candidates->filter(fn($c) => $manager->canAssignTo($c))->values();

        if ($assignable->isEmpty()) {
            ActivityLog::record(
                'task', 'assign', 'failed',
                "🚫 Hierarchy block: {$manager->name} can't assign to {$candidates->first()->name} (not in sub-tree)",
                ['manager_id' => $manager->id]
            );
            return "🚫 *Not allowed*\n\n\"{$candidates->first()->name}\" is not in your team.\n\nReply *TEAM* to see your team tree.";
        }

        // Ambiguity — multiple matches → ask user to pick
        if ($assignable->count() > 1) {
            $msg = "🤔 *{$assignable->count()}* matches for *{$employeeName}*:\n\n";
            $candidateIds = [];
            foreach ($assignable as $i => $c) {
                $num = $i + 1;
                $msg .= "*{$num}.* {$c->name}";
                if ($c->designation) $msg .= " — {$c->designation}";
                $msg .= "\n";
                $candidateIds[$num] = $c->id;
            }
            $msg .= "\nReply with number: *1*, *2*, etc.";

            $this->setSessionAwaiting($manager, 'employee_selection', [
                'candidates'       => $candidateIds,
                'original_message' => $message,
            ]);

            return $msg;
        }

        $employee = $assignable->first();

        // PHASE 4 (revised): ASSIGN ALWAYS enters batch mode.
        // Initial text (if provided) becomes the first batch item.
        // User explicitly finalizes with DONE.
        return $this->startBatchTask($manager, $employee, $waMedia, $initialText);
    }

    /**
     * Shared helper: create a Task, notify the employee via WhatsApp, return manager-facing reply.
     * If a WaMedia is attached, also forward the file to the assignee.
     */
    private function createAndNotifyTask(User $manager, User $employee, string $taskTitle, ?WaMedia $waMedia = null): string
    {
        $dueDate = $this->parseDueDate($taskTitle);

        $task = Task::create([
            'tenant_id'     => 'default',
            'title'         => $taskTitle,
            'assigned_by'   => $manager->id,
            'assigned_to'   => $employee->id,
            'status'        => 'assigned',
            'priority'      => 'medium',
            'due_date'      => $dueDate,
            'reward_points' => 50,
        ]);

        ActivityLog::record(
            'task', 'assign', 'success',
            "✅ Task assigned to {$employee->name}: \"{$taskTitle}\"",
            ['task_id' => $task->id, 'employee_id' => $employee->id, 'manager_id' => $manager->id],
            $employee->phone
        );

        $task->load(['assignedTo', 'assignedBy']);
        $delivered = $this->wa->sendTaskAssignment($task);

        // ── If a media file was attached to the ASSIGN message, forward it now ──
        $mediaDelivered = null;
        if ($waMedia && $delivered) {
            $waMedia->update(['task_id' => $task->id]);
            $caption = "📎 Attachment for: " . substr($taskTitle, 0, 80);
            $mediaDelivered = $this->wa->sendMedia(
                $employee->phone,
                $waMedia->file_path,
                $waMedia->mime_type ?? 'application/octet-stream',
                $caption,
                $waMedia->filename
            );
        }

        $dueStr = $dueDate ? $dueDate->format('d M, h:i A') : 'No deadline';
        $deliveryNote = $delivered
            ? "✅ Employee notified via WhatsApp."
            : "⚠️ *WhatsApp notification failed.*\nTask saved but employee didn't receive message.\nCheck Logs tab for reason (likely 24-hr window or unverified number).";

        if ($waMedia) {
            $deliveryNote .= "\n" . ($mediaDelivered ? "📎 Attachment forwarded." : "⚠️ Attachment forward failed — check Logs.");
        }

        return "✅ *Task Assigned!*\n\n"
             . "👤 Employee: {$employee->name}\n"
             . "📋 Task: " . substr($taskTitle, 0, 200) . (strlen($taskTitle) > 200 ? '…' : '') . "\n"
             . "🆔 Task ID: T-" . substr($task->id, 0, 6) . "\n"
             . "📅 Due: {$dueStr}\n\n"
             . $deliveryNote;
    }

    private function handleAddEmployee(User $manager, string $message): string
    {
        $message = preg_replace('/^ADD EMPLOYEE\s+/i', '', trim($message));

        if (!preg_match('/(\+?\d{10,15})/', $message, $phoneMatch)) {
            return "❌ *Invalid format*\n\nUse:\n*ADD EMPLOYEE <name> <phone> <designation>*\n\nExample:\nADD EMPLOYEE Priya Sharma +919876543210 Field Executive";
        }

        $phone = $phoneMatch[1];
        if (!str_starts_with($phone, '+')) $phone = '+91' . $phone;

        $parts = preg_split('/' . preg_quote($phoneMatch[1], '/') . '/', $message);
        $name = trim($parts[0]);
        $designation = isset($parts[1]) ? trim($parts[1]) : 'Employee';

        if (empty($name)) return "❌ Name is required.";

        if (User::where('phone', $phone)->exists()) {
            return "❌ Employee with phone {$phone} already exists.";
        }

        // New employees added via WhatsApp report to the manager who added them
        $employee = User::create([
            'name'              => $name,
            'phone'             => $phone,
            'email'             => strtolower(str_replace(' ', '.', $name)) . '+' . substr(uniqid(), -4) . '@uicgroup.com',
            'password'          => Hash::make('Emp@2026'),
            'role'              => 'employee',
            'designation'       => $designation,
            'reports_to'        => $manager->id,  // ← hierarchy!
            'whatsapp_opted_in' => true,
            'is_active'         => true,
        ]);

        ActivityLog::record(
            'user', 'create', 'success',
            "✅ Employee added via WhatsApp: {$name} (reports to {$manager->name})",
            ['user_id' => $employee->id, 'created_by' => $manager->id, 'reports_to' => $manager->id],
            $phone
        );

        $this->wa->sendMessage($phone,
            "👋 *Welcome to TaskFlow!*\n\n"
            . "You've been added by {$manager->name}.\n\n"
            . "📱 You'll receive tasks here on WhatsApp.\n"
            . "Reply *HELP* anytime."
        );

        return "✅ *Employee Added!*\n\n"
             . "👤 Name: {$name}\n"
             . "📱 Phone: {$phone}\n"
             . "💼 Role: {$designation}\n"
             . "👔 Reports to: {$manager->name}";
    }

    private function handleListEmployees(User $manager): string
    {
        // Show entire sub-tree, not just direct reports
        $team = $manager->allDescendants()->filter(fn($u) => $u->role === 'employee');

        if ($team->isEmpty()) {
            return "📋 No team members yet.\n\nAdd with: *ADD EMPLOYEE <name> <phone> <role>*";
        }

        $msg = "👥 *Your Team* ({$team->count()})\n\n";
        foreach ($team->take(15) as $i => $emp) {
            $activeTasks = Task::where('assigned_to', $emp->id)
                ->whereNotIn('status', ['completed', 'verified'])->count();
            $msg .= ($i + 1) . ". *{$emp->name}*\n";
            $msg .= "   📱 {$emp->phone}\n";
            $msg .= "   💼 " . ($emp->designation ?: 'Employee') . "\n";
            $msg .= "   📋 {$activeTasks} active tasks\n\n";
        }
        if ($team->count() > 15) $msg .= "...and " . ($team->count() - 15) . " more. Check dashboard for full list.";
        return trim($msg);
    }

    private function handleTeamTree(User $manager): string
    {
        $msg = "🌳 *Your Team Tree*\n\n";
        $msg .= $this->renderTreeNode($manager, 0);
        $msg .= "\n_Reply *ALL* for flat list with task counts._";
        return trim($msg);
    }

    private function renderTreeNode(User $user, int $depth): string
    {
        $indent = str_repeat('  ', $depth);
        $arrow = $depth > 0 ? '└─ ' : '';
        $role = $user->isAdmin() ? '👑' : ($user->isManager() ? '👔' : '👤');

        // Counts at this level
        $active = Task::where('assigned_to', $user->id)
            ->whereIn('status', ['assigned', 'accepted', 'in_progress', 'waiting'])
            ->count();
        $overdue = Task::where('assigned_to', $user->id)
            ->where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'verified', 'cancelled'])
            ->count();

        $line = "{$indent}{$arrow}{$role} *{$user->name}*";
        if ($user->designation) $line .= " ({$user->designation})";
        $countStr = " — 📋{$active}";
        if ($overdue > 0) $countStr .= " ⏰{$overdue}";
        $line .= $countStr . "\n";

        $reports = $user->directReports()->where('is_active', true)->orderBy('name')->get();
        foreach ($reports as $report) {
            $line .= $this->renderTreeNode($report, $depth + 1);
        }
        return $line;
    }

    private function handleReport(User $manager, string $cmd): string
    {
        $period = str_contains($cmd, 'WEEK') ? 'week' : 'today';
        $start = $period === 'week' ? now()->startOfWeek() : today();

        // Get team member IDs (own sub-tree)
        $teamIds = $manager->isAdmin()
            ? User::where('is_active', true)->pluck('id')
            : $manager->allDescendants()->pluck('id');

        $assigned  = Task::whereIn('assigned_to', $teamIds)->whereBetween('created_at', [$start, now()])->count();
        $completed = Task::whereIn('assigned_to', $teamIds)
            ->whereIn('status', ['completed', 'verified'])
            ->whereBetween('completed_at', [$start, now()])->count();
        $overdue   = Task::whereIn('assigned_to', $teamIds)
            ->where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'verified'])->count();
        $teamSize = $teamIds->count();

        $label = $period === 'week' ? 'This Week' : 'Today';

        return "📊 *{$label}'s Report*\n\n"
             . "📋 Tasks Assigned: {$assigned}\n"
             . "✅ Completed: {$completed}\n"
             . "⚠️ Overdue: {$overdue}\n"
             . "👥 Team Size: {$teamSize}\n\n"
             . "View detailed report on dashboard.";
    }

    private function handleVerify(User $manager, string $message): string
    {
        $parts = preg_split('/\s+/', trim($message), 3);
        if (count($parts) < 2) return "❌ Use: *VERIFY <task-id>*";

        $taskId = str_replace('T-', '', $parts[1]);
        $task = Task::where('id', 'LIKE', $taskId . '%')->first();

        if (!$task) return "❌ Task not found: {$parts[1]}";
        if ($task->status !== 'completed') {
            return "⚠️ Task is not in completed state (current: {$task->status})";
        }

        $task->update(['status' => 'verified', 'verified_by' => $manager->id, 'verified_at' => now()]);

        if ($task->assignedTo) {
            $this->wa->sendMessage($task->assignedTo->phone,
                "🎉 *Task Verified!*\n\n📋 {$task->title}\n✅ Verified by {$manager->name}\n⭐ +{$task->reward_points} points!"
            );
            RecalculateApixScore::dispatch($task->assignedTo->id);
        }

        ActivityLog::record(
            'task', 'verify', 'success',
            "✅ Task T-" . substr($task->id, 0, 6) . " verified by {$manager->name}",
            ['task_id' => $task->id]
        );

        return "✅ Task T-" . substr($task->id, 0, 6) . " verified!\nEmployee notified.";
    }

    private function handleReject(User $manager, string $message): string
    {
        $parts = preg_split('/\s+/', trim($message), 3);
        if (count($parts) < 3) return "❌ Use: *REJECT <task-id> <reason>*";

        $taskId = str_replace('T-', '', $parts[1]);
        $reason = $parts[2];

        $task = Task::where('id', 'LIKE', $taskId . '%')->first();
        if (!$task) return "❌ Task not found: {$parts[1]}";

        // Idempotency
        if (in_array($task->status, ['rejected', 'cancelled', 'verified'], true)) {
            return "ℹ️ Task is already *{$task->status}*. No action taken.";
        }

        $task->update(['status' => 'rejected']);

        if ($task->assignedTo) {
            $this->wa->sendMessage($task->assignedTo->phone,
                "❌ *Task Rejected*\n\n📋 {$task->title}\nReason: {$reason}"
            );
        }

        ActivityLog::record('task', 'reject', 'info', "❌ Task rejected by {$manager->name}", ['task_id' => $task->id, 'reason' => $reason]);

        return "✅ Task rejected. Employee notified.";
    }

    private function handleEmployeeStatus(User $manager, string $message): string
    {
        $parts = preg_split('/\s+/', trim($message), 2);
        $name = $parts[1] ?? '';

        $candidates = User::where('is_active', true)
            ->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($name) . '%'])
            ->orderBy('name')
            ->get();

        if ($candidates->isEmpty()) return "❌ Employee \"{$name}\" not found.";

        if ($candidates->count() > 1) {
            $msg = "🤔 Multiple matches for *{$name}*:\n\n";
            foreach ($candidates as $i => $c) {
                $msg .= "*" . ($i + 1) . ".* {$c->name}";
                if ($c->designation) $msg .= " — {$c->designation}";
                $msg .= "\n";
            }
            $msg .= "\nUse full name.";
            return $msg;
        }

        $employee = $candidates->first();

        // Compute counts
        $activeTasks = Task::where('assigned_to', $employee->id)
            ->whereNotIn('status', ['completed', 'verified', 'cancelled', 'rejected'])
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 1 WHEN 'accepted' THEN 2 WHEN 'waiting' THEN 3 WHEN 'assigned' THEN 4 ELSE 5 END")
            ->orderBy('due_date')
            ->get();

        $todayDone = Task::where('assigned_to', $employee->id)
            ->whereIn('status', ['completed', 'verified'])
            ->whereDate('completed_at', today())->count();

        $overdueCount = $activeTasks->filter(fn($t) => $t->due_date && $t->due_date->isPast())->count();

        // Currently working on (in_progress)
        $current = $activeTasks->first(fn($t) => $t->status === 'in_progress');

        $msg = "📊 *{$employee->name}*\n";
        if ($employee->designation) $msg .= "_{$employee->designation}_\n";
        $msg .= "📱 {$employee->phone}\n\n";

        if ($current) {
            $msg .= "▶️ *Currently working on:*\n";
            $msg .= "   📋 " . $current->title . "\n";
            $msg .= "   🆔 T-" . substr($current->id, 0, 6) . "\n";
            if ($current->due_date) {
                $diff = $current->due_date->diffForHumans(null, true);
                $msg .= "   📅 Due: " . $current->due_date->format('d M, h:i A')
                     . ($current->due_date->isPast() ? " (⏰ {$diff} late)" : " ({$diff} left)")
                     . "\n";
            }
            $msg .= "\n";
        }

        $msg .= "📋 Active: *{$activeTasks->count()}*";
        if ($overdueCount > 0) $msg .= "  •  ⏰ Overdue: *{$overdueCount}*";
        $msg .= "\n";
        $msg .= "✅ Completed today: *{$todayDone}*\n";

        if ($activeTasks->isNotEmpty()) {
            $msg .= "\n*All active tasks (with delay):*\n";
            foreach ($activeTasks as $i => $t) {
                $num = $i + 1;
                $msg .= "{$num}. " . $t->title . "\n";
                $msg .= "   _Status: {$t->status}_";
                if ($t->due_date) {
                    if ($t->due_date->isPast()) {
                        $msg .= "  •  ⏰ *" . $t->due_date->diffForHumans(null, true) . " late*";
                    } else {
                        $msg .= "  •  ⏳ " . $t->due_date->diffForHumans(null, true) . " left";
                    }
                }
                $msg .= "\n";
            }
        }
        // Split into multiple WhatsApp messages if too long (4096 char limit)
        return $this->sendChunksAndReturnLast($manager->phone, rtrim($msg));
    }

    private function handleStart(User $user, string $message): string
    {
        $resolve = $this->resolveTaskForCommand($user, 'START', $message);
        if (isset($resolve['reply'])) return $resolve['reply'];
        $task = $resolve['task'];

        // Idempotency check
        $freshStatus = Task::where('id', $task->id)->value('status');
        if ($freshStatus === 'in_progress') {
            return "ℹ️ Task is already *in_progress*. Keep working — send *UPDATE* or *COMPLETE*.\n\n📋 " . $task->title;
        }
        if (in_array($freshStatus, ['completed', 'verified', 'cancelled', 'rejected'], true)) {
            return "ℹ️ Task is already *{$freshStatus}*. Cannot start.\n\n📋 " . $task->title;
        }

        $task->update(['status' => 'in_progress']);
        $this->logUpdate($task, $user, 'start', $message);

        if ($task->assignedBy && $task->assignedBy->phone) {
            $this->wa->sendMessage($task->assignedBy->phone,
                "▶️ *{$user->name} started task*\n\n📋 " . $task->title
            );
        }
        return "✅ *Task Started!*\n\n📋 {$task->title}\n\nSend updates with *UPDATE*\nMark done with *COMPLETE*";
    }

    private function handleUpdate(User $user, string $message, ?string $waMessageId): string
    {
        $resolve = $this->resolveTaskForCommand($user, 'UPDATE', $message);
        if (isset($resolve['reply'])) return $resolve['reply'];
        $task = $resolve['task'];

        $this->logUpdate($task, $user, 'update', $message, $waMessageId);

        if ($task->assignedBy && $task->assignedBy->phone) {
            // Strip "UPDATE" + optional task number prefix
            $cleanMsg = preg_replace('/^UPDATE\s*(\d+)?\s*/i', '', $message);
            $this->wa->sendMessage($task->assignedBy->phone,
                "📝 *{$user->name} update*\n\n📋 " . $task->title . "\n💬 " . trim($cleanMsg)
            );
        }
        return "📝 *Update logged!*\n\nKeep going 💪\nSend *COMPLETE* when done.";
    }

    private function handleComplete(User $user, string $message, ?string $waMessageId): string
    {
        $resolve = $this->resolveTaskForCommand($user, 'COMPLETE', $message);
        if (isset($resolve['reply'])) return $resolve['reply'];
        $task = $resolve['task'];

        // Idempotency — re-fetch fresh status from DB to defeat race conditions
        // (e.g. concurrent webhook retries that beat our dedup cache).
        $freshStatus = Task::where('id', $task->id)->value('status');
        if (in_array($freshStatus, ['completed', 'verified', 'cancelled', 'rejected'], true)) {
            return "ℹ️ Task is already *{$freshStatus}*. No action taken.\n\n📋 " . $task->title;
        }

        $task->update(['status' => 'completed', 'completed_at' => now()]);
        $this->logUpdate($task, $user, 'complete', $message, $waMessageId);

        if ($task->assignedBy && $task->assignedBy->phone) {
            $this->wa->sendMessage($task->assignedBy->phone,
                "🎉 *{$user->name} completed task*\n\n"
                . "📋 " . $task->title . "\n"
                . "🆔 T-" . substr($task->id, 0, 6) . "\n\n"
                . "Reply *VERIFY T-" . substr($task->id, 0, 6) . "* to approve"
            );
        }
        RecalculateApixScore::dispatch($user->id);

        return "🎉 *Task Completed!*\n\n📋 {$task->title}\n\nManager notified for verification.";
    }

    private function handleDelay(User $user, string $message): string
    {
        $resolve = $this->resolveTaskForCommand($user, 'DELAY', $message);
        if (isset($resolve['reply'])) return $resolve['reply'];
        $task = $resolve['task'];

        $task->update(['status' => 'waiting']);
        $this->logUpdate($task, $user, 'delay', $message);

        if ($task->assignedBy && $task->assignedBy->phone) {
            $reason = preg_replace('/^DELAY\s*(\d+)?\s*/i', '', $message);
            $this->wa->sendMessage($task->assignedBy->phone,
                "⏰ *{$user->name} reported delay*\n\n📋 " . $task->title . "\nReason: " . trim($reason)
            );
        }
        return "⏰ *Delay noted.* Manager informed.";
    }

    private function handleEscalate(User $user, string $message): string
    {
        $resolve = $this->resolveTaskForCommand($user, 'ESCALATE', $message);
        if (isset($resolve['reply'])) return $resolve['reply'];
        $task = $resolve['task'];

        $task->update(['status' => 'escalated']);
        $this->logUpdate($task, $user, 'escalate', $message);

        if ($task->assignedBy && $task->assignedBy->phone) {
            $issue = preg_replace('/^ESCALATE\s*(\d+)?\s*/i', '', $message);
            $this->wa->sendMessage($task->assignedBy->phone,
                "🚨 *{$user->name} escalated*\n\n📋 " . $task->title . "\nIssue: " . trim($issue)
            );
        }
        return "🚨 *Escalated to manager.*";
    }

    private function handleScore(User $user): string
    {
        $score = $user->apixScores()->where('score_date', today())->first();
        if (!$score) return "📊 *Your APIX Today*\n\nNo score yet. Complete tasks to build!";

        $band = $this->getApixBand($score->apix_score);
        return "📊 *Your APIX Score*\n\n🏆 {$score->apix_score} — {$band}";
    }

    /**
     * Split a long message into chunks under WhatsApp's 4096-char limit and send all but the last
     * directly via the WhatsApp service. Return the last chunk so ProcessInboundWhatsApp sends it
     * (preserving correct visual order in the recipient's chat).
     *
     * If the message fits in one chunk, just returns it unchanged.
     */
    private function sendChunksAndReturnLast(string $phone, string $message, int $maxLen = 3500): string
    {
        if (strlen($message) <= $maxLen) {
            return $message; // single message, normal flow
        }

        $chunks = $this->splitMessage($message, $maxLen);
        if (count($chunks) === 1) return $chunks[0];

        // Send all but last directly (these appear first in WhatsApp)
        $last = array_pop($chunks);
        foreach ($chunks as $idx => $chunk) {
            $continued = "📋 *(continued " . ($idx + 2) . "/" . (count($chunks) + 1) . ")*\n\n";
            $body = $idx === 0 ? $chunk : $continued . $chunk;
            $this->wa->sendMessage($phone, $body);
        }
        // Add continued marker to last chunk too
        $totalParts = count($chunks) + 1;
        $last = "📋 *(continued {$totalParts}/{$totalParts})*\n\n" . $last;
        return $last;
    }

    /**
     * Split text into chunks no larger than $maxLen, breaking at newlines when possible.
     */
    private function splitMessage(string $msg, int $maxLen): array
    {
        if (strlen($msg) <= $maxLen) return [$msg];

        $lines  = explode("\n", $msg);
        $chunks = [];
        $current = '';

        foreach ($lines as $line) {
            // Single line longer than maxLen → hard-split mid-line
            if (strlen($line) > $maxLen) {
                if ($current !== '') {
                    $chunks[] = rtrim($current);
                    $current = '';
                }
                foreach (str_split($line, $maxLen - 50) as $piece) {
                    $chunks[] = $piece;
                }
                continue;
            }

            if (strlen($current) + strlen($line) + 1 > $maxLen && $current !== '') {
                $chunks[] = rtrim($current);
                $current = $line . "\n";
            } else {
                $current .= $line . "\n";
            }
        }
        if (trim($current) !== '') $chunks[] = rtrim($current);
        return $chunks;
    }

    private function handleStatus(User $user): string
    {
        $tasks = Task::where('assigned_to', $user->id)
            ->whereNotIn('status', ['completed', 'verified', 'cancelled', 'rejected'])
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 1 WHEN 'accepted' THEN 2 WHEN 'waiting' THEN 3 WHEN 'assigned' THEN 4 ELSE 5 END")
            ->orderBy('due_date')
            ->get();

        if ($tasks->isEmpty()) return "✅ No pending tasks! Great work.";

        $count = $tasks->count();
        $header = "📋 *Your Active Tasks ({$count})*\n\n";

        // Build full message — no title truncation
        $msg = $header;
        foreach ($tasks as $i => $task) {
            $num = $i + 1;
            $due = $task->due_date ? $task->due_date->format('d M h:i A') : 'No deadline';
            $msg .= "*{$num}.* " . $task->title . "\n";
            $msg .= "   _Status:_ {$task->status}  •  _Due:_ {$due}";
            if ($task->due_date && $task->due_date->isPast()) {
                $msg .= " ⏰";
            }
            $msg .= "\n\n";
        }

        // Split into multiple WhatsApp messages if too long (4096 char limit)
        return $this->sendChunksAndReturnLast($user->phone, rtrim($msg));
    }

    private function handleUnknown(User $user, string $message, ?string $waMessageId): string
    {
        $trimmed = trim($message);
        $firstWord = strtoupper(explode(' ', $trimmed)[0] ?? '');

        // ── Manager-only: bare employee name → show their status ──
        // E.g., admin types "Parag" (not "STATUS Parag") → still get status
        if ($user->isManager() && $trimmed !== '' && preg_match('/^[A-Za-z][A-Za-z .]{1,40}$/', $trimmed)) {
            $wordCount = count(preg_split('/\s+/', $trimmed));
            if ($wordCount <= 2) {
                $matches = User::where('is_active', true)
                    ->where('id', '!=', $user->id)
                    ->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($trimmed) . '%'])
                    ->get();
                $manageable = $matches->filter(fn($c) => $user->canAssignTo($c))->values();

                if ($manageable->count() === 1) {
                    // Single match — show status directly
                    return $this->handleEmployeeStatus($user, "STATUS " . $manageable->first()->name);
                }
                if ($manageable->count() > 1) {
                    // Multiple — disambiguate
                    $msg = "🤔 Multiple matches for *{$trimmed}*:\n\n";
                    foreach ($manageable as $i => $c) {
                        $msg .= "*" . ($i + 1) . ".* {$c->name}";
                        if ($c->designation) $msg .= " — {$c->designation}";
                        $msg .= "\n";
                    }
                    $msg .= "\nReply: *STATUS <full name>* for details.";
                    return $msg;
                }
                // No match — fall through to typo / help
            }
        }

        // ── Natural-language intent detection (rule-based, no AI) ──
        // Handles phrases like:
        //   "Please assign parag create new file"      → ASSIGN
        //   "parag ko ye kaam de do submit report"     → ASSIGN
        //   "what is parag doing?"                     → STATUS
        //   "Parag kis par kaam kar raha hai"          → STATUS
        //   "show full team status"                    → ALL/LIST
        $intent = $this->detectNaturalIntent($user, $trimmed);
        if ($intent !== null) {
            return $this->routeNaturalIntent($user, $intent, $waMessageId);
        }

        // ── Typo suggestion: did you mean a known command? ──
        if (strlen($firstWord) >= 3) {
            $known = [
                // employee
                'START', 'UPDATE', 'COMPLETE', 'DELAY', 'ESCALATE', 'SCORE', 'STATUS', 'HELP',
                'URGENT', 'HIGH', 'TODAY', 'OVERDUE', 'PENDING', 'CHAT', 'REPLY',
                'DONE', 'CANCEL', 'ABORT', 'FINISH', 'CLOSE',
                // manager
                'ASSIGN', 'LIST', 'TEAM', 'ALL', 'VERIFY', 'REJECT', 'REPORT',
                'REASSIGN', 'FORWARD', 'REOPEN',
            ];
            $bestMatch = null;
            $bestDist = 99;
            foreach ($known as $cmd) {
                $d = levenshtein($firstWord, $cmd);
                if ($d < $bestDist) { $bestDist = $d; $bestMatch = $cmd; }
            }
            if ($bestDist > 0 && $bestDist <= 2) {
                return "🤔 I didn't understand *\"{$firstWord}\"*.\n\n💡 Did you mean *{$bestMatch}*?\n\nReply *HELP* for the full command list.";
            }
        }

        // ── Fallback: log as raw update on active task if message is substantial ──
        $task = $this->getActiveTask($user);
        if ($task && strlen($message) > 10) {
            $this->logUpdate($task, $user, 'update', $message, $waMessageId);
            if ($task->assignedBy && $task->assignedBy->phone) {
                $this->wa->sendMessage($task->assignedBy->phone,
                    "📝 *{$user->name} update*\n\n📋 " . $task->title . "\n💬 " . $message
                );
            }
            return "📝 Update logged.\n\nSend *HELP* for commands.";
        }
        return $this->helpMessage($user);
    }

    // ════════════════════════════════════════════════════════════════════
    // PHASE 5 — NATURAL LANGUAGE INTENT DETECTION (rule-based, no AI)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Try to figure out what the user wants from free-form text.
     * Returns ['intent' => 'assign|status|complete|list', 'target' => User, ...] or null.
     */
    private function detectNaturalIntent(User $user, string $message): ?array
    {
        if (strlen($message) < 4) return null;

        $lower = ' ' . strtolower($message) . ' ';

        // Find the mentioned person (if any)
        $target = $this->findUserMentionedIn($message, $user);

        // Question-y signals push toward STATUS over ASSIGN
        $isQuestion = (str_contains($message, '?') ||
            (bool) preg_match('/\b(kya|kis|kaun|kahan|kab|what|how|where|when|which|why)\b/i', $message));

        // Keyword score per intent
        $scores = ['status' => 0, 'assign' => 0, 'complete' => 0, 'list' => 0];

        // ─── STATUS keywords ───
        $statusKw = [
            'kis par kaam', 'kis pr kaam', 'kis par work', 'kya kar rah', 'kya kar rha',
            'kaam kya', 'kya kaam', 'kaam batao', 'task batao', 'task kya',
            'what is doing', 'what are doing', 'what doing', 'whats doing', "what's doing",
            'doing what', 'working on', 'progress of', 'status of',
            'progress kya', 'how is doing', 'how is', "how's",
            ' doing ', ' working ', ' status ', ' progress ',
        ];
        foreach ($statusKw as $kw) {
            if (str_contains($lower, $kw)) $scores['status']++;
        }
        if ($isQuestion) $scores['status']++;

        // ─── ASSIGN keywords ───
        $assignKw = [
            'assign', 'please assign', 'delegate',
            'kaam de do', 'kaam dedo', 'kaam de ', 'kaam do ', 'kaam dena',
            'task de do', 'task dedo', 'task de ', 'task do ',
            'task dena', 'task assign',
            ' de do ', ' dedo ', ' de dena ',
            'ko de ', 'ko dedo', 'ko de do',
            'create task', 'new task', 'naya kaam', 'naya task',
            'ye kaam', 'yeh kaam', 'is kaam',
            'give task', 'give the task', 'give him task', 'give her task',
            'karwa do', 'karwado',
        ];
        foreach ($assignKw as $kw) {
            if (str_contains($lower, $kw)) $scores['assign']++;
        }
        if ($isQuestion) $scores['assign']--;

        // ─── COMPLETE keywords ───
        $completeKw = [
            'complete kar', 'complete kr', 'mark done', 'mark complete',
            'done kar', 'finish kar', 'finished',
            'ho gaya', 'khatam', 'task done',
        ];
        foreach ($completeKw as $kw) {
            if (str_contains($lower, $kw)) $scores['complete']++;
        }

        // ─── LIST/ALL keywords ───
        $listKw = [
            'show team', 'show all', 'list team', 'list of team', 'whole team',
            'all task', 'puri team', 'team dikha', 'sab dikha', 'everyone',
            'all employee',
        ];
        foreach ($listKw as $kw) {
            if (str_contains($lower, $kw)) $scores['list']++;
        }

        // Pick best
        arsort($scores);
        $topIntent = key($scores);
        $topScore  = $scores[$topIntent];
        if ($topScore <= 0) return null;

        // ASSIGN and STATUS need a target person
        if ($topIntent === 'status') {
            if (!$target) return null;
            return ['intent' => 'status', 'target' => $target];
        }
        if ($topIntent === 'assign') {
            if (!$target) return null;
            $taskText = $this->extractTaskFromMessage($message, $target);
            return ['intent' => 'assign', 'target' => $target, 'task' => $taskText];
        }
        if ($topIntent === 'list') {
            return ['intent' => 'list'];
        }
        // COMPLETE — needs task selection, skip natural-lang for now
        return null;
    }

    /**
     * Find an active user mentioned in the message (by full name or first name).
     */
    private function findUserMentionedIn(string $message, User $askingUser): ?User
    {
        $lower = strtolower($message);
        $allUsers = User::where('is_active', true)
            ->where('id', '!=', $askingUser->id)
            ->get();

        // Try longest match first (full name)
        $best = null;
        $bestLen = 0;
        foreach ($allUsers as $u) {
            $nameLower = strtolower($u->name);
            if (strlen($nameLower) >= 3 && str_contains($lower, $nameLower) && strlen($nameLower) > $bestLen) {
                $best = $u;
                $bestLen = strlen($nameLower);
            }
        }
        if ($best) return $best;

        // First name match (word boundary)
        foreach ($allUsers as $u) {
            $firstName = strtolower(explode(' ', $u->name)[0]);
            if (strlen($firstName) >= 3 && preg_match('/\b' . preg_quote($firstName, '/') . '\b/i', $message)) {
                return $u;
            }
        }
        return null;
    }

    /**
     * Strip the assign-intent fluff from a message to extract just the task description.
     */
    private function extractTaskFromMessage(string $message, User $target): string
    {
        $cleaned = $message;

        // Remove the target's full name and first name
        $cleaned = preg_replace('/\b' . preg_quote($target->name, '/') . '\b/i', '', $cleaned);
        $firstName = explode(' ', $target->name)[0];
        if (strlen($firstName) >= 3) {
            $cleaned = preg_replace('/\b' . preg_quote($firstName, '/') . '\b/i', '', $cleaned);
        }

        // Remove common assign-intent stopwords (multi-word first to avoid partial matches)
        $stopwords = [
            'please assign', 'kindly assign', 'pls assign',
            'kaam de do', 'kaam dedo', 'kaam de dena', 'kaam de', 'kaam do',
            'task de do', 'task dedo', 'task de', 'task do', 'task dena',
            'ko de do', 'ko dedo', 'ko de',
            'create task', 'new task', 'create a task', 'add task',
            'naya kaam', 'naya task', 'ye kaam', 'yeh kaam', 'is kaam',
            'give task', 'give the task', 'give him', 'give her',
            'karwa do', 'karwado',
            'please', 'pleas', 'plz', 'kindly',
            'assign', 'delegate', 'task',
        ];
        foreach ($stopwords as $sw) {
            $cleaned = preg_replace('/\b' . preg_quote($sw, '/') . '\b/i', '', $cleaned);
        }

        // Cleanup punctuation + spaces
        $cleaned = preg_replace('/[,;:]+/', ' ', $cleaned);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned, " \t\n\r.-");

        return $cleaned;
    }

    /**
     * Route a detected natural-language intent to the right handler.
     * Prepends a "🧠 Understood as: ..." line for transparency.
     */
    private function routeNaturalIntent(User $user, array $intent, ?string $waMessageId): string
    {
        $type = $intent['intent'];

        if ($type === 'status' && isset($intent['target'])) {
            $target = $intent['target'];
            $understanding = "🧠 *Understood as:* Status check for *{$target->name}*\n\n———\n\n";
            return $understanding . $this->handleEmployeeStatus($user, "STATUS " . $target->name);
        }

        if ($type === 'assign' && isset($intent['target'])) {
            if (!$user->isManager()) {
                return "🚫 Only managers can assign tasks.";
            }
            $target = $intent['target'];
            $taskText = $intent['task'] ?? '';
            $understanding = "🧠 *Understood as:* Assign to *{$target->name}*"
                . ($taskText !== '' ? " — \"" . $taskText . "\"" : "")
                . "\n\n———\n\n";

            $synthetic = "ASSIGN " . $target->name;
            if ($taskText !== '') $synthetic .= " " . $taskText;
            return $understanding . $this->handleAssign($user, $synthetic);
        }

        if ($type === 'list') {
            if ($user->isManager()) {
                $understanding = "🧠 *Understood as:* Show full team overview\n\n———\n\n";
                return $understanding . $this->handleAll($user);
            }
            return "Reply *STATUS* to view your own tasks.";
        }

        return "";
    }

    // ════════════════════════════════════════════════════════════════════
    // PHASE 1 — QUERY COMMANDS (URGENT / HIGH / TODAY / OVERDUE / PENDING)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Generic task query for both employees (their assigned tasks) and managers (tasks they assigned).
     */
    private function handleQuery(User $user, string $filter): string
    {
        // Scope: employees see assigned_to=self; managers see assigned_by=self
        $query = Task::query();
        if ($user->isManager()) {
            $query->where('assigned_by', $user->id);
        } else {
            $query->where('assigned_to', $user->id);
        }

        $labelMap = [
            'urgent'  => '🔴 URGENT',
            'high'    => '🟠 HIGH PRIORITY',
            'today'   => '📅 TODAY',
            'overdue' => '⏰ OVERDUE',
            'pending' => '📋 PENDING (not started)',
        ];
        $label = $labelMap[$filter] ?? strtoupper($filter);

        switch ($filter) {
            case 'urgent':
                $query->where('priority', 'critical')
                      ->whereNotIn('status', ['completed', 'verified', 'cancelled']);
                break;
            case 'high':
                $query->whereIn('priority', ['high', 'critical'])
                      ->whereNotIn('status', ['completed', 'verified', 'cancelled']);
                break;
            case 'today':
                $query->whereDate('due_date', today())
                      ->whereNotIn('status', ['completed', 'verified', 'cancelled']);
                break;
            case 'overdue':
                $query->where('due_date', '<', now())
                      ->whereNotIn('status', ['completed', 'verified', 'cancelled']);
                break;
            case 'pending':
                $query->where('status', 'assigned');
                break;
            default:
                return "Unknown query: {$filter}";
        }

        $tasks = $query->with(['assignedTo', 'assignedBy'])
                       ->orderBy('due_date', 'asc')
                       ->orderBy('created_at', 'desc')
                       ->limit(20)
                       ->get();

        if ($tasks->isEmpty()) {
            return "{$label}\n\n✨ No {$filter} tasks. All clear!";
        }

        $msg = "{$label} — *{$tasks->count()}* tasks:\n\n";
        foreach ($tasks as $i => $t) {
            $num = $i + 1;
            $who = $user->isManager()
                ? "👤 {$t->assignedTo?->name}"
                : "👤 From: {$t->assignedBy?->name}";
            $due = $t->due_date ? $t->due_date->format('d M, h:i A') : 'No deadline';
            $msg .= "*{$num}.* {$t->title}\n   {$who}\n   📅 {$due}  •  Status: {$t->status}\n\n";
        }
        return trim($msg);
    }

    // ════════════════════════════════════════════════════════════════════
    // PHASE 1 — CANCEL & REASSIGN (manager actions)
    // ════════════════════════════════════════════════════════════════════

    /**
     * CANCEL T-abc123 [optional reason]
     */
    private function handleCancel(User $user, string $message): string
    {
        if (!$user->isManager()) {
            return "🚫 Only managers/admins can cancel tasks.";
        }

        $parts = preg_split('/\s+/', trim($message), 3);
        if (count($parts) < 2) {
            return "❌ *Invalid format*\n\nUse:\n*CANCEL T-abc123 [reason]*\n\nExample:\nCANCEL T-019e94 client postponed";
        }

        $shortId = strtolower(str_replace('T-', '', $parts[1]));
        $reason  = $parts[2] ?? 'No reason given';

        $task = Task::with(['assignedTo', 'assignedBy'])
            ->whereRaw('LOWER(id::text) LIKE ?', [strtolower($shortId) . '%'])
            ->first();

        if (!$task) {
            return "❌ Task *T-{$shortId}* not found.";
        }

        if ($task->assigned_by !== $user->id && !($task->assignedTo && $user->canAssignTo($task->assignedTo))) {
            return "🚫 You didn't assign this task — you can't cancel it.";
        }

        if (in_array($task->status, ['completed', 'verified', 'cancelled'])) {
            return "⚠️ Task is already *{$task->status}* — cannot be cancelled.";
        }

        $task->update(['status' => 'cancelled']);
        ActivityLog::record(
            'task', 'cancel', 'success',
            "🗑 Task cancelled by {$user->name}: \"{$task->title}\" — {$reason}",
            ['task_id' => $task->id]
        );

        // Notify assignee
        if ($task->assignedTo && $task->assignedTo->phone) {
            $this->wa->sendMessage($task->assignedTo->phone,
                "🚫 *Task Cancelled*\n\n"
                . "📋 " . $task->title . "\n"
                . "👤 By: {$user->name}\n"
                . "Reason: {$reason}"
            );
        }

        return "🚫 *Task Cancelled*\n\n📋 {$task->title}\n👤 Was assigned to: " . ($task->assignedTo?->name ?? 'Unknown') . "\nAssignee notified.";
    }

    /**
     * REASSIGN T-abc123 NewName  (or FORWARD as alias)
     */
    private function handleReassign(User $user, string $message): string
    {
        if (!$user->isManager()) {
            return "🚫 Only managers/admins can reassign tasks.";
        }

        $parts = preg_split('/\s+/', trim($message), 3);
        if (count($parts) < 3) {
            return "❌ *Invalid format*\n\nUse:\n*REASSIGN T-abc123 NewName*\n\nExample:\nREASSIGN T-019e94 Rahul";
        }

        $shortId = strtolower(str_replace('T-', '', $parts[1]));
        $newName = $parts[2];

        $task = Task::with(['assignedTo', 'assignedBy'])
            ->whereRaw('LOWER(id::text) LIKE ?', [strtolower($shortId) . '%'])
            ->first();

        if (!$task) {
            return "❌ Task *T-{$shortId}* not found.";
        }

        if ($task->assigned_by !== $user->id && !($task->assignedTo && $user->canAssignTo($task->assignedTo))) {
            return "🚫 You didn't assign this task — you can't reassign it.";
        }

        if (in_array($task->status, ['completed', 'verified', 'cancelled'])) {
            return "⚠️ Task is *{$task->status}* — cannot be reassigned.";
        }

        // Find new employee
        $candidates = User::where('is_active', true)
            ->where('id', '!=', $user->id)
            ->where(function ($q) use ($newName) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($newName) . '%']);
            })
            ->get()
            ->filter(fn($c) => $user->canAssignTo($c))
            ->values();

        if ($candidates->isEmpty()) {
            return "❌ \"{$newName}\" not found in your team. Reply *LIST*.";
        }
        if ($candidates->count() > 1) {
            $msg = "🤔 Multiple matches for *{$newName}*:\n\n";
            foreach ($candidates as $i => $c) {
                $msg .= "*" . ($i + 1) . ".* {$c->name}\n";
            }
            $msg .= "\nUse full name: REASSIGN T-{$shortId} <FullName>";
            return $msg;
        }

        $newEmployee = $candidates->first();
        $oldEmployee = $task->assignedTo;

        $task->update([
            'assigned_to' => $newEmployee->id,
            'status'      => 'assigned',
        ]);

        ActivityLog::record(
            'task', 'reassign', 'success',
            "🔄 Task reassigned: {$oldEmployee?->name} → {$newEmployee->name} by {$user->name}",
            ['task_id' => $task->id, 'from_user' => $oldEmployee?->id, 'to_user' => $newEmployee->id]
        );

        // Notify old employee
        if ($oldEmployee && $oldEmployee->phone) {
            $this->wa->sendMessage($oldEmployee->phone,
                "🔄 *Task Removed*\n\n📋 " . $task->title . "\nTask is now assigned to {$newEmployee->name}."
            );
        }

        // Notify new employee with full task details
        $task->load(['assignedTo', 'assignedBy']);
        $delivered = $this->wa->sendTaskAssignment($task);

        $deliveryNote = $delivered
            ? "✅ {$newEmployee->name} notified."
            : "⚠️ Could not notify {$newEmployee->name} — check Logs.";

        return "🔄 *Task Reassigned*\n\n"
             . "📋 {$task->title}\n"
             . "👤 " . ($oldEmployee?->name ?? '?') . " → {$newEmployee->name}\n\n"
             . $deliveryNote;
    }

    // ════════════════════════════════════════════════════════════════════
    // PHASE 2 — INTER-USER CHAT (CHAT / DM / REPLY)
    // ════════════════════════════════════════════════════════════════════

    /**
     * CHAT <name> <message>  — one-shot DM (Phase 2)
     * CHAT <name>            — open conversational session (Phase 3 NEW)
     */
    private function handleChat(User $sender, string $message): string
    {
        $parts = preg_split('/\s+/', trim($message), 3);
        if (count($parts) < 2) {
            return "❌ *Format*\n\n"
                 . "*CHAT <name>* — start conversation (CLOSE to exit)\n"
                 . "*CHAT <name> <message>* — one-shot DM\n\n"
                 . "Example: CHAT Parag kal 10am free ho?";
        }

        // Phase 3: just "CHAT <name>" → open conversational mode
        if (count($parts) === 2) {
            return $this->startChatSession($sender, $parts[1]);
        }

        // Phase 2: "CHAT <name> <message>" → one-shot DM
        $targetName = $parts[1];
        $chatText   = $parts[2];

        $candidates = User::where('is_active', true)
            ->where('id', '!=', $sender->id)
            ->where(function ($q) use ($targetName) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($targetName) . '%']);
            })
            ->get();

        if ($candidates->isEmpty()) {
            return "❌ \"{$targetName}\" not found. Reply *LIST*.";
        }
        if ($candidates->count() > 1) {
            $msg = "🤔 Multiple matches for *{$targetName}*:\n\n";
            foreach ($candidates as $i => $c) {
                $msg .= "*" . ($i + 1) . ".* {$c->name}\n";
            }
            $msg .= "\nUse full name for precision.";
            return $msg;
        }

        $target = $candidates->first();
        if (!$target->phone) {
            return "❌ {$target->name} has no phone number — cannot send message.";
        }

        // Send forwarded message to target via bot
        $forwarded = "💬 *{$sender->name} says:*\n\n{$chatText}\n\n_Reply with *REPLY <your message>* to respond._";
        $delivered = $this->wa->sendMessage($target->phone, $forwarded);

        if (!$delivered) {
            return "⚠️ *Could not deliver to {$target->name}*\n\nLikely 24-hr window expired or number unverified. Check Logs tab.";
        }

        // Store chat context on TARGET (so REPLY routes back to sender)
        $targetState = is_array($target->wa_session_state) ? $target->wa_session_state : [];
        $targetState['last_chat_from_id']     = $sender->id;
        $targetState['last_chat_from_name']   = $sender->name;
        $targetState['last_chat_expires_at']  = now()->addMinutes(60)->toIso8601String();
        $target->wa_session_state = $targetState;
        $target->save();

        ActivityLog::record(
            'chat', 'send', 'success',
            "💬 {$sender->name} → {$target->name}: " . substr($chatText, 0, 60),
            ['from' => $sender->id, 'to' => $target->id]
        );

        return "✅ *Message sent to {$target->name}*\n\n💬 {$chatText}";
    }

    /**
     * REPLY <message> — replies to the last CHAT received
     */
    private function handleReply(User $sender, string $message): string
    {
        $state = is_array($sender->wa_session_state) ? $sender->wa_session_state : [];

        $fromId  = $state['last_chat_from_id'] ?? null;
        $expires = $state['last_chat_expires_at'] ?? null;

        if (!$fromId) {
            return "❌ You have no incoming message to reply to.\n\nUse *CHAT <name> <message>* to start a chat.";
        }

        if ($expires) {
            try {
                if (\Carbon\Carbon::parse($expires)->isPast()) {
                    // Clear stale chat context
                    unset($state['last_chat_from_id'], $state['last_chat_from_name'], $state['last_chat_expires_at']);
                    $sender->wa_session_state = empty($state) ? null : $state;
                    $sender->save();
                    return "❌ Reply window expired (60 min). Use *CHAT <name> <message>* fresh.";
                }
            } catch (\Exception $e) {
                // continue
            }
        }

        $target = User::find($fromId);
        if (!$target || !$target->phone) {
            return "❌ Original sender is unavailable.";
        }

        $cleanReply = trim(preg_replace('/^REPLY\s*/i', '', $message));
        if ($cleanReply === '') {
            return "❌ Reply is empty. Use: *REPLY <your message>*";
        }

        $forwarded = "💬 *{$sender->name} replied:*\n\n{$cleanReply}\n\n_Reply with *REPLY <message>* to continue._";
        $delivered = $this->wa->sendMessage($target->phone, $forwarded);

        if (!$delivered) {
            return "⚠️ *Could not deliver reply to {$target->name}*\n\nCheck Logs tab.";
        }

        // Reverse the chat context for the original sender (bidirectional thread)
        $targetState = is_array($target->wa_session_state) ? $target->wa_session_state : [];
        $targetState['last_chat_from_id']    = $sender->id;
        $targetState['last_chat_from_name']  = $sender->name;
        $targetState['last_chat_expires_at'] = now()->addMinutes(60)->toIso8601String();
        $target->wa_session_state = $targetState;
        $target->save();

        ActivityLog::record(
            'chat', 'reply', 'success',
            "💬 {$sender->name} replied to {$target->name}: " . substr($cleanReply, 0, 60),
            ['from' => $sender->id, 'to' => $target->id]
        );

        return "✅ *Reply sent to {$target->name}*";
    }

    // ════════════════════════════════════════════════════════════════════
    // PHASE 4 — BATCHED ASSIGN (multi-message task with attachments)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Start a batched task. Now invoked from BOTH:
     * - "ASSIGN Parag" alone (no initial text)
     * - "ASSIGN Parag Long data" (initialText = "Long data" becomes first batch entry)
     * - "ASSIGN Parag\nLong data" — same thing, newlines are whitespace
     * - ASSIGN with attached media file (waMedia is the first batch item)
     */
    private function startBatchTask(User $manager, User $employee, ?WaMedia $waMedia, ?string $initialText = null): string
    {
        $now = now()->toIso8601String();

        $textLines = [];
        if ($initialText !== null && trim($initialText) !== '') {
            $textLines[] = trim($initialText);
        }

        $mediaIds = [];
        if ($waMedia) {
            $mediaIds[] = $waMedia->id;
        }

        $this->setSessionAwaiting($manager, 'task_batch', [
            'task_for_user_id'   => $employee->id,
            'task_for_user_name' => $employee->name,
            'buffered_text'      => $textLines,
            'buffered_media'     => $mediaIds,
            'started_at'         => $now,
            'last_activity_at'   => $now,
        ], 60); // 60 min hard expiry (selection slot)

        $intro = "📦 *Building task for {$employee->name}*\n\n"
               . "Now send anything — text, images, PDFs, Excel, Word, PPT — it will all be added to this task.\n\n"
               . "Type *DONE* to finalize and send.\n"
               . "Type *CANCEL* to abort.\n"
               . "_⏰ Auto-sends after 2 min idle._\n";

        $hasContent = !empty($textLines) || $waMedia;
        if ($hasContent) {
            $bits = [];
            if (!empty($textLines)) $bits[] = count($textLines) . " text";
            if ($waMedia)           $bits[] = "1 " . $waMedia->type;
            $intro .= "\n✓ Already added: " . implode(' + ', $bits);
        }

        return $intro;
    }

    /**
     * Append text and/or media to the in-progress batch.
     * Updates last_activity_at so the 2-min idle check resets.
     */
    private function appendToBatch(User $user, string $text, ?WaMedia $waMedia): string
    {
        $state = is_array($user->wa_session_state) ? $user->wa_session_state : [];
        $textLines = $state['buffered_text'] ?? [];
        $mediaIds  = $state['buffered_media'] ?? [];

        $added = [];
        if ($text !== '') {
            $textLines[] = $text;
            $added[] = "text";
        }
        if ($waMedia) {
            $mediaIds[] = $waMedia->id;
            $added[] = $waMedia->type;
        }

        if (empty($added)) {
            return "📦 *Batch open*\n\nNothing to add. Type *DONE* to finalize.";
        }

        $state['buffered_text']    = $textLines;
        $state['buffered_media']   = $mediaIds;
        $state['last_activity_at'] = now()->toIso8601String();  // reset 2-min idle timer
        $user->wa_session_state    = $state;
        $user->save();

        $textCount = count($textLines);
        $mediaCount = count($mediaIds);
        $forName = $state['task_for_user_name'] ?? '?';

        return "✓ Added " . implode(' + ', $added) . " to task for *{$forName}*\n"
             . "📝 {$textCount} text · 📎 {$mediaCount} files\n\n"
             . "Type *DONE* to send, *CANCEL* to abort.\n"
             . "_⏰ Auto-sends after 2 min idle._";
    }

    /**
     * Finalize the batched task: create Task, forward all collected items to assignee.
     */
    private function finalizeBatchedTask(User $manager): string
    {
        $state = is_array($manager->wa_session_state) ? $manager->wa_session_state : [];
        $employeeId = $state['task_for_user_id'] ?? null;
        $textLines = $state['buffered_text'] ?? [];
        $mediaIds  = $state['buffered_media'] ?? [];

        // Clear batch state regardless of outcome
        foreach (['awaiting', 'task_for_user_id', 'task_for_user_name', 'buffered_text', 'buffered_media', 'started_at', 'last_activity_at', 'expires_at'] as $k) {
            unset($state[$k]);
        }
        $manager->wa_session_state = empty($state) ? null : $state;
        $manager->save();

        if (!$employeeId) {
            return "❌ Batch state lost. Try again with *ASSIGN <name>*.";
        }
        if (empty($textLines) && empty($mediaIds)) {
            return "❌ Batch is empty. Use *ASSIGN <name>* to start fresh.";
        }

        $employee = User::find($employeeId);
        if (!$employee || !$employee->is_active) {
            return "❌ Employee is no longer available. Discarding batch.";
        }

        if (!$manager->canAssignTo($employee)) {
            return "🚫 You can no longer assign to *{$employee->name}*.";
        }

        // Build title — first non-empty text, or fallback
        $title = '';
        foreach ($textLines as $line) {
            if (trim($line) !== '') {
                $title = $line;
                break;
            }
        }
        if ($title === '') {
            $title = count($mediaIds) > 0 ? "Task with " . count($mediaIds) . " attachments" : "Multi-message task";
        }
        // Truncate to fit title column (varchar 500)
        if (strlen($title) > 480) $title = substr($title, 0, 477) . '...';

        // Create task
        $dueDate = $this->parseDueDate($title);
        $task = Task::create([
            'tenant_id'     => 'default',
            'title'         => $title,
            'assigned_by'   => $manager->id,
            'assigned_to'   => $employee->id,
            'status'        => 'assigned',
            'priority'      => 'medium',
            'due_date'      => $dueDate,
            'reward_points' => 50,
        ]);

        ActivityLog::record(
            'task', 'assign_batch', 'success',
            "📦 Batched task assigned to {$employee->name}: \"{$title}\"",
            ['task_id' => $task->id, 'text_count' => count($textLines), 'media_count' => count($mediaIds)]
        );

        // Notify assignee
        $task->load(['assignedTo', 'assignedBy']);
        $notified = $this->wa->sendTaskAssignment($task);

        // Forward all buffered text lines (except the title's source line — to avoid duplicating)
        $textsForwarded = 0;
        foreach ($textLines as $line) {
            if (trim($line) === '') continue;
            $msg = "📋 *Task T-" . substr($task->id, 0, 6) . " — additional info:*\n\n" . $line;
            if ($this->wa->sendMessage($employee->phone, $msg)) {
                $textsForwarded++;
            }
        }

        // Forward all buffered media
        $mediaForwarded = 0;
        $mediaFailed = 0;
        foreach ($mediaIds as $mediaId) {
            $waMedia = WaMedia::find($mediaId);
            if (!$waMedia || !is_readable($waMedia->file_path)) {
                $mediaFailed++;
                continue;
            }
            $waMedia->update(['task_id' => $task->id]);
            $caption = "📎 For task T-" . substr($task->id, 0, 6);
            $ok = $this->wa->sendMedia(
                $employee->phone,
                $waMedia->file_path,
                $waMedia->mime_type ?? 'application/octet-stream',
                $caption,
                $waMedia->filename
            );
            if ($ok) $mediaForwarded++;
            else $mediaFailed++;
        }

        return "✅ *Batched Task Assigned!*\n\n"
             . "👤 To: {$employee->name}\n"
             . "🆔 T-" . substr($task->id, 0, 6) . "\n"
             . "📋 " . substr($title, 0, 100) . (strlen($title) > 100 ? '…' : '') . "\n\n"
             . ($notified ? "✅ Notification delivered.\n" : "⚠️ Notification failed.\n")
             . "📝 Text forwarded: {$textsForwarded}\n"
             . "📎 Media forwarded: {$mediaForwarded}"
             . ($mediaFailed > 0 ? " (⚠️ {$mediaFailed} failed)" : "");
    }

    /**
     * Cancel an in-progress batch.
     */
    private function cancelBatchedTask(User $manager): string
    {
        $state = is_array($manager->wa_session_state) ? $manager->wa_session_state : [];
        $forName = $state['task_for_user_name'] ?? null;

        foreach (['awaiting', 'task_for_user_id', 'task_for_user_name', 'buffered_text', 'buffered_media', 'started_at', 'last_activity_at', 'expires_at'] as $k) {
            unset($state[$k]);
        }
        $manager->wa_session_state = empty($state) ? null : $state;
        $manager->save();

        return "🚫 *Batch cancelled*" . ($forName ? " (was building for {$forName})" : "") . "\n\nNormal commands will work now.";
    }

    /**
     * Forward an attached media file in chat-mode to the peer.
     */
    private function forwardChatMedia(User $user, WaMedia $waMedia, string $caption): string
    {
        $state = is_array($user->wa_session_state) ? $user->wa_session_state : [];
        $peerId = $state['in_chat_with_id'] ?? null;
        if (!$peerId) return "";

        $peer = User::find($peerId);
        if (!$peer || !$peer->phone || !$peer->is_active) {
            foreach (['in_chat_with_id', 'in_chat_with_name', 'in_chat_started_at'] as $k) {
                unset($state[$k]);
            }
            $user->wa_session_state = empty($state) ? null : $state;
            $user->save();
            return "❌ Peer is no longer available. Chat auto-closed.";
        }

        if (!is_readable($waMedia->file_path)) {
            return "⚠️ File expire ho gayi server pe.";
        }

        $captionForPeer = "💬 *{$user->name}:* " . ($caption ?: '(file)');
        $ok = $this->wa->sendMedia(
            $peer->phone,
            $waMedia->file_path,
            $waMedia->mime_type ?? 'application/octet-stream',
            $captionForPeer,
            $waMedia->filename
        );

        if (!$ok) {
            return "⚠️ *{$peer->name}* couldn't deliver the file.";
        }
        return ""; // silent
    }

    // ════════════════════════════════════════════════════════════════════
    // PHASE 4 — ALL command (team overview with counts)
    // ════════════════════════════════════════════════════════════════════

    /**
     * ALL — list all employees in the manager's reachable tree with task counts.
     */
    private function handleAll(User $manager): string
    {
        $employees = User::where('is_active', true)
            ->where('id', '!=', $manager->id)
            ->orderBy('name')
            ->get();

        $manageable = $employees->filter(fn($e) => $manager->canAssignTo($e))->values();

        if ($manageable->isEmpty()) {
            return "❌ Your team has no assignable employees.\n\nReply *TEAM* to see hierarchy.";
        }

        $msg = "👥 *Team Overview — {$manageable->count()} people*\n\n";

        foreach ($manageable as $i => $emp) {
            $active = Task::where('assigned_to', $emp->id)
                ->whereIn('status', ['assigned', 'accepted', 'in_progress', 'waiting'])
                ->count();
            $overdue = Task::where('assigned_to', $emp->id)
                ->where('due_date', '<', now())
                ->whereNotIn('status', ['completed', 'verified', 'cancelled'])
                ->count();
            $completedToday = Task::where('assigned_to', $emp->id)
                ->whereIn('status', ['completed', 'verified'])
                ->whereDate('completed_at', today())
                ->count();
            $reports = User::where('reports_to', $emp->id)->where('is_active', true)->count();

            $num = $i + 1;
            $msg .= "*{$num}. {$emp->name}*";
            if ($emp->designation) $msg .= " — _{$emp->designation}_";
            $msg .= "\n";
            $msg .= "   📋 Active: {$active}";
            if ($overdue > 0) $msg .= "  •  ⏰ Overdue: *{$overdue}*";
            $msg .= "\n";
            $msg .= "   ✅ Today: {$completedToday}";
            if ($reports > 0) $msg .= "  •  👥 Manages: {$reports}";
            $msg .= "\n\n";
        }

        return trim($msg);
    }

    // ════════════════════════════════════════════════════════════════════
    // PHASE 6 — RECURRING SCHEDULES (SCHEDULE / SCHEDULES / UNSCHEDULE)
    // ════════════════════════════════════════════════════════════════════

    /**
     * SCHEDULE <name> <when> <task title>
     *
     * <when> can be:
     *   daily                  → every day
     *   mon / tue / ... / sun  → that single day each week
     *   mon,wed,fri            → multiple days
     *   weekdays               → Mon–Fri
     *   weekends               → Sat–Sun
     */
    private function handleSchedule(User $manager, string $message): string
    {
        if (!$manager->isManager()) {
            return "🚫 Only managers/admins can schedule recurring tasks.";
        }

        $parts = preg_split('/\s+/', trim($message), 4);
        if (count($parts) < 4) {
            return "❌ *Format:* SCHEDULE <name> <when> <task>\n\n"
                 . "*<when> options:*\n"
                 . "• `daily` — every day\n"
                 . "• `mon` / `tue` / `wed` / `thu` / `fri` / `sat` / `sun`\n"
                 . "• `mon,wed,fri` — multiple days\n"
                 . "• `weekdays` — Mon–Fri\n"
                 . "• `weekends` — Sat–Sun\n\n"
                 . "*Examples:*\n"
                 . "• SCHEDULE Parag daily send morning standup\n"
                 . "• SCHEDULE Parag mon,wed,fri client follow-up calls\n"
                 . "• SCHEDULE Rahul weekdays update inventory sheet";
        }

        $employeeName = $parts[1];
        $scheduleSpec = strtolower($parts[2]);
        $taskTitle    = $parts[3];

        // Find candidates
        $candidates = User::where('is_active', true)
            ->where('id', '!=', $manager->id)
            ->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($employeeName) . '%'])
            ->get();

        $assignable = $candidates->filter(fn($c) => $manager->canAssignTo($c))->values();

        if ($assignable->isEmpty()) {
            return "❌ Employee \"{$employeeName}\" not found in your team.\n\nReply *LIST* to see your team.";
        }
        if ($assignable->count() > 1) {
            $msg = "🤔 Multiple matches for *{$employeeName}*:\n\n";
            foreach ($assignable as $i => $c) {
                $msg .= "*" . ($i + 1) . ".* {$c->name}\n";
            }
            return $msg . "\nUse the full name.";
        }

        $employee = $assignable->first();

        // Parse schedule spec
        $scheduleType = null;
        $daysOfWeek   = null;

        if ($scheduleSpec === 'daily') {
            $scheduleType = 'daily';
        } elseif ($scheduleSpec === 'weekdays') {
            $scheduleType = 'weekly';
            $daysOfWeek   = ['mon', 'tue', 'wed', 'thu', 'fri'];
        } elseif (in_array($scheduleSpec, ['weekend', 'weekends'], true)) {
            $scheduleType = 'weekly';
            $daysOfWeek   = ['sat', 'sun'];
        } elseif (str_contains($scheduleSpec, ',')) {
            $scheduleType = 'weekly';
            $daysOfWeek   = [];
            foreach (explode(',', $scheduleSpec) as $d) {
                $n = $this->normalizeDayName(trim($d));
                if ($n) $daysOfWeek[] = $n;
            }
            if (empty($daysOfWeek)) {
                return "❌ Invalid days. Use: mon, tue, wed, thu, fri, sat, sun";
            }
            $daysOfWeek = array_values(array_unique($daysOfWeek));
        } else {
            $n = $this->normalizeDayName($scheduleSpec);
            if (!$n) {
                return "❌ Invalid schedule keyword: \"{$scheduleSpec}\"\n\n"
                     . "Use: daily, mon, tue, wed, thu, fri, sat, sun, weekdays, weekends, or comma-list.";
            }
            $scheduleType = 'weekly';
            $daysOfWeek   = [$n];
        }

        $schedule = TaskSchedule::create([
            'tenant_id'     => 'default',
            'title'         => $taskTitle,
            'assigned_by'   => $manager->id,
            'assigned_to'   => $employee->id,
            'schedule_type' => $scheduleType,
            'days_of_week'  => $daysOfWeek,
            'priority'      => 'medium',
            'reward_points' => 50,
            'is_active'     => true,
        ]);

        ActivityLog::record(
            'schedule', 'create', 'success',
            "📅 Schedule created by {$manager->name} for {$employee->name}: \"{$taskTitle}\"",
            ['schedule_id' => $schedule->id, 'type' => $scheduleType, 'days' => $daysOfWeek]
        );

        $whenStr = $scheduleType === 'daily'
            ? 'every day'
            : implode(', ', array_map(fn($d) => ucfirst($d), $daysOfWeek));

        return "✅ *Recurring Task Scheduled!*\n\n"
             . "👤 For: {$employee->name}\n"
             . "📋 " . substr($taskTitle, 0, 200) . "\n"
             . "📅 When: {$whenStr}\n"
             . "🆔 Schedule ID: S-" . substr($schedule->id, 0, 6) . "\n\n"
             . "Bot will auto-create the task at 8 AM IST on matching days.\n"
             . "_Reply *SCHEDULES* to list • *UNSCHEDULE S-xxx* to remove._";
    }

    /**
     * SCHEDULES — list all active recurring schedules created by this manager.
     */
    private function handleSchedules(User $manager): string
    {
        if (!$manager->isManager()) {
            return "🚫 Only managers/admins can view schedules.";
        }

        $schedules = TaskSchedule::with(['assignedTo'])
            ->where('assigned_by', $manager->id)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->get();

        if ($schedules->isEmpty()) {
            return "📅 No active recurring schedules.\n\nCreate one with:\n*SCHEDULE <name> <when> <task>*";
        }

        $msg = "📅 *Your Active Schedules ({$schedules->count()})*\n\n";
        foreach ($schedules as $i => $s) {
            $num = $i + 1;
            $when = $s->schedule_type === 'daily'
                ? 'Daily'
                : implode(', ', array_map(fn($d) => ucfirst($d), $s->days_of_week ?? []));
            $msg .= "*{$num}. S-" . substr($s->id, 0, 6) . "* → {$s->assignedTo->name}\n";
            $msg .= "   📋 " . substr($s->title, 0, 80) . (strlen($s->title) > 80 ? '…' : '') . "\n";
            $msg .= "   📅 {$when}\n";
            if ($s->last_dispatched_at) {
                $msg .= "   ⏰ Last sent: " . $s->last_dispatched_at->diffForHumans() . "\n";
            }
            $msg .= "\n";
        }
        $msg .= "_Reply *UNSCHEDULE S-xxx* to remove a schedule._";
        return $msg;
    }

    /**
     * UNSCHEDULE S-xxx — deactivate a recurring schedule.
     */
    private function handleUnschedule(User $manager, string $message): string
    {
        if (!$manager->isManager()) {
            return "🚫 Only managers/admins can remove schedules.";
        }

        $parts = preg_split('/\s+/', trim($message), 2);
        if (count($parts) < 2) {
            return "❌ Format: *UNSCHEDULE S-xxx*\n\nReply *SCHEDULES* to see your schedule IDs.";
        }

        $shortId = strtolower(str_replace('s-', '', $parts[1]));
        $schedule = TaskSchedule::whereRaw('LOWER(id::text) LIKE ?', [$shortId . '%'])
            ->first();

        if (!$schedule) {
            return "❌ Schedule not found: {$parts[1]}";
        }

        // Authority: creator or admin
        if ($schedule->assigned_by !== $manager->id && $manager->role !== 'admin') {
            return "🚫 You can only remove schedules you created.";
        }

        $schedule->update(['is_active' => false]);

        ActivityLog::record(
            'schedule', 'deactivate', 'success',
            "🚫 Schedule S-" . substr($schedule->id, 0, 6) . " deactivated by {$manager->name}",
            ['schedule_id' => $schedule->id]
        );

        return "🚫 *Schedule deactivated*\n\nS-" . substr($schedule->id, 0, 6)
             . " will no longer auto-create tasks.\n📋 " . substr($schedule->title, 0, 100);
    }

    /**
     * Normalize various day-name inputs (full name / abbreviation) to 3-letter lowercase.
     */
    private function normalizeDayName(string $input): ?string
    {
        $map = [
            'monday' => 'mon', 'mon' => 'mon',
            'tuesday' => 'tue', 'tue' => 'tue', 'tues' => 'tue',
            'wednesday' => 'wed', 'wed' => 'wed',
            'thursday' => 'thu', 'thu' => 'thu', 'thur' => 'thu', 'thurs' => 'thu',
            'friday' => 'fri', 'fri' => 'fri',
            'saturday' => 'sat', 'sat' => 'sat',
            'sunday' => 'sun', 'sun' => 'sun',
        ];
        return $map[strtolower(trim($input))] ?? null;
    }

    // ════════════════════════════════════════════════════════════════════
    // PHASE 3 — CONVERSATIONAL CHAT SESSION (CHAT <name> → CLOSE)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Start a persistent chat session between two users.
     * Both users enter chat-mode: any message they send (other than CLOSE)
     * is forwarded silently to the peer. Commands are disabled until CLOSE.
     */
    private function startChatSession(User $sender, string $targetName): string
    {
        // Find target by name
        $candidates = User::where('is_active', true)
            ->where('id', '!=', $sender->id)
            ->where(function ($q) use ($targetName) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($targetName) . '%']);
            })
            ->get();

        if ($candidates->isEmpty()) {
            return "❌ \"{$targetName}\" not found. Reply *LIST*.";
        }
        if ($candidates->count() > 1) {
            $msg = "🤔 Multiple matches for *{$targetName}*:\n\n";
            foreach ($candidates as $i => $c) {
                $msg .= "*" . ($i + 1) . ".* {$c->name}\n";
            }
            $msg .= "\nUse full name for precision.";
            return $msg;
        }

        $target = $candidates->first();
        if (!$target->phone) {
            return "❌ {$target->name} has no phone number.";
        }

        // If target is already in chat with someone OTHER than sender, block
        $targetState = is_array($target->wa_session_state) ? $target->wa_session_state : [];
        if (!empty($targetState['in_chat_with_id']) && $targetState['in_chat_with_id'] !== $sender->id) {
            return "❌ *{$target->name}* is currently in another chat. Try again later.";
        }

        // Notify target FIRST — if fails, don't change state
        $notify = "💬 *{$sender->name}* wants to start a chat with you.\n\n"
                . "Anything you send now goes directly to *{$sender->name}*.\n"
                . "🔚 Send *CLOSE* to end the chat.";

        if (!$this->wa->sendMessage($target->phone, $notify)) {
            return "⚠️ *{$target->name}* could not be notified. Check Logs tab.";
        }

        // Set BOTH sides
        $now = now()->toIso8601String();

        $senderState = is_array($sender->wa_session_state) ? $sender->wa_session_state : [];
        $senderState['in_chat_with_id']    = $target->id;
        $senderState['in_chat_with_name']  = $target->name;
        $senderState['in_chat_started_at'] = $now;
        $sender->wa_session_state = $senderState;
        $sender->save();

        $targetState['in_chat_with_id']    = $sender->id;
        $targetState['in_chat_with_name']  = $sender->name;
        $targetState['in_chat_started_at'] = $now;
        $target->wa_session_state = $targetState;
        $target->save();

        ActivityLog::record(
            'chat', 'session_start', 'success',
            "💬 Chat session opened: {$sender->name} ↔ {$target->name}",
            ['from' => $sender->id, 'to' => $target->id]
        );

        return "💬 *Chat with {$target->name} started!*\n\n"
             . "Anything you send now goes directly to *{$target->name}*.\n"
             . "Other commands won't work right now.\n\n"
             . "🔚 Send *CLOSE* to end the chat.";
    }

    /**
     * Close the chat session for this user AND the peer.
     */
    private function closeChatSession(User $user, bool $silentForPeer = false): string
    {
        $state = is_array($user->wa_session_state) ? $user->wa_session_state : [];
        $peerId = $state['in_chat_with_id'] ?? null;
        $peerName = $state['in_chat_with_name'] ?? 'unknown';

        // Clear user's chat fields (preserve other state like awaiting/options)
        foreach (['in_chat_with_id', 'in_chat_with_name', 'in_chat_started_at'] as $k) {
            unset($state[$k]);
        }
        $user->wa_session_state = empty($state) ? null : $state;
        $user->save();

        if (!$peerId) {
            return "🔚 No active chat session.";
        }

        // Clear peer's chat fields and notify them
        $peer = User::find($peerId);
        if ($peer) {
            $peerState = is_array($peer->wa_session_state) ? $peer->wa_session_state : [];
            foreach (['in_chat_with_id', 'in_chat_with_name', 'in_chat_started_at'] as $k) {
                unset($peerState[$k]);
            }
            $peer->wa_session_state = empty($peerState) ? null : $peerState;
            $peer->save();

            if (!$silentForPeer && $peer->phone) {
                $this->wa->sendMessage($peer->phone,
                    "🔚 *{$user->name}* closed the chat.\n\nNormal commands will work now."
                );
            }
        }

        ActivityLog::record(
            'chat', 'session_close', 'success',
            "🔚 Chat session closed by {$user->name} (peer: {$peerName})",
            ['user_id' => $user->id, 'peer_id' => $peerId]
        );

        return "🔚 *Chat with {$peerName} closed.*\n\nNormal commands will work now.";
    }

    /**
     * Forward a message from chat-mode user to their peer.
     * Returns empty string — ProcessInboundWhatsApp skips empty replies (no echo to sender).
     */
    private function forwardChatMessage(User $user, string $text): string
    {
        $state = is_array($user->wa_session_state) ? $user->wa_session_state : [];
        $peerId = $state['in_chat_with_id'] ?? null;
        if (!$peerId) {
            // Inconsistent state — shouldn't happen, but bail safely
            return "";
        }

        $peer = User::find($peerId);
        if (!$peer || !$peer->phone || !$peer->is_active) {
            // Peer gone — clean up and inform user
            foreach (['in_chat_with_id', 'in_chat_with_name', 'in_chat_started_at'] as $k) {
                unset($state[$k]);
            }
            $user->wa_session_state = empty($state) ? null : $state;
            $user->save();
            return "❌ Peer is no longer available. Chat auto-closed.\n\nNormal commands will work now.";
        }

        $forwarded = "💬 *{$user->name}:* {$text}";
        $delivered = $this->wa->sendMessage($peer->phone, $forwarded);

        if (!$delivered) {
            return "⚠️ *{$peer->name}* could not be delivered.\n\nSend *CLOSE* to exit chat.";
        }

        // Empty reply = no echo to sender (natural chat feel)
        return "";
    }

    // ════════════════════════════════════════════════════════════════════
    // PHASE 3 — REOPEN COMPLETED TASK
    // ════════════════════════════════════════════════════════════════════

    /**
     * REOPEN T-xxx [reason] — re-open a completed/verified task for additional work.
     * Allowed for: assignee, original manager, or admin.
     */
    private function handleReopen(User $user, string $message): string
    {
        $parts = preg_split('/\s+/', trim($message), 3);
        if (count($parts) < 2) {
            return "❌ *Format*\n\n*REOPEN T-xxx [reason]*\n\nExample: REOPEN T-019e94 client wants more changes";
        }

        $shortId = strtolower(str_replace('T-', '', $parts[1]));
        $reason  = $parts[2] ?? 'Re-opened for additional work';

        $task = Task::with(['assignedTo', 'assignedBy'])
            ->whereRaw('LOWER(id::text) LIKE ?', [strtolower($shortId) . '%'])
            ->first();

        if (!$task) {
            return "❌ Task *T-{$shortId}* not found.";
        }

        // Authority check: assignee, original manager, or anyone in admin role
        $isAuthorized = ($task->assigned_to === $user->id)
                     || ($task->assigned_by === $user->id)
                     || ($user->role === 'admin');
        if (!$isAuthorized) {
            return "🚫 This task is not associated with you — cannot be reopened.";
        }

        if (!in_array($task->status, ['completed', 'verified'])) {
            return "⚠️ Task *{$task->status}* hai — cannot be reopened. Only completed/verified tasks can be reopened.";
        }

        $previousStatus = $task->status;
        $task->update([
            'status'       => 'in_progress',
            'completed_at' => null,
        ]);

        ActivityLog::record(
            'task', 'reopen', 'success',
            "🔓 Task reopened by {$user->name}: \"{$task->title}\" (was {$previousStatus}) — {$reason}",
            ['task_id' => $task->id, 'previous_status' => $previousStatus]
        );

        // Notify the OTHER party (whoever's not the actor)
        $other = ($user->id === $task->assigned_to) ? $task->assignedBy : $task->assignedTo;
        if ($other && $other->phone) {
            $this->wa->sendMessage($other->phone,
                "🔓 *Task Reopened*\n\n"
                . "📋 " . $task->title . "\n"
                . "👤 By: {$user->name}\n"
                . "Reason: {$reason}\n\n"
                . "Status is now *in_progress*."
            );
        }

        return "🔓 *Task Reopened*\n\n"
             . "📋 {$task->title}\n"
             . ($other ? "Notified: {$other->name}" : "")
             . "\nStatus: in_progress";
    }

    // ════════════════════════════════════════════════════════════════════
    // EXISTING — help and unknown handlers (improved)
    // ════════════════════════════════════════════════════════════════════

    private function helpMessage(User $user): string
    {
        if ($user->isManager()) {
            return "📋 *Manager Commands*\n"
                 . "━━━━━━━━━━━━━━━━━━\n\n"
                 . "*📦 Assign Tasks*\n"
                 . "• `ASSIGN <name> <task>` — start a task\n"
                 . "• Then send text / image / PDF / Excel / Word / PPT / voice note\n"
                 . "• Send *DONE* to finalize, *CANCEL* to abort\n"
                 . "• ⏰ Auto-finalizes after 2 min idle\n\n"
                 . "*📅 Recurring Schedules*\n"
                 . "• `SCHEDULE <name> daily <task>` — every day\n"
                 . "• `SCHEDULE <name> mon,wed,fri <task>` — specific days\n"
                 . "• `SCHEDULE <name> weekdays <task>` — Mon-Fri\n"
                 . "• `SCHEDULES` — list active schedules\n"
                 . "• `UNSCHEDULE S-xxx` — remove a schedule\n\n"
                 . "*👥 Team Info*\n"
                 . "• `LIST` — basic team list\n"
                 . "• `ALL` — team with task counts\n"
                 . "• `TEAM` — team tree view\n"
                 . "• `STATUS <name>` or just `<name>` — full employee status\n"
                 . "• `REPORT TODAY` / `REPORT WEEK` — stats\n\n"
                 . "*✅ Task Actions*\n"
                 . "• `VERIFY T-xxx` — approve completed task\n"
                 . "• `REJECT T-xxx <reason>` — reject\n"
                 . "• `CANCEL T-xxx [reason]` — cancel\n"
                 . "• `REASSIGN T-xxx <name>` — move task\n"
                 . "• `REOPEN T-xxx [reason]` — reopen completed task\n\n"
                 . "*📊 Quick Filters*\n"
                 . "URGENT • HIGH • TODAY • OVERDUE • PENDING\n\n"
                 . "*💬 Chat*\n"
                 . "• `CHAT <name>` — open conversation (CLOSE to exit)\n"
                 . "• `CHAT <name> <msg>` — one-shot DM\n"
                 . "• `REPLY <msg>` — reply to last received chat\n\n"
                 . "*👤 Add Employee*\n"
                 . "`ADD EMPLOYEE <name> <phone> <role>`\n\n"
                 . "💡 *Tip:* You can also chat naturally:\n"
                 . "  _\"what is Parag doing?\"_ → status\n"
                 . "  _\"please assign Rahul fix login\"_ → task";
        }

        return "📋 *Employee Commands*\n"
             . "━━━━━━━━━━━━━━━━━━\n\n"
             . "*🎯 Task Lifecycle*\n"
             . "• `START` — begin a task\n"
             . "• `UPDATE <text>` — send progress\n"
             . "• `COMPLETE` — mark done\n"
             . "• `DELAY <reason>` — report delay\n"
             . "• `ESCALATE <issue>` — flag urgent\n"
             . "• `REOPEN T-xxx [reason]` — reopen your completed task\n\n"
             . "*📊 Info*\n"
             . "• `STATUS` — view all your tasks\n"
             . "• `SCORE` — your APIX score\n\n"
             . "*📊 Quick Filters*\n"
             . "URGENT • HIGH • TODAY • OVERDUE • PENDING\n\n"
             . "*💬 Chat*\n"
             . "• `CHAT <name>` — open conversation (CLOSE to exit)\n"
             . "• `CHAT <name> <msg>` — one-shot DM\n"
             . "• `REPLY <msg>` — reply to last received chat\n\n"
             . "💡 *Tip:* When you have multiple tasks, the bot shows a numbered list.\n"
             . "Reply with the number, e.g. *1* or *COMPLETE 2*.";
    }

    private function getActiveTask(User $user): ?Task
    {
        return Task::where('assigned_to', $user->id)
            ->whereIn('status', ['assigned', 'accepted', 'in_progress', 'waiting'])
            ->latest()->first();
    }

    /**
     * Get all active tasks for user, ordered oldest first (stable for numbered list).
     */
    private function getActiveTasksList(User $user)
    {
        return Task::where('assigned_to', $user->id)
            ->whereIn('status', ['assigned', 'accepted', 'in_progress', 'waiting'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Set or update session 'awaiting' slot, MERGING with existing state so chat context etc. survives.
     */
    private function setSessionAwaiting(User $user, string $type, array $extraData = [], int $expiryMinutes = 10): void
    {
        $state = is_array($user->wa_session_state) ? $user->wa_session_state : [];
        $state['awaiting'] = $type;
        foreach ($extraData as $k => $v) {
            $state[$k] = $v;
        }
        $state['expires_at'] = now()->addMinutes($expiryMinutes)->toIso8601String();
        $user->wa_session_state = $state;
        $user->save();
    }

    /**
     * Clear only the 'awaiting' selection slot — PRESERVES chat context fields (last_chat_from_id etc).
     */
    private function clearSessionAwaiting(User $user): void
    {
        $state = is_array($user->wa_session_state) ? $user->wa_session_state : [];
        foreach (['awaiting', 'command', 'options', 'candidates', 'original_message', 'expires_at'] as $k) {
            unset($state[$k]);
        }
        $user->wa_session_state = empty($state) ? null : $state;
        $user->save();
    }

    /**
     * Resolve which task an employee command refers to.
     * Returns ['task' => Task] on success, ['reply' => string] when bot needs to ask user.
     *
     * Rules (revised):
     * - 0 active tasks → reply: "no active task"
     * - User explicitly included a number ("COMPLETE 2") → use that one
     * - Otherwise → ALWAYS show numbered list & ask user to pick (even for 1 task)
     */
    private function resolveTaskForCommand(User $user, string $cmdName, string $fullMessage): array
    {
        $activeTasks = $this->getActiveTasksList($user);

        if ($activeTasks->isEmpty()) {
            return ['reply' => "You have no active tasks.\n\nReply *STATUS* to view your tasks."];
        }

        // If user already typed a number, honor it
        $hasNumber = preg_match('/^\s*[A-Za-z]+\s+(\d+)(?:\s|$)/', $fullMessage, $m);
        if ($hasNumber) {
            $idx = (int) $m[1];
            if ($idx >= 1 && $idx <= $activeTasks->count()) {
                return ['task' => $activeTasks[$idx - 1]];
            }
            return ['reply' => "⚠️ Invalid number. You have *{$activeTasks->count()}* active task(s). Reply *STATUS* to see them again."];
        }

        // Otherwise — ALWAYS show numbered list and ask user to pick (even for 1 task)
        $cmdUpper = strtoupper($cmdName);
        $count = $activeTasks->count();
        $taskWord = $count === 1 ? 'task' : 'tasks';
        $msg = "📋 You have *{$count}* active {$taskWord}:\n\n";
        $options = [];
        foreach ($activeTasks as $i => $t) {
            $num = $i + 1;
            $msg .= "*{$num}.* " . $t->title . "\n";
            $options[$num] = $t->id;
        }
        $msg .= "\nWhich one do you want to *{$cmdUpper}*?\n";
        $msg .= "Reply: *{$cmdUpper} 1*, *{$cmdUpper} 2*, etc.\n";
        $msg .= "Or just send the number: *1*, *2*, ...";

        $this->setSessionAwaiting($user, 'task_selection', [
            'command' => $cmdUpper,
            'options' => $options,
        ]);

        return ['reply' => $msg];
    }

    /**
     * Handle a bare-number reply ("2") by checking session state.
     * Returns the bot's reply string OR null if no valid pending selection.
     *
     * Supports:
     * - awaiting='task_selection' → resolves which task, recurses into handle() with synthetic command
     * - awaiting='employee_selection' → resolves which employee for pending ASSIGN, creates task
     */
    private function resolveNumericReply(User $user, int $num, ?string $waMessageId): ?string
    {
        $state = $user->wa_session_state;
        if (!is_array($state)) return null;

        // Expiry check
        if (!empty($state['expires_at'])) {
            try {
                if (\Carbon\Carbon::parse($state['expires_at'])->isPast()) {
                    $this->clearSessionAwaiting($user);
                    return null;
                }
            } catch (\Exception $e) {
                $this->clearSessionAwaiting($user);
                return null;
            }
        }

        $awaiting = $state['awaiting'] ?? null;

        // ── Branch 1: task selection (existing flow) ──
        if ($awaiting === 'task_selection') {
            $taskId = $state['options'][$num] ?? null;
            if (!$taskId) return null;
            $command = $state['command'] ?? null;
            if (!$command) return null;

            $this->clearSessionAwaiting($user);

            // Verify task still active, find its CURRENT index in active list
            $activeTasks = $this->getActiveTasksList($user);
            $idx = $activeTasks->search(fn($t) => $t->id === $taskId);
            if ($idx === false) {
                return "⚠️ This task is no longer active (probably completed). Reply *STATUS* to view your tasks.";
            }
            $currentNum = $idx + 1;
            // Recurse with synthetic full command e.g. "COMPLETE 2"
            return $this->handle($user, $command, "{$command} {$currentNum}", $waMessageId);
        }

        // ── Branch 2: employee selection (for ASSIGN ambiguity — Phase 2) ──
        if ($awaiting === 'employee_selection') {
            $candidateIds = $state['candidates'] ?? [];
            $originalMessage = $state['original_message'] ?? '';
            $candidateId = $candidateIds[$num] ?? null;
            if (!$candidateId || !$originalMessage) return null;

            $candidate = User::find($candidateId);
            if (!$candidate || !$candidate->is_active) {
                $this->clearSessionAwaiting($user);
                return "⚠️ Employee is no longer available. Reply *LIST* to see your team.";
            }

            $this->clearSessionAwaiting($user);

            // Extract initial text from original (skip first 2 words: ASSIGN <name>)
            $parts = preg_split('/\s+/', trim($originalMessage), 3);
            $initialText = $parts[2] ?? null;

            if (!$user->canAssignTo($candidate)) {
                return "🚫 *Not allowed*\n\n\"{$candidate->name}\" is not in your team.";
            }

            // Always enter batch mode (same as direct ASSIGN flow)
            return $this->startBatchTask($user, $candidate, null, $initialText);
        }

        return null;
    }

    private function logUpdate(Task $task, User $user, string $command, string $message, ?string $waMessageId = null): void
    {
        TaskUpdate::create([
            'task_id'       => $task->id,
            'user_id'       => $user->id,
            'wa_message_id' => $waMessageId,
            'command'       => $command,
            'message'       => $message,
        ]);
    }

    private function getApixBand(float $score): string
    {
        if ($score >= 90) return '🏆 Elite';
        if ($score >= 75) return '⭐ High Performer';
        if ($score >= 60) return '✅ On Track';
        if ($score >= 45) return '⚠️ Needs Attention';
        return '🚨 At Risk';
    }

    private function parseDueDate(string $text): ?\Carbon\Carbon
    {
        $text = strtolower($text);
        if (preg_match('/by (\d{1,2})\s*(am|pm)/', $text, $m)) {
            $hour = (int)$m[1];
            if ($m[2] === 'pm' && $hour < 12) $hour += 12;
            return today()->setHour($hour);
        }
        if (str_contains($text, 'today')) return today()->endOfDay();
        if (str_contains($text, 'tomorrow')) return now()->addDay()->endOfDay();
        if (preg_match('/in (\d+) (day|days|hour|hours)/', $text, $m)) {
            return $m[2] === 'hour' || $m[2] === 'hours'
                ? now()->addHours((int)$m[1])
                : now()->addDays((int)$m[1]);
        }
        return null;
    }
}
