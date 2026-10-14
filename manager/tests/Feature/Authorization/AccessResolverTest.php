<?php

namespace Tests\Feature\Authorization;

use App\Enums\Permission;
use App\Enums\RoleKey;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Users\UserService;
use App\Support\Authorization\AccessResolver;
use DomainException;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessResolverTest extends TestCase
{
    /** @return iterable<string, array{RoleKey, Permission}> */
    public static function roleMatrix(): iterable
    {
        foreach (RoleKey::cases() as $role) {
            foreach (Permission::cases() as $permission) {
                yield "{$role->value} × {$permission->value}" => [$role, $permission];
            }
        }
    }

    #[DataProvider('roleMatrix')]
    public function test_global_role_matches_permission_matrix(RoleKey $role, Permission $permission): void
    {
        $user = $this->userWithRole($role);
        $expected = in_array($permission, $role->permissions(), true);

        $this->assertSame($expected, $user->can($permission->value), 'Gate (sem endpoint)');

        $endpoint = Endpoint::factory()->create();
        $this->assertSame($expected, $user->can($permission->value, $endpoint), 'Gate (com endpoint)');
    }

    public function test_auditor_and_viewer_cannot_execute_anything(): void
    {
        $endpoint = Endpoint::factory()->create();
        $forbidden = [
            Permission::TasksCreate, Permission::TasksCreateHighRisk, Permission::ScreenView,
            Permission::FilesTransferSend, Permission::FilesTransferFetch, Permission::PowershellExecute,
        ];

        foreach ([RoleKey::Auditor, RoleKey::Viewer] as $role) {
            $user = $this->userWithRole($role);
            foreach ($forbidden as $permission) {
                $this->assertFalse($user->can($permission->value, $endpoint), "{$role->value} não pode {$permission->value}");
            }
        }
    }

    public function test_user_without_assignment_is_denied_everything(): void
    {
        $user = User::factory()->create();
        $endpoint = Endpoint::factory()->create();

        foreach (Permission::cases() as $permission) {
            $this->assertFalse($user->can($permission->value));
            $this->assertFalse($user->can($permission->value, $endpoint));
        }
    }

    public function test_group_scoped_role_is_isolated_between_groups(): void
    {
        $groupA = EndpointGroup::factory()->create();
        $groupB = EndpointGroup::factory()->create();
        $inA = Endpoint::factory()->inGroup($groupA)->create();
        $inB = Endpoint::factory()->inGroup($groupB)->create();
        $ungrouped = Endpoint::factory()->create();

        $operatorA = $this->userWithRole(RoleKey::Operator, $groupA);

        $this->assertTrue($operatorA->can('tasks.create', $inA));
        $this->assertFalse($operatorA->can('tasks.create', $inB), 'outro grupo');
        $this->assertFalse($operatorA->can('tasks.create', $ungrouped), 'endpoint sem grupo exige escopo global');
        // Permissão de menu (qualquer escopo) existe, mas não de sistema:
        $this->assertTrue($operatorA->can('tasks.create'));
        $this->assertFalse($operatorA->can('users.manage'));
    }

    public function test_group_scope_includes_descendant_groups(): void
    {
        $parent = EndpointGroup::factory()->create();
        $child = EndpointGroup::factory()->create(['parent_id' => $parent->id]);
        $grandchild = EndpointGroup::factory()->create(['parent_id' => $child->id]);
        $sibling = EndpointGroup::factory()->create();

        $user = $this->userWithRole(RoleKey::Support, $parent);

        $this->assertTrue($user->can('inventory.view', Endpoint::factory()->inGroup($grandchild)->create()));
        $this->assertFalse($user->can('inventory.view', Endpoint::factory()->inGroup($sibling)->create()));
    }

    public function test_scope_endpoints_filters_listings(): void
    {
        $groupA = EndpointGroup::factory()->create();
        $inA = Endpoint::factory()->inGroup($groupA)->create();
        Endpoint::factory()->inGroup(EndpointGroup::factory()->create())->create();
        Endpoint::factory()->create();

        $resolver = app(AccessResolver::class);

        $scoped = $this->userWithRole(RoleKey::Viewer, $groupA);
        $this->assertSame([$inA->id], $resolver->scopeEndpoints(Endpoint::query(), $scoped, Permission::InventoryView)->pluck('id')->all());

        $global = $this->userWithRole(RoleKey::Viewer);
        $this->assertSame(3, $resolver->scopeEndpoints(Endpoint::query(), $global, Permission::InventoryView)->count());

        $none = User::factory()->create();
        $this->assertSame(0, $resolver->scopeEndpoints(Endpoint::query(), $none, Permission::InventoryView)->count());

        // O papel limitado não concede permissão que o papel não tem:
        $this->assertSame(0, $resolver->scopeEndpoints(Endpoint::query(), $scoped, Permission::TasksCreate)->count());
    }

    public function test_inactive_and_deleted_users_are_denied(): void
    {
        $inactive = $this->userWithRole(RoleKey::Admin);
        app(UserService::class)->assignRole(User::factory()->create(), RoleKey::Admin); // outro admin existe
        app(UserService::class)->setActive($inactive, false);
        $this->assertFalse($inactive->fresh()->can('users.manage'));

        $deleted = $this->userWithRole(RoleKey::Admin);
        $deleted->delete();
        $this->assertFalse($deleted->can('users.manage'));
    }

    public function test_cannot_deactivate_or_demote_the_last_global_admin(): void
    {
        $service = app(UserService::class);
        $admin = $this->userWithRole(RoleKey::Admin);

        try {
            $service->setActive($admin, false);
            $this->fail('deveria bloquear');
        } catch (DomainException) {
            $this->assertTrue($admin->fresh()->is_active);
        }

        $this->expectException(DomainException::class);
        $service->removeAssignment(RoleAssignment::where('user_id', $admin->id)->firstOrFail());
    }

    public function test_second_admin_allows_removing_the_first(): void
    {
        $service = app(UserService::class);
        $first = $this->userWithRole(RoleKey::Admin);
        $this->userWithRole(RoleKey::Admin);

        $service->setActive($first, false);

        $this->assertFalse($first->fresh()->is_active);
    }

    public function test_role_assignment_is_unique_per_scope(): void
    {
        $service = app(UserService::class);
        $user = User::factory()->create();
        $group = EndpointGroup::factory()->create();

        $service->assignRole($user, RoleKey::Viewer);
        $service->assignRole($user, RoleKey::Viewer); // idempotente (global)
        $service->assignRole($user, RoleKey::Viewer, $group);

        $this->assertSame(2, RoleAssignment::where('user_id', $user->id)->count());

        $this->expectException(QueryException::class);
        RoleAssignment::query()->insert(['user_id' => $user->id, 'role_id' => RoleAssignment::first()->role_id, 'endpoint_group_id' => null]);
    }
}
