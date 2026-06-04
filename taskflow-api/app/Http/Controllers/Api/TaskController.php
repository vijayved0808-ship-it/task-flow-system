<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Task\Models\Task;
use App\Domain\WhatsApp\Services\WhatsAppService;
use App\Domain\Logs\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TaskController extends Controller
{
    public function __construct(private WhatsAppService $wa) {}

    public function index(Request $request)
    {
        $tasks = Task::with(['assignedTo:id,name,phone,designation', 'assignedBy:id,name'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->priority, fn($q, $p) => $q->where('priority', $p))
            ->when($request->assigned_to, fn($q, $u) => $q->where('assigned_to', $u))
            ->latest()
            ->paginate(50);

        return response()->json($tasks);
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'title'         => 'required|string|max:500',
                'description'   => 'nullable|string',
                'assigned_to'   => 'required|uuid|exists:users,id',
                'team_id'       => 'nullable|uuid|exists:teams,id',
                'priority'      => 'in:low,medium,high,critical',
                'due_date'      => 'nullable|date',
                'reward_points' => 'integer|min:0|max:500',
            ]);

            $data['assigned_by']   = $request->user()->id;
            $data['status']        = 'assigned';
            $data['priority']      = $data['priority'] ?? 'medium';
            $data['reward_points'] = $data['reward_points'] ?? 50;
            $data['tenant_id']     = 'default';

            $task = Task::create($data);
            $task->load(['assignedTo', 'assignedBy']);

            ActivityLog::record(
                'task', 'create', 'success',
                "📋 Task created: {$task->title}",
                [
                    'task_id' => $task->id,
                    'assigned_to' => $task->assignedTo?->name,
                    'phone' => $task->assignedTo?->phone,
                    'points' => $task->reward_points
                ],
                $task->assignedTo?->phone
            );

            // Send WhatsApp notification
            if ($task->assignedTo && $task->assignedTo->phone) {
                $this->wa->sendTaskAssignment($task);
            } else {
                ActivityLog::record(
                    'task', 'whatsapp_skip', 'failed',
                    "Task created but assignee has no phone — WhatsApp skipped",
                    ['task_id' => $task->id]
                );
            }

            return response()->json($task, 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            ActivityLog::record(
                'task', 'create', 'failed',
                "Task validation failed",
                ['errors' => $e->errors()]
            );
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            ActivityLog::record(
                'task', 'create', 'failed',
                "Task creation error: " . $e->getMessage(),
                ['exception' => get_class($e)]
            );
            return response()->json([
                'message' => 'Server error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(Task $task)
    {
        return response()->json($task->load(['assignedTo', 'assignedBy']));
    }

    public function update(Request $request, Task $task)
    {
        $data = $request->validate([
            'title'         => 'string|max:500',
            'description'   => 'nullable|string',
            'priority'      => 'in:low,medium,high,critical',
            'due_date'      => 'nullable|date',
            'reward_points' => 'integer|min:0|max:500',
        ]);

        $task->update($data);
        return response()->json($task);
    }

    public function destroy(Task $task)
    {
        $task->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function updateStatus(Request $request, Task $task)
    {
        $request->validate(['status' => 'required|in:assigned,accepted,in_progress,waiting,completed,verified,rejected,escalated']);
        $task->update(['status' => $request->status]);
        return response()->json($task->fresh()->load('assignedTo'));
    }
}
