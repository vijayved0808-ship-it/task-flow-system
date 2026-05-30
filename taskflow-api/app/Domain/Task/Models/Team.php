<?php

namespace App\Domain\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Domain\User\Models\User;

class Team extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'manager_id', 'description'];

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'team_members')->withTimestamps();
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }
}
