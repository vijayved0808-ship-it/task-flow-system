<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\Task\Models\Team;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index()
    {
        return response()->json(Team::with(['manager', 'members'])->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string',
            'manager_id'  => 'nullable|uuid|exists:users,id',
            'description' => 'nullable|string',
        ]);
        return response()->json(Team::create($data), 201);
    }

    public function show(Team $team)
    {
        return response()->json($team->load(['manager', 'members']));
    }

    public function update(Request $request, Team $team)
    {
        $team->update($request->only(['name', 'manager_id', 'description']));
        return response()->json($team);
    }

    public function destroy(Team $team)
    {
        $team->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function addMember(Request $request, Team $team)
    {
        $request->validate(['user_id' => 'required|uuid|exists:users,id']);
        $team->members()->syncWithoutDetaching([$request->user_id]);
        return response()->json(['message' => 'Member added']);
    }
}
