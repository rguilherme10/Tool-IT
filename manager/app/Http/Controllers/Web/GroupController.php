<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AlertRule;
use App\Models\EndpointGroup;
use App\Models\RoleAssignment;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Grupos de computadores (hierarquia). Estrutura é configuração do sistema: permissão `settings.manage`. */
class GroupController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        Gate::authorize('settings.manage');

        $groups = EndpointGroup::query()->withCount(['endpoints', 'children'])->orderBy('name')->get();

        return view('groups.index', ['rows' => $this->flatten($groups)]);
    }

    public function create(): View
    {
        Gate::authorize('settings.manage');

        return view('groups.form', ['group' => new EndpointGroup, 'parents' => EndpointGroup::orderBy('name')->get(['id', 'uuid', 'name'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $data = $this->validated($request, null);

        $group = EndpointGroup::create($data);
        $this->audit->log('group.created', $request->user(), $group, metadata: ['name' => $group->name, 'parent_id' => $group->parent_id]);

        return redirect()->route('groups.index')->with('status', "Grupo “{$group->name}” criado.");
    }

    public function edit(EndpointGroup $group): View
    {
        Gate::authorize('settings.manage');

        // O grupo e seus descendentes não podem ser o pai dele (evita ciclos).
        $invalid = EndpointGroup::expandWithDescendants([$group->id]);

        return view('groups.form', [
            'group' => $group,
            'parents' => EndpointGroup::whereNotIn('id', $invalid)->orderBy('name')->get(['id', 'uuid', 'name']),
        ]);
    }

    public function update(Request $request, EndpointGroup $group): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $before = $group->only(['name', 'description', 'parent_id']);
        $group->update($this->validated($request, $group));

        $this->audit->log('group.updated', $request->user(), $group, metadata: ['before' => $before, 'after' => $group->only(['name', 'description', 'parent_id'])]);

        return redirect()->route('groups.index')->with('status', "Grupo “{$group->name}” atualizado.");
    }

    public function destroy(Request $request, EndpointGroup $group): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $request->validate(['confirm' => ['accepted']]);

        // Só grupos vazios: apagar um grupo com dependências mudaria silenciosamente permissões e escopos.
        $blockers = array_filter([
            'computadores' => $group->endpoints()->count(),
            'subgrupos' => $group->children()->count(),
            'atribuições de papel' => RoleAssignment::where('endpoint_group_id', $group->id)->count(),
            'regras de alerta' => AlertRule::where('endpoint_group_id', $group->id)->count(),
        ]);
        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'confirm' => 'O grupo não está vazio: '.implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($blockers), $blockers)).'. Mova ou remova antes.',
            ]);
        }

        $this->audit->log('group.deleted', $request->user(), $group, metadata: ['name' => $group->name]);
        $group->delete();

        return redirect()->route('groups.index')->with('status', 'Grupo excluído.');
    }

    /** @return array{name: string, description: ?string, parent_id: ?int} */
    private function validated(Request $request, ?EndpointGroup $current): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'description' => ['nullable', 'string', 'max:500'],
            'parent' => ['nullable', 'string', 'exists:endpoint_groups,uuid'],
        ]);

        $parent = ! empty($data['parent']) ? EndpointGroup::where('uuid', $data['parent'])->firstOrFail() : null;

        if ($current !== null && $parent !== null && in_array($parent->id, EndpointGroup::expandWithDescendants([$current->id]), true)) {
            throw ValidationException::withMessages(['parent' => 'Um grupo não pode ficar dentro dele mesmo ou de um subgrupo seu.']);
        }

        $siblingExists = EndpointGroup::query()
            ->where('parent_id', $parent?->id)->where('name', $data['name'])
            ->when($current, fn ($q) => $q->where('id', '!=', $current->id))->exists();
        if ($siblingExists) {
            throw ValidationException::withMessages(['name' => 'Já existe um grupo com este nome neste nível.']);
        }

        return ['name' => $data['name'], 'description' => $data['description'] ?? null, 'parent_id' => $parent?->id];
    }

    /**
     * Árvore achatada em ordem (pai → filhos) com profundidade, para exibir indentado.
     *
     * @param  Collection<int, EndpointGroup>  $groups
     * @return list<array{group: EndpointGroup, depth: int}>
     */
    private function flatten($groups): array
    {
        $byParent = $groups->groupBy(fn ($g) => $g->parent_id ?? 0);
        $rows = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$rows, $byParent) {
            foreach ($byParent->get($parent, []) as $group) {
                $rows[] = ['group' => $group, 'depth' => $depth];
                $walk($group->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $rows;
    }
}
