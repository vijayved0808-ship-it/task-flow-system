<?php

namespace App\Domain\User\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\HasApiTokens;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\ApixScore;
use App\Domain\Task\Models\Team;

class User extends Authenticatable
{
    use HasUuids, HasApiTokens;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'role',
        'department', 'designation', 'employee_code',
        'whatsapp_opted_in', 'wa_session_state',
        'is_active', 'last_seen_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'whatsapp_opted_in' => 'boolean',
        'is_active'         => 'boolean',
        'wa_session_state'  => 'array',
        'last_seen_at'      => 'datetime',
    ];

    public function assignedTasks()
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function createdTasks()
    {
        return $this->hasMany(Task::class, 'assigned_by');
    }

    public function apixScores()
    {
        return $this->hasMany(ApixScore::class);
    }

    public function teams()
    {
        return $this->belongsToMany(Team::class, 'team_members')->withTimestamps();
    }

    public function isManager(): bool
    {
        return in_array($this->role, ['admin', 'manager', 'super_admin']);
    }

    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }
}
