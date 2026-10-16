<?php

namespace App\Http\Controllers\Web;

use App\Enums\EndpointStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\EndpointGroup;
use App\Services\Audit\AuditLogger;
use App\Services\Tasks\TaskService;
use App\Support\Authorization\AccessResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Gestão de um computador: mover de grupo e desativar/reativar. Exige `endpoints.manage` no escopo do computador. */
class EndpointManagementController extends Controller
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly AuditLogger $audit,
        private readonly TaskService $tasks,
    ) {}

    public function moveGroup(Request $request, string $uuid): RedirectResponse
    {
        $endpoint = EndpointController::resolveVisible($this->access, $request, $uuid);
        abort_unless($this->access->can($request->user(), Permission::EndpointsManage, $endpoint), 403);

        $data = $request->validate(['group' => ['nullable', 'string', 'exists:endpoint_groups,uuid']]);
        $target = ! empty($data['group']) ? EndpointGroup::where('uuid', $data['group'])->firstOrFail() : null;

        // Precisa poder gerenciar também o DESTINO: ninguém "empurra" computadores para escopos que não administra.
        abort_unless($this->access->canForGroup($request->user(), Permission::EndpointsManage, $target), 403);

        $from = $endpoint->endpoint_group_id;
        if ($from === $target?->id) {
            return back()->with('status', 'O computador já está neste grupo.');
        }

        $endpoint->update(['endpoint_group_id' => $target?->id]);
        $this->audit->log('endpoint.group_changed', $request->user(), $endpoint, $endpoint->id, ['from' => $from, 'to' => $target?->id]);

        return back()->with('status', 'Grupo atualizado.');
    }

    public function setStatus(Request $request, string $uuid): RedirectResponse
    {
        $endpoint = EndpointController::resolveVisible($this->access, $request, $uuid);
        abort_unless($this->access->can($request->user(), Permission::EndpointsManage, $endpoint), 403);

        $data = $request->validate(['status' => ['required', Rule::in([EndpointStatus::Active->value, EndpointStatus::Disabled->value])]]);
        $new = EndpointStatus::from($data['status']);

        if ($endpoint->status === $new) {
            return back()->with('status', 'Nada a alterar.');
        }

        DB::transaction(function () use ($endpoint, $new, $request) {
            $endpoint->update(['status' => $new]);
            $this->audit->log($new === EndpointStatus::Disabled ? 'endpoint.disabled' : 'endpoint.enabled', $request->user(), $endpoint, $endpoint->id);

            // Computador desativado não executa tarefas: as abertas são canceladas na mesma transação.
            if ($new === EndpointStatus::Disabled) {
                $this->tasks->cancelOpenFor($endpoint, 'endpoint_deactivated');
            }
        });

        return back()->with('status', $new === EndpointStatus::Disabled
            ? 'Computador desativado: o agente será recusado e os alertas dele serão encerrados.'
            : 'Computador reativado.');
    }
}
