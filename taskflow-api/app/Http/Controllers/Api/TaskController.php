<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Task\Models\Task;
use App\Domain\WhatsApp\Services\WhatsAppService;
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

            Log::info('Task created', [
                'task_id'     => $task->id,
                'assigned_to' => $task->assigned_to,
                'title'       => $task->title
            ]);

            // Send WhatsApp notification to assigned user (non-blocking)
            try {
                if ($task->assignedTo && $task->assignedTo->phone) {
                    $sent = $this->wa->sendTaskAssignment($task);
                    Log::info('Task assignment WA result', [
                        'task_id' => $task->id,
                        'to'      => $task->assignedTo->phone,
                        'sent'    => $sent
                    ]);
                } else {
                    Log::warning('Task created but assignee has no phone', ['task_id' => $task->id]);
                }
            } catch (\Exception $waError) {
                Log::error('WhatsApp send failed for task', [
                    'task_id' => $task->id,
                    'error'   => $waError->getMessage()
                ]);
                // Don't fail the request
            }

            return response()->json($task, 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Task creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $request->all()
            ]);
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
