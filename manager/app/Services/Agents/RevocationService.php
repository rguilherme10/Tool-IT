<?php

namespace App\Services\Agents;

use App\Enums\AgentInstallationStatus;
use App\Models\AgentInstallation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class RevocationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Idempotente. Efeito imediato: a próxima requisição do Agent recebe 403 agent.revoked. */
    public function revoke(AgentInstallation $installation, ?User $actor, string $reason): void
    {
        DB::transaction(function () use ($installation, $actor, $reason) {
            $installation->refresh();
            if (! $installation->isActive()) {
                return;
            }

            $installation->forceFill([
                'status' => AgentInstallationStatus::Revoked,
                'revoked_at' => now(),
                'revoked_by' => $actor?->id,
                'revoke_reason' => mb_substr($reason, 0, 255),
                'previous_public_key' => null,
                'previous_key_valid_until' => null,
            ])->save();

            $this->audit->log('agent.revoked', $actor, $installation, $installation->endpoint_id, [
                'installation' => $installation->uuid,
                'reason' => $reason,
            ]);
        });
    }
}
