<?php

namespace App\Domain\WhatsApp\Handlers;

use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskUpdate;
use App\Domain\User\Models\User;
use App\Domain\WhatsApp\Services\WhatsAppService;
use App\Jobs\RecalculateApixScore;

class CommandHandler
{
    public function __construct(private WhatsAppService $wa) {}

    public function handle(User $user, string $command, string $fullMessage, string $waMessageId): string
    {
        // Update last seen
        $user->update(['last_seen_at' => now()]);

        return match (strtoupper(trim($command))) {
            'START'    => $this->handleStart($user, $fullMessage),
            'UPDATE'   => $this->handleUpdate($user, $fullMessage, $waMessageId),
            'COMPLETE' => $this->handleComplete($user, $fullMessage, $waMessageId),
            'DELAY'    => $this->handleDelay($user, $fullMessage),
            'ESCALATE' => $this->handleEscalate($user, $fullMessage),
            'SCORE'    => $this->handleScore($user),
            'STATUS'   => $this->handleStatus($user),
            'HELP'     => $this->helpMessage(),
            default    => $this->handleUnknown($user, $fullMessage, $waMessageId),
        };
    }

    private function handleStart(User $user, string $message): string
    {
        $task = $this->getActiveTask($user);
        if (!$task) return "No active task found.\n\nReply *STATUS* to see your tasks.";

        $task->update(['status' => 'in_progress']);
        $this->logUpdate($task, $user, 'start', $message);

        return "✅ *Task Started!*\n\n"
             . "📋 {$task->title}\n\n"
             . "Send updates anytime with *UPDATE*.\n"
             . "Mark done with *COMPLETE*.";
    }

    private function handleUpdate(User $user, string $message, string $waMessageId): string
    {
        $task = $this->getActiveTask($user);
        if (!$task) return "No active task found. Reply *STATUS* to see your tasks.";

        $this->logUpdate($task, $user, 'update', $message, $waMessageId);

        return "📝 *Update logged!*\n\nKeep going 💪\nSend *COMPLETE* when done.";
    }

    private function handleComplete(User $user, string $message, string $waMessageId): string
    {
        $task = $this->getActiveTask($user);
        if (!$task) return "No active task found.";

        $task->update(['status' => 'completed', 'completed_at' => now()]);
        $this->logUpdate($task, $user, 'complete', $message, $waMessageId);

        // Notify manager
        if ($task->assignedBy && $task->assignedBy->phone) {
            $this->wa->sendTaskCompletedNotification($task);
        }

        // Recalculate score
        RecalculateApixScore::dispatch($user->id);

        // Clear session
        $user->update(['wa_session_state' => []]);

        return "🎉 *Task Completed!*\n\n"
             . "📋 {$task->title}\n\n"
             . "Manager has been notified.\n"
             . "⭐ +{$task->reward_points} points added!\n\n"
             . "Reply *SCORE* to see your APIX today.";
    }

    private function handleDelay(User $user, string $message): string
    {
        $task = $this->getActiveTask($user);
        if (!$task) return "No active task found.";

        $task->update(['status' => 'waiting']);
        $this->logUpdate($task, $user, 'delay', $message);

        if ($task->assignedBy && $task->assignedBy->phone) {
            $this->wa->sendMessage($task->assignedBy->phone,
                "⏰ *Delay Reported*\n\n"
                . "Employee: {$user->name}\n"
                . "Task: {$task->title}\n"
                . "Reason: {$message}\n\n"
                . "Please follow up."
            );
        }

        return "⏰ *Delay noted.* Reason logged and manager informed.\n\nContinue when ready — send *START* to resume.";
    }

    private function handleEscalate(User $user, string $message): string
    {
        $task = $this->getActiveTask($user);
        if (!$task) return "No active task found.";

        $task->update(['status' => 'escalated']);
        $this->logUpdate($task, $user, 'escalate', $message);

        if ($task->assignedBy && $task->assignedBy->phone) {
            $this->wa->sendMessage($task->assignedBy->phone,
                "🚨 *Task Escalated*\n\n"
                . "Employee: {$user->name}\n"
                . "Task: {$task->title}\n"
                . "Issue: {$message}\n\n"
                . "Immediate attention required."
            );
        }

        return "🚨 *Escalated to your manager.* They've been notified immediately.\n\nStay available for their response.";
    }

    private function handleScore(User $user): string
    {
        $score = $user->apixScores()->where('score_date', today())->first();

        if (!$score) {
            return "📊 *Your APIX Score*\n\nNo score calculated yet today.\nComplete tasks to build your score!";
        }

        $band = $this->getApixBand($score->apix_score);

        return "📊 *Your APIX Score Today*\n\n"
             . "🏆 Score: {$score->apix_score} — {$band}\n\n"
             . "Completion: {$score->completion_rate}%\n"
             . "Timeliness: {$score->timeliness_score}%\n"
             . "Quality: {$score->quality_score}%\n"
             . "Consistency: {$score->consistency_score}%\n\n"
             . "Tasks done: {$score->tasks_completed}/{$score->tasks_assigned}";
    }

    private function handleStatus(User $user): string
    {
        $tasks = Task::where('assigned_to', $user->id)
            ->whereNotIn('status', ['completed', 'verified'])
            ->limit(5)->get();

        if ($tasks->isEmpty()) return "✅ No pending tasks! Great work.";

        $msg = "📋 *Your Active Tasks*\n\n";
        foreach ($tasks as $i => $task) {
            $due = $task->due_date ? $task->due_date->format('d M') : 'No deadline';
            $msg .= ($i + 1) . ". {$task->title}\n   Status: {$task->status} | Due: {$due}\n\n";
        }

        return trim($msg);
    }

    private function handleUnknown(User $user, string $message, string $waMessageId): string
    {
        // Check if there's an active task and treat as an update
        $task = $this->getActiveTask($user);
        if ($task && strlen($message) > 10) {
            $this->logUpdate($task, $user, 'update', $message, $waMessageId);
            return "📝 Update logged!\n\nSend *COMPLETE* when done or *HELP* for commands.";
        }

        return $this->helpMessage();
    }

    private function helpMessage(): string
    {
        return "📋 *TaskFlow Commands*\n\n"
             . "*START* — Begin your task\n"
             . "*UPDATE* — Send progress update\n"
             . "*COMPLETE* — Mark task as done\n"
             . "*DELAY* — Report a delay\n"
             . "*ESCALATE* — Flag an issue\n"
             . "*STATUS* — View your tasks\n"
             . "*SCORE* — Your APIX score\n"
             . "*HELP* — Show this menu";
    }

    private function getActiveTask(User $user): ?Task
    {
        $session = $user->wa_session_state ?? [];
        if (!empty($session['active_task_id'])) {
            $task = Task::find($session['active_task_id']);
            if ($task) return $task;
        }

        // Fallback: get latest assigned/in_progress task
        return Task::where('assigned_to', $user->id)
            ->whereIn('status', ['assigned', 'accepted', 'in_progress'])
            ->latest()
            ->first();
    }

    private function logUpdate(Task $task, User $user, string $command, string $message, string $waMessageId = null): void
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
}
