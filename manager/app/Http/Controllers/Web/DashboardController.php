<?php

namespace App\Http\Controllers\Web;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
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

        return view('dashboard', [
            'total' => $base()->count(),
            'online' => $base()->where('last_seen_at', '>=', $cutoff)->count(),
            'offline' => $base()->where('last_seen_at', '<', $cutoff)->count(),
            'neverSeen' => $base()->whereNull('last_seen_at')->count(),
        ]);
    }
}
