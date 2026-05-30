<?php

namespace App\Domain\Task\Services;

use App\Domain\Task\Models\Task;
use App\Domain\User\Models\User;
use App\Domain\WhatsApp\Services\WhatsAppService;
use App\Jobs\AnalyzeTaskWithAI;

class TaskService
{
    public function __construct(private WhatsAppService $wa) {}

    public function create(array $data): Task
    {
        $task = Task::create($data);

        // Notify employee via WhatsApp
        if ($task->assignedTo && $task->assignedTo->phone) {
            $this->wa->sendTaskAssignment($task);
        }

        return $task;
    }

    public function updateStatus(Task $task, string $newStatus, User $updatedBy): Task
    {
        $oldStatus = $task->status;
        $task->update(['status' => $newStatus]);

        if ($newStatus === 'completed') {
            $task->update(['completed_at' => now()]);
            // Notify manager
            if ($task->assignedBy) {
                $this->wa->sendTaskCompletedNotification($task);
            }
            // Queue AI analysis
            AnalyzeTaskWithAI::dispatch($task);
        }

        return $task;
    }
}
