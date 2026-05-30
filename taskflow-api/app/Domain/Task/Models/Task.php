<?php

namespace App\Domain\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Domain\User\Models\User;

class Task extends Model
{
    use HasUuids;

    protected $fillable = [
        'title', 'description', 'assigned_by', 'assigned_to', 'team_id',
        'status', 'priority', 'due_date', 'reward_points', 'is_recurring',
        'ai_score', 'ai_summary', 'verified_by', 'verified_at', 'completed_at',
    ];

    protected $casts = [
        'due_date'     => 'datetime',
        'completed_at' => 'datetime',
        'verified_at'  => 'datetime',
        'is_recurring' => 'boolean',
        'ai_score'     => 'float',
    ];

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function updates()
    {
        return $this->hasMany(TaskUpdate::class);
    }

    public function isOverdue(): bool
    {
        return $this->due_date && $this->due_date->isPast()
            && !in_array($this->status, ['completed', 'verified']);
    }
}
