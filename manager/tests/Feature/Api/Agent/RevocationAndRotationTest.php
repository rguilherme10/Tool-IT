<?php

namespace Tests\Feature\Api\Agent;

use App\Enums\RoleKey;
use App\Models\AgentInstallation;
use App\Models\Endpoint;
use App\Services\Agents\RevocationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgent;
use Tests\TestCase;

class RevocationAndRotationTest extends TestCase
{
    private const HB = '/api/agent/v1/heartbeat';

    private const ROTATE = '/api/agent/v1/keys/rotate';

    public function test_revoked_agent_is_cut_off_immediately_and_cannot_operate(): void
    {
        $agent = $this->enrolledAgent();
        $agent->post(self::HB)->assertOk();
        $installation = AgentInstallation::firstOrFail();
        $before = Endpoint::firstOrFail()->last_seen_at;

        $this->travel(10)->minutes();
        app(RevocationService::class)->revoke($installation, $this->userWithRole(RoleKey::Admin), 'equipamento perdido');

        $agent->post(self::HB, ['current_user' => 'INVASOR'])
            ->assertForbidden()->assertJsonPath('error.code', 'agent.revoked');
        $agent->post(self::ROTATE, ['new_public_key' => (new FakeAgent($this))->publicKeyB64])
            ->assertForbidden()->assertJsonPath('error.code', 'agent.revoked');

        $endpoint = Endpoint::firstOrFail();
        $this->assertNull($endpoint->current_user, 'revogado não altera estado');
        $this->assertTrue($before->equalTo($endpoint->last_seen_at));
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.revoked', 'endpoint_id' => $endpoint->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.revoked_access_attempt']);
        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_revocation_is_idempotent_and_records_who_and_why(): void
    {
        $this->enrolledAgent();
        $installation = AgentInstallation::firstOrFail();
        $admin = $this->userWithRole(RoleKey::Admin);
        $service = app(RevocationService::class);

        $service->revoke($installation, $admin, 'motivo A');
        $service->revoke($installation, $admin, 'motivo B');

        $installation->refresh();
        $this->assertSame('revoked', $installation->status->value);
        $this->assertSame($admin->id, $installation->revoked_by);
        $this->assertSame('motivo A', $installation->revoke_reason);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'agent.revoked')->count());
    }

    public function test_key_rotation_switches_to_the_new_key_and_keeps_old_one_during_grace(): void
    {
        $agent = $this->enrolledAgent();
        $oldSign = fn () => $agent->post(self::HB);
        $oldKeyAgent = clone $agent; // mantém a chave antiga (mesma referência privada)

        $newPublic = $agent->publicKeyB64;
        $newKey = new FakeAgent($this);
        $newKey->agentId = $agent->agentId;

        $agent->post(self::ROTATE, ['new_public_key' => $newKey->publicKeyB64])
            ->assertOk()->assertJson(['rotated' => true])->assertJsonStructure(['previous_key_valid_until']);

        $this->assertNotSame($newPublic, AgentInstallation::firstOrFail()->public_key);
        $newKey->post(self::HB)->assertOk();            // chave nova funciona
        $oldKeyAgent->post(self::HB)->assertOk();       // antiga ainda vale na janela de transição
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.key_rotated']);

        $this->travel(301)->seconds();                  // fim da janela
        $oldKeyAgent->post(self::HB)->assertUnauthorized();
        $newKey->post(self::HB)->assertOk();
        $this->assertNotNull($oldSign);
    }

    public function test_rotation_rejects_invalid_duplicate_or_current_keys(): void
    {
        $agent = $this->enrolledAgent();
        $other = $this->enrolledAgent();

        $agent->post(self::ROTATE, ['new_public_key' => 'lixo'])->assertUnprocessable();
        $agent->post(self::ROTATE, [])->assertUnprocessable();
        $agent->post(self::ROTATE, ['new_public_key' => $agent->publicKeyB64])->assertUnprocessable();   // a mesma
        $agent->post(self::ROTATE, ['new_public_key' => $other->publicKeyB64])->assertUnprocessable();   // de outra instalação
        $agent->post(self::HB)->assertOk(); // continua operando com a chave original
    }

    public function test_rotation_cannot_be_requested_by_unauthenticated_caller(): void
    {
        $agent = $this->enrolledAgent();
        $attacker = new FakeAgent($this);
        $attacker->agentId = $agent->agentId;

        $attacker->post(self::ROTATE, ['new_public_key' => $attacker->publicKeyB64])->assertUnauthorized();

        $agent->post(self::HB)->assertOk();
    }
}
