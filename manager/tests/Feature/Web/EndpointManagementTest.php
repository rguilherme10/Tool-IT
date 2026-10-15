<?php

namespace Tests\Feature\Web;

use App\Enums\AlertRuleType;
use App\Enums\RoleKey;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Services\Users\UserService;
use Tests\TestCase;

class EndpointManagementTest extends TestCase
{
    private EndpointGroup $groupA;

    private EndpointGroup $groupB;

    private EndpointGroup $groupC;

    protected function setUp(): void
    {
        parent::setUp();
        $this->groupA = EndpointGroup::factory()->create(['name' => 'Financeiro']);
        $this->groupB = EndpointGroup::factory()->create(['name' => 'Diretoria']);
        $this->groupC = EndpointGroup::factory()->create(['name' => 'Vendas']);
    }

    private function move(Endpoint $endpoint, ?EndpointGroup $target): array
    {
        return ['/endpoints/'.$endpoint->uuid.'/group', ['group' => $target?->uuid]];
    }

    public function test_operator_moves_a_computer_between_groups_they_both_manage(): void
    {
        $endpoint = $this->activeEndpoint($this->groupA);
        $operator = $this->userWithRole(RoleKey::Operator, $this->groupA);
        $this->userWithRole(RoleKey::Operator, $this->groupB);
        UserService::class;
        app(UserService::class)->assignRole($operator, RoleKey::Operator, $this->groupB);

        [$url, $data] = $this->move($endpoint, $this->groupB);
        $this->actingAs($operator)->post($url, $data)->assertRedirect()->assertSessionHas('status', 'Grupo atualizado.');

        $this->assertSame($this->groupB->id, $endpoint->fresh()->endpoint_group_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'endpoint.group_changed', 'endpoint_id' => $endpoint->id, 'actor_id' => (string) $operator->id]);
    }

    public function test_a_computer_cannot_be_pushed_into_a_scope_the_operator_does_not_manage(): void
    {
        $endpoint = $this->activeEndpoint($this->groupA);
        $operatorA = $this->userWithRole(RoleKey::Operator, $this->groupA);

        [$url, $data] = $this->move($endpoint, $this->groupB);
        $this->actingAs($operatorA)->post($url, $data)->assertForbidden();                         // destino fora do escopo
        [$url, $data] = $this->move($endpoint, null);
        $this->actingAs($operatorA)->post($url, $data)->assertForbidden();                         // "sem grupo" exige escopo global

        $this->assertSame($this->groupA->id, $endpoint->fresh()->endpoint_group_id);
    }

    public function test_computers_of_other_groups_are_invisible_and_viewers_cannot_manage(): void
    {
        $inB = $this->activeEndpoint($this->groupB);
        $inA = $this->activeEndpoint($this->groupA);

        [$url, $data] = $this->move($inB, $this->groupA);
        $this->actingAs($this->userWithRole(RoleKey::Operator, $this->groupA))->post($url, $data)->assertNotFound();
        [$url, $data] = $this->move($inA, $this->groupC);
        $this->actingAs($this->userWithRole(RoleKey::Viewer))->post($url, $data)->assertForbidden();
        $this->actingAs($this->userWithRole(RoleKey::Support, $this->groupA))->post($url, $data)->assertForbidden();
        $this->actingAs($this->userWithRole(RoleKey::Operator, $this->groupA))->post($url, ['group' => fake()->uuid()])->assertSessionHasErrors('group');
    }

    public function test_global_operator_can_ungroup_and_a_noop_move_is_reported(): void
    {
        $endpoint = $this->activeEndpoint($this->groupA);
        $operator = $this->userWithRole(RoleKey::Operator);

        [$url, $data] = $this->move($endpoint, $this->groupA);
        $this->actingAs($operator)->post($url, $data)->assertSessionHas('status', 'O computador já está neste grupo.');

        [$url, $data] = $this->move($endpoint, null);
        $this->actingAs($operator)->post($url, $data)->assertRedirect();
        $this->assertNull($endpoint->fresh()->endpoint_group_id);
    }

    public function test_disabling_refuses_the_agent_and_enabling_restores_it(): void
    {
        $agent = $this->enrolledAgent($this->groupA);
        $endpoint = Endpoint::firstOrFail();
        $operator = $this->userWithRole(RoleKey::Operator, $this->groupA);
        $agent->post('/api/agent/v1/heartbeat')->assertOk();

        $this->actingAs($operator)->post('/endpoints/'.$endpoint->uuid.'/status', ['status' => 'disabled'])->assertRedirect();
        $this->assertSame('disabled', $endpoint->fresh()->status->value);
        $agent->post('/api/agent/v1/heartbeat')->assertForbidden()->assertJsonPath('error.code', 'endpoint.disabled');
        $this->assertDatabaseHas('audit_logs', ['action' => 'endpoint.disabled', 'endpoint_id' => $endpoint->id]);

        $this->actingAs($operator)->post('/endpoints/'.$endpoint->uuid.'/status', ['status' => 'active'])->assertRedirect();
        $agent->post('/api/agent/v1/heartbeat')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'endpoint.enabled', 'endpoint_id' => $endpoint->id]);
    }

    public function test_disabling_closes_the_computers_alerts_on_next_evaluation(): void
    {
        $this->freezeTime();
        AlertRule::factory()->create(['type' => AlertRuleType::AgentOffline, 'threshold' => 300, 'min_duration_seconds' => 0]);
        $endpoint = $this->activeEndpoint($this->groupA, ['last_seen_at' => now()->subHour()]);
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->assertSame(1, Alert::active()->count());

        $this->actingAs($this->userWithRole(RoleKey::Operator, $this->groupA))->post('/endpoints/'.$endpoint->uuid.'/status', ['status' => 'disabled']);
        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->assertSame(0, Alert::active()->count());
        $this->assertSame('endpoint_inactive', Alert::firstOrFail()->resolved_reason);
    }

    public function test_status_validation_and_permission(): void
    {
        $endpoint = $this->activeEndpoint($this->groupA);
        $operator = $this->userWithRole(RoleKey::Operator, $this->groupA);

        $this->actingAs($operator)->post('/endpoints/'.$endpoint->uuid.'/status', ['status' => 'pending'])->assertSessionHasErrors('status');
        $this->actingAs($operator)->post('/endpoints/'.$endpoint->uuid.'/status', [])->assertSessionHasErrors('status');
        $this->actingAs($this->userWithRole(RoleKey::Support, $this->groupA))->post('/endpoints/'.$endpoint->uuid.'/status', ['status' => 'disabled'])->assertForbidden();
        $this->actingAs($operator)->post('/endpoints/'.$endpoint->uuid.'/status', ['status' => 'active'])->assertSessionHas('status', 'Nada a alterar.');
        $this->assertSame('active', $endpoint->fresh()->status->value);
    }

    public function test_management_panel_is_only_rendered_for_those_who_can_manage(): void
    {
        $endpoint = $this->activeEndpoint($this->groupA);
        $url = '/endpoints/'.$endpoint->uuid;

        $this->actingAs($this->userWithRole(RoleKey::Operator, $this->groupA))->get($url)->assertOk()->assertSee('Gerenciar computador')->assertSee('Desativar computador');
        $this->actingAs($this->userWithRole(RoleKey::Support, $this->groupA))->get($url)->assertOk()->assertDontSee('Gerenciar computador');
        $this->actingAs($this->userWithRole(RoleKey::Viewer))->get($url)->assertOk()->assertDontSee('Gerenciar computador');
    }

    public function test_group_selector_only_offers_groups_the_operator_manages(): void
    {
        $endpoint = $this->activeEndpoint($this->groupA);
        $operator = $this->userWithRole(RoleKey::Operator, $this->groupA);

        $html = $this->actingAs($operator)->get('/endpoints/'.$endpoint->uuid)->getContent();

        $this->assertStringContainsString('value="'.$this->groupA->uuid.'"', $html);
        $this->assertStringNotContainsString('value="'.$this->groupB->uuid.'"', $html);
        $this->assertStringNotContainsString('(sem grupo)', $html, 'sem escopo global não pode desagrupar');
    }
}
