<?php

namespace App\Http\Controllers\Web;

use App\Enums\Permission;
use App\Enums\TaskState;
use App\Http\Controllers\Controller;
use App\Models\Endpoint;
use App\Models\RemoteTask;
use App\Services\Tasks\TaskService;
use App\Support\Authorization\AccessResolver;
use App\Support\Tasks\TaskCatalog;
use App\Support\Tasks\TaskForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Fino de propósito: as regras (catálogo, risco, aprovação, separação de funções, estados, auditoria) estão em TaskService.
 * Aqui só se resolve o recurso dentro do escopo do usuário (fora do escopo = 404) e se traduz o resultado em HTTP:
 * AuthorizationException do serviço vira 403; DomainException vira mensagem no formulário.
 */
class TaskController extends Controller
{
    public function __construct(private readonly AccessResolver $access, private readonly TaskService $tasks) {}

    public function store(Request $request, string $uuid): RedirectResponse
    {
        $endpoint = EndpointController::resolveVisible($this->access, $request, $uuid);

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(TaskCatalog::types())],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        try {
            $task = $this->tasks->create(
                $request->user(), $endpoint, $data['type'],
                TaskForm::params($data['type'], $request->all()), $data['idempotency_key'],
            );
        } catch (\DomainException $e) {
            return $this->failed($e);
        }

        // create() devolve a tarefa já existente para a mesma chave: nunca levar o usuário a outra tarefa.
        if ((int) $task->endpoint_id !== (int) $endpoint->id || $task->type !== $data['type']) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Esta chave já foi usada em outra tarefa. Recarregue a página e tente de novo.',
            ]);
        }

        return redirect()->route('endpoints.show', ['uuid' => $endpoint->uuid, 'tab' => 'tasks'])
            ->with('status', $task->state === TaskState::PendingApproval
                ? 'Tarefa criada. Ela só será executada depois da aprovação de outra pessoa.'
                : 'Tarefa criada. O agente a executa no próximo contato (até cerca de 10 segundos).');
    }

    public function show(Request $request, string $task): View
    {
        $task = $this->findVisible($request, $task)->load('endpoint.group:id,name', 'creator:id,name', 'approver:id,name', 'attempts');
        abort_unless($this->access->can($request->user(), Permission::TasksView, $task->endpoint), 403);

        return view('tasks.show', ['task' => $task, 'user' => $request->user()]);
    }

    public function approvals(Request $request): View
    {
        abort_unless($this->access->canAnywhere($request->user(), Permission::TasksApprove), 403);

        $visible = $this->access->scopeEndpoints(Endpoint::query(), $request->user(), Permission::TasksApprove)->select('endpoints.id');

        return view('tasks.approvals', [
            'tasks' => RemoteTask::query()
                ->whereIn('endpoint_id', $visible)
                ->where('state', TaskState::PendingApproval->value)
                ->with('endpoint:id,uuid,hostname,endpoint_group_id', 'endpoint.group:id,name', 'creator:id,name')
                ->orderBy('created_at')->orderBy('id')
                ->paginate(20),
            'user' => $request->user(),
        ]);
    }

    public function approve(Request $request, string $task): RedirectResponse
    {
        $task = $this->findVisible($request, $task);

        return $this->transition($task, fn () => $this->tasks->approve($request->user(), $task), 'Tarefa aprovada. O agente a executa no próximo contato.');
    }

    public function reject(Request $request, string $task): RedirectResponse
    {
        $task = $this->findVisible($request, $task);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->transition($task, fn () => $this->tasks->reject($request->user(), $task, $data['reason'] ?? null), 'Tarefa rejeitada.');
    }

    public function cancel(Request $request, string $task): RedirectResponse
    {
        $task = $this->findVisible($request, $task);

        return $this->transition($task, fn () => $this->tasks->cancel($request->user(), $task), 'Cancelamento registrado. Tarefas em execução são interrompidas no próximo contato do agente.');
    }

    /** Resolve a tarefa só entre computadores que o usuário enxerga; qualquer outra (ou inexistente) = 404. */
    private function findVisible(Request $request, string $uuid): RemoteTask
    {
        $visible = $this->access->scopeEndpoints(Endpoint::query(), $request->user(), Permission::InventoryView)->select('endpoints.id');

        return RemoteTask::query()->whereIn('endpoint_id', $visible)->where('uuid', $uuid)->with('endpoint')->firstOrFail();
    }

    private function transition(RemoteTask $task, callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return $this->failed($e, $task);
        }

        return redirect()->back(fallback: route('tasks.show', $task->uuid))->with('status', $message);
    }

    private function failed(\DomainException $e, ?RemoteTask $task = null): RedirectResponse
    {
        $back = $task === null ? redirect()->back() : redirect()->back(fallback: route('tasks.show', $task->uuid));

        return $back->withInput()->withErrors(['task' => $e->getMessage()]);
    }
}
