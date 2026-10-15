<?php

namespace App\Http\Controllers\Web;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Services\Alerts\AlertService;
use App\Support\Authorization\AccessResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AlertController extends Controller
{
    public function __construct(private readonly AccessResolver $access) {}

    public function index(Request $request): View
    {
        abort_unless($this->access->canAnywhere($request->user(), Permission::AlertsView), 403);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'open', 'acknowledged', 'resolved', 'all'])],
            'severity' => ['nullable', Rule::in(array_column(AlertSeverity::cases(), 'value'))],
            'group' => ['nullable', 'string', 'max:36'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'active';

        // Toda consulta passa pelo escopo do usuário (anti-BOLA): só alertas de computadores que ele pode ver.
        $visible = $this->visibleEndpoints($request)->select('endpoints.id');
        $base = Alert::query()->whereIn('endpoint_id', $visible);

        $alerts = (clone $base)
            ->with('endpoint:id,uuid,hostname,endpoint_group_id', 'endpoint.group:id,name')
            ->when($status === 'active', fn ($q) => $q->active())
            ->when(in_array($status, ['open', 'acknowledged', 'resolved'], true), fn ($q) => $q->where('status', $status))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['group'] ?? null, fn ($q, $uuid) => $q->whereHas('endpoint', fn ($e) => $e->whereIn(
                'endpoint_group_id', EndpointGroup::expandWithDescendants(EndpointGroup::where('uuid', $uuid)->pluck('id')->all()),
            )))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->whereHas('endpoint', fn ($e) => $e->whereRaw(
                "hostname LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v).'%'],
            )))
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('opened_at')
            ->paginate(25)
            ->withQueryString();

        return view('alerts.index', [
            'alerts' => $alerts,
            'filters' => ['status' => $status, 'severity' => $filters['severity'] ?? '', 'group' => $filters['group'] ?? '', 'q' => $filters['q'] ?? ''],
            'groups' => EndpointGroup::orderBy('name')->get(['uuid', 'name']),
            'counts' => [
                'open' => (clone $base)->where('status', AlertStatus::Open->value)->count(),
                'acknowledged' => (clone $base)->where('status', AlertStatus::Acknowledged->value)->count(),
            ],
        ]);
    }

    public function acknowledge(Request $request, string $uuid, AlertService $service): RedirectResponse
    {
        $alert = Alert::query()
            ->whereIn('endpoint_id', $this->visibleEndpoints($request)->select('endpoints.id'))
            ->where('uuid', $uuid)->with('endpoint')->firstOrFail();   // fora do escopo = 404

        abort_unless($this->access->can($request->user(), Permission::AlertsManage, $alert->endpoint), 403);

        $done = $service->acknowledge($alert, $request->user());

        return back()->with('status', $done ? 'Alerta reconhecido.' : 'Este alerta já foi reconhecido ou resolvido.');
    }

    private function visibleEndpoints(Request $request)
    {
        return $this->access->scopeEndpoints(Endpoint::query(), $request->user(), Permission::AlertsView);
    }
}
