<?php

namespace Tests\Feature\Web;

use App\Enums\RoleKey;
use App\Models\AgentInstallation;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EndpointIsolationTest extends TestCase
{
    private EndpointGroup $groupA;

    private EndpointGroup $groupB;

    private Endpoint $inA;

    private Endpoint $inB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->groupA = EndpointGroup::factory()->create(['name' => 'Financeiro']);
        $this->groupB = EndpointGroup::factory()->create(['name' => 'Diretoria']);
        $this->inA = Endpoint::factory()->inGroup($this->groupA)->create(['hostname' => 'PC-A-001']);
        $this->inB = Endpoint::factory()->inGroup($this->groupB)->create(['hostname' => 'PC-B-001']);
    }

    public function test_listing_shows_only_endpoints_in_the_users_scope(): void
    {
        $operatorA = $this->userWithRole(RoleKey::Operator, $this->groupA);

        $this->actingAs($operatorA)->get('/endpoints')->assertOk()
            ->assertSee('PC-A-001')->assertDontSee('PC-B-001');

        // Filtrar por grupo alheio não revela nada:
        $this->actingAs($operatorA)->get('/endpoints?group='.$this->groupB->uuid)->assertOk()
            ->assertDontSee('PC-B-001');
    }

    public function test_global_role_sees_everything(): void
    {
        $this->actingAs($this->userWithRole(RoleKey::Viewer))->get('/endpoints')->assertOk()
            ->assertSee('PC-A-001')->assertSee('PC-B-001');
    }

    public function test_detail_of_another_groups_endpoint_is_not_found(): void
    {
        $operatorA = $this->userWithRole(RoleKey::Operator, $this->groupA);

        $this->actingAs($operatorA)->get('/endpoints/'.$this->inA->uuid)->assertOk()->assertSee('PC-A-001');
        $this->actingAs($operatorA)->get('/endpoints/'.$this->inB->uuid)->assertNotFound();
        $this->actingAs($operatorA)->get('/endpoints/'.fake()->uuid())->assertNotFound();
        $this->actingAs($operatorA)->get('/endpoints/1')->assertNotFound(); // ids sequenciais não funcionam
    }

    public function test_user_without_any_role_is_denied(): void
    {
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get('/endpoints')->assertForbidden();
        $this->actingAs($nobody)->get('/endpoints/'.$this->inA->uuid)->assertNotFound();
        $this->actingAs($nobody)->get('/enrollment/create')->assertForbidden();
    }

    public function test_dashboard_counts_only_visible_endpoints(): void
    {
        $this->inA->update(['last_seen_at' => now()]);
        $this->inB->update(['last_seen_at' => now()]);

        $scoped = $this->actingAs($this->userWithRole(RoleKey::Viewer, $this->groupA))->get('/');
        $scoped->assertOk()->assertViewHas('total', 1)->assertViewHas('online', 1);

        $this->actingAs($this->userWithRole(RoleKey::Viewer))->get('/')->assertViewHas('total', 2);
    }

    public function test_hostname_filter_treats_like_wildcards_literally(): void
    {
        Endpoint::factory()->create(['hostname' => 'PC_100%']);
        $viewer = $this->userWithRole(RoleKey::Viewer);

        $this->actingAs($viewer)->get('/endpoints?hostname=%25')->assertOk()
            ->assertSee('PC_100%')->assertDontSee('PC-A-001');
    }

    public function test_status_filters_use_last_contact(): void
    {
        $this->inA->update(['last_seen_at' => now()]);
        $this->inB->update(['last_seen_at' => now()->subHour()]);
        $viewer = $this->userWithRole(RoleKey::Viewer);

        $this->actingAs($viewer)->get('/endpoints?status=online')->assertSee('PC-A-001')->assertDontSee('PC-B-001');
        $this->actingAs($viewer)->get('/endpoints?status=offline')->assertSee('PC-B-001')->assertDontSee('PC-A-001');
        $this->actingAs($viewer)->get('/endpoints?status=invalido')->assertSessionHasErrors('status');
    }

    public function test_data_coming_from_the_endpoint_is_escaped(): void
    {
        $payload = '<script>alert(1)</script>';
        $evil = Endpoint::factory()->create([
            'hostname' => $payload, 'current_user' => '"><img src=x onerror=alert(2)>',
            'os_name' => $payload, 'ip_addresses' => ['10.0.0.1'],
        ]);
        $viewer = $this->userWithRole(RoleKey::Viewer);

        foreach (['/endpoints', '/endpoints/'.$evil->uuid] as $path) {
            $html = $this->actingAs($viewer)->get($path)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert', $html, $path);
            $this->assertStringNotContainsString('<img src=x', $html, $path);
            $this->assertStringContainsString('&lt;script&gt;', $html, $path);
        }
    }

    public function test_no_view_prints_unescaped_output_except_the_whitelisted_qr_code(): void
    {
        $offenders = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (preg_match_all('/\{!!(.+?)!!\}/s', $file->getContents(), $m)) {
                foreach ($m[1] as $expr) {
                    if (! str_contains($expr, '$qrSvg')) {
                        $offenders[] = $file->getRelativePathname().': '.trim($expr);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, '{!! !!} proibido para dados que podem vir do endpoint');
    }

    public function test_revocation_requires_scope_and_permission(): void
    {
        $agent = $this->enrolledAgent($this->groupA);
        $endpoint = AgentInstallation::firstOrFail()->endpoint;
        $url = '/endpoints/'.$endpoint->uuid.'/revoke';

        // Suporte do mesmo grupo vê, mas não tem permissão de revogar.
        $this->actingAs($this->userWithRole(RoleKey::Support, $this->groupA))->post($url, ['reason' => 'teste'])->assertForbidden();
        // Operador de outro grupo nem enxerga.
        $this->actingAs($this->userWithRole(RoleKey::Operator, $this->groupB))->post($url, ['reason' => 'teste'])->assertNotFound();
        // Auditor global enxerga, mas não revoga.
        $this->actingAs($this->userWithRole(RoleKey::Auditor))->post($url, ['reason' => 'teste'])->assertForbidden();
        $agent->post('/api/agent/v1/heartbeat')->assertOk();

        $operator = $this->userWithRole(RoleKey::Operator, $this->groupA);
        $this->actingAs($operator)->post($url, [])->assertSessionHasErrors('reason');
        $this->actingAs($operator)->post($url, ['reason' => 'notebook roubado'])
            ->assertRedirect(route('endpoints.show', $endpoint->uuid));

        $agent->post('/api/agent/v1/heartbeat')->assertForbidden()->assertJsonPath('error.code', 'agent.revoked');
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.revoked', 'actor_id' => (string) $operator->id]);
    }

    public function test_revoke_button_is_only_rendered_for_those_who_can_revoke(): void
    {
        $this->enrolledAgent($this->groupA);
        $endpoint = AgentInstallation::firstOrFail()->endpoint;

        $this->actingAs($this->userWithRole(RoleKey::Operator, $this->groupA))->get('/endpoints/'.$endpoint->uuid)
            ->assertSee('Revogar agente');
        $this->actingAs($this->userWithRole(RoleKey::Support, $this->groupA))->get('/endpoints/'.$endpoint->uuid)
            ->assertOk()->assertDontSee('Revogar agente');
    }

    public function test_audit_panel_requires_global_audit_permission(): void
    {
        $this->enrolledAgent($this->groupA);
        $endpoint = AgentInstallation::firstOrFail()->endpoint;

        $this->actingAs($this->userWithRole(RoleKey::Auditor))->get('/endpoints/'.$endpoint->uuid)
            ->assertOk()->assertSee('Auditoria recente')->assertSee('agent.enrolled');
        $this->actingAs($this->userWithRole(RoleKey::Operator, $this->groupA))->get('/endpoints/'.$endpoint->uuid)
            ->assertOk()->assertDontSee('Auditoria recente');
    }

    public function test_enrollment_token_flow_shows_the_token_exactly_once(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator, $this->groupA);

        $this->actingAs($operator)->get('/enrollment/create')->assertOk()->assertSee('Financeiro')->assertDontSee('Diretoria');

        $response = $this->actingAs($operator)->post('/enrollment', [
            'group' => $this->groupA->uuid, 'ttl_hours' => 6, 'max_uses' => 1, 'label' => 'Sala 3',
        ])->assertRedirect(route('enrollment.show'));

        $page = $this->actingAs($operator)->followRedirects($response)->assertOk();
        $this->assertMatchesRegularExpression('/tk_[A-Za-z0-9_-]{40,}/', $page->getContent());

        // Segunda visita: sem token.
        $this->actingAs($operator)->get('/enrollment/token')->assertRedirect(route('enrollment.create'));
    }

    public function test_enrollment_token_scope_and_multi_use_are_enforced_by_the_server(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator, $this->groupA);

        $this->actingAs($operator)->post('/enrollment', ['group' => $this->groupB->uuid, 'ttl_hours' => 1, 'max_uses' => 1])->assertForbidden();
        $this->actingAs($operator)->post('/enrollment', ['ttl_hours' => 1, 'max_uses' => 1])->assertForbidden();             // sem grupo
        $this->actingAs($operator)->post('/enrollment', ['group' => $this->groupA->uuid, 'ttl_hours' => 1, 'max_uses' => 50])->assertForbidden(); // multiuso
        $this->actingAs($operator)->post('/enrollment', ['group' => $this->groupA->uuid, 'ttl_hours' => 999, 'max_uses' => 1])->assertSessionHasErrors('ttl_hours');

        $this->actingAs($this->userWithRole(RoleKey::Viewer))->post('/enrollment', ['ttl_hours' => 1, 'max_uses' => 1])->assertForbidden();
    }

    public function test_state_changing_forms_carry_a_csrf_token(): void
    {
        // O framework desliga a verificação de CSRF em testes; garantimos ao menos que todo form POST envia o token.
        $this->enrolledAgent($this->groupA);
        $endpoint = AgentInstallation::firstOrFail()->endpoint;
        $operator = $this->userWithRole(RoleKey::Operator, $this->groupA);

        foreach (['/login', '/enrollment/create', '/endpoints/'.$endpoint->uuid, '/security'] as $path) {
            $html = $this->actingAs($operator)->get($path)->getContent();
            preg_match_all('/<form[^>]+method="POST"[^>]*>(.*?)<\/form>/is', $html, $forms);
            foreach ($forms[1] as $form) {
                $this->assertStringContainsString('name="_token"', $form, "form sem CSRF em {$path}");
            }
        }
    }
}
