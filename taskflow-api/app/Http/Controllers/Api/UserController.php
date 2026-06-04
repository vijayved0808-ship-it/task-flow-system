<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\User\Models\User;
use App\Domain\Task\Models\Task;
use App\Domain\WhatsApp\Services\WhatsAppService;
use App\Domain\Logs\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function __construct(private WhatsAppService $wa) {}

    public function index(Request $request)
    {
        // Return all users with their stats
        $users = User::orderByRaw("CASE role WHEN 'admin' THEN 1 WHEN 'manager' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();

        // Add stats to each user
        $users->each(function ($user) {
            $user->stats = [
                'total_assigned'  => Task::where('assigned_to', $user->id)->count(),
                'completed'       => Task::where('assigned_to', $user->id)->whereIn('status', ['completed', 'verified'])->count(),
                'pending'         => Task::where('assigned_to', $user->id)->whereNotIn('status', ['completed', 'verified', 'rejected'])->count(),
                'overdue'         => Task::where('assigned_to', $user->id)->where('due_date', '<', now())->whereNotIn('status', ['completed', 'verified'])->count(),
                'direct_reports'  => User::where('reports_to', $user->id)->where('is_active', true)->count(),
            ];
        });

        return response()->json($users);
    }

    /**
     * Return tree structure starting from root users (those with no manager).
     */
    public function tree()
    {
        $rootUsers = User::whereNull('reports_to')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(
            $rootUsers->map(fn($u) => $this->buildTreeNode($u))
        );
    }

    private function buildTreeNode(User $user): array
    {
        $children = User::where('reports_to', $user->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'phone'       => $user->phone,
            'role'        => $user->role,
            'designation' => $user->designation,
            'department'  => $user->department,
            'reports_to'  => $user->reports_to,
            'is_active'   => $user->is_active,
            'stats'       => [
                'total_assigned' => Task::where('assigned_to', $user->id)->count(),
                'completed'      => Task::where('assigned_to', $user->id)->whereIn('status', ['completed', 'verified'])->count(),
                'pending'        => Task::where('assigned_to', $user->id)->whereNotIn('status', ['completed', 'verified', 'rejected'])->count(),
                'overdue'        => Task::where('assigned_to', $user->id)->where('due_date', '<', now())->whereNotIn('status', ['completed', 'verified'])->count(),
            ],
            'children' => $children->map(fn($c) => $this->buildTreeNode($c))->all(),
        ];
    }

    public function store(Request $request)
    {
        try {
            $phone = $this->normalizePhone($request->input('phone', ''));
            $request->merge(['phone' => $phone]);

            $data = $request->validate([
                'name'        => 'required|string|max:255',
                'email'       => 'nullable|email|max:255',
                'phone'       => 'required|string|max:20',
                'role'        => 'nullable|in:admin,manager,employee',
                'department'  => 'nullable|string|max:100',
                'designation' => 'nullable|string|max:100',
                'reports_to'  => 'nullable|uuid|exists:users,id',
            ]);

            if (empty($data['email'])) {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/', '.', $data['name']));
                $slug = trim($slug, '.');
                $data['email'] = $slug . '+' . substr(uniqid(), -4) . '@uicgroup.com';
            }

            if (User::where('phone', $data['phone'])->exists()) {
                $existing = User::where('phone', $data['phone'])->first();
                return response()->json([
                    'message' => "Phone {$data['phone']} already used by: " . $existing->name,
                    'errors'  => ['phone' => ['Already exists']]
                ], 422);
            }

            if (User::where('email', $data['email'])->exists()) {
                $data['email'] = str_replace('@', '+' . substr(uniqid(), -4) . '@', $data['email']);
            }

            $data['role']              = $data['role'] ?? 'employee';
            $data['password']          = Hash::make('Emp@2026');
            $data['whatsapp_opted_in'] = true;
            $data['is_active']         = true;

            $user = User::create($data);

            ActivityLog::record(
                'user', 'create', 'success',
                "👤 User added: {$user->name} ({$user->role})" . ($user->reports_to ? " — reports to {$user->manager?->name}" : ""),
                ['user_id' => $user->id, 'created_via' => 'dashboard'],
                $user->phone
            );

            try {
                $adminName = $request->user()?->name ?? 'Admin';
                if ($this->wa->isEnabled()) {
                    $this->wa->sendMessage($user->phone,
                        "👋 *Welcome to TaskFlow!*\n\n"
                        . "Hi {$user->name},\n"
                        . "You've been added by {$adminName}.\n"
                        . "Role: " . ucfirst($user->role) . "\n\n"
                        . "📱 Reply *HELP* anytime for commands."
                    );
                }
            } catch (\Exception $waError) {
                Log::warning('Welcome WA failed', ['error' => $waError->getMessage()]);
            }

            return response()->json($user, 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('User creation failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    public function show(User $user)
    {
        return response()->json($user);
    }

    public function update(Request $request, User $user)
    {
        try {
            if ($request->has('phone')) {
                $phone = $this->normalizePhone($request->input('phone'));
                $request->merge(['phone' => $phone]);
            }

            $data = $request->validate([
                'name'        => 'sometimes|string|max:255',
                'email'       => 'sometimes|nullable|email|max:255',
                'phone'       => 'sometimes|string|max:20',
                'role'        => 'sometimes|in:admin,manager,employee',
                'department'  => 'sometimes|nullable|string|max:100',
                'designation' => 'sometimes|nullable|string|max:100',
                'is_active'   => 'sometimes|boolean',
                'reports_to'  => 'sometimes|nullable|uuid|exists:users,id',
            ]);

            // Prevent circular hierarchy
            if (isset($data['reports_to']) && $data['reports_to']) {
                if ($data['reports_to'] === $user->id) {
                    return response()->json(['message' => "User can't report to themselves"], 422);
                }
                // Check if the new manager is actually in this user's sub-tree (would create cycle)
                $descendants = $user->allDescendants();
                if ($descendants->contains('id', $data['reports_to'])) {
                    return response()->json(['message' => "Can't set reports_to: would create circular hierarchy"], 422);
                }
            }

            if (isset($data['phone']) && $data['phone'] !== $user->phone) {
                $existing = User::where('phone', $data['phone'])->where('id', '!=', $user->id)->first();
                if ($existing) {
                    return response()->json([
                        'message' => "Phone {$data['phone']} already used by: " . $existing->name,
                        'errors'  => ['phone' => ['Already exists']]
                    ], 422);
                }
            }

            $user->update($data);

            ActivityLog::record(
                'user', 'update', 'success',
                "✏️ User updated: {$user->name}",
                ['user_id' => $user->id, 'changes' => array_keys($data)]
            );

            return response()->json($user->fresh());

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('User update failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(User $user)
    {
        try {
            if ($user->role === 'admin') {
                $user->update(['is_active' => false]);
                return response()->json(['message' => 'Admin deactivated (not deleted)']);
            }

            // Re-parent children to this user's manager
            $newParent = $user->reports_to;
            User::where('reports_to', $user->id)->update(['reports_to' => $newParent]);

            $user->update(['is_active' => false]);

            ActivityLog::record(
                'user', 'deactivate', 'success',
                "🗑 User deactivated: {$user->name}",
                ['user_id' => $user->id, 'reparented_to' => $newParent]
            );

            return response()->json(['message' => 'User deactivated. Children re-parented.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    public function tasks(User $user, Request $request)
    {
        return response()->json(
            $user->assignedTasks()
                ->when($request->status, fn($q, $s) => $q->where('status', $s))
                ->latest()->get()
        );
    }

    public function scores(User $user)
    {
        return response()->json(
            $user->apixScores()->orderByDesc('score_date')->take(30)->get()
        );
    }

    private function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);

        if (empty($phone)) return '';
        if (str_starts_with($phone, '+')) return $phone;

        if (strlen($phone) === 10) return '+91' . $phone;
        if (str_starts_with($phone, '91') && strlen($phone) === 12) return '+' . $phone;
        if (str_starts_with($phone, '0') && strlen($phone) === 11) return '+91' . substr($phone, 1);

        return '+' . $phone;
    }
}
