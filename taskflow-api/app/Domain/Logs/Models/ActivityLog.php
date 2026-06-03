<?php

namespace App\Domain\Logs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ActivityLog extends Model
{
    use HasUuids;

    protected $table = 'activity_logs';

    protected $fillable = [
        'type', 'action', 'status', 'message', 'meta', 'phone'
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public static function record(string $type, string $action, string $status, string $message, array $meta = [], ?string $phone = null): self
    {
        try {
            return self::create([
                'type' => $type,
                'action' => $action,
                'status' => $status,
                'message' => substr($message, 0, 500),
                'meta' => $meta,
                'phone' => $phone,
            ]);
        } catch (\Exception $e) {
            // Fail silently — logs must never break app
            \Illuminate\Support\Facades\Log::error('ActivityLog write failed: ' . $e->getMessage());
            return new self();
        }
    }
}
