<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Support\Authorization\AccessResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Administradores (escopo global de usuários) só operam o painel com MFA TOTP confirmado. */
class EnsureTwoFactorForAdmins
{
    public function __construct(private readonly AccessResolver $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null
            && config('toolit.security.require_mfa_for_admins', true)
            && $this->access->canGlobally($user, Permission::UsersManage)
            && ! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('security.show')
                ->with('status', 'Administradores precisam ativar a verificação em duas etapas (TOTP).');
        }

        return $next($request);
    }
}
