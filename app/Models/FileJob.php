<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FileJob extends Model
{
    use HasUuids;

    protected $fillable = ['type', 'status', 'progress_stage', 'result'];

    protected $casts = ['result' => 'array'];

    public function scopeExpired($query, $days = 1)
    {
        return $query->where('created_at', '<', now()->subDays($days));
    }
}
