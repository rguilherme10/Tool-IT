<?php

namespace App\Http\Controllers\Web;

use App\Enums\RoleKey;
use App\Http\Controllers\Controller;
use App\Models\EndpointGroup;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Users\UserService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Usuários, papéis e escopos. Permissão de sistema `users.manage` (administrador com escopo global). */
class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): View
    {
        Gate::authorize('users.manage');
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->with('roleAssignments.role:id,key,name', 'roleAssignments.group:id,name')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->whereRaw("name LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%'])
                ->orWhereRaw("email LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%'])))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('users.index', ['users' => $users, 'q' => $q]);
    }

    public function create(): View
    {
        Gate::authorize('users.manage');

        return view('users.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('users.manage');
        // E-mail é gravado em minúsculas: valida a unicidade já normalizado (SQLite diferencia maiúsculas, MySQL não).
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['nullable', Rule::in(array_column(RoleKey::cases(), 'value'))],
            'group' => ['nullable', 'string', 'exists:endpoint_groups,uuid'],
        ]);

        $user = $this->users->create($data['name'], $data['email'], $data['password'], $request->user());
        if (! empty($data['role'])) {
            $this->users->assignRole($user, RoleKey::from($data['role']), $this->group($data['group'] ?? null), $request->user());
        }

        return redirect()->route('users.edit', $user)->with('status', 'Usuário criado. Informe a senha provisória por um canal seguro.');
    }

    public function edit(User $user): View
    {
        Gate::authorize('users.manage');

        return view('users.edit', $this->formData() + [
            'user' => $user->load('roleAssignments.role:id,key,name', 'roleAssignments.group:id,name'),
        ]);
    }

    public function setActive(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users.manage');
        $active = $request->boolean('active');

        if (! $active && $user->is($request->user())) {
            throw ValidationException::withMessages(['active' => 'Você não pode desativar a própria conta.']);
        }

        try {
            $this->users->setActive($user, $active, $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['active' => $e->getMessage()]);
        }

        return back()->with('status', $active ? 'Usuário ativado.' : 'Usuário desativado e sessões encerradas.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users.manage');
        $data = $request->validate(['password' => ['required', 'confirmed', Password::defaults()]]);

        $this->users->resetPassword($user, $data['password'], $request->user());

        return back()->with('status', 'Senha redefinida e sessões do usuário encerradas.');
    }

    public function assignRole(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users.manage');
        $data = $request->validate([
            'role' => ['required', Rule::in(array_column(RoleKey::cases(), 'value'))],
            'group' => ['nullable', 'string', 'exists:endpoint_groups,uuid'],
        ]);

        $this->users->assignRole($user, RoleKey::from($data['role']), $this->group($data['group'] ?? null), $request->user());

        return back()->with('status', 'Papel atribuído.');
    }

    public function removeAssignment(Request $request, User $user, RoleAssignment $assignment): RedirectResponse
    {
        Gate::authorize('users.manage');
        abort_unless($assignment->user_id === $user->id, 404);   // não permite apagar atribuição de outro usuário pela URL

        try {
            $this->users->removeAssignment($assignment, $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['assignment' => $e->getMessage()]);
        }

        return back()->with('status', 'Atribuição removida.');
    }

    private function group(?string $uuid): ?EndpointGroup
    {
        return $uuid ? EndpointGroup::where('uuid', $uuid)->firstOrFail() : null;
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'roles' => Role::orderBy('id')->get(['key', 'name', 'description']),
            'groups' => EndpointGroup::orderBy('name')->get(['uuid', 'name']),
        ];
    }
}
