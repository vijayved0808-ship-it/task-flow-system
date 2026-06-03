<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\User\Models\User;
use App\Domain\WhatsApp\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function __construct(private WhatsAppService $wa) {}

    public function index()
    {
        return response()->json(
            User::orderByRaw("CASE role WHEN 'admin' THEN 1 WHEN 'manager' THEN 2 ELSE 3 END")
                ->orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        try {
            // Normalize phone
            $phone = $this->normalizePhone($request->input('phone', ''));
            $request->merge(['phone' => $phone]);

            $data = $request->validate([
                'name'        => 'required|string|max:255',
                'email'       => 'nullable|email|max:255',
                'phone'       => 'required|string|max:20',
                'role'        => 'nullable|in:admin,manager,employee',
                'department'  => 'nullable|string|max:100',
                'designation' => 'nullable|string|max:100',
            ]);

            // Auto-generate email if not provided
            if (empty($data['email'])) {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/', '.', $data['name']));
                $slug = trim($slug, '.');
                $data['email'] = $slug . '+' . substr(uniqid(), -4) . '@uicgroup.com';
            }

            // Check duplicate phone
            if (User::where('phone', $data['phone'])->exists()) {
                $existing = User::where('phone', $data['phone'])->first();
                return response()->json([
                    'message' => "Phone {$data['phone']} already used by: " . $existing->name,
                    'errors' => ['phone' => ['Already exists']]
                ], 422);
            }

            // Check duplicate email
            if (User::where('email', $data['email'])->exists()) {
                $data['email'] = str_replace('@', '+' . substr(uniqid(), -4) . '@', $data['email']);
            }

            $data['role']              = $data['role'] ?? 'employee';
            $data['password']          = Hash::make('Emp@2026');
            $data['whatsapp_opted_in'] = true;
            $data['is_active']         = true;

            $user = User::create($data);

            Log::info('User created', ['user_id' => $user->id, 'phone' => $user->phone, 'role' => $user->role]);

            // Send welcome WhatsApp (non-blocking)
            try {
                $adminName = $request->user()?->name ?? 'Admin';
                if ($this->wa->isEnabled() ?? true) {
                    $this->wa->sendMessage($user->phone,
                        "👋 *Welcome to TaskFlow!*\n\n"
                        . "Hi {$user->name},\n\n"
                        . "You've been added by {$adminName}.\n"
                        . "Role: " . ucfirst($user->role) . "\n\n"
                        . "📱 You'll receive tasks here on WhatsApp.\n"
                        . "Reply *HELP* anytime for commands.\n\n"
                        . "Let's get started! 💪"
                    );
                }
            } catch (\Exception $waError) {
                Log::warning('WhatsApp welcome failed', ['user_id' => $user->id, 'error' => $waError->getMessage()]);
            }

            return response()->json($user, 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('User creation failed', [
                'error' => $e->getMessage(),
                'input' => $request->except(['password'])
            ]);
            return response()->json([
                'message' => 'Server error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function show(User $user)
    {
        return response()->json($user);
    }

    public function update(Request $request, User $user)
    {
        try {
            // Normalize phone if provided
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
            ]);

            // Check phone duplicate (exclude self)
            if (isset($data['phone']) && $data['phone'] !== $user->phone) {
                $existing = User::where('phone', $data['phone'])->where('id', '!=', $user->id)->first();
                if ($existing) {
                    return response()->json([
                        'message' => "Phone {$data['phone']} already used by: " . $existing->name,
                        'errors' => ['phone' => ['Already exists']]
                    ], 422);
                }
            }

            // Check email duplicate (exclude self)
            if (isset($data['email']) && $data['email'] !== $user->email) {
                $existing = User::where('email', $data['email'])->where('id', '!=', $user->id)->first();
                if ($existing) {
                    return response()->json([
                        'message' => "Email already used by: " . $existing->name,
                        'errors' => ['email' => ['Already exists']]
                    ], 422);
                }
            }

            $oldPhone = $user->phone;
            $user->update($data);

            Log::info('User updated', [
                'user_id' => $user->id,
                'changes' => $data,
                'phone_changed' => isset($data['phone']) && $data['phone'] !== $oldPhone
            ]);

            return response()->json($user->fresh());

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('User update failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => 'Server error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(User $user)
    {
        try {
            // Don't delete admin users — deactivate only
            if ($user->role === 'admin') {
                $user->update(['is_active' => false]);
                return response()->json(['message' => 'Admin deactivated (not deleted)']);
            }

            // For non-admin: deactivate instead of hard delete (preserve task history)
            $user->update(['is_active' => false]);

            Log::info('User deactivated', ['user_id' => $user->id, 'name' => $user->name]);

            return response()->json(['message' => 'User deactivated successfully']);
        } catch (\Exception $e) {
            Log::error('User delete failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
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

    // Helper: Normalize phone numbers
    private function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);

        if (empty($phone)) return '';
        if (str_starts_with($phone, '+')) return $phone;

        // Auto-add country code
        if (strlen($phone) === 10) return '+91' . $phone;
        if (str_starts_with($phone, '91') && strlen($phone) === 12) return '+' . $phone;
        if (str_starts_with($phone, '0') && strlen($phone) === 11) return '+91' . substr($phone, 1);

        return '+' . $phone;
    }
}
