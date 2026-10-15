<?php

namespace Tests\Feature\Web;

use App\Enums\AlertRuleType;
use App\Enums\RoleKey;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\EndpointGroup;
use App\Models\User;
use Database\Seeders\AlertRulesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlertRulesUiTest extends TestCase
{
    private function admin(): User
    {
        return $this->withTwoFactor($this->userWithRole(RoleKey::Admin));
    }

    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'cpu_usage', 'name' => 'CPU crítica', 'severity' => 'critical', 'threshold' => 95, 'clear_threshold' => 85,
            'min_duration_minutes' => 10, 'clear_duration_minutes' => 3, 'repeat_interval_minutes' => 30, 'enabled' => '1',
        ];
    }

    public function test_only_global_settings_managers_can_see_or_change_rules(): void
    {
        $rule = AlertRule::factory()->create();

        foreach ([RoleKey::Operator, RoleKey::Support, RoleKey::Auditor, RoleKey::Viewer] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->get('/alert-rules')->assertForbidden();
            $this->actingAs($user)->get('/alert-rules/create')->assertForbidden();
            $this->actingAs($user)->post('/alert-rules', $this->valid())->assertForbidden();
            $this->actingAs($user)->get('/alert-rules/'.$rule->uuid.'/edit')->assertForbidden();
            $this->actingAs($user)->put('/alert-rules/'.$rule->uuid, $this->valid())->assertForbidden();
            $this->actingAs($user)->delete('/alert-rules/'.$rule->uuid, ['confirm' => 1])->assertForbidden();
        }

        // Administrador limitado a um grupo também não (permissão de sistema exige escopo global).
        $scopedAdmin = $this->withTwoFactor($this->userWithRole(RoleKey::Admin, EndpointGroup::factory()->create()));
        $this->actingAs($scopedAdmin)->get('/alert-rules')->assertForbidden();

        $this->assertSame(1, AlertRule::count());
    }

    public function test_admin_sees_the_rules_with_active_alert_counts(): void
    {
        $rule = AlertRule::factory()->create(['name' => 'Minha regra']);
        Alert::factory()->forEndpoint($this->activeEndpoint(), $rule);

        $this->actingAs($this->admin())->get('/alert-rules')->assertOk()->assertSee('Minha regra')->assertSee('CPU');
    }

    public function test_admin_creates_a_rule_with_unit_conversion_and_audit(): void
    {
        $group = EndpointGroup::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/alert-rules', $this->valid(['group' => $group->uuid]))->assertRedirect('/alert-rules');

        $rule = AlertRule::firstOrFail();
        $this->assertSame(AlertRuleType::CpuUsage, $rule->type);
        $this->assertSame(95.0, $rule->threshold);
        $this->assertSame(85.0, $rule->clear_threshold);
        $this->assertSame(600, $rule->min_duration_seconds);
        $this->assertSame(180, $rule->clear_duration_seconds);
        $this->assertSame(1800, $rule->repeat_interval_seconds);
        $this->assertSame($group->id, $rule->endpoint_group_id);
        $this->assertSame($admin->id, (int) $rule->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert_rule.created', 'actor_id' => (string) $admin->id]);
    }

    public function test_validation_rejects_inconsistent_limits(): void
    {
        $admin = $this->admin();
        $cases = [
            'histerese acima do limite' => $this->valid(['threshold' => 90, 'clear_threshold' => 95]),
            'histerese igual ao limite' => $this->valid(['threshold' => 90, 'clear_threshold' => 90]),
            'limite acima de 100 %' => $this->valid(['threshold' => 150]),
            'limite zero' => $this->valid(['threshold' => 0]),
            'sem nome' => $this->valid(['name' => '']),
            'tipo inexistente' => $this->valid(['type' => 'rootkit']),
            'gravidade inválida' => $this->valid(['severity' => 'apocalipse']),
            'grupo inexistente' => $this->valid(['group' => fake()->uuid()]),
            'duração negativa' => $this->valid(['min_duration_minutes' => -1]),
            'duração gigante' => $this->valid(['min_duration_minutes' => 99999]),
            'offline curto demais' => $this->valid(['type' => 'agent_offline', 'threshold' => 10, 'clear_threshold' => 5]),
            'versão ausente' => ['type' => 'agent_outdated', 'name' => 'x', 'severity' => 'info'],
            'versão inválida' => ['type' => 'agent_outdated', 'name' => 'x', 'severity' => 'info', 'min_version' => '1.x; DROP'],
            'nome com controle' => $this->valid(['name' => "a\x00b"]),
        ];

        foreach ($cases as $name => $payload) {
            $this->actingAs($admin)->post('/alert-rules', $payload)->assertSessionHasErrors();
            $this->assertSame(0, AlertRule::count(), $name);
        }
    }

    public function test_offline_and_outdated_rules_have_their_own_shape(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/alert-rules', [
            'type' => 'agent_offline', 'name' => 'Sem contato', 'severity' => 'critical', 'threshold' => 600, 'clear_threshold' => 120,
            'repeat_interval_minutes' => 60, 'enabled' => '1',
        ])->assertRedirect('/alert-rules');
        $this->actingAs($admin)->post('/alert-rules', [
            'type' => 'agent_outdated', 'name' => 'Versão', 'severity' => 'info', 'min_version' => '0.3.0', 'enabled' => '1',
        ])->assertRedirect('/alert-rules');

        $offline = AlertRule::where('type', 'agent_offline')->firstOrFail();
        $this->assertSame(600.0, $offline->threshold);
        $this->assertSame(0, $offline->min_duration_seconds);
        $outdated = AlertRule::where('type', 'agent_outdated')->firstOrFail();
        $this->assertSame(['min_version' => '0.3.0'], $outdated->params);
        $this->assertSame(0.0, $outdated->threshold);
    }

    public function test_update_keeps_the_type_and_records_before_and_after(): void
    {
        $rule = AlertRule::factory()->create(['name' => 'Antes', 'threshold' => 90, 'clear_threshold' => 80]);
        $admin = $this->admin();

        $this->actingAs($admin)->put('/alert-rules/'.$rule->uuid, $this->valid(['type' => 'disk_usage', 'name' => 'Depois']))->assertRedirect('/alert-rules');

        $fresh = $rule->fresh();
        $this->assertSame('Depois', $fresh->name);
        $this->assertSame(AlertRuleType::CpuUsage, $fresh->type, 'trocar o tipo não é permitido na edição');
        $log = DB::table('audit_logs')->where('action', 'alert_rule.updated')->first();
        $this->assertStringContainsString('Antes', $log->metadata);
        $this->assertStringContainsString('Depois', $log->metadata);
    }

    public function test_disabled_checkbox_turns_the_rule_off(): void
    {
        $rule = AlertRule::factory()->create();
        $payload = $this->valid();
        unset($payload['enabled']);

        $this->actingAs($this->admin())->put('/alert-rules/'.$rule->uuid, $payload);

        $this->assertFalse($rule->fresh()->enabled);
    }

    public function test_delete_requires_confirmation_and_closes_the_alerts_on_next_evaluation(): void
    {
        $rule = AlertRule::factory()->create();
        $alert = Alert::factory()->forEndpoint($this->activeEndpoint(), $rule);
        $admin = $this->admin();

        $this->actingAs($admin)->delete('/alert-rules/'.$rule->uuid)->assertSessionHasErrors('confirm');
        $this->assertSame(1, AlertRule::count());

        $this->actingAs($admin)->delete('/alert-rules/'.$rule->uuid, ['confirm' => 1])->assertRedirect('/alert-rules');
        $this->assertSame(0, AlertRule::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert_rule.deleted']);
        $this->assertNull($alert->fresh()->alert_rule_id, 'o alerta sobrevive à regra (histórico)');

        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->assertSame('rule_removed', $alert->fresh()->resolved_reason);
    }

    public function test_rule_ids_are_uuids_not_enumerable(): void
    {
        $rule = AlertRule::factory()->create();

        $this->actingAs($this->admin())->get('/alert-rules/'.$rule->id.'/edit')->assertNotFound();
        $this->actingAs($this->admin())->get('/alert-rules/'.$rule->uuid.'/edit')->assertOk();
    }

    public function test_default_rules_seed_is_idempotent_and_never_recreates_deleted_rules(): void
    {
        $this->seed(AlertRulesSeeder::class);
        $this->assertSame(5, AlertRule::count());

        AlertRule::where('type', 'cpu_usage')->delete();
        $this->seed(AlertRulesSeeder::class);

        $this->assertSame(4, AlertRule::count(), 'o administrador apagou: não recria');
        $this->assertTrue(AlertRule::where('type', 'disk_usage')->firstOrFail()->severity->value === 'critical');
    }

    public function test_form_pages_render_for_create_and_edit(): void
    {
        $rule = AlertRule::factory()->create(['name' => 'Editável']);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/alert-rules/create')->assertOk()->assertSee('Nova regra de alerta');
        $this->actingAs($admin)->get('/alert-rules/'.$rule->uuid.'/edit')->assertOk()->assertSee('Editável')->assertSee('Excluir regra');
    }
}
