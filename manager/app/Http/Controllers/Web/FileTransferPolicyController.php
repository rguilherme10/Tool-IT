<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\FileTransferPolicyRequest;
use App\Models\EndpointGroup;
use App\Models\FileTransferPolicy;
use App\Services\Transfers\TransferPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Política de transferência de arquivos por grupo. Configuração do sistema: `settings.manage` (global). */
class FileTransferPolicyController extends Controller
{
    public function __construct(private readonly TransferPolicyService $policies) {}

    public function index(): View
    {
        Gate::authorize('settings.manage');

        $policies = FileTransferPolicy::query()->with('group:id,uuid,name')->get()
            ->sortBy(fn (FileTransferPolicy $p) => [$p->endpoint_group_id === null ? 0 : 1, mb_strtolower((string) $p->group?->name)])
            ->values();

        return view('file-policies.index', ['policies' => $policies]);
    }

    public function create(): View
    {
        Gate::authorize('settings.manage');

        return view('file-policies.form', [
            'policy' => new FileTransferPolicy,
            'values' => $this->formValues(TransferPolicyService::DEFAULTS),
            'groups' => EndpointGroup::orderBy('name')->get(['id', 'uuid', 'name']),
            'taken' => FileTransferPolicy::pluck('scope_key')->all(),
        ]);
    }

    public function store(FileTransferPolicyRequest $request): RedirectResponse
    {
        $this->policies->create($request->user(), $request->scopeGroup(), $request->policyAttributes());

        return redirect()->route('file-policies.index')->with('status', 'Política de transferência criada.');
    }

    public function edit(FileTransferPolicy $policy): View
    {
        Gate::authorize('settings.manage');

        return view('file-policies.form', [
            'policy' => $policy->load('group:id,uuid,name'),
            'values' => $this->formValues($policy->only(['send_dirs', 'fetch_dirs', 'allowed_extensions', 'max_bytes', 'allow_overwrite'])),
            'groups' => collect(),
            'taken' => [],
        ]);
    }

    public function update(FileTransferPolicyRequest $request, FileTransferPolicy $policy): RedirectResponse
    {
        $this->policies->update($request->user(), $policy, $request->policyAttributes());

        return redirect()->route('file-policies.index')->with('status', 'Política de transferência atualizada.');
    }

    public function destroy(Request $request, FileTransferPolicy $policy): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $request->validate(['confirm' => ['accepted']]);

        $this->policies->delete($request->user(), $policy);

        return redirect()->route('file-policies.index')->with('status', 'Política removida. Os computadores desse escopo passam a herdar a política do grupo acima (ou ficam sem transferência).');
    }

    /**
     * Valores exibidos no formulário: listas de diretórios uma por linha, extensões separadas por espaço.
     *
     * @param  array<string, mixed>  $source
     * @return array{send_dirs: string, fetch_dirs: string, allowed_extensions: string, max_bytes: int, allow_overwrite: bool}
     */
    private function formValues(array $source): array
    {
        return [
            'send_dirs' => implode("\n", $source['send_dirs'] ?? []),
            'fetch_dirs' => implode("\n", $source['fetch_dirs'] ?? []),
            'allowed_extensions' => implode(' ', $source['allowed_extensions'] ?? []),
            'max_bytes' => (int) ($source['max_bytes'] ?? 0),
            'allow_overwrite' => (bool) ($source['allow_overwrite'] ?? false),
        ];
    }
}
