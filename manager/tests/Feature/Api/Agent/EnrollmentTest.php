<?php

namespace Tests\Feature\Api\Agent;

use App\Enums\RoleKey;
use App\Models\AgentInstallation;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\EnrollmentToken;
use App\Services\Agents\EnrollmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgent;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    /** @return array{0: string, 1: EnrollmentToken} */
    private function token(?EndpointGroup $group = null, int $maxUses = 1, ?Endpoint $replaces = null, ?RoleKey $as = null): array
    {
        $admin = $this->userWithRole($as ?? RoleKey::Admin);
        [$token, $plain] = app(EnrollmentService::class)->issueToken($admin, $group, $maxUses, replaces: $replaces);

        return [$plain, $token];
    }

    public function test_enrolls_with_valid_token_and_creates_endpoint_and_installation(): void
    {
        $group = EndpointGroup::factory()->create();
        [$plain] = $this->token($group);
        $agent = new FakeAgent($this);

        $response = $agent->enroll($plain);

        $response->assertCreated()->assertJsonStructure([
            'agent_id', 'endpoint_id', 'server_time',
            'config' => ['heartbeat_seconds', 'metrics_seconds', 'inventory_hours', 'max_payload_bytes'],
        ]);

        $endpoint = Endpoint::where('uuid', $response->json('endpoint_id'))->firstOrFail();
        $this->assertSame('PC-TESTE-01', $endpoint->hostname);
        $this->assertSame($group->id, $endpoint->endpoint_group_id);
        $this->assertSame('active', $endpoint->status->value);

        $installation = AgentInstallation::where('uuid', $response->json('agent_id'))->firstOrFail();
        $this->assertTrue($installation->isActive());
        $this->assertSame($endpoint->id, $installation->endpoint_id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.enrolled', 'endpoint_id' => $endpoint->id]);
        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_token_is_stored_only_as_hash_and_never_logged(): void
    {
        [$plain, $token] = $this->token();

        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertStringNotContainsString($plain, json_encode(DB::table('enrollment_tokens')->get()));
        $this->assertStringNotContainsString($plain, json_encode(DB::table('audit_logs')->get()));
    }

    public function test_token_cannot_be_reused(): void
    {
        [$plain] = $this->token();

        (new FakeAgent($this))->enroll($plain)->assertCreated();
        (new FakeAgent($this))->enroll($plain)->assertForbidden()->assertJsonPath('error.code', 'enrollment.invalid');

        $this->assertSame(1, AgentInstallation::count());
        $this->assertSame(1, Endpoint::count());
    }

    public function test_multi_use_token_respects_its_limit(): void
    {
        [$plain] = $this->token(maxUses: 2);

        (new FakeAgent($this))->enroll($plain)->assertCreated();
        (new FakeAgent($this))->enroll($plain)->assertCreated();
        (new FakeAgent($this))->enroll($plain)->assertForbidden();
    }

    public function test_expired_revoked_and_unknown_tokens_get_the_same_generic_answer(): void
    {
        [$expired, $t1] = $this->token();
        $t1->update(['expires_at' => now()->subMinute()]);
        [$revoked, $t2] = $this->token();
        $t2->update(['revoked_at' => now()]);

        $bodies = [];
        foreach ([$expired, $revoked, 'tk_inexistente'] as $plain) {
            $r = (new FakeAgent($this))->enroll($plain)->assertForbidden();
            $bodies[] = [$r->json('error.code'), $r->json('error.message')];
        }

        $this->assertCount(1, array_unique($bodies, SORT_REGULAR), 'respostas devem ser indistinguíveis');
        $this->assertSame(0, Endpoint::count());
    }

    public function test_rejects_invalid_or_non_p256_public_keys(): void
    {
        [$plain] = $this->token();
        $rsa = openssl_pkey_new(['config' => FakeAgent::opensslConfig(), 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $rsaDer = base64_encode(base64_decode(trim(preg_replace('/-----[A-Z ]+-----/', '', openssl_pkey_get_details($rsa)['key']))));

        foreach (['isso-nao-e-base64!!', base64_encode('lixo'), $rsaDer] as $badKey) {
            (new FakeAgent($this))->enroll($plain, ['public_key' => $badKey])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation.failed')
                ->assertJsonStructure(['error' => ['details' => ['fields' => ['public_key']]]]);
        }

        // Falhas de validação não consomem o token.
        (new FakeAgent($this))->enroll($plain)->assertCreated();
    }

    public function test_rejects_reusing_a_public_key(): void
    {
        $agent = new FakeAgent($this);
        [$a] = $this->token();
        [$b] = $this->token();

        $agent->enroll($a)->assertCreated();
        $agent->enroll($b)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    }

    public function test_validates_payload(): void
    {
        [$plain] = $this->token();
        $agent = new FakeAgent($this);

        $agent->enroll($plain, ['hostname' => "PC\x00evil"])->assertUnprocessable();
        $agent->enroll($plain, ['hostname' => str_repeat('a', 256)])->assertUnprocessable();
        $agent->enroll($plain, ['agent_version' => 'abc'])->assertUnprocessable();
        $agent->enroll($plain, ['os' => ['arch' => 'sparc']])->assertUnprocessable();
        $this->postJson('/api/agent/v1/enroll', [])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    }

    public function test_replacement_token_reuses_endpoint_and_revokes_previous_installation(): void
    {
        [$first] = $this->token();
        $oldAgent = new FakeAgent($this);
        $oldAgent->enroll($first)->assertCreated();
        $endpoint = Endpoint::firstOrFail();

        [$second] = $this->token(replaces: $endpoint);
        $newAgent = new FakeAgent($this);
        $newAgent->enroll($second, ['hostname' => 'PC-NOVO'])->assertCreated()
            ->assertJsonPath('endpoint_id', $endpoint->uuid);

        $this->assertSame(1, Endpoint::count());
        $this->assertSame('PC-NOVO', $endpoint->fresh()->hostname);
        $this->assertSame(1, AgentInstallation::where('status', 'active')->count());

        $oldAgent->post('/api/agent/v1/heartbeat')->assertForbidden()->assertJsonPath('error.code', 'agent.revoked');
        $newAgent->post('/api/agent/v1/heartbeat')->assertOk();
    }

    public function test_only_one_active_installation_per_endpoint_at_database_level(): void
    {
        [$plain] = $this->token();
        (new FakeAgent($this))->enroll($plain)->assertCreated();
        $installation = AgentInstallation::firstOrFail();

        $this->expectException(QueryException::class);
        AgentInstallation::create([
            'endpoint_id' => $installation->endpoint_id,
            'public_key' => 'x', 'key_fingerprint' => str_repeat('a', 64),
            'status' => 'active', 'enrolled_at' => now(),
        ]);
    }

    public function test_issuing_tokens_is_authorized_by_scope(): void
    {
        $groupA = EndpointGroup::factory()->create();
        $groupB = EndpointGroup::factory()->create();
        $service = app(EnrollmentService::class);

        $operatorA = $this->userWithRole(RoleKey::Operator, $groupA);
        $service->issueToken($operatorA, $groupA); // ok
        $this->assertDatabaseHas('audit_logs', ['action' => 'enrollment.token_created']);

        foreach ([
            fn () => $service->issueToken($operatorA, $groupB),                       // outro grupo
            fn () => $service->issueToken($operatorA, null),                          // sem grupo exige global
            fn () => $service->issueToken($operatorA, $groupA, maxUses: 5),           // multiuso exige admin global
            fn () => $service->issueToken($this->userWithRole(RoleKey::Viewer), null), // sem permissão
            fn () => $service->issueToken($this->userWithRole(RoleKey::Auditor), null),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('deveria negar');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $service->issueToken($this->userWithRole(RoleKey::Admin), null, maxUses: 5); // admin global pode
    }

    public function test_enrollment_endpoint_is_rate_limited(): void
    {
        $agent = new FakeAgent($this);
        for ($i = 0; $i < 10; $i++) {
            $agent->enroll('tk_x')->assertForbidden();
        }

        $agent->enroll('tk_x')->assertStatus(429)->assertJsonPath('error.code', 'rate.limited');
    }
}
