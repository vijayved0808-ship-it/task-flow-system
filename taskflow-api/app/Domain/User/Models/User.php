<?php

namespace App\Domain\User\Models;

use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\ApixScore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'role', 'reports_to',
        'department', 'designation', 'team_id', 'whatsapp_opted_in',
        'is_active', 'last_seen_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at'  => 'datetime',
        'last_seen_at'       => 'datetime',
        'password'           => 'hashed',
        'whatsapp_opted_in'  => 'boolean',
        'is_active'          => 'boolean',
    ];

    // Role helpers
    public function isManager(): bool
    {
        return in_array($this->role, ['admin', 'manager']);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    // Hierarchy relationships
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to');
    }

    /**
     * Get all descendants (entire sub-tree below this user).
     * Returns flat collection of all users at any depth below.
     */
    public function allDescendants(): \Illuminate\Support\Collection
    {
        $descendants = collect();
        $queue = $this->directReports()->where('is_active', true)->get();

        while ($queue->isNotEmpty()) {
            $current = $queue->shift();
            $descendants->push($current);
            $children = $current->directReports()->where('is_active', true)->get();
            foreach ($children as $child) {
                $queue->push($child);
            }
        }
        return $descendants;
    }

    /**
     * Check if this user can assign tasks to $target.
     * Admin can assign to anyone. Manager can assign to anyone in their sub-tree.
     */
    public function canAssignTo(User $target): bool
    {
        if ($this->id === $target->id) return false; // Can't self-assign
        if ($this->isAdmin()) return true;            // Admin = anyone
        if (!$this->isManager()) return false;        // Employees can't assign

        // Manager: check if target is in their sub-tree
        return $this->allDescendants()->contains('id', $target->id);
    }

    // Task relationships
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_by');
    }

    public function apixScores(): HasMany
    {
        return $this->hasMany(ApixScore::class);
    }
}
