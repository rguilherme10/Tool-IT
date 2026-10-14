<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Endpoint;
use App\Models\User;
use App\Support\Authorization\AccessResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccessResolver::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Password::defaults(function () {
            $rule = Password::min(12)->mixedCase()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        $this->defineGates();
        $this->defineRateLimiters();
    }

    /**
     * Uma Gate por permissão: `$user->can('tasks.create', $endpoint)` / `@can('audit.view')`.
     * Sem endpoint: permissões de sistema exigem escopo global; as demais, qualquer escopo.
     */
    private function defineGates(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, function (User $user, ?Endpoint $endpoint = null) use ($permission) {
                $resolver = app(AccessResolver::class);

                return match (true) {
                    $endpoint !== null => $resolver->can($user, $permission, $endpoint),
                    $permission->isSystemLevel() => $resolver->canGlobally($user, $permission),
                    default => $resolver->canAnywhere($user, $permission),
                };
            });
        }
    }

    private function defineRateLimiters(): void
    {
        // Enrollment: sem assinatura ainda, limita por IP.
        RateLimiter::for('agent-enroll', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));

        // API do Agent já autenticada: por instalação; antes disso, por IP (impede inundação com ids forjados).
        RateLimiter::for('agent-api', fn (Request $r) => Limit::perMinute(
            (int) config('toolit.agent.rate_limit_per_minute', 120)
        )->by((string) ($r->attributes->get('agent_installation_uuid') ?? $r->ip())));

        RateLimiter::for('agent-api-preauth', fn (Request $r) => Limit::perMinute(600)->by($r->ip()));
    }
}
