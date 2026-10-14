<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Endpoint;
use App\Models\User;
use App\Support\Authorization\AccessResolver;

/** Toda decisão sobre um Endpoint passa por aqui → AccessResolver (escopo por grupo). */
class EndpointPolicy
{
    public function __construct(private readonly AccessResolver $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canAnywhere($user, Permission::InventoryView);
    }

    public function view(User $user, Endpoint $endpoint): bool
    {
        return $this->access->can($user, Permission::InventoryView, $endpoint);
    }

    public function update(User $user, Endpoint $endpoint): bool
    {
        return $this->access->can($user, Permission::EndpointsManage, $endpoint);
    }

    public function delete(User $user, Endpoint $endpoint): bool
    {
        return $this->access->can($user, Permission::EndpointsManage, $endpoint);
    }

    public function revoke(User $user, Endpoint $endpoint): bool
    {
        return $this->access->can($user, Permission::EndpointsRevoke, $endpoint);
    }
}
