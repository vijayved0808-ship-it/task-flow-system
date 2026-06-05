<?php

namespace App\Domain\WhatsApp\Models;

use App\Domain\Task\Models\Task;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaMedia extends Model
{
    use HasUuids;

    protected $table = 'wa_media';

    protected $fillable = [
        'meta_media_id', 'user_id', 'type', 'mime_type', 'filename',
        'file_path', 'caption', 'task_id', 'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
