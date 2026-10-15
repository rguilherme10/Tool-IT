<?php

namespace App\Models;

use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'type', 'name', 'severity', 'threshold', 'clear_threshold', 'min_duration_seconds', 'clear_duration_seconds',
    'repeat_interval_seconds', 'params', 'endpoint_group_id', 'enabled',
])]
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $rule) {
            $rule->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'type' => AlertRuleType::class,
            'severity' => AlertSeverity::class,
            'threshold' => 'float',
            'clear_threshold' => 'float',
            'min_duration_seconds' => 'integer',
            'clear_duration_seconds' => 'integer',
            'repeat_interval_seconds' => 'integer',
            'params' => 'array',
            'enabled' => 'boolean',
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

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /** @param Builder<AlertRule> $query */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Limite de "limpeza" efetivo (histerese). Padrões seguros quando não informado:
     * métricas % → limite − 5 pontos; sem comunicação → metade do limite.
     */
    public function effectiveClearThreshold(): float
    {
        if ($this->clear_threshold !== null) {
            return $this->clear_threshold;
        }

        return $this->type === AlertRuleType::AgentOffline
            ? $this->threshold / 2
            : max(0.0, $this->threshold - 5);
    }
}
