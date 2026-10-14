<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'token_hash', 'label', 'endpoint_group_id', 'created_by', 'replaces_endpoint_id',
    'max_uses', 'uses', 'expires_at', 'revoked_at',
])]
class EnrollmentToken extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_uses' => 'integer',
            'uses' => 'integer',
        ];
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(EndpointGroup::class, 'endpoint_group_id');
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at->isFuture()
            && $this->uses < $this->max_uses;
    }
}
