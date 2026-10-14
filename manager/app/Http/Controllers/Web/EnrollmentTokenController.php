<?php

namespace App\Http\Controllers\Web;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\EndpointGroup;
use App\Services\Agents\EnrollmentService;
use App\Support\Authorization\AccessResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EnrollmentTokenController extends Controller
{
    public function __construct(private readonly AccessResolver $access) {}

    public function create(Request $request): View
    {
        abort_unless($this->access->canAnywhere($request->user(), Permission::EnrollmentCreate), 403);

        $groups = EndpointGroup::orderBy('name')->get()
            ->filter(fn (EndpointGroup $g) => $this->access->canForGroup($request->user(), Permission::EnrollmentCreate, $g));

        return view('enrollment.create', [
            'groups' => $groups,
            'canUngrouped' => $this->access->canForGroup($request->user(), Permission::EnrollmentCreate, null),
            'canMulti' => $this->access->canGlobally($request->user(), Permission::EnrollmentCreateMulti),
        ]);
    }

    public function store(Request $request, EnrollmentService $enrollment): RedirectResponse
    {
        $data = $request->validate([
            'group' => ['nullable', 'string', 'exists:endpoint_groups,uuid'],
            'label' => ['nullable', 'string', 'max:120'],
            'ttl_hours' => ['required', 'integer', 'min:1', 'max:72'],
            'max_uses' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        $group = isset($data['group']) ? EndpointGroup::where('uuid', $data['group'])->firstOrFail() : null;

        // AuthorizationException → 403 (escopo e multiuso são verificados no serviço).
        [, $plain] = $enrollment->issueToken(
            $request->user(), $group, (int) $data['max_uses'], (int) $data['ttl_hours'], label: $data['label'] ?? null,
        );

        // Flash: o token em texto puro existe somente na próxima requisição.
        return redirect()->route('enrollment.show')->with('plain_token', $plain);
    }

    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('plain_token')) {
            return redirect()->route('enrollment.create');
        }

        return view('enrollment.show', ['token' => $request->session()->get('plain_token')]);
    }
}
