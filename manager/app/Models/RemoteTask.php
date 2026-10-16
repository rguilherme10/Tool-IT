<?php

namespace App\Models;

use App\Enums\TaskRisk;
use App\Enums\TaskState;
use Database\Factories\RemoteTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/** Escrita somente por App\Services\Tasks\TaskService (máquina de estados + auditoria); guarded evita atribuição em massa. */
class RemoteTask extends Model
{
    /** @use HasFactory<RemoteTaskFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $task) {
            $task->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'state' => TaskState::class,
            'risk' => TaskRisk::class,
            'params' => 'array',
            'result_summary' => 'array',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(RemoteTaskAttempt::class)->orderBy('number');
    }

    /** Última tentativa (a vigente enquanto a tarefa está em execução). */
    public function currentAttempt(): HasOne
    {
        return $this->hasOne(RemoteTaskAttempt::class)->ofMany('number', 'max');
    }
}
