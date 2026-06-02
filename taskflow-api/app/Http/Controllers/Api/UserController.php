<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\User\Models\User;
use App\Domain\WhatsApp\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private WhatsAppService $wa) {}

    public function index()
    {
        return response()->json(
            User::orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        try {
            // Normalize phone number FIRST before validation
            $phone = trim($request->input('phone', ''));
            $phone = preg_replace('/[\s\-\(\)]/', '', $phone); // remove spaces, dashes, brackets
            
            // Ensure phone has + prefix
            if (!empty($phone) && !str_starts_with($phone, '+')) {
                // If 10 digits, assume India
                if (strlen($phone) === 10) {
                    $phone = '+91' . $phone;
                } 
                // If starts with 91, add +
                elseif (str_starts_with($phone, '91') && strlen($phone) === 12) {
                    $phone = '+' . $phone;
                }
                // If starts with 0, treat as Indian
                elseif (str_starts_with($phone, '0') && strlen($phone) === 11) {
                    $phone = '+91' . substr($phone, 1);
                }
                else {
                    $phone = '+' . $phone;
                }
            }
            
            $request->merge(['phone' => $phone]);

            // Validate WITHOUT password requirement
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
            $existingPhone = User::where('phone', $data['phone'])->first();
            if ($existingPhone) {
                return response()->json([
                    'message' => "Phone number {$data['phone']} already registered to: " . $existingPhone->name,
                    'errors' => ['phone' => ['Already exists']]
                ], 422);
            }

            // Check duplicate email
            $existingEmail = User::where('email', $data['email'])->first();
            if ($existingEmail) {
                // Auto-fix by adding suffix
                $data['email'] = str_replace('@', '+' . substr(uniqid(), -4) . '@', $data['email']);
            }

            // Set defaults
            $data['role']              = $data['role'] ?? 'employee';
            $data['password']          = Hash::make('Emp@2026');
            $data['whatsapp_opted_in'] = true;
            $data['is_active']         = true;

            // Create user
            $user = User::create($data);

            Log::info('User created', ['user_id' => $user->id, 'phone' => $user->phone]);

            // Try sending welcome WhatsApp message (don't fail if WA fails)
            try {
                $adminName = $request->user()?->name ?? 'Admin';
                $this->wa->sendMessage($user->phone,
                    "👋 *Welcome to TaskFlow!*\n\n"
                    . "Hi {$user->name},\n\n"
                    . "You've been added by {$adminName}.\n\n"
                    . "📱 You'll receive tasks here on WhatsApp.\n"
                    . "Reply *HELP* anytime to see all commands.\n\n"
                    . "Let's get started! 💪"
                );
            } catch (\Exception $waError) {
                Log::warning('WhatsApp welcome message failed', [
                    'user_id' => $user->id,
                    'error' => $waError->getMessage()
                ]);
                // Don't fail the request - user is still created
            }

            return response()->json($user, 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('User validation failed', [
                'errors' => $e->errors(),
                'input' => $request->except(['password'])
            ]);
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('User creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $request->except(['password'])
            ]);
            return response()->json([
                'message' => 'Server error: ' . $e->getMessage(),
                'detail' => app()->environment('production') ? null : $e->getTraceAsString()
            ], 500);
        }
    }

    public function show(User $user)
    {
        return response()->json($user);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'        => 'string|max:255',
            'department'  => 'nullable|string',
            'designation' => 'nullable|string',
            'is_active'   => 'boolean',
            'role'        => 'in:admin,manager,employee',
        ]);
        $user->update($data);
        return response()->json($user);
    }

    public function destroy(User $user)
    {
        $user->update(['is_active' => false]);
        return response()->json(['message' => 'User deactivated']);
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
}
