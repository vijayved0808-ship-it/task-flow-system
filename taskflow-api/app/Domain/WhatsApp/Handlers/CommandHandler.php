<?php

namespace App\Domain\WhatsApp\Handlers;

use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskUpdate;
use App\Domain\User\Models\User;
use App\Domain\WhatsApp\Services\WhatsAppService;
use App\Domain\Logs\Models\ActivityLog;
use App\Jobs\RecalculateApixScore;
use Illuminate\Support\Facades\Hash;

class CommandHandler
{
    public function __construct(private WhatsAppService $wa) {}

    public function handle(User $user, string $command, string $fullMessage, ?string $waMessageId = null): string
    {
        $user->update(['last_seen_at' => now()]);
        $textTrimmed = trim($fullMessage);

        // ──────────────────────────────────────────────────────────────
        // PHASE 3 — CHAT SESSION MODE
        // If user is in active chat with someone, forward ALL messages
        // (except CLOSE/END/BYE/EXIT) to the peer. Commands do NOT execute.
        // ──────────────────────────────────────────────────────────────
        $stateNow = $user->wa_session_state;
        if (is_array($stateNow) && !empty($stateNow['in_chat_with_id'])) {
            $upperFirst = strtoupper(explode(' ', $textTrimmed)[0] ?? '');
            if (in_array($upperFirst, ['CLOSE', 'END', 'BYE', 'EXIT'], true)) {
                return $this->closeChatSession($user, false);
            }
            return $this->forwardChatMessage($user, $textTrimmed);
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
            $managerResult = $this->tryManagerCommand($user, $cmd, $fullMessage);
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
            'CLOSE'    => "ℹ️ Aap kisi chat session me nahi hain.\n\nReply *HELP* for commands.",
            'END'      => "ℹ️ Aap kisi chat session me nahi hain.\n\nReply *HELP* for commands.",
            default    => $this->handleUnknown($user, $fullMessage, $waMessageId),
        };
    }

    private function tryManagerCommand(User $manager, string $cmd, string $fullMessage): ?string
    {
        if ($cmd === 'ASSIGN') return $this->handleAssign($manager, $fullMessage);
        if ($cmd === 'ADD EMPLOYEE') return $this->handleAddEmployee($manager, $fullMessage);
        if ($cmd === 'LIST' || $cmd === 'LIST EMPLOYEES') return $this->handleListEmployees($manager);
        if ($cmd === 'TEAM') return $this->handleTeamTree($manager);
        if ($cmd === 'REPORT TODAY' || $cmd === 'REPORT WEEK' || $cmd === 'REPORT') return $this->handleReport($manager, $cmd);
        if ($cmd === 'VERIFY') return $this->handleVerify($manager, $fullMessage);
        if ($cmd === 'REJECT') return $this->handleReject($manager, $fullMessage);
        if ($cmd === 'STATUS') {
            $parts = preg_split('/\s+/', trim($fullMessage), 2);
            if (count($parts) === 2 && !empty($parts[1])) {
                return $this->handleEmployeeStatus($manager, $fullMessage);
            }
        }
        return null;
    }

    private function handleAssign(User $manager, string $message): string
    {
        $parts = preg_split('/\s+/', trim($message), 3);
        if (count($parts) < 3) {
            return "❌ *Invalid format*\n\nUse:\n*ASSIGN <name> <task description>*\n\nExample:\nASSIGN Priya Visit Dr. Patel today by 5PM";
        }

        $employeeName = $parts[1];
        $taskTitle    = $parts[2];

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

        // Filter to only those manager can assign to (hierarchy check)
        $assignable = $candidates->filter(fn($c) => $manager->canAssignTo($c))->values();

        if ($assignable->isEmpty()) {
            ActivityLog::record(
                'task', 'assign', 'failed',
                "🚫 Hierarchy block: {$manager->name} can't assign to {$candidates->first()->name} (not in sub-tree)",
                ['manager_id' => $manager->id]
            );
            return "🚫 *Not allowed*\n\n\"{$candidates->first()->name}\" is not in your team.\n\nYou can only assign tasks to people who report to you (directly or indirectly).\n\nReply *TEAM* to see your team tree.";
        }

        // Phase 2: Ambiguity — multiple matches → ask user to pick
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

        // Single match — proceed
        return $this->createAndNotifyTask($manager, $assignable->first(), $taskTitle);
    }

    /**
     * Shared helper: create a Task, notify the employee via WhatsApp, return manager-facing reply.
     * Honestly reports delivery success/failure based on sendTaskAssignment return value.
     */
    private function createAndNotifyTask(User $manager, User $employee, string $taskTitle): string
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

        $dueStr = $dueDate ? $dueDate->format('d M, h:i A') : 'No deadline';
        $deliveryNote = $delivered
            ? "✅ Employee notified via WhatsApp."
            : "⚠️ *WhatsApp notification failed.*\nTask saved but employee didn't receive message.\nCheck Logs tab for reason (likely 24-hr window or unverified number).";

        return "✅ *Task Assigned!*\n\n"
             . "👤 Employee: {$employee->name}\n"
             . "📋 Task: {$taskTitle}\n"
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
        return trim($msg);
    }

    private function renderTreeNode(User $user, int $depth): string
    {
        $indent = str_repeat('  ', $depth);
        $arrow = $depth > 0 ? '└─ ' : '';
        $role = $user->isAdmin() ? '👑' : ($user->isManager() ? '👔' : '👤');

        $line = "{$indent}{$arrow}{$role} *{$user->name}*";
        if ($user->designation) $line .= " ({$user->designation})";
        $line .= "\n";

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

        $employee = User::where('is_active', true)
            ->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($name) . '%'])
            ->first();

        if (!$employee) return "❌ Employee \"{$name}\" not found.";

        $activeTasks = Task::where('assigned_to', $employee->id)
            ->whereNotIn('status', ['completed', 'verified'])->get();
        $todayDone = Task::where('assigned_to', $employee->id)
            ->whereIn('status', ['completed', 'verified'])
            ->whereDate('completed_at', today())->count();

        $msg = "📊 *{$employee->name}*\n\n"
             . "📱 {$employee->phone}\n"
             . "📋 Active Tasks: {$activeTasks->count()}\n"
             . "✅ Completed Today: {$todayDone}\n";

        if ($activeTasks->count() > 0) {
            $msg .= "\n*Active Tasks:*\n";
            foreach ($activeTasks->take(5) as $t) {
                $msg .= "• " . $t->title . " ({$t->status})\n";
            }
        }
        return $msg;
    }

    private function handleStart(User $user, string $message): string
    {
        $resolve = $this->resolveTaskForCommand($user, 'START', $message);
        if (isset($resolve['reply'])) return $resolve['reply'];
        $task = $resolve['task'];

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

    private function handleStatus(User $user): string
    {
        $tasks = Task::where('assigned_to', $user->id)
            ->whereNotIn('status', ['completed', 'verified'])->limit(5)->get();

        if ($tasks->isEmpty()) return "✅ No pending tasks! Great work.";

        $msg = "📋 *Your Active Tasks*\n\n";
        foreach ($tasks as $i => $task) {
            $due = $task->due_date ? $task->due_date->format('d M h:i A') : 'No deadline';
            $msg .= ($i + 1) . ". {$task->title}\n   Status: {$task->status} | Due: {$due}\n\n";
        }
        return trim($msg);
    }

    private function handleUnknown(User $user, string $message, ?string $waMessageId): string
    {
        // First check: does this look like an attempted command with a typo?
        $firstWord = strtoupper(explode(' ', trim($message))[0] ?? '');
        if (strlen($firstWord) >= 3) {
            $known = [
                // employee
                'START', 'UPDATE', 'COMPLETE', 'DELAY', 'ESCALATE', 'SCORE', 'STATUS', 'HELP',
                'URGENT', 'HIGH', 'TODAY', 'OVERDUE', 'PENDING', 'CHAT', 'REPLY',
                // manager
                'ASSIGN', 'LIST', 'TEAM', 'VERIFY', 'REJECT', 'REPORT', 'CANCEL', 'REASSIGN', 'FORWARD',
            ];
            $bestMatch = null;
            $bestDist = 99;
            foreach ($known as $cmd) {
                $d = levenshtein($firstWord, $cmd);
                if ($d < $bestDist) { $bestDist = $d; $bestMatch = $cmd; }
            }
            // Only suggest if very close (1 or 2 char diff) — and word isn't exact match (which would have been parsed already)
            if ($bestDist > 0 && $bestDist <= 2) {
                return "🤔 *\"{$firstWord}\"* samajh nahi aaya.\n\n💡 Kya tu *{$bestMatch}* keh raha tha?\n\nReply *HELP* for full command list.";
            }
        }

        // Otherwise — fall back to existing behavior: log as raw update on active task if msg is substantial
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
            return "{$label}\n\n✨ Koi {$filter} task nahi hai. Sab clear!";
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
            return "🚫 Sirf manager/admin task cancel kar sakte hain.";
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
            return "🚫 Yeh task tumne assign nahi kiya — cancel nahi kar sakte.";
        }

        if (in_array($task->status, ['completed', 'verified', 'cancelled'])) {
            return "⚠️ Task already *{$task->status}* hai — cancel nahi kar sakte.";
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
            return "🚫 Sirf manager/admin task reassign kar sakte hain.";
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
            return "🚫 Yeh task tumne assign nahi kiya — reassign nahi kar sakte.";
        }

        if (in_array($task->status, ['completed', 'verified', 'cancelled'])) {
            return "⚠️ Task *{$task->status}* hai — reassign nahi kar sakte.";
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
            return "❌ \"{$newName}\" team me nahi mila. Reply *LIST*.";
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
                "🔄 *Task Removed*\n\n📋 " . $task->title . "\nTask ab {$newEmployee->name} ko assign ho gaya hai."
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
            return "❌ {$target->name} ke paas phone number nahi hai — message bhej nahi sakte.";
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
            return "❌ Tumhe abhi koi message nahi mila reply karne ke liye.\n\nUse *CHAT <name> <message>* to start a chat.";
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
            return "❌ Original sender available nahi hai.";
        }

        $cleanReply = trim(preg_replace('/^REPLY\s*/i', '', $message));
        if ($cleanReply === '') {
            return "❌ Reply blank hai. Use: *REPLY <your message>*";
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
            return "❌ {$target->name} ke paas phone number nahi hai.";
        }

        // If target is already in chat with someone OTHER than sender, block
        $targetState = is_array($target->wa_session_state) ? $target->wa_session_state : [];
        if (!empty($targetState['in_chat_with_id']) && $targetState['in_chat_with_id'] !== $sender->id) {
            return "❌ *{$target->name}* abhi kisi aur ke saath chat me hai. Thodi der baad try karo.";
        }

        // Notify target FIRST — if fails, don't change state
        $notify = "💬 *{$sender->name}* aapse chat shuru kar raha hai.\n\n"
                . "Ab aapka koi bhi message direct *{$sender->name}* ko jayega.\n"
                . "🔚 Chat khatam karne ke liye *CLOSE* bhejo.";

        if (!$this->wa->sendMessage($target->phone, $notify)) {
            return "⚠️ *{$target->name}* ko notify nahi kar paye. Check Logs tab.";
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
             . "Ab aapka koi bhi message direct *{$target->name}* ko jayega.\n"
             . "Other commands abhi work nahi karenge.\n\n"
             . "🔚 Chat khatam karne ke liye *CLOSE* bhejo.";
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
                    "🔚 *{$user->name}* ne chat close kar di.\n\nNormal commands ab kaam karenge."
                );
            }
        }

        ActivityLog::record(
            'chat', 'session_close', 'success',
            "🔚 Chat session closed by {$user->name} (peer: {$peerName})",
            ['user_id' => $user->id, 'peer_id' => $peerId]
        );

        return "🔚 *Chat with {$peerName} closed.*\n\nNormal commands ab kaam karenge.";
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
            return "❌ Peer ab available nahi hai. Chat auto-closed.\n\nNormal commands ab kaam karenge.";
        }

        $forwarded = "💬 *{$user->name}:* {$text}";
        $delivered = $this->wa->sendMessage($peer->phone, $forwarded);

        if (!$delivered) {
            return "⚠️ *{$peer->name}* ko message deliver nahi hua.\n\nSend *CLOSE* to exit chat.";
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
            return "❌ *Format*\n\n*REOPEN T-xxx [reason]*\n\nExample: REOPEN T-019e94 client ko aur changes chahiye";
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
            return "🚫 Yeh task tumse related nahi hai — reopen nahi kar sakte.";
        }

        if (!in_array($task->status, ['completed', 'verified'])) {
            return "⚠️ Task *{$task->status}* hai — reopen nahi kar sakte. Sirf completed/verified tasks reopen ho sakte hain.";
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
                . "Status ab *in_progress* hai."
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
            return "📋 *Manager Commands*\n\n"
                 . "*ASSIGN <name> <task>* — Assign task\n"
                 . "*ADD EMPLOYEE <name> <phone> <role>* — Add employee\n"
                 . "*LIST* — Show team\n"
                 . "*TEAM* — Show team tree\n"
                 . "*STATUS <name>* — Employee status\n"
                 . "*CANCEL T-xxx [reason]* — Cancel a task\n"
                 . "*REASSIGN T-xxx <name>* — Move task to someone else\n"
                 . "*REOPEN T-xxx [reason]* — Reopen completed/verified task\n"
                 . "*REPORT TODAY* / *REPORT WEEK* — Stats\n"
                 . "*VERIFY T-xxx* — Approve completed task\n"
                 . "*REJECT T-xxx <reason>* — Reject\n\n"
                 . "📊 *Quick filters:* URGENT • HIGH • TODAY • OVERDUE • PENDING\n\n"
                 . "💬 *Chat modes:*\n"
                 . "  *CHAT <name>* — open conversation (CLOSE to exit)\n"
                 . "  *CHAT <name> <message>* — one-shot DM\n"
                 . "  *REPLY <message>* — respond to last received chat\n\n"
                 . "Example: ASSIGN Parag visit Dr. Patel today by 5PM";
        }

        return "📋 *Employee Commands*\n\n"
             . "*START* — Begin task\n"
             . "*UPDATE <text>* — Send progress\n"
             . "*COMPLETE* — Mark done\n"
             . "*DELAY <reason>* — Report delay\n"
             . "*ESCALATE <issue>* — Flag urgent\n"
             . "*REOPEN T-xxx [reason]* — Reopen own completed task\n"
             . "*STATUS* — View all my tasks\n"
             . "*SCORE* — My APIX\n\n"
             . "📊 *Quick filters:* URGENT • HIGH • TODAY • OVERDUE • PENDING\n\n"
             . "💬 *Chat modes:*\n"
             . "  *CHAT <name>* — open conversation (CLOSE to exit)\n"
             . "  *CHAT <name> <message>* — one-shot DM\n"
             . "  *REPLY <message>* — respond to last received chat\n\n"
             . "💡 Multiple tasks? Bot list dega — phir *START 1*, *COMPLETE 2*, ya sirf *1*, *2* etc.";
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
     * Rules:
     * - 0 active tasks → reply: "no active task"
     * - 1 active task  → use it (backward-compatible, current behavior unchanged)
     * - 2+ active tasks + message has "COMMAND <number>" → pick that one
     * - 2+ active tasks + no number → show numbered list, save session state
     */
    private function resolveTaskForCommand(User $user, string $cmdName, string $fullMessage): array
    {
        $activeTasks = $this->getActiveTasksList($user);

        if ($activeTasks->isEmpty()) {
            return ['reply' => "No active task found.\n\nReply *STATUS* to see your tasks."];
        }

        if ($activeTasks->count() === 1) {
            return ['task' => $activeTasks->first()];
        }

        // Multiple — try to extract number after command word: "COMPLETE 2 ..." or "COMPLETE 2"
        $hasNumber = preg_match('/^\s*[A-Za-z]+\s+(\d+)(?:\s|$)/', $fullMessage, $m);
        if ($hasNumber) {
            $idx = (int) $m[1];
            if ($idx >= 1 && $idx <= $activeTasks->count()) {
                return ['task' => $activeTasks[$idx - 1]];
            }
            return ['reply' => "⚠️ Galat number. Aapke paas *{$activeTasks->count()}* active tasks hain. Reply *STATUS* phir se dekhne ke liye."];
        }

        // No number — show numbered list + save session state for 10 min
        $cmdUpper = strtoupper($cmdName);
        $msg = "🤔 Aapke paas *{$activeTasks->count()}* active tasks hain:\n\n";
        $options = [];
        foreach ($activeTasks as $i => $t) {
            $num = $i + 1;
            $msg .= "*{$num}.* " . $t->title . "\n";
            $options[$num] = $t->id;
        }
        $msg .= "\nReply: *{$cmdUpper} 1*, *{$cmdUpper} 2*, etc.\n";
        $msg .= "Ya sirf number bhejke: *1*, *2*, ...";

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
                return "⚠️ Yeh task ab active nahi hai (shayad complete ho gaya). Reply *STATUS* dekhne ke liye.";
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
                return "⚠️ Employee ab available nahi hai. Reply *LIST* dekhne ke liye.";
            }

            $this->clearSessionAwaiting($user);

            // Extract task title from original (skip first 2 words: ASSIGN <name>)
            $parts = preg_split('/\s+/', trim($originalMessage), 3);
            $taskTitle = $parts[2] ?? 'Unnamed task';

            if (!$user->canAssignTo($candidate)) {
                return "🚫 *Not allowed*\n\n\"{$candidate->name}\" is not in your team.";
            }

            return $this->createAndNotifyTask($user, $candidate, $taskTitle);
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
