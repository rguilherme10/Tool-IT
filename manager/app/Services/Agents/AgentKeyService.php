<?php

namespace App\Services\Agents;

use App\Models\AgentInstallation;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AgentKeyService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Troca a chave da instalação. A chave anterior continua válida por uma janela curta
     * (transição sem perda de requests em voo). Chamado já autenticado com a chave vigente.
     */
    public function rotate(AgentInstallation $installation, string $newPublicKeyBase64): CarbonInterface
    {
        try {
            $newKey = PublicKey::fromBase64Der($newPublicKeyBase64);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['new_public_key' => $e->getMessage()]);
        }

        return DB::transaction(function () use ($installation, $newKey) {
            if ($newKey->fingerprint === $installation->key_fingerprint
                || AgentInstallation::where('key_fingerprint', $newKey->fingerprint)->exists()) {
                throw ValidationException::withMessages(['new_public_key' => 'Chave já utilizada.']);
            }

            $validUntil = now()->addSeconds(config('toolit.agent.previous_key_grace_seconds'));
            $oldFingerprint = $installation->key_fingerprint;

            $installation->forceFill([
                'previous_public_key' => $installation->public_key,
                'previous_key_valid_until' => $validUntil,
                'public_key' => $newKey->base64Der,
                'key_fingerprint' => $newKey->fingerprint,
            ])->save();

            $this->audit->log('agent.key_rotated', $installation, $installation, $installation->endpoint_id, [
                'old_fingerprint' => $oldFingerprint,
                'new_fingerprint' => $newKey->fingerprint,
            ]);

            return $validUntil;
        });
    }
}
