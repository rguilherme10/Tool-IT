<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Agents\RevocationService;
use App\Support\Authorization\AccessResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AgentRevocationController extends Controller
{
    public function __invoke(Request $request, string $uuid, AccessResolver $access, RevocationService $revocation): RedirectResponse
    {
        $endpoint = EndpointController::resolveVisible($access, $request, $uuid);
        $this->authorize('revoke', $endpoint);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $count = 0;
        foreach ($endpoint->installations()->where('status', 'active')->get() as $installation) {
            $revocation->revoke($installation, $request->user(), $data['reason']);
            $count++;
        }

        return redirect()->route('endpoints.show', $endpoint->uuid)
            ->with('status', $count > 0 ? 'Agente revogado. Ele não poderá mais se comunicar com o Manager.' : 'Nenhuma instalação ativa para revogar.');
    }
}
