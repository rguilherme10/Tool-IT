<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Escrita somente por App\Services\Tasks\TaskService; guarded evita atribuição em massa. */
class RemoteTaskAttempt extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $attempt) {
            $attempt->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'exit_code' => 'integer',
            'truncated' => 'boolean',
            'claimed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(RemoteTask::class, 'remote_task_id');
    }
}
