<?php

namespace App\Http\Controllers\Web;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Endpoint;
use App\Support\Authorization\AccessResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Janela para considerar um endpoint "online" (3 × intervalo padrão de heartbeat). */
    public const ONLINE_WINDOW_SECONDS = 180;

    public function __invoke(Request $request, AccessResolver $access): View
    {
        $base = fn () => $access->scopeEndpoints(Endpoint::query(), $request->user(), Permission::InventoryView);
        $cutoff = now()->subSeconds(self::ONLINE_WINDOW_SECONDS);

        $alerts = $access->canAnywhere($request->user(), Permission::AlertsView)
            ? Alert::query()->active()
                ->whereIn('endpoint_id', $access->scopeEndpoints(Endpoint::query(), $request->user(), Permission::AlertsView)->select('endpoints.id'))
                ->selectRaw('severity, COUNT(*) as total')->groupBy('severity')->pluck('total', 'severity')
            : null;

        return view('dashboard', [
            'alerts' => $alerts === null ? null : [
                'critical' => (int) ($alerts['critical'] ?? 0),
                'warning' => (int) ($alerts['warning'] ?? 0),
                'info' => (int) ($alerts['info'] ?? 0),
            ],
            'total' => $base()->count(),
            'online' => $base()->where('last_seen_at', '>=', $cutoff)->count(),
            'offline' => $base()->where('last_seen_at', '<', $cutoff)->count(),
            'neverSeen' => $base()->whereNull('last_seen_at')->count(),
        ]);
    }
}
