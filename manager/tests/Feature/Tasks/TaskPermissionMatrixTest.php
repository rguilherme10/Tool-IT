<?php

namespace Tests\Feature\Tasks;

use App\Enums\RoleKey;
use App\Enums\TaskState;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\RemoteTask;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Matriz papel × tipo × escopo (docs/permissions-matrix.md), aplicada no serviço — não só na UI. */
class TaskPermissionMatrixTest extends TestCase
{
    private const TYPES = [
        'process.list' => [],
        'service.restart' => ['name' => 'Spooler'],          // na allowlist: médio risco
        'process.kill' => ['pid' => 4321, 'name' => 'notepad.exe'],
        'agent.restart' => [],
    ];

    /** Quem pode CRIAR cada tipo (com escopo válido). */
    private const CAN_CREATE = [
        'admin' => ['process.list', 'service.restart', 'process.kill', 'agent.restart'],
        'operator' => ['process.list', 'service.restart', 'process.kill', 'agent.restart'],
        'support' => ['process.list'],
        'auditor' => [],
        'viewer' => [],
    ];

    private const SCOPES = ['global', 'same_group', 'other_group'];

    /**
     * Cria o usuário com o papel no escopo e devolve [usuário, endpoint].
     *
     * @return array{0: User, 1: Endpoint}
     */
    private function actorAndEndpoint(RoleKey $role, string $scope): array
    {
        $groupA = EndpointGroup::factory()->create();
        $groupB = EndpointGroup::factory()->create();

        $user = $this->userWithRole($role, $scope === 'global' ? null : $groupA);
        $endpoint = $this->activeEndpoint($scope === 'other_group' ? $groupB : $groupA);

        return [$user, $endpoint];
    }

    public static function createMatrix(): array
    {
        $cases = [];
        foreach (RoleKey::cases() as $role) {
            foreach (array_keys(self::TYPES) as $type) {
                foreach (self::SCOPES as $scope) {
                    $allowed = in_array($type, self::CAN_CREATE[$role->value], true) && $scope !== 'other_group';
                    $cases["{$role->value} {$type} {$scope}"] = [$role, $type, $scope, $allowed];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('createMatrix')]
    public function test_create_matrix(RoleKey $role, string $type, string $scope, bool $allowed): void
    {
        [$user, $endpoint] = $this->actorAndEndpoint($role, $scope);

        try {
            $task = app(TaskService::class)->create($user, $endpoint, $type, self::TYPES[$type]);
            $this->assertTrue($allowed, "{$role->value} não deveria criar {$type} ({$scope})");
            $this->assertSame($endpoint->id, $task->endpoint_id);
        } catch (AuthorizationException) {
            $this->assertFalse($allowed, "{$role->value} deveria criar {$type} ({$scope})");
            $this->assertSame(0, RemoteTask::count());
        }
    }

    public static function roleScopeMatrix(): array
    {
        $cases = [];
        foreach (RoleKey::cases() as $role) {
            foreach (self::SCOPES as $scope) {
                $cases["{$role->value} {$scope}"] = [$role, $scope];
            }
        }

        return $cases;
    }

    #[DataProvider('roleScopeMatrix')]
    public function test_only_admin_in_scope_approves(RoleKey $role, string $scope): void
    {
        [$user, $endpoint] = $this->actorAndEndpoint($role, $scope);
        $creator = $this->userWithRole(RoleKey::Admin);
        $service = app(TaskService::class);
        $task = $service->create($creator, $endpoint, 'process.kill', self::TYPES['process.kill']);
        $allowed = $role === RoleKey::Admin && $scope !== 'other_group';

        try {
            $service->approve($user, $task);
            $this->assertTrue($allowed, "{$role->value} não deveria aprovar ({$scope})");
            $this->assertSame(TaskState::Available, $task->fresh()->state);
        } catch (AuthorizationException) {
            $this->assertFalse($allowed, "{$role->value} deveria aprovar ({$scope})");
            $this->assertSame(TaskState::PendingApproval, $task->fresh()->state);
        }

        // Rejeitar segue as mesmas guardas.
        if (! $allowed) {
            $this->expectException(AuthorizationException::class);
            $service->reject($user, $task->fresh(), 'não');
        }
    }

    #[DataProvider('roleScopeMatrix')]
    public function test_cancel_requires_permission_in_scope(RoleKey $role, string $scope): void
    {
        [$user, $endpoint] = $this->actorAndEndpoint($role, $scope);
        $service = app(TaskService::class);
        $task = $service->create($this->userWithRole(RoleKey::Admin), $endpoint, 'process.list', []);
        $allowed = in_array($role, [RoleKey::Admin, RoleKey::Operator, RoleKey::Support], true) && $scope !== 'other_group';

        try {
            $service->cancel($user, $task);
            $this->assertTrue($allowed, "{$role->value} não deveria cancelar ({$scope})");
            $this->assertSame(TaskState::Cancelled, $task->fresh()->state);
        } catch (AuthorizationException) {
            $this->assertFalse($allowed, "{$role->value} deveria cancelar ({$scope})");
            $this->assertSame(TaskState::Available, $task->fresh()->state);
        }
    }

    public function test_operator_creates_kill_but_cannot_approve_even_others_task(): void
    {
        $endpoint = $this->activeEndpoint();
        $operator = $this->userWithRole(RoleKey::Operator);
        $other = $this->userWithRole(RoleKey::Operator);
        $service = app(TaskService::class);

        $task = $service->create($operator, $endpoint, 'process.kill', self::TYPES['process.kill']);
        $this->assertSame(TaskState::PendingApproval, $task->state);

        $this->expectException(AuthorizationException::class);
        $service->approve($other, $task);
    }
}
