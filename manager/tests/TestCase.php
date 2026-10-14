<?php

namespace Tests;

use App\Enums\RoleKey;
use App\Models\EndpointGroup;
use App\Models\User;
use App\Services\Agents\EnrollmentService;
use App\Services\Users\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeAgent;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Marca o TOTP como ativo e confirmado (para passar pelo middleware de MFA obrigatório). */
    protected function withTwoFactor(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['aaaaaaaaaa-bbbbbbbbbb'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    /** Agent simulado já registrado via fluxo real de enrollment. */
    protected function enrolledAgent(?EndpointGroup $group = null): FakeAgent
    {
        $admin = $this->userWithRole(RoleKey::Admin);
        [, $plain] = app(EnrollmentService::class)->issueToken($admin, $group);

        $agent = new FakeAgent($this);
        $agent->enroll($plain)->assertCreated();

        return $agent;
    }

    /** Usuário ativo com um papel (global ou limitado a um grupo). */
    protected function userWithRole(RoleKey $role, ?EndpointGroup $group = null): User
    {
        $user = User::factory()->create();
        app(UserService::class)->assignRole($user, $role, $group);

        return $user;
    }
}
