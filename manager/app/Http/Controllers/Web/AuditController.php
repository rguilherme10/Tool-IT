<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Endpoint;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Trilha de auditoria: somente leitura. Permissão de sistema `audit.view` (administrador e auditor globais). */
class AuditController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('audit.view');

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:96'],
            'actor_type' => ['nullable', 'in:user,agent,system'],
            'user' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = DB::table('audit_logs')
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['actor_type'] ?? null, fn ($q, $v) => $q->where('actor_type', $v))
            ->when($filters['user'] ?? null, fn ($q, $v) => $q->where('actor_type', 'user')->whereIn(
                'actor_id', User::query()->withTrashed()->where(fn ($u) => $u->whereRaw("email LIKE ? ESCAPE '!'", [$this->like($v)])->orWhereRaw("name LIKE ? ESCAPE '!'", [$this->like($v)]))
                    ->pluck('id')->map(fn ($id) => (string) $id)->all(),
            ))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', CarbonImmutable::parse($v)->startOfDay()->utc()->format('Y-m-d H:i:s')))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<=', CarbonImmutable::parse($v)->endOfDay()->utc()->format('Y-m-d H:i:s')))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->whereIn(
                'endpoint_id', Endpoint::query()->withTrashed()->whereRaw("hostname LIKE ? ESCAPE '!'", [$this->like($v)])->pluck('id')->all(),
            ));

        $rows = $query->orderByDesc('seq')->paginate(50)->withQueryString();

        $userIds = $rows->getCollection()->where('actor_type', 'user')->pluck('actor_id')->unique()->all();
        $endpointIds = $rows->getCollection()->pluck('endpoint_id')->filter()->unique()->all();

        return view('audit.index', [
            'rows' => $rows,
            'users' => User::query()->withTrashed()->whereIn('id', $userIds)->pluck('name', 'id'),
            'endpoints' => Endpoint::query()->withTrashed()->whereIn('id', $endpointIds)->pluck('hostname', 'id'),
            'actions' => DB::table('audit_logs')->select('action')->distinct()->orderBy('action')->pluck('action'),
            'filters' => array_merge(['action' => '', 'actor_type' => '', 'user' => '', 'from' => '', 'to' => '', 'q' => ''], $filters),
        ]);
    }

    /** Recalcula toda a cadeia (somente leitura) e registra que a verificação foi feita. */
    public function verify(Request $request, AuditVerifier $verifier, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('audit.view');

        $result = $verifier->verify();
        $audit->log('audit.integrity_checked', $request->user(), metadata: ['ok' => $result['ok'], 'records' => $result['count']]);

        return redirect()->route('audit.index')->with($result['ok'] ? 'status' : 'integrity_error', $result['message']);
    }

    /** Padrão LIKE com curingas digitados escapados ('!' como escape: portável entre MySQL e SQLite). */
    private function like(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }
}
