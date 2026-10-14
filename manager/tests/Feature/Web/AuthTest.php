<?php

namespace Tests\Feature\Web;

use App\Enums\RoleKey;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthTest extends TestCase
{
    private function userWithPassword(RoleKey $role = RoleKey::Viewer, string $password = 'Senha-Forte-123'): User
    {
        $user = $this->userWithRole($role);
        $user->forceFill(['password' => $password])->save();

        return $user;
    }

    public function test_guests_are_redirected_to_login_from_every_panel_route(): void
    {
        foreach (['/', '/endpoints', '/security', '/enrollment/create', '/enrollment/token'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->post('/endpoints/'.fake()->uuid().'/revoke')->assertRedirect('/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Entrar no Toolit');
    }

    public function test_valid_login_is_audited_and_records_last_login(): void
    {
        $user = $this->userWithPassword();

        $this->post('/login', ['email' => $user->email, 'password' => 'Senha-Forte-123'])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => (string) $user->id]);
    }

    public function test_failed_login_is_audited_without_storing_the_email_in_clear(): void
    {
        $user = $this->userWithPassword();

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'errada'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
        $row = DB::table('audit_logs')->where('action', 'auth.login_failed')->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString($user->email, json_encode($row));
        $this->assertStringContainsString(hash('sha256', $user->email), $row->metadata);
    }

    public function test_inactive_user_cannot_log_in_and_message_matches_wrong_password(): void
    {
        $inactive = $this->userWithPassword();
        $inactive->forceFill(['is_active' => false])->save();

        $a = $this->from('/login')->post('/login', ['email' => $inactive->email, 'password' => 'Senha-Forte-123']);
        $b = $this->from('/login')->post('/login', ['email' => 'ninguem@example.com', 'password' => 'x']);

        $this->assertGuest();
        $a->assertSessionHasErrors('email');
        $b->assertSessionHasErrors('email');
        $this->assertSame(
            $a->getSession()->get('errors')->get('email'),
            $b->getSession()->get('errors')->get('email'),
            'conta inativa e senha errada devem ser indistinguíveis',
        );
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->userWithPassword();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'errada']);
        }

        // Mesmo com a senha correta, a 6ª tentativa na janela é barrada.
        $this->post('/login', ['email' => $user->email, 'password' => 'Senha-Forte-123'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_self_registration_and_password_reset_are_not_exposed(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'Senha-Forte-123', 'password_confirmation' => 'Senha-Forte-123'])->assertStatus(404);
        $this->get('/forgot-password')->assertNotFound();
    }

    public function test_admin_must_enable_two_factor_before_using_the_panel(): void
    {
        $admin = $this->userWithRole(RoleKey::Admin);

        $this->actingAs($admin)->get('/')->assertRedirect(route('security.show'));
        $this->actingAs($admin)->get('/endpoints')->assertRedirect(route('security.show'));
        $this->actingAs($admin)->get('/security')->assertOk();

        $this->withTwoFactor($admin);
        $this->actingAs($admin->fresh())->get('/')->assertOk();
    }

    public function test_non_admin_roles_do_not_require_two_factor(): void
    {
        $this->actingAs($this->userWithRole(RoleKey::Operator))->get('/')->assertOk();
    }

    public function test_full_totp_enrollment_flow_and_login_challenge(): void
    {
        $admin = $this->userWithPassword(RoleKey::Admin);
        $google = new Google2FA;

        // Ativar (exige confirmação de senha recente).
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.enable'))->assertRedirect();
        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);

        $this->actingAs($admin)->get('/security')->assertOk()->assertSee('<svg', false)->assertSee('Chave manual');

        // Código errado não confirma; correto confirma.
        $secret = decrypt($admin->two_factor_secret);
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.confirm'), ['code' => $google->getCurrentOtp($secret)])->assertRedirect();

        $this->assertNotNull($admin->fresh()->two_factor_confirmed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.2fa_enabled']);

        // Novo login: senha correta leva ao desafio; só após o código fica autenticado.
        auth()->logout();
        $this->post('/login', ['email' => $admin->email, 'password' => 'Senha-Forte-123'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertSessionHasErrors();
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.2fa_failed']);

        // O código usado na confirmação não pode ser reaproveitado (anti-replay do Fortify): prova disso + código do passo seguinte.
        $this->post(route('two-factor.login.store'), ['code' => $google->getCurrentOtp($secret)])->assertSessionHasErrors();
        $this->assertGuest();
        $this->post(route('two-factor.login.store'), ['code' => $google->oathTotp($secret, $google->getTimestamp() + 1)])->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_deactivated_user_loses_the_session_on_next_request(): void
    {
        $user = $this->userWithRole(RoleKey::Operator);
        $this->actingAs($user)->get('/')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh())->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_password_confirmation_is_required_to_change_two_factor_settings(): void
    {
        $user = $this->userWithRole(RoleKey::Operator);

        $this->actingAs($user)->post(route('two-factor.enable'))->assertRedirect(route('password.confirm'));
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_security_page_does_not_leak_secrets_once_enabled(): void
    {
        $user = $this->withTwoFactor($this->userWithRole(RoleKey::Operator));

        $this->actingAs($user)->get('/security')->assertOk()
            ->assertDontSee('JBSWY3DPEHPK3PXP')   // segredo TOTP só aparece durante a ativação
            ->assertDontSee('<svg', false);
    }

    public function test_the_last_admin_guard_applies_to_ui_driven_deactivation(): void
    {
        $admin = $this->userWithRole(RoleKey::Admin);
        $this->expectException(\DomainException::class);
        app(UserService::class)->setActive($admin, false);
    }
}
