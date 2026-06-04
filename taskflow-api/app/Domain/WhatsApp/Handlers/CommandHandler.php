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
        $cmd = strtoupper(trim($command));

        // Manager/Admin commands first
        if ($user->isManager()) {
            $managerResult = $this->tryManagerCommand($user, $cmd, $fullMessage);
            if ($managerResult !== null) return $managerResult;
        }

        // Common employee commands
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
            default    => $this->handleUnknown($user, $fullMessage, $waMessageId),
        };
    }

    private function tryManagerCommand(User $manager, string $cmd, string $fullMessage): ?string
    {
        // ASSIGN — uses fullMessage to parse name + task
        if ($cmd === 'ASSIGN') {
            return $this->handleAssign($manager, $fullMessage);
        }
        // ADD EMPLOYEE
        if ($cmd === 'ADD EMPLOYEE') {
            return $this->handleAddEmployee($manager, $fullMessage);
        }
        // LIST or LIST EMPLOYEES
        if ($cmd === 'LIST' || $cmd === 'LIST EMPLOYEES') {
            return $this->handleListEmployees($manager);
        }
        // REPORT TODAY / REPORT WEEK / REPORT
        if ($cmd === 'REPORT TODAY' || $cmd === 'REPORT WEEK' || $cmd === 'REPORT') {
            return $this->handleReport($manager, $cmd);
        }
        // VERIFY
        if ($cmd === 'VERIFY') {
            return $this->handleVerify($manager, $fullMessage);
        }
        // REJECT
        if ($cmd === 'REJECT') {
            return $this->handleReject($manager, $fullMessage);
        }
        // STATUS <name> — only if message has more than just "STATUS"
        if ($cmd === 'STATUS') {
            $parts = preg_split('/\s+/', trim($fullMessage), 2);
            if (count($parts) === 2 && !empty($parts[1])) {
                return $this->handleEmployeeStatus($manager, $fullMessage);
            }
            // Just "STATUS" — fall through to employee status logic
        }
        return null;
    }

    private function handleAssign(User $manager, string $message): string
    {
        // Format: ASSIGN <name> <task...>
        $parts = preg_split('/\s+/', trim($message), 3);
        if (count($parts) < 3) {
            return "❌ *Invalid format*\n\nUse:\n*ASSIGN <name> <task description>*\n\nExample:\nASSIGN Priya Visit Dr. Patel today by 5PM";
        }

        $employeeName = $parts[1];
        $taskTitle    = $parts[2];

        ActivityLog::record(
            'task', 'assign_attempt', 'info',
            "🔍 Looking for employee: \"{$employeeName}\"",
            ['manager_id' => $manager->id, 'task_title' => $taskTitle]
        );

        // Find employee by ANY part of name (case insensitive, more flexible)
        $employee = User::where('is_active', true)
            ->where('id', '!=', $manager->id)  // Don't assign to self
            ->where(function ($q) use ($employeeName) {
                $term = strtolower($employeeName);
                $q->whereRaw('LOWER(name) LIKE ?', ['%' . $term . '%'])
                  ->orWhereRaw('LOWER(SPLIT_PART(name, \' \', 1)) = ?', [$term]);
            })
            ->first();

        if (!$employee) {
            ActivityLog::record(
                'task', 'assign', 'failed',
                "❌ Employee \"{$employeeName}\" not found",
                ['manager_id' => $manager->id]
            );
            return "❌ *Employee \"{$employeeName}\" not found*\n\nReply *LIST* to see all employees.";
        }

        // Parse optional due date from task text
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
            [
                'task_id' => $task->id,
                'employee_id' => $employee->id,
                'manager_id' => $manager->id,
                'phone' => $employee->phone
            ],
            $employee->phone
        );

        // Load relationships for notification
        $task->load(['assignedTo', 'assignedBy']);

        // Notify employee via WhatsApp
        $this->wa->sendTaskAssignment($task);

        $dueStr = $dueDate ? $dueDate->format('d M, h:i A') : 'No deadline';

        return "✅ *Task Assigned!*\n\n"
             . "👤 Employee: {$employee->name}\n"
             . "📋 Task: {$taskTitle}\n"
             . "🆔 Task ID: T-" . substr($task->id, 0, 6) . "\n"
             . "📅 Due: {$dueStr}\n\n"
             . "Employee notified via WhatsApp.";
    }

    private function handleAddEmployee(User $manager, string $message): string
    {
        $message = preg_replace('/^ADD EMPLOYEE\s+/i', '', trim($message));

        if (!preg_match('/(\+?\d{10,15})/', $message, $phoneMatch)) {
            return "❌ *Invalid format*\n\nUse:\n*ADD EMPLOYEE <name> <phone> <designation>*\n\nExample:\nADD EMPLOYEE Priya Sharma +919876543210 Field Executive";
        }

        $phone = $phoneMatch[1];
        if (!str_starts_with($phone, '+')) {
            $phone = '+91' . $phone;
        }

        $parts = preg_split('/' . preg_quote($phoneMatch[1], '/') . '/', $message);
        $name = trim($parts[0]);
        $designation = isset($parts[1]) ? trim($parts[1]) : 'Employee';

        if (empty($name)) return "❌ Name is required.";

        if (User::where('phone', $phone)->exists()) {
            return "❌ Employee with phone {$phone} already exists.";
        }

        $employee = User::create([
            'name'              => $name,
            'phone'             => $phone,
            'email'             => strtolower(str_replace(' ', '.', $name)) . '+' . substr(uniqid(), -4) . '@uicgroup.com',
            'password'          => Hash::make('Emp@2026'),
            'role'              => 'employee',
            'designation'       => $designation,
            'whatsapp_opted_in' => true,
            'is_active'         => true,
        ]);

        ActivityLog::record(
            'user', 'create', 'success',
            "✅ Employee added via WhatsApp: {$name}",
            ['user_id' => $employee->id, 'created_by' => $manager->id],
            $phone
        );

        $this->wa->sendMessage($phone,
            "👋 *Welcome to TaskFlow!*\n\n"
            . "You've been added by {$manager->name}.\n\n"
            . "📱 You'll receive tasks here on WhatsApp.\n"
            . "Reply *HELP* anytime to see all commands."
        );

        return "✅ *Employee Added!*\n\n"
             . "👤 Name: {$name}\n"
             . "📱 Phone: {$phone}\n"
             . "💼 Role: {$designation}";
    }

    private function handleListEmployees(User $manager): string
    {
        $employees = User::where('role', 'employee')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($employees->isEmpty()) {
            return "📋 No employees yet.\n\nAdd one with:\n*ADD EMPLOYEE <name> <phone> <designation>*";
        }

        $msg = "👥 *Your Team* ({$employees->count()})\n\n";
        foreach ($employees as $i => $emp) {
            $activeTasks = Task::where('assigned_to', $emp->id)
                ->whereNotIn('status', ['completed', 'verified'])->count();
            $msg .= ($i + 1) . ". *{$emp->name}*\n";
            $msg .= "   📱 {$emp->phone}\n";
            $msg .= "   💼 " . ($emp->designation ?: 'Employee') . "\n";
            $msg .= "   📋 {$activeTasks} active tasks\n\n";
        }
        return trim($msg);
    }

    private function handleReport(User $manager, string $cmd): string
    {
        $period = str_contains($cmd, 'WEEK') ? 'week' : 'today';
        $start = $period === 'week' ? now()->startOfWeek() : today();

        $assigned  = Task::whereBetween('created_at', [$start, now()])->count();
        $completed = Task::whereIn('status', ['completed', 'verified'])
            ->whereBetween('completed_at', [$start, now()])->count();
        $overdue   = Task::where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'verified'])->count();
        $activeEmployees = User::where('role', 'employee')->where('is_active', true)->count();

        $label = $period === 'week' ? 'This Week' : 'Today';

        return "📊 *{$label}'s Report*\n\n"
             . "📋 Tasks Assigned: {$assigned}\n"
             . "✅ Completed: {$completed}\n"
             . "⚠️ Overdue: {$overdue}\n"
             . "👥 Active Team: {$activeEmployees}";
    }

    private function handleVerify(User $manager, string $message): string
    {
        $parts = preg_split('/\s+/', trim($message), 3);
        if (count($parts) < 2) {
            return "❌ Use: *VERIFY <task-id>*\n\nExample: VERIFY T-a1b2c3";
        }

        $taskId = str_replace('T-', '', $parts[1]);
        $task = Task::where('id', 'LIKE', $taskId . '%')->first();

        if (!$task) return "❌ Task not found: {$parts[1]}";
        if ($task->status !== 'completed') {
            return "⚠️ Task is not in completed state (current: {$task->status})";
        }

        $task->update([
            'status'      => 'verified',
            'verified_by' => $manager->id,
            'verified_at' => now(),
        ]);

        if ($task->assignedTo) {
            $this->wa->sendMessage($task->assignedTo->phone,
                "🎉 *Task Verified!*\n\n"
                . "📋 {$task->title}\n"
                . "✅ Verified by {$manager->name}\n"
                . "⭐ +{$task->reward_points} points credited!"
            );
            RecalculateApixScore::dispatch($task->assignedTo->id);
        }

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
                "❌ *Task Rejected*\n\n"
                . "📋 {$task->title}\n"
                . "Reason: {$reason}\n\n"
                . "Please rework and resubmit."
            );
        }
        return "✅ Task rejected. Employee notified.";
    }

    private function handleEmployeeStatus(User $manager, string $message): string
    {
        $parts = preg_split('/\s+/', trim($message), 2);
        $name = $parts[1] ?? '';

        $employee = User::where('role', 'employee')
            ->where('is_active', true)
            ->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($name) . '%'])
            ->first();

        if (!$employee) return "❌ Employee \"{$name}\" not found.";

        $activeTasks = Task::where('assigned_to', $employee->id)
            ->whereNotIn('status', ['completed', 'verified'])->get();
        $todayDone = Task::where('assigned_to', $employee->id)
            ->whereIn('status', ['completed', 'verified'])
            ->whereDate('completed_at', today())->count();

        $apix = $employee->apixScores()->where('score_date', today())->first()?->apix_score ?? 'N/A';

        $msg = "📊 *{$employee->name}*\n\n"
             . "📱 {$employee->phone}\n"
             . "📋 Active Tasks: {$activeTasks->count()}\n"
             . "✅ Completed Today: {$todayDone}\n"
             . "🏆 APIX Today: {$apix}\n";

        if ($activeTasks->count() > 0) {
            $msg .= "\n*Active Tasks:*\n";
            foreach ($activeTasks->take(5) as $t) {
                $msg .= "• " . $t->title . " ({$t->status})\n";
            }
        }
        return $msg;
    }

    // ============ EMPLOYEE COMMANDS ============

    private function handleStart(User $user, string $message): string
    {
        $task = $this->getActiveTask($user);
        if (!$task) return "No active task found.\n\nReply *STATUS* to see your tasks.";

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
        $task = $this->getActiveTask($user);
        if (!$task) return "No active task found. Reply *STATUS* to see your tasks.";

        $this->logUpdate($task, $user, 'update', $message, $waMessageId);

        if ($task->assignedBy && $task->assignedBy->phone) {
            $cleanMsg = preg_replace('/^UPDATE\s*/i', '', $message);
            $this->wa->sendMessage($task->assignedBy->phone,
                "📝 *{$user->name} update*\n\n📋 " . $task->title . "\n💬 " . trim($cleanMsg)
            );
        }
        return "📝 *Update logged!*\n\nKeep going 💪\nSend *COMPLETE* when done.";
    }

    private function handleComplete(User $user, string $message, ?string $waMessageId): string
    {
        $task = $this->getActiveTask($user);
        if (!$task) return "No active task found.";

        $task->update(['status' => 'completed', 'completed_at' => now()]);
        $this->logUpdate($task, $user, 'complete', $message, $waMessageId);

        if ($task->assignedBy && $task->assignedBy->phone) {
            $this->wa->sendMessage($task->assignedBy->phone,
                "🎉 *{$user->name} completed task*\n\n"
                . "📋 " . $task->title . "\n"
                . "🆔 T-" . substr($task->id, 0, 6) . "\n\n"
                . "Reply *VERIFY T-" . substr($task->id, 0, 6) . "* to approve\n"
                . "Or *REJECT T-" . substr($task->id, 0, 6) . " <reason>* to reject"
            );
        }
        RecalculateApixScore::dispatch($user->id);

        return "🎉 *Task Completed!*\n\n📋 {$task->title}\n\nManager notified for verification.";
    }

    private function handleDelay(User $user, string $message): string
    {
        $task = $this->getActiveTask($user);
        if (!$task) return "No active task found.";

        $task->update(['status' => 'waiting']);
        $this->logUpdate($task, $user, 'delay', $message);

        if ($task->assignedBy && $task->assignedBy->phone) {
            $reason = preg_replace('/^DELAY\s*/i', '', $message);
            $this->wa->sendMessage($task->assignedBy->phone,
                "⏰ *{$user->name} reported delay*\n\n📋 " . $task->title . "\nReason: " . trim($reason)
            );
        }
        return "⏰ *Delay noted.* Manager informed.\n\nSend *START* to resume.";
    }

    private function handleEscalate(User $user, string $message): string
    {
        $task = $this->getActiveTask($user);
        if (!$task) return "No active task found.";

        $task->update(['status' => 'escalated']);
        $this->logUpdate($task, $user, 'escalate', $message);

        if ($task->assignedBy && $task->assignedBy->phone) {
            $issue = preg_replace('/^ESCALATE\s*/i', '', $message);
            $this->wa->sendMessage($task->assignedBy->phone,
                "🚨 *{$user->name} escalated task*\n\n📋 " . $task->title . "\nIssue: " . trim($issue)
            );
        }
        return "🚨 *Escalated to manager.*";
    }

    private function handleScore(User $user): string
    {
        $score = $user->apixScores()->where('score_date', today())->first();
        if (!$score) return "📊 *Your APIX Today*\n\nNo score yet. Complete tasks to build score!";

        $band = $this->getApixBand($score->apix_score);
        return "📊 *Your APIX Score*\n\n🏆 {$score->apix_score} — {$band}\n\nTasks: {$score->tasks_completed}/{$score->tasks_assigned}";
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
        $task = $this->getActiveTask($user);
        if ($task && strlen($message) > 10) {
            $this->logUpdate($task, $user, 'update', $message, $waMessageId);
            if ($task->assignedBy && $task->assignedBy->phone) {
                $this->wa->sendMessage($task->assignedBy->phone,
                    "📝 *{$user->name} update*\n\n📋 " . $task->title . "\n💬 " . $message
                );
            }
            return "📝 Update logged for your active task.\n\nSend *HELP* for all commands.";
        }
        return $this->helpMessage($user);
    }

    private function helpMessage(User $user): string
    {
        if ($user->isManager()) {
            return "📋 *Manager Commands*\n\n"
                 . "*ASSIGN <name> <task>* — Assign task\n"
                 . "*ADD EMPLOYEE <name> <phone> <role>* — Add employee\n"
                 . "*LIST* — Show team\n"
                 . "*STATUS <name>* — Employee status\n"
                 . "*REPORT TODAY* — Today's stats\n"
                 . "*REPORT WEEK* — Week stats\n"
                 . "*VERIFY <task-id>* — Approve task\n"
                 . "*REJECT <task-id> <reason>* — Reject\n\n"
                 . "Example:\nASSIGN Parag Visit Dr. Patel today";
        }

        return "📋 *Employee Commands*\n\n"
             . "*START* — Begin task\n"
             . "*UPDATE <text>* — Send progress\n"
             . "*COMPLETE* — Mark done\n"
             . "*DELAY <reason>* — Report delay\n"
             . "*ESCALATE <issue>* — Flag urgent\n"
             . "*STATUS* — View your tasks\n"
             . "*SCORE* — Your APIX score\n"
             . "*HELP* — Show this menu";
    }

    // ============ HELPERS ============

    private function getActiveTask(User $user): ?Task
    {
        return Task::where('assigned_to', $user->id)
            ->whereIn('status', ['assigned', 'accepted', 'in_progress', 'waiting'])
            ->latest()->first();
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
