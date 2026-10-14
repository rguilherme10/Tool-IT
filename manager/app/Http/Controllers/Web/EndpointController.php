<?php

namespace App\Http\Controllers\Web;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\EndpointInventory;
use App\Models\NetworkInterface;
use App\Models\SoftwareItem;
use App\Support\Authorization\AccessResolver;
use App\Support\Metrics\MetricSeries;
use Carbon\CarbonImmutable;
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

    public const METRIC_RANGES = ['1h' => 1, '6h' => 6, '24h' => 24, '7d' => 168];

    public function show(Request $request, string $uuid): View
    {
        $endpoint = $this->visibleEndpoint($request, $uuid)->load('group:id,name', 'activeInstallation');
        $this->authorize('view', $endpoint);

        $user = $request->user();
        $sensitive = $this->access->can($user, Permission::InventoryViewSensitive, $endpoint);
        $metrics = $this->access->can($user, Permission::MetricsView, $endpoint);

        $tabs = ['details' => 'Detalhes', 'hardware' => 'Hardware'];
        if ($sensitive) {
            $tabs += ['software' => 'Software', 'network' => 'Rede'];
        }
        if ($metrics) {
            $tabs += ['metrics' => 'Métricas'];
        }

        $tab = (string) $request->query('tab', 'details');
        if (! isset($tabs[$tab])) {
            // Aba conhecida mas sem permissão = 403; qualquer outro valor cai em "Detalhes".
            abort_if(in_array($tab, ['software', 'network', 'metrics'], true), 403);
            $tab = 'details';
        }

        $data = [
            'endpoint' => $endpoint,
            'online' => $endpoint->last_seen_at?->gte(now()->subSeconds(DashboardController::ONLINE_WINDOW_SECONDS)) ?? false,
            'tabs' => $tabs,
            'tab' => $tab,
            'sensitive' => $sensitive,
        ];

        return view('endpoints.show', $data + match ($tab) {
            'hardware' => ['inventory' => EndpointInventory::where('endpoint_id', $endpoint->id)->first()],
            'software' => $this->softwareData($request, $endpoint),
            'network' => ['interfaces' => NetworkInterface::where('endpoint_id', $endpoint->id)->orderBy('name')->get()],
            'metrics' => $this->metricsData($request, $endpoint),
            default => ['audit' => $this->access->canGlobally($user, Permission::AuditView)
                ? DB::table('audit_logs')->where('endpoint_id', $endpoint->id)->orderByDesc('seq')->limit(20)->get()
                : null],
        });
    }

    /** @return array<string, mixed> */
    private function softwareData(Request $request, Endpoint $endpoint): array
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'in:active,removed,all'],
        ]);
        $state = $filters['state'] ?? 'active';

        $items = SoftwareItem::where('endpoint_id', $endpoint->id)
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->whereRaw(
                "name LIKE ? ESCAPE '!'",
                ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v).'%'],
            ))
            ->when($state === 'active', fn ($q) => $q->whereNull('removed_at'))
            ->when($state === 'removed', fn ($q) => $q->whereNotNull('removed_at'))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return [
            'items' => $items,
            'filters' => ['q' => $filters['q'] ?? '', 'state' => $state],
            'counts' => [
                'active' => SoftwareItem::where('endpoint_id', $endpoint->id)->whereNull('removed_at')->count(),
                'removed' => SoftwareItem::where('endpoint_id', $endpoint->id)->whereNotNull('removed_at')->count(),
            ],
            'softwareStatus' => EndpointInventory::where('endpoint_id', $endpoint->id)->value('section_status')['software'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function metricsData(Request $request, Endpoint $endpoint): array
    {
        $range = (string) $request->query('range', '24h');
        $range = isset(self::METRIC_RANGES[$range]) ? $range : '24h';
        $from = CarbonImmutable::now('UTC')->subHours(self::METRIC_RANGES[$range])->format('Y-m-d H:i:s');

        $rows = DB::table('metric_samples')
            ->where('endpoint_id', $endpoint->id)->where('sampled_at', '>=', $from)
            ->orderBy('sampled_at')
            ->get(['sampled_at', 'cpu_percent', 'mem_total_mb', 'mem_available_mb'])
            ->all();

        $latest = DB::table('metric_samples')->where('endpoint_id', $endpoint->id)->orderByDesc('sampled_at')->first();
        $points = MetricSeries::bucket($rows, 120);

        return [
            'range' => $range,
            'ranges' => array_keys(self::METRIC_RANGES),
            'sampleCount' => count($rows),
            'latest' => $latest ? (object) [
                'at' => CarbonImmutable::parse($latest->sampled_at, 'UTC'),
                'cpu' => $latest->cpu_percent,
                'memUsed' => $latest->mem_total_mb > 0 ? round((1 - ($latest->mem_available_mb / $latest->mem_total_mb)) * 100, 1) : null,
                'memTotalMb' => $latest->mem_total_mb,
                'uptime' => $latest->uptime_seconds,
                'volumes' => json_decode($latest->volumes, true) ?? [],
            ] : null,
            'cpuLine' => MetricSeries::polyline(array_column($points, 'cpu'), 600, 100),
            'memLine' => MetricSeries::polyline(array_column($points, 'mem'), 600, 100),
            'recent' => DB::table('metric_samples')->where('endpoint_id', $endpoint->id)->orderByDesc('sampled_at')->limit(15)
                ->get(['sampled_at', 'cpu_percent', 'mem_total_mb', 'mem_available_mb']),
        ];
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
