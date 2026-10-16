<?php

namespace App\Models;

use Database\Factories\FileTransferPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Política de transferência de arquivos de um grupo (ou global, com endpoint_group_id nulo).
 * Escrita só pelo serviço de políticas (com validação do PathGuard e auditoria); guarded evita atribuição em massa.
 */
class FileTransferPolicy extends Model
{
    /** @use HasFactory<FileTransferPolicyFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $policy) {
            $policy->uuid ??= (string) Str::uuid();
        });

        // A chave de escopo nunca vem de fora: é derivada do grupo, então não há duas políticas para o mesmo escopo.
        static::saving(function (self $policy) {
            $policy->scope_key = self::scopeKeyFor($policy->endpoint_group_id === null ? null : (int) $policy->endpoint_group_id);
        });
    }

    public static function scopeKeyFor(?int $groupId): string
    {
        return $groupId === null ? 'global' : 'group:'.$groupId;
    }

    protected function casts(): array
    {
        return [
            'send_dirs' => 'array',
            'fetch_dirs' => 'array',
            'allowed_extensions' => 'array',
            'max_bytes' => 'integer',
            'allow_overwrite' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(EndpointGroup::class, 'endpoint_group_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
