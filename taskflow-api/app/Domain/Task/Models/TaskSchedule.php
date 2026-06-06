<?php

namespace App\Domain\Task\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskSchedule extends Model
{
    use HasUuids;

    protected $table = 'task_schedules';

    protected $fillable = [
        'tenant_id', 'title', 'assigned_by', 'assigned_to',
        'schedule_type', 'days_of_week', 'priority', 'reward_points',
        'is_active', 'last_dispatched_at',
    ];

    protected $casts = [
        'days_of_week'       => 'array',
        'is_active'          => 'boolean',
        'last_dispatched_at' => 'datetime',
    ];

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
