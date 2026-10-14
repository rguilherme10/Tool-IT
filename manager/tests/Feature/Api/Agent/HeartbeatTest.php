<?php

namespace Tests\Feature\Api\Agent;

use App\Models\AgentInstallation;
use App\Models\Endpoint;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    private const HB = '/api/agent/v1/heartbeat';

    public function test_heartbeat_updates_endpoint_state(): void
    {
        $agent = $this->enrolledAgent();
        $this->travel(5)->minutes();

        $agent->post(self::HB, [
            'uptime_seconds' => 3600,
            'service_state' => 'running',
            'agent_version' => '0.2.0',
            'current_user' => 'EMPRESA\\maria',
            'ip_addresses' => ['10.0.0.5', 'fe80::1'],
            'pending_events' => 0,
        ])->assertOk()->assertJson([
            'config_version' => 1,
            'tasks_available' => false,
            'cancel_task_ids' => [],
            'update_available' => null,
        ])->assertJsonStructure(['server_time']);

        $endpoint = Endpoint::firstOrFail();
        $this->assertSame('0.2.0', $endpoint->agent_version);
        $this->assertSame('EMPRESA\\maria', $endpoint->current_user);
        $this->assertSame(['10.0.0.5', 'fe80::1'], $endpoint->ip_addresses);
        $this->assertTrue($endpoint->last_seen_at->isSameMinute(now()));
        $this->assertTrue(AgentInstallation::firstOrFail()->last_seen_at->isSameMinute(now()));
    }

    public function test_empty_heartbeat_is_valid_and_keeps_previous_data(): void
    {
        $agent = $this->enrolledAgent();
        $agent->post(self::HB, ['current_user' => 'A\\b'])->assertOk();

        $agent->post(self::HB)->assertOk();

        $this->assertSame('A\\b', Endpoint::firstOrFail()->current_user);
    }

    public function test_heartbeat_validates_payload(): void
    {
        $agent = $this->enrolledAgent();

        foreach ([
            ['ip_addresses' => ['nao-e-ip']],
            ['ip_addresses' => array_fill(0, 33, '10.0.0.1')],
            ['uptime_seconds' => -1],
            ['service_state' => 'hackeado'],
            ['agent_version' => 'x'],
            ['current_user' => "a\x00b"],
        ] as $bad) {
            $agent->post(self::HB, $bad)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        }
    }

    public function test_heartbeat_cannot_change_another_endpoint(): void
    {
        $a = $this->enrolledAgent();
        $b = $this->enrolledAgent();

        $a->post(self::HB, ['current_user' => 'SOMENTE-A'])->assertOk();

        $endpointB = AgentInstallation::where('uuid', $b->agentId)->firstOrFail()->endpoint;
        $this->assertNull($endpointB->current_user);
    }

    public function test_disabled_endpoint_is_refused(): void
    {
        $agent = $this->enrolledAgent();
        Endpoint::query()->update(['status' => 'disabled']);

        $agent->post(self::HB)->assertForbidden()->assertJsonPath('error.code', 'endpoint.disabled');
    }
}
