<?php

namespace App\Http\Controllers\Web;

use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Http\Controllers\Controller;
use App\Http\Requests\AlertRuleRequest;
use App\Models\AlertRule;
use App\Models\EndpointGroup;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Configuração de regras: permissão de sistema `settings.manage` (somente administradores globais). */
class AlertRuleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        Gate::authorize('settings.manage');

        return view('alert-rules.index', [
            'rules' => AlertRule::query()->with('group:id,name')->withCount(['alerts as active_alerts_count' => fn ($q) => $q->active()])
                ->orderBy('type')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('settings.manage');

        return view('alert-rules.form', $this->formData(new AlertRule([
            'type' => AlertRuleType::CpuUsage, 'severity' => AlertSeverity::Warning, 'threshold' => 90, 'clear_threshold' => 80,
            'min_duration_seconds' => 900, 'clear_duration_seconds' => 120, 'repeat_interval_seconds' => 3600, 'enabled' => true,
        ])));
    }

    public function store(AlertRuleRequest $request): RedirectResponse
    {
        $rule = new AlertRule($this->attributes($request, $request->ruleType()));
        $rule->created_by = $request->user()->id;
        $rule->save();

        $this->audit->log('alert_rule.created', $request->user(), $rule, metadata: $this->snapshot($rule));

        return redirect()->route('alert-rules.index')->with('status', "Regra “{$rule->name}” criada.");
    }

    public function edit(AlertRule $rule): View
    {
        Gate::authorize('settings.manage');

        return view('alert-rules.form', $this->formData($rule));
    }

    public function update(AlertRuleRequest $request, AlertRule $rule): RedirectResponse
    {
        $before = $this->snapshot($rule);
        $rule->fill($this->attributes($request, $rule->type))->save();

        $this->audit->log('alert_rule.updated', $request->user(), $rule, metadata: ['before' => $before, 'after' => $this->snapshot($rule)]);

        return redirect()->route('alert-rules.index')->with('status', "Regra “{$rule->name}” atualizada.");
    }

    public function destroy(Request $request, AlertRule $rule): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $request->validate(['confirm' => ['accepted']]);

        $this->audit->log('alert_rule.deleted', $request->user(), $rule, metadata: $this->snapshot($rule));
        $rule->delete();   // alertas ativos são fechados pelo avaliador (rule_removed)

        return redirect()->route('alert-rules.index')->with('status', 'Regra excluída. Os alertas dela serão encerrados na próxima avaliação.');
    }

    /** @return array<string, mixed> */
    private function attributes(AlertRuleRequest $request, AlertRuleType $type): array
    {
        $d = $request->validated();
        $group = ! empty($d['group']) ? EndpointGroup::where('uuid', $d['group'])->firstOrFail() : null;
        $outdated = $type === AlertRuleType::AgentOutdated;

        return [
            'type' => $type,
            'name' => $d['name'],
            'severity' => $d['severity'],
            'threshold' => $outdated ? 0 : $d['threshold'],
            'clear_threshold' => $outdated ? null : ($d['clear_threshold'] ?? null),
            'min_duration_seconds' => $type->usesMetrics() ? ((int) ($d['min_duration_minutes'] ?? 0)) * 60 : 0,
            'clear_duration_seconds' => $type->usesMetrics() ? ((int) ($d['clear_duration_minutes'] ?? 2)) * 60 : 120,
            'repeat_interval_seconds' => ((int) ($d['repeat_interval_minutes'] ?? 0)) * 60,
            'params' => $outdated ? ['min_version' => $d['min_version']] : null,
            'endpoint_group_id' => $group?->id,
            'enabled' => $request->boolean('enabled'),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(AlertRule $rule): array
    {
        return $rule->only(['name', 'severity', 'threshold', 'clear_threshold', 'min_duration_seconds', 'clear_duration_seconds',
            'repeat_interval_seconds', 'endpoint_group_id', 'enabled']) + ['type' => $rule->type->value];
    }

    /** @return array<string, mixed> */
    private function formData(AlertRule $rule): array
    {
        return [
            'rule' => $rule,
            'types' => AlertRuleType::cases(),
            'severities' => AlertSeverity::cases(),
            'groups' => EndpointGroup::orderBy('name')->get(['id', 'uuid', 'name']),
        ];
    }
}
