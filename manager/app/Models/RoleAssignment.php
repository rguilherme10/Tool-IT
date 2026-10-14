<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Atribuição de papel a usuário. endpoint_group_id NULL = escopo global.
 */
#[Fillable(['user_id', 'role_id', 'endpoint_group_id', 'assigned_by'])]
class RoleAssignment extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(EndpointGroup::class, 'endpoint_group_id');
    }

    public function isGlobal(): bool
    {
        return $this->endpoint_group_id === null;
    }
}
