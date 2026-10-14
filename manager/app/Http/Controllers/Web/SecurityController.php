<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Tela de MFA (TOTP). As ações usam as rotas do Fortify (exigem confirmação de senha). */
class SecurityController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;

        return view('security.show', [
            'enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'pending' => $pending,
            'qrSvg' => $pending ? $user->twoFactorQrCodeSvg() : null,
            'secret' => $pending ? decrypt($user->two_factor_secret) : null,
            'recoveryCodes' => $user->hasEnabledTwoFactorAuthentication() ? $user->recoveryCodes() : [],
        ]);
    }
}
