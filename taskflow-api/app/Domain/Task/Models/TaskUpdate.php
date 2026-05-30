<?php

namespace App\Domain\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Domain\User\Models\User;

class TaskUpdate extends Model
{
    use HasUuids;

    protected $fillable = [
        'task_id', 'user_id', 'wa_message_id', 'command',
        'message', 'ai_analysis', 'response_time_minutes',
    ];

    protected $casts = [
        'ai_analysis' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }
}
