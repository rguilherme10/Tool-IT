<?php

namespace App\Http\Controllers\Web;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Support\Authorization\AccessResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EndpointController extends Controller
{
    public function __construct(private readonly AccessResolver $access) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Endpoint::class);

        $filters = $request->validate([
            'hostname' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', 'string', 'max:36'],
            'status' => ['nullable', 'in:online,offline,never'],
        ]);

        $cutoff = now()->subSeconds(DashboardController::ONLINE_WINDOW_SECONDS);

        $endpoints = $this->access->scopeEndpoints(Endpoint::query(), $request->user(), Permission::InventoryView)
            ->with('group:id,name')
            // '!' como escape: portável (MySQL e SQLite); % e _ digitados pelo usuário valem literalmente.
            ->when($filters['hostname'] ?? null, fn ($q, $v) => $q->whereRaw(
                "hostname LIKE ? ESCAPE '!'",
                ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v).'%'],
            ))
            ->when($filters['group'] ?? null, fn ($q, $uuid) => $q->whereIn('endpoint_group_id',
                EndpointGroup::expandWithDescendants(EndpointGroup::where('uuid', $uuid)->pluck('id')->all())))
            ->when(($filters['status'] ?? null) === 'online', fn ($q) => $q->where('last_seen_at', '>=', $cutoff))
            ->when(($filters['status'] ?? null) === 'offline', fn ($q) => $q->where('last_seen_at', '<', $cutoff))
            ->when(($filters['status'] ?? null) === 'never', fn ($q) => $q->whereNull('last_seen_at'))
            ->orderBy('hostname')
            ->paginate(25)
            ->withQueryString();

        return view('endpoints.index', [
            'endpoints' => $endpoints,
            'groups' => EndpointGroup::orderBy('name')->get(['uuid', 'name']),
            'filters' => $filters,
            'cutoff' => $cutoff,
        ]);
    }

    public function show(Request $request, string $uuid): View
    {
        $endpoint = $this->visibleEndpoint($request, $uuid)->load('group:id,name', 'activeInstallation');
        $this->authorize('view', $endpoint);

        $audit = $this->access->canGlobally($request->user(), Permission::AuditView)
            ? DB::table('audit_logs')->where('endpoint_id', $endpoint->id)->orderByDesc('seq')->limit(20)->get()
            : null;

        return view('endpoints.show', [
            'endpoint' => $endpoint,
            'online' => $endpoint->last_seen_at?->gte(now()->subSeconds(DashboardController::ONLINE_WINDOW_SECONDS)) ?? false,
            'audit' => $audit,
        ]);
    }

    /** Fora do escopo do usuário = 404 (não confirma a existência do recurso). */
    public static function resolveVisible(AccessResolver $access, Request $request, string $uuid): Endpoint
    {
        return $access->scopeEndpoints(Endpoint::query(), $request->user(), Permission::InventoryView)
            ->where('uuid', $uuid)->firstOrFail();
    }

    private function visibleEndpoint(Request $request, string $uuid): Endpoint
    {
        return self::resolveVisible($this->access, $request, $uuid);
    }
}
