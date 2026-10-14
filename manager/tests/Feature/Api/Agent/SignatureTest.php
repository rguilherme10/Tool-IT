<?php

namespace Tests\Feature\Api\Agent;

use App\Models\AgentInstallation;
use App\Services\Agents\SignatureVerifier;
use Tests\Support\FakeAgent;
use Tests\TestCase;

class SignatureTest extends TestCase
{
    private const HB = '/api/agent/v1/heartbeat';

    public function test_valid_signed_request_is_accepted(): void
    {
        $this->enrolledAgent()->post(self::HB, ['uptime_seconds' => 10])->assertOk();
    }

    public function test_meta_is_public(): void
    {
        $this->getJson('/api/agent/v1/meta')->assertOk()
            ->assertJsonPath('protocol', 'v1')->assertJsonStructure(['min_agent_version', 'server_time']);
    }

    public function test_unsigned_request_is_rejected(): void
    {
        $this->postJson(self::HB, [])->assertUnauthorized()->assertJsonPath('error.code', 'auth.invalid');
    }

    public function test_each_missing_header_is_rejected(): void
    {
        $agent = $this->enrolledAgent();

        foreach (['HTTP_X_AGENT_ID', 'HTTP_X_TIMESTAMP', 'HTTP_X_NONCE', 'HTTP_X_SIGNATURE'] as $header) {
            $agent->post(self::HB, [], ['drop_headers' => [$header]])
                ->assertUnauthorized()->assertJsonPath('error.code', 'auth.invalid');
        }
    }

    public function test_every_failure_mode_returns_the_same_generic_answer(): void
    {
        $agent = $this->enrolledAgent();
        $other = new FakeAgent($this);

        $cases = [
            'agente desconhecido' => ['agent_id' => '11111111-1111-4111-8111-111111111111'],
            'id malformado' => ['agent_id' => 'nao-e-uuid'],
            'assinatura de outra chave' => ['signature' => $other->sign('POST', self::HB, '', (string) now()->timestamp, 'nonce-nonce-nonce-1', '')],
            'assinatura lixo' => ['signature' => base64_encode('lixo')],
            'assinatura não-base64' => ['signature' => '@@@'],
        ];

        $shapes = [];
        foreach ($cases as $name => $tweak) {
            $r = $agent->post(self::HB, [], $tweak)->assertUnauthorized();
            $shapes[$name] = [$r->json('error.code'), $r->json('error.message'), $r->json('error.details')];
        }

        $this->assertCount(1, array_unique($shapes, SORT_REGULAR), 'não pode haver oráculo: '.json_encode($shapes));
    }

    public function test_tampering_with_any_signed_element_invalidates_the_signature(): void
    {
        $agent = $this->enrolledAgent();
        $body = ['uptime_seconds' => 5];

        $agent->post(self::HB, $body, ['signed_body' => json_encode(['uptime_seconds' => 6])])->assertUnauthorized();
        $agent->post(self::HB, $body, ['signed_path' => '/api/agent/v1/outra'])->assertUnauthorized();
        $agent->post(self::HB, $body, ['signed_method' => 'GET'])->assertUnauthorized();
        $agent->post(self::HB.'?x=2', $body, ['signed_query' => 'x=1'])->assertUnauthorized();
        $agent->post(self::HB, $body, ['timestamp' => now()->timestamp, 'signature' => $agent->sign('POST', self::HB, '', (string) (now()->timestamp + 1), 'nonce-nonce-nonce-2', json_encode($body))])
            ->assertUnauthorized();
    }

    public function test_query_parameter_order_does_not_matter(): void
    {
        $agent = $this->enrolledAgent();

        $agent->post(self::HB.'?b=2&a=1', [], ['signed_query' => 'a=1&b=2'])->assertOk();
    }

    public function test_clock_skew_is_rejected_in_both_directions_and_hints_server_time(): void
    {
        $agent = $this->enrolledAgent();

        foreach ([-121, 121, -3600, 86400] as $offset) {
            $agent->post(self::HB, [], ['timestamp' => now()->timestamp + $offset])
                ->assertUnauthorized()->assertJsonStructure(['error' => ['details' => ['server_time']]]);
        }

        $agent->post(self::HB, [], ['timestamp' => now()->timestamp - 100])->assertOk();
        $agent->post(self::HB, [], ['timestamp' => now()->timestamp + 100])->assertOk();
    }

    public function test_replayed_request_is_rejected_even_with_valid_signature(): void
    {
        $agent = $this->enrolledAgent();
        $tweak = ['nonce' => 'nonce-reutilizado-001'];

        $agent->post(self::HB, [], $tweak)->assertOk();
        $agent->post(self::HB, [], $tweak)->assertUnauthorized()->assertJsonPath('error.code', 'auth.invalid');
    }

    public function test_nonce_can_be_reused_after_it_expires_but_timestamp_window_still_blocks_old_replays(): void
    {
        $agent = $this->enrolledAgent();
        $t0 = now()->timestamp;
        $tweak = ['nonce' => 'nonce-reutilizado-002', 'timestamp' => $t0];

        $agent->post(self::HB, [], $tweak)->assertOk();

        // Passado o TTL do nonce (300 s) o mesmo request já está fora da janela de ±120 s → continua barrado.
        $this->travel(301)->seconds();
        $agent->post(self::HB, [], $tweak)->assertUnauthorized();
    }

    public function test_invalid_nonce_formats_are_rejected(): void
    {
        $agent = $this->enrolledAgent();

        foreach (['curto', str_repeat('a', 65), 'nonce com espaço!!!!', "nonce\nquebra-de-linha"] as $nonce) {
            $agent->post(self::HB, [], ['nonce' => $nonce])->assertUnauthorized();
        }
    }

    public function test_revoked_installation_with_bad_signature_still_looks_like_any_other_failure(): void
    {
        $agent = $this->enrolledAgent();
        AgentInstallation::query()->update(['status' => 'revoked', 'revoked_at' => now()]);

        // Sem provar a posse da chave, não se descobre que a instalação está revogada.
        $agent->post(self::HB, [], ['signature' => base64_encode('x')])
            ->assertUnauthorized()->assertJsonPath('error.code', 'auth.invalid');
    }

    public function test_canonical_string_matches_the_documented_contract_vector(): void
    {
        // Vetor fixo: o Agent C# deve produzir exatamente esta string (docs/api/agent-api-v1.md §1).
        $canonical = app(SignatureVerifier::class)->canonical(
            'post', '/api/agent/v1/heartbeat', 'b=2&a=1', '1760000000', 'AAAAAAAAAAAAAAAA', '{"a":1}',
        );

        $this->assertSame(
            "v1\nPOST\n/api/agent/v1/heartbeat\na=1&b=2\n1760000000\nAAAAAAAAAAAAAAAA\n"
            .'015abd7f5cc57a2dd94b7590f04ad8084273905ee33ec5cebeae62276a97f862',
            $canonical,
        );
    }

    public function test_unknown_routes_and_methods_use_the_agent_error_format(): void
    {
        $this->getJson('/api/agent/v1/nao-existe')->assertNotFound()->assertJsonPath('error.code', 'request.not_found');
        $this->getJson('/api/agent/v1/enroll')->assertStatus(405)->assertJsonPath('error.code', 'request.method_not_allowed');
    }

    public function test_per_installation_rate_limit(): void
    {
        $agent = $this->enrolledAgent();
        config(['toolit.agent.rate_limit_per_minute' => 5]);

        for ($i = 0; $i < 5; $i++) {
            $agent->post(self::HB)->assertOk();
        }

        $agent->post(self::HB)->assertStatus(429)->assertJsonPath('error.code', 'rate.limited');
    }
}
