<?php

namespace App\Support\Authorization;

use App\Enums\Permission;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decisão central de autorização (RBAC com escopo por grupo de endpoints).
 *
 * Negado por padrão. Atribuição global (endpoint_group_id NULL) vale para todos os endpoints;
 * atribuição por grupo vale para o grupo e seus descendentes. Endpoint sem grupo só é
 * alcançável por atribuição global. Usuário inativo/excluído nunca tem acesso.
 *
 * Registrado como singleton por requisição: o cache em memória evita N consultas.
 */
class AccessResolver
{
    /** @var array<int, list<array{group: ?int, permissions: array<string, true>}>> */
    private array $cache = [];

    /** @var array<string, list<int>> */
    private array $expanded = [];

    /** Permissão de nível de sistema (usuários, auditoria, configurações): exige atribuição global. */
    public function canGlobally(User $user, Permission $permission): bool
    {
        foreach ($this->assignments($user) as $a) {
            if ($a['group'] === null && isset($a['permissions'][$permission->value])) {
                return true;
            }
        }

        return false;
    }

    /** O usuário tem a permissão em ALGUM escopo? (ex.: exibir item de menu, listar endpoints) */
    public function canAnywhere(User $user, Permission $permission): bool
    {
        foreach ($this->assignments($user) as $a) {
            if (isset($a['permissions'][$permission->value])) {
                return true;
            }
        }

        return false;
    }

    /** A permissão vale para este endpoint específico? */
    public function can(User $user, Permission $permission, Endpoint $endpoint): bool
    {
        $scope = $this->scopeFor($user, $permission);

        if ($scope === true) {
            return true;
        }

        return $endpoint->endpoint_group_id !== null
            && in_array((int) $endpoint->endpoint_group_id, $scope, true);
    }

    /** A permissão vale para este grupo (ou, com $group nulo, apenas em escopo global)? */
    public function canForGroup(User $user, Permission $permission, ?EndpointGroup $group): bool
    {
        $scope = $this->scopeFor($user, $permission);

        if ($scope === true) {
            return true;
        }

        return $group !== null && in_array((int) $group->id, $scope, true);
    }

    /**
     * Endpoints visíveis ao usuário para a permissão. Toda listagem DEVE passar por aqui (anti-BOLA).
     *
     * @param  Builder<Endpoint>  $query
     * @return Builder<Endpoint>
     */
    public function scopeEndpoints(Builder $query, User $user, Permission $permission): Builder
    {
        $scope = $this->scopeFor($user, $permission);

        if ($scope === true) {
            return $query;
        }

        return $scope === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('endpoint_group_id', $scope);
    }

    /** @return true|list<int> true = global; lista = grupos (já expandidos) */
    private function scopeFor(User $user, Permission $permission): true|array
    {
        $groups = [];

        foreach ($this->assignments($user) as $a) {
            if (! isset($a['permissions'][$permission->value])) {
                continue;
            }
            if ($a['group'] === null) {
                return true;
            }
            $groups[] = $a['group'];
        }

        if ($groups === []) {
            return [];
        }

        sort($groups);
        $key = implode(',', $groups);

        return $this->expanded[$key] ??= EndpointGroup::expandWithDescendants($groups);
    }

    /** @return list<array{group: ?int, permissions: array<string, true>}> */
    private function assignments(User $user): array
    {
        if (! $user->is_active || $user->trashed()) {
            return [];
        }

        return $this->cache[$user->getKey()] ??= RoleAssignment::query()
            ->where('user_id', $user->getKey())
            ->with('role.permissions:id,key')
            ->get()
            ->map(fn (RoleAssignment $a) => [
                'group' => $a->endpoint_group_id === null ? null : (int) $a->endpoint_group_id,
                'permissions' => $a->role->permissions->pluck('key')->flip()->map(fn () => true)->all(),
            ])
            ->all();
    }

    /** Invalida o cache (após alterar atribuições na mesma requisição/teste). */
    public function flush(): void
    {
        $this->cache = [];
        $this->expanded = [];
    }
}
