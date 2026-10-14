<?php

namespace App\Services\Agents;

use App\Enums\AgentInstallationStatus;
use App\Enums\EndpointStatus;
use App\Enums\Permission;
use App\Exceptions\EnrollmentException;
use App\Models\AgentInstallation;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\EnrollmentToken;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Authorization\AccessResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EnrollmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RevocationService $revocation,
        private readonly AccessResolver $access,
    ) {}

    /**
     * Emite um token de enrollment. O texto puro só existe no retorno desta chamada
     * (no banco fica apenas o SHA-256).
     *
     * @return array{0: EnrollmentToken, 1: string}
     *
     * @throws AuthorizationException
     */
    public function issueToken(
        User $actor,
        ?EndpointGroup $group = null,
        int $maxUses = 1,
        ?int $ttlHours = null,
        ?Endpoint $replaces = null,
        ?string $label = null,
    ): array {
        $this->authorizeIssue($actor, $group, $maxUses, $replaces);

        $plain = 'tk_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $token = DB::transaction(function () use ($actor, $group, $maxUses, $ttlHours, $replaces, $label, $plain) {
            $token = EnrollmentToken::create([
                'token_hash' => EnrollmentToken::hashToken($plain),
                'label' => $label,
                'endpoint_group_id' => $replaces?->endpoint_group_id ?? $group?->id,
                'created_by' => $actor->id,
                'replaces_endpoint_id' => $replaces?->id,
                'max_uses' => $maxUses,
                'uses' => 0,
                'expires_at' => now()->addHours($ttlHours ?? config('toolit.agent.enrollment_ttl_hours')),
            ]);

            $this->audit->log('enrollment.token_created', $actor, $token, $replaces?->id, [
                'endpoint_group_id' => $token->endpoint_group_id,
                'max_uses' => $maxUses,
                'expires_at' => $token->expires_at->toIso8601String(),
                'replaces_endpoint_id' => $replaces?->id,
            ]);

            return $token;
        });

        return [$token, $plain];
    }

    /**
     * @param  array{enrollment_token: string, public_key: string, hostname: string, os?: array<string, mixed>, agent_version?: string, machine_fingerprint?: string}  $data
     * @return array{0: AgentInstallation, 1: Endpoint}
     *
     * @throws EnrollmentException|ValidationException
     */
    public function enroll(array $data): array
    {
        try {
            $key = PublicKey::fromBase64Der($data['public_key']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['public_key' => $e->getMessage()]);
        }

        return DB::transaction(function () use ($data, $key) {
            $token = EnrollmentToken::where('token_hash', EnrollmentToken::hashToken($data['enrollment_token']))
                ->lockForUpdate()->first();

            if ($token === null || ! $token->isUsable()) {
                throw EnrollmentException::invalidToken();
            }

            // Consumo atômico e portável: protege também onde lockForUpdate não serializa.
            $consumed = DB::table('enrollment_tokens')
                ->where('id', $token->id)
                ->whereColumn('uses', '<', 'max_uses')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->increment('uses');
            if ($consumed !== 1) {
                throw EnrollmentException::invalidToken();
            }

            if (AgentInstallation::where('key_fingerprint', $key->fingerprint)->exists()) {
                throw ValidationException::withMessages(['public_key' => 'Chave já registrada.']);
            }

            $os = $data['os'] ?? [];
            $attributes = [
                'hostname' => $data['hostname'],
                'os_name' => $os['name'] ?? null,
                'os_version' => $os['version'] ?? null,
                'os_build' => $os['build'] ?? null,
                'os_arch' => $os['arch'] ?? null,
                'agent_version' => $data['agent_version'] ?? null,
                'status' => EndpointStatus::Active,
                'last_seen_at' => now(),
            ];

            if ($token->replaces_endpoint_id !== null) {
                $endpoint = Endpoint::find($token->replaces_endpoint_id) ?? throw EnrollmentException::invalidToken();
                foreach ($endpoint->installations()->where('status', AgentInstallationStatus::Active->value)->get() as $old) {
                    $this->revocation->revoke($old, null, 'Substituída por nova instalação (reativação).');
                }
                $endpoint->update($attributes);
            } else {
                $endpoint = Endpoint::create($attributes + ['endpoint_group_id' => $token->endpoint_group_id]);
            }

            $installation = AgentInstallation::create([
                'endpoint_id' => $endpoint->id,
                'public_key' => $key->base64Der,
                'key_fingerprint' => $key->fingerprint,
                'status' => AgentInstallationStatus::Active,
                'agent_version' => $data['agent_version'] ?? null,
                'machine_fingerprint' => $data['machine_fingerprint'] ?? null,
                'enrolled_at' => now(),
                'last_seen_at' => now(),
            ]);

            $this->audit->log('agent.enrolled', $installation, $installation, $endpoint->id, [
                'installation' => $installation->uuid,
                'enrollment_token_id' => $token->id,
                'replaced_endpoint' => $token->replaces_endpoint_id !== null,
                'hostname' => $endpoint->hostname,
                'key_fingerprint' => $key->fingerprint,
            ]);

            return [$installation, $endpoint];
        });
    }

    private function authorizeIssue(User $actor, ?EndpointGroup $group, int $maxUses, ?Endpoint $replaces): void
    {
        $allowed = $replaces !== null
            ? $this->access->can($actor, Permission::EnrollmentCreate, $replaces)
            : $this->access->canForGroup($actor, Permission::EnrollmentCreate, $group);

        if ($maxUses > 1) {
            $allowed = $allowed && $this->access->canGlobally($actor, Permission::EnrollmentCreateMulti);
        }

        if (! $allowed) {
            throw new AuthorizationException('Sem permissão para emitir token de enrollment neste escopo.');
        }
    }
}
