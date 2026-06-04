<?php

namespace App\Domain\WhatsApp\Handlers;

use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskUpdate;
use App\Domain\User\Models\User;
use App\Domain\WhatsApp\Services\WhatsAppService;
use App\Jobs\RecalculateApixScore;
use Illuminate\Support\Facades\Hash;

class CommandHandler
{
    public function __construct(private WhatsAppService $wa) {}

    public function handle(User $user, string $command, string $fullMessage, ?string $waMessageId = null): string
    {
        $user->update(['last_seen_at' => now()]);
        $cmd = strtoupper(trim($command));

        // Manager/Admin commands
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
            'HELP'     => $this->helpMessage($user),
            default    => $this->handleUnknown($user, $fullMessage, $waMessageId),
        };
    }

    // ============ MANAGER COMMANDS ============

    private function tryManagerCommand(User $manager, string $cmd, string $fullMessage): ?string
    {
        // ASSIGN <employee_name> <task_title>
        if (str_starts_with($cmd, 'ASSIGN ')) {
            return $this->handleAssign($manager, $fullMessage);
        }
        // ADD EMPLOYEE <name> <phone> <designation>
        if (str_starts_with($cmd, 'ADD EMPLOYEE')) {
            return $this->handleAddEmployee($manager, $fullMessage);
        }
        // LIST EMPLOYEES
        if ($cmd === 'LIST EMPLOYEES' || $cmd === 'LIST') {
            return $this->handleListEmployees($manager);
        }
        // REPORT TODAY / REPORT WEEK
        if (str_starts_with($cmd, 'REPORT')) {
            return $this->handleReport($manager, $cmd);
        }
        // VERIFY <task_id>
        if (str_starts_with($cmd, 'VERIFY ')) {
            return $this->handleVerify($manager, $fullMessage);
        }
        // REJECT <task_id> <reason>
        if (str_starts_with($cmd, 'REJECT ')) {
            return $this->handleReject($manager, $fullMessage);
        }
        // STATUS <employee_name>
        if (str_starts_with($cmd, 'STATUS ') && strlen($cmd) > 8) {
            return $this->handleEmployeeStatus($manager, $fullMessage);
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

        // Find employee by first name (case insensitive)
        $employee = User::where('role', 'employee')
            ->where('is_active', true)
            ->where(function ($q) use ($employeeName) {
                $q->whereRaw('LOWER(name) LIKE ?', [strtolower($employeeName) . '%'])
                  ->orWhereRaw('LOWER(SPLIT_PART(name, \' \', 1)) = ?', [strtolower($employeeName)]);
            })
            ->first();

        if (!$employee) {
            return "❌ *Employee \"{$employeeName}\" not found*\n\nReply *LIST* to see all employees.";
        }

        // Parse optional due date from task text
        $dueDate = $this->parseDueDate($taskTitle);

        $task = Task::create([
            'tenant_id'     => 'default', // Single tenant for now
            'title'         => $taskTitle,
            'assigned_by'   => $manager->id,
            'assigned_to'   => $employee->id,
            'status'        => 'assigned',
            'priority'      => 'medium',
            'due_date'      => $dueDate,
            'reward_points' => 50,
        ]);

        // Notify employee via WhatsApp
        $this->wa->sendTaskAssignment($task);

        $dueStr = $dueDate ? $dueDate->format('d M, h:i A') : 'No deadline';

        return "✅ *Task Assigned!*\n\n"
             . "👤 Employee: {$employee->name}\n"
             . "📋 Task: {$taskTitle}\n"
             . "🆔 Task ID: T-" . substr($task->id, 0, 6) . "\n"
             . "📅 Due: {$dueStr}\n\n"
             . "Employee has been notified via WhatsApp.";
    }

    private function handleAddEmployee(User $manager, string $message): string
    {
        // Format: ADD EMPLOYEE <name> <phone> [designation]
        // Example: ADD EMPLOYEE Priya Sharma +919876543210 Field Executive
        $message = preg_replace('/^ADD EMPLOYEE\s+/i', '', trim($message));
        
        // Extract phone number
        if (!preg_match('/(\+?\d{10,15})/', $message, $phoneMatch)) {
            return "❌ *Invalid format*\n\nUse:\n*ADD EMPLOYEE <name> <phone> <designation>*\n\nExample:\nADD EMPLOYEE Priya Sharma +919876543210 Field Executive";
        }

        $phone = $phoneMatch[1];
        if (!str_starts_with($phone, '+')) {
            $phone = '+91' . $phone; // Default India
        }

        // Split name (before phone) and designation (after phone)
        $parts = preg_split('/' . preg_quote($phoneMatch[1], '/') . '/', $message);
        $name = trim($parts[0]);
        $designation = isset($parts[1]) ? trim($parts[1]) : 'Employee';

        if (empty($name)) {
            return "❌ Name is required.";
        }

        // Check if already exists
        if (User::where('phone', $phone)->exists()) {
            return "❌ Employee with phone {$phone} already exists.";
        }

        $employee = User::create([
            'name'              => $name,
            'phone'             => $phone,
            'email'             => strtolower(str_replace(' ', '.', $name)) . '@uicgroup.com',
            'password'          => Hash::make('Emp@2026'),
            'role'              => 'employee',
            'designation'       => $designation,
            'whatsapp_opted_in' => true,
            'is_active'         => true,
        ]);

        // Send welcome message to new employee
        $this->wa->sendMessage($phone,
            "👋 *Welcome to TaskFlow!*\n\n"
            . "You've been added by {$manager->name}.\n\n"
            . "📱 You'll receive tasks here on WhatsApp.\n"
            . "Reply *HELP* anytime to see all commands.\n\n"
            . "Let's get started! 💪"
        );

        return "✅ *Employee Added!*\n\n"
             . "👤 Name: {$name}\n"
             . "📱 Phone: {$phone}\n"
             . "💼 Role: {$designation}\n\n"
             . "Welcome message sent to employee.";
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
            $msg .= "   💼 {$emp->designation}\n";
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
             . "👥 Active Team: {$activeEmployees}\n\n"
             . "View detailed report on dashboard.";
    }

    private function handleVerify(User $manager, string $message): string
    {
        $parts = preg_split('/\s+/', trim($message), 3);
        if (count($parts) < 2) {
            return "❌ Use: *VERIFY <task-id>*\n\nExample: VERIFY T-a1b2c3";
        }

        $taskId = str_replace('T-', '', $parts[1]);
        $task = Task::where('id', 'LIKE', $taskId . '%')->first();

        if (!$task) {
            return "❌ Task not found: {$parts[1]}";
        }

        if ($task->status !== 'completed') {
            return "⚠️ Task is not in completed state (current: {$task->status})";
        }

        $task->update([
            'status'      => 'verified',
            'verified_by' => $manager->id,
            'verified_at' => now(),
        ]);

        // Notify employee
        if ($task->assignedTo) {
            $this->wa->sendMessage($task->assignedTo->phone,
                "🎉 *Task Verified!*\n\n"
                . "📋 {$task->title}\n"
                . "✅ Verified by {$manager->name}\n"
                . "⭐ +{$task->reward_points} points credited!\n\n"
                . "Great work! 💪"
            );

            RecalculateApixScore::dispatch($task->assignedTo->id);
        }

        return "✅ Task T-" . substr($task->id, 0, 6) . " verified!\nEmployee notified.";
    }

    private function handleReject(User $manager, string $message): string
    {
        $parts = preg_split('/\s+/', trim($message), 3);
        if (count($parts) < 3) {
            return "❌ Use: *REJECT <task-id> <reason>*";
        }

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
        return "✅ Task rejected. Employee notified with reason.";
    }

    private function handleEmployeeStatus(User $manager, string $message): string
    {
        $parts = preg_split('/\s+/', trim($message), 2);
        $name = $parts[1] ?? '';

        $employee = User::where('role', 'employee')
            ->whereRaw('LOWER(name) LIKE ?', [strtolower($name) . '%'])
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

        // Notify manager
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

        // Notify manager with update content
        if ($task->assignedBy && $task->assignedBy->phone) {
            $cleanMsg = preg_replace('/^UPDATE\s*/i', '', $message);
            $this->wa->sendMessage($task->assignedBy->phone,
                "📝 *{$user->name} update*\n\n"
                . "📋 " . $task->title . "\n"
                . "💬 " . trim($cleanMsg)
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

        return "🎉 *Task Completed!*\n\n📋 {$task->title}\n\n"
             . "Manager notified for verification.\n"
             . "⭐ +{$task->reward_points} points pending approval.";
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
                "⏰ *{$user->name} reported delay*\n\n"
                . "📋 " . $task->title . "\n"
                . "Reason: " . trim($reason)
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
                "🚨 *{$user->name} escalated task*\n\n"
                . "📋 " . $task->title . "\n"
                . "Issue: " . trim($issue) . "\n\n"
                . "Immediate attention required!"
            );
        }
        return "🚨 *Escalated to manager.*\nThey've been notified immediately.";
    }

    private function handleScore(User $user): string
    {
        $score = $user->apixScores()->where('score_date', today())->first();
        if (!$score) {
            return "📊 *Your APIX Today*\n\nNo score calculated yet.\nComplete tasks to build your score!";
        }
        $band = $this->getApixBand($score->apix_score);
        return "📊 *Your APIX Score*\n\n"
             . "🏆 {$score->apix_score} — {$band}\n\n"
             . "Tasks: {$score->tasks_completed}/{$score->tasks_assigned}";
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
                 . "Example:\nASSIGN Priya Visit Dr. Patel today";
        }

        return "📋 *Employee Commands*\n\n"
             . "*START* — Begin task\n"
             . "*UPDATE <text>* — Send progress\n"
             . "*COMPLETE <text>* — Mark done\n"
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
