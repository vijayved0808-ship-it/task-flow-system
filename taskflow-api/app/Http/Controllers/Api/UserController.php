<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\User\Models\User;
use App\Domain\WhatsApp\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'email'       => 'nullable|email|unique:users,email',
            'phone'       => 'required|string|unique:users,phone',
            'role'        => 'required|in:admin,manager,employee',
            'department'  => 'nullable|string',
            'designation' => 'nullable|string',
        ]);

        // Ensure phone starts with +
        if (!str_starts_with($data['phone'], '+')) {
            $data['phone'] = '+91' . $data['phone'];
        }

        // Auto-generate email if not provided
        if (empty($data['email'])) {
            $slug = strtolower(str_replace(' ', '.', $data['name']));
            $data['email'] = $slug . '@uicgroup.com';
        }

        $data['password']          = Hash::make('Emp@2026');
        $data['whatsapp_opted_in'] = true;
        $data['is_active']         = true;

        $user = User::create($data);

        // Send welcome WhatsApp message
        $adminName = $request->user()->name ?? 'Admin';
        $this->wa->sendMessage($user->phone,
            "👋 *Welcome to TaskFlow!*\n\n"
            . "Hi {$user->name},\n\n"
            . "You've been added by {$adminName}.\n\n"
            . "📱 You'll receive tasks here on WhatsApp.\n"
            . "Reply *HELP* anytime to see all commands.\n\n"
            . "Let's get started! 💪"
        );

        return response()->json($user, 201);
    }

    public function show(User $user)
    {
        return response()->json($user);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'        => 'string',
            'department'  => 'nullable|string',
            'designation' => 'nullable|string',
            'is_active'   => 'boolean',
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
