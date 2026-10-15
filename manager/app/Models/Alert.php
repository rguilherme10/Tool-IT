<?php

namespace App\Models;

use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Escrita somente por App\Services\Alerts\AlertService (máquina de estados); guarded evita atribuição em massa. */
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $alert) {
            $alert->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'rule_type' => AlertRuleType::class,
            'status' => AlertStatus::class,
            'severity' => AlertSeverity::class,
            'details' => 'array',
            'opened_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_repeated_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'repeat_count' => 'integer',
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

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    /** @param Builder<Alert> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', AlertStatus::activeValues());
    }

    public static function dedupeHash(int $ruleId, int $endpointId, ?string $subject): string
    {
        return hash('sha256', "{$ruleId}|{$endpointId}|".($subject ?? ''));
    }
}
