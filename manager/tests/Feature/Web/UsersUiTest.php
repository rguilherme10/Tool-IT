<?php

namespace Tests\Feature\Web;

use App\Enums\RoleKey;
use App\Models\EndpointGroup;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsersUiTest extends TestCase
{
    private const STRONG = 'Senha-Forte-123';

    private function admin(): User
    {
        return $this->withTwoFactor($this->userWithRole(RoleKey::Admin));
    }

    public function test_only_global_admins_manage_users(): void
    {
        $target = User::factory()->create();

        $scopedAdmin = $this->withTwoFactor($this->userWithRole(RoleKey::Admin, EndpointGroup::factory()->create()));
        $others = [$scopedAdmin, ...array_map(fn ($r) => $this->userWithRole($r), [RoleKey::Operator, RoleKey::Support, RoleKey::Auditor, RoleKey::Viewer])];
        foreach ($others as $user) {
            $this->actingAs($user)->get('/users')->assertForbidden();
            $this->actingAs($user)->get('/users/create')->assertForbidden();
            $this->actingAs($user)->post('/users', ['name' => 'x'])->assertForbidden();
            $this->actingAs($user)->get('/users/'.$target->id.'/edit')->assertForbidden();
            $this->actingAs($user)->post('/users/'.$target->id.'/active', ['active' => 0])->assertForbidden();
            $this->actingAs($user)->post('/users/'.$target->id.'/password', ['password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertForbidden();
            $this->actingAs($user)->post('/users/'.$target->id.'/roles', ['role' => 'admin'])->assertForbidden();
        }
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_create_user_with_initial_role_and_scope_is_audited(): void
    {
        $admin = $this->admin();
        $group = EndpointGroup::factory()->create();

        $this->actingAs($admin)->post('/users', [
            'name' => 'Maria Operadora', 'email' => 'Maria@Empresa.Local', 'password' => self::STRONG, 'password_confirmation' => self::STRONG,
            'role' => 'operator', 'group' => $group->uuid,
        ])->assertRedirect();

        $user = User::where('email', 'maria@empresa.local')->firstOrFail();   // e-mail normalizado
        $this->assertTrue(Hash::check(self::STRONG, $user->password));
        $assignment = RoleAssignment::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($group->id, $assignment->endpoint_group_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'actor_id' => (string) $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role_assigned']);
        $this->assertStringNotContainsString(self::STRONG, json_encode(DB::table('audit_logs')->get()), 'senha nunca vai para a auditoria');
    }

    public function test_user_creation_validation(): void
    {
        $admin = $this->admin();
        $existing = User::factory()->create(['email' => 'dup@empresa.local']);
        $base = ['name' => 'Fulano', 'email' => 'fulano@empresa.local', 'password' => self::STRONG, 'password_confirmation' => self::STRONG];

        $cases = [
            'senha curta' => ['password' => 'Curta1a', 'password_confirmation' => 'Curta1a'],
            'sem maiúscula' => ['password' => 'senha-forte-123', 'password_confirmation' => 'senha-forte-123'],
            'sem número' => ['password' => 'Senha-Forte-Abc', 'password_confirmation' => 'Senha-Forte-Abc'],
            'confirmação diferente' => ['password_confirmation' => 'Outra-Senha-123'],
            'e-mail duplicado' => ['email' => 'DUP@empresa.local'],
            'e-mail inválido' => ['email' => 'nao-e-email'],
            'papel inexistente' => ['role' => 'superuser'],
            'grupo inexistente' => ['role' => 'viewer', 'group' => fake()->uuid()],
            'nome vazio' => ['name' => ''],
        ];

        foreach ($cases as $name => $override) {
            $this->actingAs($admin)->post('/users', $override + $base)->assertSessionHasErrors();
            $this->assertNull(User::where('email', 'fulano@empresa.local')->first(), $name);
        }
        $this->assertTrue($existing->fresh()->is_active);
    }

    public function test_mass_assignment_cannot_activate_or_elevate_through_the_form(): void
    {
        $this->actingAs($this->admin())->post('/users', [
            'name' => 'Intruso', 'email' => 'intruso@empresa.local', 'password' => self::STRONG, 'password_confirmation' => self::STRONG,
            'is_active' => 0, 'role_id' => 1, 'two_factor_secret' => 'x', 'last_login_at' => '2020-01-01',
        ])->assertRedirect();

        $user = User::where('email', 'intruso@empresa.local')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->last_login_at);
        $this->assertSame(0, RoleAssignment::where('user_id', $user->id)->count(), 'sem papel até que um administrador conceda');
    }

    public function test_list_search_and_wildcards(): void
    {
        User::factory()->create(['name' => 'Ana Souza', 'email' => 'ana@empresa.local']);
        User::factory()->create(['name' => 'Bruno Lima', 'email' => 'bruno@empresa.local']);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/users')->assertSee('Ana Souza')->assertSee('Bruno Lima');
        $this->actingAs($admin)->get('/users?q=bruno')->assertSee('Bruno Lima')->assertDontSee('Ana Souza');
        $this->actingAs($admin)->get('/users?q=%25')->assertDontSee('Ana Souza');
    }

    public function test_assign_and_remove_roles_with_scope(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $group = EndpointGroup::factory()->create();

        $this->actingAs($admin)->post("/users/{$user->id}/roles", ['role' => 'support', 'group' => $group->uuid])->assertRedirect();
        $this->actingAs($admin)->post("/users/{$user->id}/roles", ['role' => 'support', 'group' => $group->uuid])->assertRedirect();   // idempotente
        $this->actingAs($admin)->post("/users/{$user->id}/roles", ['role' => 'viewer'])->assertRedirect();
        $this->assertSame(2, RoleAssignment::where('user_id', $user->id)->count());

        $this->actingAs($admin)->post("/users/{$user->id}/roles", ['role' => 'root'])->assertSessionHasErrors('role');
        $this->actingAs($admin)->post("/users/{$user->id}/roles", [])->assertSessionHasErrors('role');

        $assignment = RoleAssignment::where('user_id', $user->id)->whereNotNull('endpoint_group_id')->firstOrFail();
        $this->actingAs($admin)->delete("/users/{$user->id}/roles/{$assignment->id}")->assertRedirect();
        $this->assertDatabaseMissing('role_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.role_removed']);
    }

    public function test_an_assignment_of_another_user_cannot_be_deleted_through_the_url(): void
    {
        $admin = $this->admin();
        $victim = $this->userWithRole(RoleKey::Operator);
        $other = User::factory()->create();
        $assignment = RoleAssignment::where('user_id', $victim->id)->firstOrFail();

        $this->actingAs($admin)->delete("/users/{$other->id}/roles/{$assignment->id}")->assertNotFound();

        $this->assertDatabaseHas('role_assignments', ['id' => $assignment->id]);
    }

    public function test_the_last_global_admin_cannot_be_removed_or_deactivated(): void
    {
        $admin = $this->admin();
        $assignment = RoleAssignment::where('user_id', $admin->id)->firstOrFail();

        $this->actingAs($admin)->delete("/users/{$admin->id}/roles/{$assignment->id}")->assertSessionHasErrors('assignment');
        $this->assertDatabaseHas('role_assignments', ['id' => $assignment->id]);

        $second = $this->userWithRole(RoleKey::Admin);
        // Com um segundo admin, desativar OUTRO é permitido; a si mesmo continua proibido.
        $this->actingAs($admin)->post("/users/{$admin->id}/active", ['active' => 0])->assertSessionHasErrors('active');
        $this->actingAs($admin)->post("/users/{$second->id}/active", ['active' => 0])->assertRedirect();
        $this->assertFalse($second->fresh()->is_active);

        // Agora o primeiro volta a ser o único: nem outro admin (inativo) o salva.
        $this->actingAs($admin)->delete("/users/{$admin->id}/roles/{$assignment->id}")->assertSessionHasErrors('assignment');
    }

    public function test_deactivating_ends_the_users_sessions_and_blocks_access(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->admin();
        $user = $this->userWithRole(RoleKey::Operator);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 'outra', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)->post("/users/{$user->id}/active", ['active' => 0])->assertRedirect();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['id' => 'abc']);
        $this->assertDatabaseHas('sessions', ['id' => 'outra']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deactivated']);

        $this->actingAs($admin)->post("/users/{$user->id}/active", ['active' => 1])->assertRedirect();
        $this->assertTrue($user->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.activated']);
    }

    public function test_admin_reset_changes_the_password_ends_sessions_and_never_logs_it(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->admin();
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'sess', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)->post("/users/{$user->id}/password", ['password' => 'x', 'password_confirmation' => 'x'])->assertSessionHasErrors('password');
        $this->actingAs($admin)->post("/users/{$user->id}/password", ['password' => 'Nova-Senha-Forte-9', 'password_confirmation' => 'Nova-Senha-Forte-9'])->assertRedirect();

        $user->refresh();
        $this->assertTrue(Hash::check('Nova-Senha-Forte-9', $user->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'sess']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset']);
        $this->assertStringNotContainsString('Nova-Senha-Forte-9', json_encode(DB::table('audit_logs')->get()));
    }

    public function test_user_can_change_own_password_with_the_current_one(): void
    {
        $user = $this->userWithRole(RoleKey::Operator);
        $user->forceFill(['password' => 'Senha-Atual-123'])->save();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->put('/user/password', ['current_password' => 'errada', 'password' => 'Outra-Senha-Forte-9', 'password_confirmation' => 'Outra-Senha-Forte-9'])
            ->assertSessionHasErrors('current_password', errorBag: 'updatePassword');
        $this->assertTrue(Hash::check('Senha-Atual-123', $user->fresh()->password));

        $this->actingAs($user)->put('/user/password', ['current_password' => 'Senha-Atual-123', 'password' => 'curta', 'password_confirmation' => 'curta'])
            ->assertSessionHasErrors('password', errorBag: 'updatePassword');

        $this->actingAs($user)->put('/user/password', ['current_password' => 'Senha-Atual-123', 'password' => 'Outra-Senha-Forte-9', 'password_confirmation' => 'Outra-Senha-Forte-9'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Outra-Senha-Forte-9', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed', 'actor_id' => (string) $user->id]);
    }

    public function test_security_page_has_the_password_form(): void
    {
        $this->actingAs($this->userWithRole(RoleKey::Operator))->get('/security')->assertOk()->assertSee('Alterar senha');
    }

    public function test_user_text_is_escaped_in_the_list(): void
    {
        User::factory()->create(['name' => '<img src=x onerror=alert(1)>']);

        $html = $this->actingAs($this->admin())->get('/users')->getContent();

        $this->assertStringNotContainsString('<img src=x', $html);
    }
}
