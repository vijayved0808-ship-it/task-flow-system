<?php

namespace App\Domain\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Domain\User\Models\User;

class ApixScore extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'score_date',
        'completion_rate', 'timeliness_score', 'quality_score',
        'consistency_score', 'manager_rating', 'apix_score',
        'tasks_assigned', 'tasks_completed', 'tasks_on_time',
        'tasks_late', 'total_updates', 'avg_response_minutes',
    ];

    protected $casts = [
        'score_date'        => 'date',
        'apix_score'        => 'float',
        'completion_rate'   => 'float',
        'timeliness_score'  => 'float',
        'quality_score'     => 'float',
        'consistency_score' => 'float',
        'manager_rating'    => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
