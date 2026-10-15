<?php

namespace App\Models;

use App\Enums\AgentInstallationStatus;
use App\Enums\EndpointStatus;
use Database\Factories\EndpointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'hostname', 'endpoint_group_id', 'status', 'os_name', 'os_version', 'os_build', 'os_arch',
    'manufacturer', 'model', 'serial_number', 'domain', 'current_user', 'ip_addresses',
    'agent_version', 'last_seen_at', 'last_inventory_at',
])]
class Endpoint extends Model
{
    /** @use HasFactory<EndpointFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (self $endpoint) {
            $endpoint->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => EndpointStatus::class,
            'ip_addresses' => 'array',
            'last_seen_at' => 'datetime',
            'last_inventory_at' => 'datetime',
        ];
    }

    /** Rotas usam uuid: IDs sequenciais não são expostos (anti-enumeração). */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(EndpointGroup::class, 'endpoint_group_id');
    }

    public function installations(): HasMany
    {
        return $this->hasMany(AgentInstallation::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function activeInstallation(): HasOne
    {
        return $this->hasOne(AgentInstallation::class)->where('status', AgentInstallationStatus::Active->value);
    }
}
