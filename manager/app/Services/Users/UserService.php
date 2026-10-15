<?php

namespace App\Services\Users;

use App\Enums\RoleKey;
use App\Models\EndpointGroup;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Authorization\AccessResolver;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Única porta de escrita para usuários e atribuições de papel (tudo auditado). */
class UserService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessResolver $access,
    ) {}

    public function create(string $name, string $email, string $password, ?User $actor = null): User
    {
        return DB::transaction(function () use ($name, $email, $password, $actor) {
            $user = User::create(['name' => $name, 'email' => mb_strtolower($email), 'password' => $password]);
            $this->audit->log('user.created', $actor, $user, metadata: ['email' => $user->email]);

            return $user;
        });
    }

    /** Redefinição pelo administrador. Encerra as sessões do usuário (quando a sessão está no banco). */
    public function resetPassword(User $user, string $password, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $password, $actor) {
            $user->forceFill(['password' => $password, 'remember_token' => null])->save();
            $this->audit->log('user.password_reset', $actor, $user);
            $this->terminateSessions($user);
        });
    }

    /** Derruba as sessões abertas do usuário. Com sessão em Redis/arquivo não há como listar por usuário: documentado. */
    public function terminateSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    public function assignRole(User $user, RoleKey|Role $role, ?EndpointGroup $group = null, ?User $actor = null): RoleAssignment
    {
        $role = $role instanceof Role ? $role : Role::where('key', $role->value)->firstOrFail();

        return DB::transaction(function () use ($user, $role, $group, $actor) {
            $assignment = RoleAssignment::firstOrCreate(
                ['user_id' => $user->id, 'role_id' => $role->id, 'endpoint_group_id' => $group?->id],
                ['assigned_by' => $actor?->id],
            );

            if ($assignment->wasRecentlyCreated) {
                $this->audit->log('user.role_assigned', $actor, $user, metadata: [
                    'role' => $role->key, 'endpoint_group_id' => $group?->id,
                ]);
            }
            $this->access->flush();

            return $assignment;
        });
    }

    public function removeAssignment(RoleAssignment $assignment, ?User $actor = null): void
    {
        DB::transaction(function () use ($assignment, $actor) {
            $assignment->loadMissing('role', 'user');
            $this->guardLastAdmin($assignment->user, removingAssignment: $assignment);

            $assignment->delete();
            $this->audit->log('user.role_removed', $actor, $assignment->user, metadata: [
                'role' => $assignment->role->key, 'endpoint_group_id' => $assignment->endpoint_group_id,
            ]);
            $this->access->flush();
        });
    }

    public function setActive(User $user, bool $active, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $active, $actor) {
            if (! $active) {
                $this->guardLastAdmin($user);
            }

            $user->forceFill(['is_active' => $active])->save();
            $this->audit->log($active ? 'user.activated' : 'user.deactivated', $actor, $user);
            $this->access->flush();

            if (! $active) {
                $this->terminateSessions($user);
            }
        });
    }

    /** Nunca permitir ficar sem nenhum administrador global ativo. */
    private function guardLastAdmin(User $target, ?RoleAssignment $removingAssignment = null): void
    {
        $isGlobalAdmin = fn (User $u) => $u->roleAssignments()
            ->whereNull('endpoint_group_id')
            ->whereHas('role', fn ($q) => $q->where('key', RoleKey::Admin->value))
            ->when($removingAssignment, fn ($q) => $q->where('id', '!=', $removingAssignment->id))
            ->exists();

        if ($removingAssignment !== null) {
            $wasAdmin = $removingAssignment->isGlobal() && $removingAssignment->role->key === RoleKey::Admin->value;
            if (! $wasAdmin) {
                return;
            }
        } elseif (! $isGlobalAdmin($target)) {
            return;
        }

        $otherAdmins = User::query()
            ->where('is_active', true)
            ->where('id', '!=', $target->id)
            ->whereHas('roleAssignments', fn ($q) => $q->whereNull('endpoint_group_id')
                ->whereHas('role', fn ($r) => $r->where('key', RoleKey::Admin->value)))
            ->count();

        if ($otherAdmins === 0) {
            throw new DomainException('Não é possível remover ou desativar o último administrador global.');
        }
    }
}
