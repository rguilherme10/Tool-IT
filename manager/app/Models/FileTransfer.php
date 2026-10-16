<?php

namespace App\Models;

use App\Enums\TransferDirection;
use App\Enums\TransferStatus;
use Database\Factories\FileTransferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Transferência de arquivo (envio ou recuperação). Escrita somente por App\Services\Transfers\TransferService
 * (máquina de estados + auditoria); guarded evita atribuição em massa. Os bytes ficam no disco privado
 * `local`, em transfers/{uuid}/, com nomes gerados pelo servidor — original_name é só metadado.
 */
class FileTransfer extends Model
{
    /** @use HasFactory<FileTransferFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $t) {
            $t->uuid ??= (string) Str::uuid();
            $t->stored_name ??= $t->uuid;
        });
    }

    protected function casts(): array
    {
        return [
            'direction' => TransferDirection::class,
            'status' => TransferStatus::class,
            'size' => 'integer',
            'chunk_size' => 'integer',
            'chunks_total' => 'integer',
            'chunks_received' => 'integer',
            'overwrite' => 'boolean',
            'expires_at' => 'datetime',
            'purged_at' => 'datetime',
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

    public function task(): BelongsTo
    {
        return $this->belongsTo(RemoteTask::class, 'remote_task_id');
    }
}
