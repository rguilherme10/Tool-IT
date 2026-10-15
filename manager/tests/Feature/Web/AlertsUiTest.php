<?php

namespace Tests\Feature\Web;

use App\Enums\AlertStatus;
use App\Enums\RoleKey;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\EndpointGroup;
use App\Models\User;
use Tests\TestCase;

class AlertsUiTest extends TestCase
{
    private EndpointGroup $groupA;

    private EndpointGroup $groupB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->groupA = EndpointGroup::factory()->create(['name' => 'Financeiro']);
        $this->groupB = EndpointGroup::factory()->create(['name' => 'Diretoria']);
    }

    private function alertFor(string $hostname, ?EndpointGroup $group, ?AlertRule $rule = null, AlertStatus $status = AlertStatus::Open): Alert
    {
        return Alert::factory()->forEndpoint($this->activeEndpoint($group, ['hostname' => $hostname]), $rule, status: $status);
    }

    public function test_list_shows_only_alerts_of_visible_endpoints(): void
    {
        $this->alertFor('PC-A-001', $this->groupA);
        $this->alertFor('PC-B-001', $this->groupB);
        $operatorA = $this->userWithRole(RoleKey::Operator, $this->groupA);

        $this->actingAs($operatorA)->get('/alerts')->assertOk()->assertSee('PC-A-001')->assertDontSee('PC-B-001');
        $this->actingAs($this->userWithRole(RoleKey::Viewer))->get('/alerts')->assertSee('PC-A-001')->assertSee('PC-B-001');
    }

    public function test_user_without_alert_permission_gets_403_and_no_menu_entry(): void
    {
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get('/alerts')->assertForbidden();
    }

    public function test_default_filter_hides_resolved_and_status_filter_shows_them(): void
    {
        $this->alertFor('PC-ATIVO', null);
        $this->alertFor('PC-RESOLVIDO', null, status: AlertStatus::Resolved);
        $viewer = $this->userWithRole(RoleKey::Viewer);

        $this->actingAs($viewer)->get('/alerts')->assertSee('PC-ATIVO')->assertDontSee('PC-RESOLVIDO');
        $this->actingAs($viewer)->get('/alerts?status=resolved')->assertSee('PC-RESOLVIDO')->assertDontSee('PC-ATIVO');
        $this->actingAs($viewer)->get('/alerts?status=all')->assertSee('PC-ATIVO')->assertSee('PC-RESOLVIDO');
        $this->actingAs($viewer)->get('/alerts?status=invalido')->assertSessionHasErrors('status');
    }

    public function test_severity_group_and_hostname_filters(): void
    {
        $critical = AlertRule::factory()->create(['severity' => 'critical']);
        $this->alertFor('PC-CRIT', $this->groupA, $critical);
        $this->alertFor('PC-WARN', $this->groupB);
        $viewer = $this->userWithRole(RoleKey::Viewer);

        $this->actingAs($viewer)->get('/alerts?severity=critical')->assertSee('PC-CRIT')->assertDontSee('PC-WARN');
        $this->actingAs($viewer)->get('/alerts?group='.$this->groupB->uuid)->assertSee('PC-WARN')->assertDontSee('PC-CRIT');
        $this->actingAs($viewer)->get('/alerts?q=crit')->assertSee('PC-CRIT')->assertDontSee('PC-WARN');
        $this->actingAs($viewer)->get('/alerts?q=%25')->assertDontSee('PC-CRIT');   // curinga literal
    }

    public function test_most_severe_alerts_come_first(): void
    {
        $this->alertFor('PC-INFO', null, AlertRule::factory()->create(['severity' => 'info']));
        $this->alertFor('PC-CRITICO', null, AlertRule::factory()->create(['severity' => 'critical']));
        $this->alertFor('PC-ATENCAO', null, AlertRule::factory()->create(['severity' => 'warning']));

        $this->actingAs($this->userWithRole(RoleKey::Viewer))->get('/alerts')->assertSeeInOrder(['PC-CRITICO', 'PC-ATENCAO', 'PC-INFO']);
    }

    public function test_acknowledge_requires_scope_and_permission_and_is_audited(): void
    {
        $alert = $this->alertFor('PC-A-001', $this->groupA);
        $url = '/alerts/'.$alert->uuid.'/acknowledge';

        $this->actingAs($this->userWithRole(RoleKey::Viewer))->post($url)->assertForbidden();                 // vê, mas não gerencia
        $this->actingAs($this->userWithRole(RoleKey::Auditor))->post($url)->assertForbidden();
        $this->actingAs($this->userWithRole(RoleKey::Operator, $this->groupB))->post($url)->assertNotFound();  // outro grupo nem enxerga
        $this->assertSame(AlertStatus::Open, $alert->fresh()->status);

        $operator = $this->userWithRole(RoleKey::Operator, $this->groupA);
        $this->actingAs($operator)->from('/alerts')->post($url)->assertRedirect('/alerts')->assertSessionHas('status', 'Alerta reconhecido.');

        $fresh = $alert->fresh();
        $this->assertSame(AlertStatus::Acknowledged, $fresh->status);
        $this->assertSame($operator->id, $fresh->acknowledged_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.acknowledged', 'actor_id' => (string) $operator->id, 'endpoint_id' => $alert->endpoint_id]);

        // Segunda vez: sem efeito.
        $this->actingAs($operator)->from('/alerts')->post($url)->assertSessionHas('status', 'Este alerta já foi reconhecido ou resolvido.');
    }

    public function test_unknown_alert_is_404_and_guests_are_sent_to_login(): void
    {
        $this->actingAs($this->userWithRole(RoleKey::Operator))->post('/alerts/'.fake()->uuid().'/acknowledge')->assertNotFound();
        $this->actingAs($this->userWithRole(RoleKey::Operator))->post('/alerts/1/acknowledge')->assertNotFound();

        auth()->logout();
        $this->post('/alerts/'.fake()->uuid().'/acknowledge')->assertRedirect('/login');
        $this->get('/alerts')->assertRedirect('/login');
    }

    public function test_acknowledge_button_only_appears_for_those_who_can_manage(): void
    {
        $this->alertFor('PC-A-001', $this->groupA);

        $this->actingAs($this->userWithRole(RoleKey::Operator, $this->groupA))->get('/alerts')->assertSee('>Reconhecer</button>', false);
        $this->actingAs($this->userWithRole(RoleKey::Viewer))->get('/alerts')->assertOk()->assertDontSee('>Reconhecer</button>', false);
    }

    public function test_alert_text_from_the_endpoint_is_escaped(): void
    {
        $endpoint = $this->activeEndpoint(null, ['hostname' => '<script>alert(1)</script>']);
        Alert::factory()->forEndpoint($endpoint);

        $html = $this->actingAs($this->userWithRole(RoleKey::Viewer))->get('/alerts')->getContent();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_dashboard_counts_active_alerts_by_severity_within_scope(): void
    {
        $this->alertFor('A', $this->groupA, AlertRule::factory()->create(['severity' => 'critical']));
        $this->alertFor('B', $this->groupA, AlertRule::factory()->create(['severity' => 'warning']));
        $this->alertFor('C', $this->groupB, AlertRule::factory()->create(['severity' => 'critical']));
        $this->alertFor('D', $this->groupA, AlertRule::factory()->create(['severity' => 'critical']), AlertStatus::Resolved);

        $scoped = $this->actingAs($this->userWithRole(RoleKey::Viewer, $this->groupA))->get('/');
        $scoped->assertOk()->assertViewHas('alerts', ['critical' => 1, 'warning' => 1, 'info' => 0]);

        $this->actingAs($this->userWithRole(RoleKey::Viewer))->get('/')->assertViewHas('alerts', ['critical' => 2, 'warning' => 1, 'info' => 0]);
    }

    public function test_endpoint_list_shows_alert_counts_and_filters(): void
    {
        $withAlert = $this->activeEndpoint(null, ['hostname' => 'PC-COM-ALERTA']);
        $this->activeEndpoint(null, ['hostname' => 'PC-LIMPO']);
        Alert::factory()->forEndpoint($withAlert);
        $viewer = $this->userWithRole(RoleKey::Viewer);

        $this->actingAs($viewer)->get('/endpoints')->assertSee('PC-COM-ALERTA')->assertSee('PC-LIMPO');
        $this->actingAs($viewer)->get('/endpoints?alerts=1')->assertSee('PC-COM-ALERTA')->assertDontSee('PC-LIMPO');
    }

    public function test_endpoint_alerts_tab_is_scoped_and_lists_active_then_recent_resolved(): void
    {
        $endpoint = $this->activeEndpoint($this->groupA);
        Alert::factory()->forEndpoint($endpoint, AlertRule::factory()->create(['name' => 'Regra Ativa']));
        Alert::factory()->forEndpoint($endpoint, AlertRule::factory()->create(['name' => 'Regra Antiga']), status: AlertStatus::Resolved);

        $url = '/endpoints/'.$endpoint->uuid.'?tab=alerts';
        $this->actingAs($this->userWithRole(RoleKey::Operator, $this->groupA))->get($url)->assertOk()
            ->assertSeeInOrder(['Regra Ativa', 'Regra Antiga']);
        $this->actingAs($this->userWithRole(RoleKey::Operator, $this->groupB))->get($url)->assertNotFound();
    }
}
