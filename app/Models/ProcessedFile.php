<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedFile extends Model
{
    protected $fillable = [
        'filename',
        'type',
        'path',
        'size',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at && now()->greaterThan($this->expires_at);
    }

    public function incrementDownload(): void
    {
        $this->increment('downloads');
    }
}
