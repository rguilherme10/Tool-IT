<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\RoleKey;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\EndpointGroup;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Alerts\AlertService;
use App\Services\Alerts\MetricThresholdEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlertEvaluationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
    }

    private function evaluate(): array
    {
        return app(AlertEvaluator::class)->run();
    }

    private function cpuRule(array $overrides = []): AlertRule
    {
        return AlertRule::factory()->create($overrides + [
            'type' => AlertRuleType::CpuUsage, 'threshold' => 90, 'clear_threshold' => 80,
            'min_duration_seconds' => 600, 'clear_duration_seconds' => 120,
        ]);
    }

    public function test_sustained_high_cpu_opens_exactly_one_alert(): void
    {
        $this->cpuRule();
        $endpoint = $this->activeEndpoint();
        $this->steady($endpoint, 15, 0, cpu: 95);

        $stats = $this->evaluate();

        $this->assertSame(1, $stats['opened']);
        $alert = Alert::firstOrFail();
        $this->assertSame(AlertStatus::Open, $alert->status);
        $this->assertSame(AlertSeverity::Warning, $alert->severity);
        $this->assertSame($endpoint->id, $alert->endpoint_id);
        $this->assertStringContainsString('CPU com 95,0 %', $alert->title);
        $this->assertSame(95.0, (float) $alert->details['value']);
    }

    public function test_running_again_never_duplicates_the_alert(): void
    {
        $this->cpuRule();
        $this->steady($this->activeEndpoint(), 15, 0, cpu: 95);

        $this->evaluate();
        $this->travel(1)->minutes();
        $second = $this->evaluate();

        $this->assertSame(0, $second['opened']);
        $this->assertSame(1, Alert::count());
    }

    public function test_the_database_itself_allows_only_one_active_alert_per_key(): void
    {
        $rule = $this->cpuRule();
        $endpoint = $this->activeEndpoint();
        Alert::factory()->forEndpoint($endpoint, $rule);

        $this->expectException(QueryException::class);
        Alert::factory()->forEndpoint($endpoint, $rule);   // mesma regra+computador+assunto, ainda ativo
    }

    public function test_a_resolved_alert_does_not_block_a_new_one_later(): void
    {
        $rule = $this->cpuRule();
        $endpoint = $this->activeEndpoint();
        $old = Alert::factory()->forEndpoint($endpoint, $rule);
        $old->forceFill(['status' => AlertStatus::Resolved, 'resolved_at' => now()])->save();

        Alert::factory()->forEndpoint($endpoint, $rule);

        $this->assertSame(2, Alert::count());
    }

    public function test_a_single_high_reading_does_not_open_an_alert(): void
    {
        $this->cpuRule();
        $endpoint = $this->activeEndpoint();
        $this->steady($endpoint, 15, 1, cpu: 30);
        $this->insertSamples($endpoint, [[0, 99, 40, 40]]);

        $this->assertSame(0, $this->evaluate()['opened']);
    }

    public function test_a_short_dip_inside_the_window_prevents_the_alert(): void
    {
        $this->cpuRule();
        $endpoint = $this->activeEndpoint();
        $rows = array_map(fn ($m) => [$m, $m === 7 ? 60 : 95, 40, 40], range(15, 0));
        $this->insertSamples($endpoint, $rows);

        $this->assertSame(0, $this->evaluate()['opened']);
    }

    public function test_not_enough_history_does_not_open_an_alert(): void
    {
        $this->cpuRule();
        $this->steady($this->activeEndpoint(), 5, 0, cpu: 99);   // só 5 min de histórico para uma regra de 10 min

        $this->assertSame(0, $this->evaluate()['opened']);
    }

    public function test_short_rule_with_fast_sampling_still_requires_the_full_duration(): void
    {
        // Regressão do teste real: regra de 2 min abriu com 30 s de histórico (amostras a cada 15 s).
        $this->cpuRule(['min_duration_seconds' => 120]);
        $endpoint = $this->activeEndpoint();
        $this->insertSamples($endpoint, array_map(fn ($s) => [$s / 60, 95, 40, 40], [30, 15, 0]));

        $this->assertSame(0, $this->evaluate()['opened']);

        $this->insertSamples($endpoint, array_map(fn ($s) => [$s / 60, 95, 40, 40], [135, 120, 105, 90, 75, 60, 45]));
        $this->assertSame(1, $this->evaluate()['opened'], 'com 2 min de histórico contínuo abre');
    }

    public function test_missing_samples_inside_the_window_do_not_count_as_sustained(): void
    {
        $this->cpuRule();
        $endpoint = $this->activeEndpoint();
        $this->insertSamples($endpoint, array_map(fn ($m) => [$m, 95, 40, 40], array_merge(range(15, 10), range(4, 0))));   // 5 min de buraco

        $this->assertSame(0, $this->evaluate()['opened']);
    }

    public function test_stale_data_neither_opens_nor_resolves(): void
    {
        $rule = $this->cpuRule();
        $endpoint = $this->activeEndpoint();
        $this->steady($endpoint, 40, 30, cpu: 95);              // agente parou de enviar há 30 min
        $existing = Alert::factory()->forEndpoint($endpoint, $rule);

        $stats = $this->evaluate();

        $this->assertSame(0, $stats['opened']);
        $this->assertSame(AlertStatus::Open, $existing->fresh()->status, 'sem dado novo, nada é concluído');
    }

    public function test_hysteresis_keeps_the_alert_open_inside_the_band_and_resolves_below_it(): void
    {
        $rule = $this->cpuRule();
        $endpoint = $this->activeEndpoint();
        $alert = Alert::factory()->forEndpoint($endpoint, $rule);

        // 85 % está entre o limpar (80) e o limite (90): permanece aberto.
        $this->steady($endpoint, 5, 0, cpu: 85);
        $this->assertSame(0, $this->evaluate()['resolved']);
        $this->assertSame(AlertStatus::Open, $alert->fresh()->status);

        // Abaixo de 80 por mais de 2 min: resolve.
        DB::table('metric_samples')->delete();
        $this->steady($endpoint, 5, 0, cpu: 60);
        $stats = $this->evaluate();

        $this->assertSame(1, $stats['resolved']);
        $fresh = $alert->fresh();
        $this->assertSame(AlertStatus::Resolved, $fresh->status);
        $this->assertSame('auto', $fresh->resolved_reason);
        $this->assertNotNull($fresh->resolved_at);
    }

    public function test_one_low_reading_does_not_resolve(): void
    {
        $rule = $this->cpuRule();
        $endpoint = $this->activeEndpoint();
        $alert = Alert::factory()->forEndpoint($endpoint, $rule);
        $this->steady($endpoint, 5, 1, cpu: 95);
        $this->insertSamples($endpoint, [[0, 10, 40, 40]]);

        $this->evaluate();

        $this->assertSame(AlertStatus::Open, $alert->fresh()->status);
    }

    public function test_disk_rule_alerts_per_volume(): void
    {
        $rule = AlertRule::factory()->create([
            'type' => AlertRuleType::DiskUsage, 'severity' => AlertSeverity::Critical, 'threshold' => 90, 'clear_threshold' => 85,
            'min_duration_seconds' => 300,
        ]);
        $endpoint = $this->activeEndpoint();
        $volumes = [['mount' => 'C:', 'total_gb' => 100, 'free_gb' => 5], ['mount' => 'D:', 'total_gb' => 500, 'free_gb' => 400]];
        $this->insertSamples($endpoint, array_map(fn ($m) => [$m, 10, 40, 0, $volumes], range(8, 0)));

        $this->evaluate();

        $alert = Alert::firstOrFail();
        $this->assertSame(1, Alert::count());
        $this->assertSame('C:', $alert->subject);
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertStringContainsString('Disco C: com 95,0 %', $alert->title);
        $this->assertSame($rule->id, $alert->alert_rule_id);
    }

    public function test_two_full_volumes_produce_two_independent_alerts(): void
    {
        AlertRule::factory()->create(['type' => AlertRuleType::DiskUsage, 'threshold' => 90, 'clear_threshold' => 85, 'min_duration_seconds' => 0]);
        $endpoint = $this->activeEndpoint();
        $volumes = [['mount' => 'C:', 'total_gb' => 100, 'free_gb' => 2], ['mount' => 'E:', 'total_gb' => 100, 'free_gb' => 3]];
        $this->insertSamples($endpoint, [[0, 10, 40, 0, $volumes]]);

        $this->evaluate();
        $this->evaluate();

        $this->assertEqualsCanonicalizing(['C:', 'E:'], Alert::pluck('subject')->all());
    }

    public function test_memory_rule_uses_used_percentage(): void
    {
        AlertRule::factory()->create([
            'type' => AlertRuleType::MemoryUsage, 'threshold' => 90, 'clear_threshold' => 80, 'min_duration_seconds' => 300,
        ]);
        $endpoint = $this->activeEndpoint();
        $this->steady($endpoint, 8, 0, cpu: 5, mem: 93);

        $this->evaluate();

        $this->assertStringContainsString('Memória com 93,0 %', Alert::firstOrFail()->title);
    }

    public function test_offline_agent_alert_opens_and_clears_with_hysteresis(): void
    {
        AlertRule::factory()->create(['type' => AlertRuleType::AgentOffline, 'threshold' => 300, 'clear_threshold' => 120, 'min_duration_seconds' => 0]);
        $silent = $this->activeEndpoint(attributes: ['last_seen_at' => now()->subMinutes(10)]);
        $recent = $this->activeEndpoint(attributes: ['last_seen_at' => now()->subMinutes(3)]);
        $never = $this->activeEndpoint(attributes: ['last_seen_at' => null]);

        $this->evaluate();

        $this->assertSame([$silent->id], Alert::pluck('endpoint_id')->all(), 'só quem passou do limite; quem nunca falou não alerta');
        $this->assertStringContainsString('Sem comunicação há 10 min (limite 5 min)', Alert::firstOrFail()->title);

        // Voltou há 4 min: ainda dentro da faixa (120 s < 240 s ≤ 300 s) → continua aberto.
        $silent->update(['last_seen_at' => now()->subMinutes(4)]);
        $this->evaluate();
        $this->assertSame(AlertStatus::Open, Alert::firstOrFail()->status);

        // Voltou há 30 s → resolve.
        $silent->update(['last_seen_at' => now()->subSeconds(30)]);
        $this->assertSame(1, $this->evaluate()['resolved']);
        $this->assertSame(AlertStatus::Resolved, Alert::firstOrFail()->status);
    }

    public function test_outdated_agent_rule(): void
    {
        AlertRule::factory()->create([
            'type' => AlertRuleType::AgentOutdated, 'threshold' => 0, 'clear_threshold' => null, 'min_duration_seconds' => 0,
            'params' => ['min_version' => '0.2.0'],
        ]);
        $old = $this->activeEndpoint(attributes: ['agent_version' => '0.1.9']);
        $current = $this->activeEndpoint(attributes: ['agent_version' => '0.2.0']);
        $unknown = $this->activeEndpoint(attributes: ['agent_version' => null]);

        $this->evaluate();

        $this->assertSame([$old->id], Alert::pluck('endpoint_id')->all());
        $this->assertStringContainsString('Agente 0.1.9 desatualizado (mínimo 0.2.0)', Alert::firstOrFail()->title);

        $old->update(['agent_version' => '0.2.1']);
        $this->evaluate();
        $this->assertSame(AlertStatus::Resolved, Alert::firstOrFail()->status);
    }

    public function test_revoked_or_disabled_agents_are_not_evaluated_and_their_alerts_close(): void
    {
        AlertRule::factory()->create(['type' => AlertRuleType::AgentOffline, 'threshold' => 300, 'min_duration_seconds' => 0]);
        $revoked = $this->activeEndpoint(attributes: ['last_seen_at' => now()->subHour()]);
        $disabled = $this->activeEndpoint(attributes: ['last_seen_at' => now()->subHour()]);
        $this->evaluate();
        $this->assertSame(2, Alert::count());

        $revoked->activeInstallation()->update(['status' => 'revoked', 'revoked_at' => now()]);
        $disabled->update(['status' => 'disabled']);
        $stats = $this->evaluate();

        $this->assertSame(2, $stats['orphans']);
        $this->assertSame(['endpoint_inactive'], Alert::pluck('resolved_reason')->unique()->values()->all());
        $this->assertSame(0, Alert::active()->count());
    }

    public function test_group_scoped_rule_only_covers_the_group_and_its_descendants(): void
    {
        $parent = EndpointGroup::factory()->create();
        $child = EndpointGroup::factory()->create(['parent_id' => $parent->id]);
        $other = EndpointGroup::factory()->create();
        AlertRule::factory()->create([
            'type' => AlertRuleType::AgentOffline, 'threshold' => 300, 'min_duration_seconds' => 0, 'endpoint_group_id' => $parent->id,
        ]);
        $inChild = $this->activeEndpoint($child, ['last_seen_at' => now()->subHour()]);
        $this->activeEndpoint($other, ['last_seen_at' => now()->subHour()]);
        $this->activeEndpoint(null, ['last_seen_at' => now()->subHour()]);

        $this->evaluate();

        $this->assertSame([$inChild->id], Alert::pluck('endpoint_id')->all());
    }

    public function test_repeat_counter_follows_the_interval_and_stops_once_acknowledged(): void
    {
        $rule = AlertRule::factory()->create([
            'type' => AlertRuleType::AgentOffline, 'threshold' => 300, 'min_duration_seconds' => 0, 'repeat_interval_seconds' => 600,
        ]);
        $endpoint = $this->activeEndpoint(attributes: ['last_seen_at' => now()->subHour()]);

        $this->evaluate();                                   // abre
        $this->travel(9)->minutes();
        $this->evaluate();
        $this->assertSame(0, Alert::firstOrFail()->repeat_count, 'antes do intervalo');

        $this->travel(2)->minutes();                         // 11 min desde a abertura
        $this->assertSame(1, $this->evaluate()['repeated']);
        $this->assertSame(1, Alert::firstOrFail()->repeat_count);

        $this->travel(11)->minutes();
        $user = $this->userWithRole(RoleKey::Operator);
        app(AlertService::class)->acknowledge(Alert::firstOrFail(), $user);
        $this->assertSame(0, $this->evaluate()['repeated'], 'reconhecido não repete');
        $this->assertSame($rule->id, Alert::firstOrFail()->alert_rule_id);
    }

    public function test_disabling_or_deleting_a_rule_closes_its_alerts(): void
    {
        $disabled = AlertRule::factory()->create(['type' => AlertRuleType::AgentOffline, 'threshold' => 300, 'min_duration_seconds' => 0]);
        $deleted = AlertRule::factory()->create(['type' => AlertRuleType::AgentOutdated, 'threshold' => 0, 'min_duration_seconds' => 0, 'params' => ['min_version' => '9.9.9']]);
        $this->activeEndpoint(attributes: ['last_seen_at' => now()->subHour()]);
        $this->evaluate();
        $this->assertSame(2, Alert::active()->count());

        $disabled->update(['enabled' => false]);
        $deleted->delete();
        $stats = $this->evaluate();

        $this->assertSame(2, $stats['orphans']);
        $this->assertEqualsCanonicalizing(['rule_disabled', 'rule_removed'], Alert::pluck('resolved_reason')->all());
    }

    public function test_a_failing_rule_does_not_stop_the_others(): void
    {
        $this->cpuRule(['name' => 'quebrada']);
        AlertRule::factory()->create(['type' => AlertRuleType::AgentOffline, 'threshold' => 300, 'min_duration_seconds' => 0]);
        $this->activeEndpoint(attributes: ['last_seen_at' => now()->subHour()]);
        $this->app->bind(MetricThresholdEvaluator::class, fn () => new class extends MetricThresholdEvaluator
        {
            public function evaluate(AlertRule $rule, Collection $endpointIds, CarbonImmutable $now): array
            {
                throw new \RuntimeException('defeito simulado');
            }
        });

        $stats = app(AlertEvaluator::class)->run();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $stats['opened'], 'a regra de comunicação rodou normalmente');
    }

    public function test_opening_and_resolving_are_audited_and_the_chain_stays_valid(): void
    {
        AlertRule::factory()->create(['type' => AlertRuleType::AgentOffline, 'threshold' => 300, 'clear_threshold' => 120, 'min_duration_seconds' => 0]);
        $endpoint = $this->activeEndpoint(attributes: ['last_seen_at' => now()->subHour()]);

        $this->evaluate();
        $endpoint->update(['last_seen_at' => now()]);
        $this->evaluate();

        $actions = DB::table('audit_logs')->whereIn('action', ['alert.opened', 'alert.resolved'])->orderBy('seq')->pluck('action')->all();
        $this->assertSame(['alert.opened', 'alert.resolved'], $actions);
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.opened', 'actor_type' => 'system', 'endpoint_id' => $endpoint->id]);
        $this->artisan('audit:verify')->assertSuccessful();
    }

    public function test_no_rules_or_no_endpoints_is_a_clean_noop(): void
    {
        $this->assertSame(['rules' => 0, 'opened' => 0, 'resolved' => 0, 'repeated' => 0, 'orphans' => 0, 'failed' => 0], $this->evaluate());

        $this->cpuRule();
        $this->assertSame(0, $this->evaluate()['opened']);
    }
}
