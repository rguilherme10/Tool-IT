<?php

namespace App\Models;

use App\Enums\AgentInstallationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'endpoint_id', 'public_key', 'key_fingerprint', 'previous_public_key', 'previous_key_valid_until',
    'status', 'agent_version', 'machine_fingerprint', 'enrolled_at', 'last_seen_at',
    'revoked_at', 'revoked_by', 'revoke_reason',
])]
#[Hidden(['public_key', 'previous_public_key'])]
class AgentInstallation extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $installation) {
            $installation->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => AgentInstallationStatus::class,
            'enrolled_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
            'previous_key_valid_until' => 'datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    public function isActive(): bool
    {
        return $this->status === AgentInstallationStatus::Active;
    }
}
