<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::with('teams')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string',
            'email'       => 'required|email|unique:users,email',
            'phone'       => 'required|string|unique:users,phone',
            'role'        => 'required|in:admin,manager,employee',
            'department'  => 'nullable|string',
            'designation' => 'nullable|string',
        ]);

        $data['password'] = Hash::make('Welcome@123');
        $user = User::create($data);

        return response()->json($user, 201);
    }

    public function show(User $user)
    {
        return response()->json($user->load('teams'));
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
