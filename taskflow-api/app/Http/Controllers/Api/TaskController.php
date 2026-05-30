<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Services\TaskService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private TaskService $taskService) {}

    public function index(Request $request)
    {
        $tasks = Task::with(['assignedTo', 'assignedBy'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->priority, fn($q, $p) => $q->where('priority', $p))
            ->when($request->assigned_to, fn($q, $u) => $q->where('assigned_to', $u))
            ->latest()
            ->paginate(50);

        return response()->json($tasks);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:500',
            'description' => 'nullable|string',
            'assigned_to' => 'nullable|uuid|exists:users,id',
            'team_id'     => 'nullable|uuid|exists:teams,id',
            'priority'    => 'in:low,medium,high,critical',
            'due_date'    => 'nullable|date',
            'reward_points' => 'integer|min:0|max:500',
        ]);

        $data['assigned_by'] = $request->user()->id;
        $task = $this->taskService->create($data);

        return response()->json($task, 201);
    }

    public function show(Task $task)
    {
        return response()->json($task->load(['assignedTo', 'assignedBy', 'updates']));
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
        $this->taskService->updateStatus($task, $request->status, $request->user());
        return response()->json($task->fresh());
    }

    public function updates(Task $task)
    {
        return response()->json($task->updates()->with('user')->latest()->get());
    }
}
