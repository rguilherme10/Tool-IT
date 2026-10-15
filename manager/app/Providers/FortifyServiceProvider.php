<?php

namespace App\Providers;

use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        // Conta desativada ou excluída nunca autentica (mensagem idêntica à de senha errada).
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', Str::lower((string) $request->input(Fortify::username())))->first();

            if ($user !== null && $user->is_active && Hash::check((string) $request->input('password'), $user->password)) {
                return $user;
            }

            return null;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        $this->auditAuthenticationEvents();
    }

    private function auditAuthenticationEvents(): void
    {
        $audit = fn () => app(AuditLogger::class);

        Event::listen(Login::class, function (Login $event) use ($audit) {
            $event->user->forceFill(['last_login_at' => now()])->save();
            $audit()->log('auth.login', $event->user, $event->user);
        });

        Event::listen(Logout::class, fn (Logout $e) => $e->user && $audit()->log('auth.logout', $e->user, $e->user));

        Event::listen(Failed::class, function (Failed $event) use ($audit) {
            // Só o hash do e-mail tentado: sem PII em claro e sem enumeração via painel de auditoria.
            $audit()->log('auth.login_failed', null, $event->user, metadata: [
                'email_sha256' => hash('sha256', Str::lower((string) ($event->credentials['email'] ?? ''))),
            ]);
        });

        Event::listen(PasswordUpdatedViaController::class, fn ($e) => $audit()->log('auth.password_changed', $e->user, $e->user));
        Event::listen(TwoFactorAuthenticationConfirmed::class, fn ($e) => $audit()->log('auth.2fa_enabled', $e->user, $e->user));
        Event::listen(TwoFactorAuthenticationDisabled::class, fn ($e) => $audit()->log('auth.2fa_disabled', $e->user, $e->user));
        Event::listen(RecoveryCodesGenerated::class, fn ($e) => $audit()->log('auth.2fa_recovery_codes_generated', $e->user, $e->user));
        Event::listen(TwoFactorAuthenticationFailed::class, fn ($e) => $audit()->log('auth.2fa_failed', $e->user, $e->user));
        Event::listen(TwoFactorAuthenticationChallenged::class, fn ($e) => null);
    }
}
