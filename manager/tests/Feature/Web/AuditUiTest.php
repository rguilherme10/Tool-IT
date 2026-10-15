<?php

namespace Tests\Feature\Web;

use App\Enums\RoleKey;
use App\Models\EndpointGroup;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditUiTest extends TestCase
{
    private function auditor(): User
    {
        return $this->userWithRole(RoleKey::Auditor);
    }

    public function test_only_global_audit_viewers_can_read_the_trail(): void
    {
        foreach ([RoleKey::Operator, RoleKey::Support, RoleKey::Viewer] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->get('/audit')->assertForbidden();
            $this->actingAs($user)->post('/audit/verify')->assertForbidden();
        }
        $scopedAuditor = $this->userWithRole(RoleKey::Auditor, EndpointGroup::factory()->create());
        $this->actingAs($scopedAuditor)->get('/audit')->assertForbidden();

        $this->actingAs($this->auditor())->get('/audit')->assertOk();
        $this->actingAs($this->withTwoFactor($this->userWithRole(RoleKey::Admin)))->get('/audit')->assertOk();
    }

    public function test_rows_show_who_what_and_which_computer(): void
    {
        $operator = User::factory()->create(['name' => 'Carla Operadora']);
        $endpoint = $this->activeEndpoint(attributes: ['hostname' => 'PC-AUDITADO']);
        app(AuditLogger::class)->log('endpoint.disabled', $operator, $endpoint, $endpoint->id, ['motivo' => 'teste']);

        $this->actingAs($this->auditor())->get('/audit')->assertOk()
            ->assertSee('endpoint.disabled')->assertSee('Carla Operadora')->assertSee('PC-AUDITADO')->assertSee('motivo');
    }

    /** Ações das linhas listadas (o seletor de filtro lista todas as ações, então não dá para usar assertSee). */
    private function listed(User $viewer, string $query = ''): array
    {
        $response = $this->actingAs($viewer)->get('/audit'.$query)->assertOk();

        return $response->viewData('rows')->getCollection()->pluck('action')->all();
    }

    public function test_filters_by_action_actor_type_user_computer_and_dates(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Auditada', 'email' => 'ana@empresa.local']);
        $bruno = User::factory()->create(['name' => 'Bruno Outro']);
        $endpoint = $this->activeEndpoint(attributes: ['hostname' => 'PC-FILTRO']);
        $log = app(AuditLogger::class);
        $log->log('test.acao_ana', $ana, null, $endpoint->id);
        $log->log('test.acao_bruno', $bruno);
        $log->log('test.acao_sistema');
        $viewer = $this->auditor();

        $this->assertSame(['test.acao_ana'], $this->listed($viewer, '?action=test.acao_ana'));
        $this->assertSame(['test.acao_sistema'], $this->listed($viewer, '?actor_type=system&action=test.acao_sistema'));
        $this->assertNotContains('test.acao_ana', $this->listed($viewer, '?actor_type=system'));
        $this->assertSame(['test.acao_ana'], $this->listed($viewer, '?user=ana@empresa'));
        $this->assertSame(['test.acao_ana'], $this->listed($viewer, '?q=FILTRO'));
        $this->assertSame([], $this->listed($viewer, '?from='.now()->addDay()->toDateString()));
        $this->assertSame([], $this->listed($viewer, '?to='.now()->subDays(2)->toDateString()));
        $this->assertContains('test.acao_ana', $this->listed($viewer, '?from='.now()->toDateString().'&to='.now()->toDateString()));
        $this->assertSame([], $this->listed($viewer, '?user=%25'));                                           // curinga literal
        $this->actingAs($viewer)->get('/audit?actor_type=hacker')->assertSessionHasErrors('actor_type');
        $this->actingAs($viewer)->get('/audit?from=nao-e-data')->assertSessionHasErrors('from');
    }

    public function test_metadata_is_pretty_printed_and_escaped(): void
    {
        app(AuditLogger::class)->log('test.xss', null, null, null, ['campo' => '<script>alert(1)</script>']);

        $html = $this->actingAs($this->auditor())->get('/audit?action=test.xss')->getContent();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_pagination_shows_the_newest_first(): void
    {
        $log = app(AuditLogger::class);
        for ($i = 1; $i <= 60; $i++) {
            $log->log('test.item_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }
        $viewer = $this->auditor();

        $first = $this->listed($viewer);                       // inclui a atribuição de papel do próprio auditor, que é a mais nova
        $this->assertCount(50, $first);
        $this->assertSame('user.role_assigned', $first[0]);
        $this->assertSame('test.item_060', $first[1]);
        $this->assertNotContains('test.item_001', $first);
        $this->assertContains('test.item_001', $this->listed($viewer, '?page=2'));
    }

    public function test_verify_reports_an_intact_chain_and_records_the_check(): void
    {
        app(AuditLogger::class)->log('test.um');
        app(AuditLogger::class)->log('test.dois');
        $auditor = $this->auditor();

        $this->actingAs($auditor)->post('/audit/verify')->assertRedirect('/audit')->assertSessionHas('status');
        $this->assertStringContainsString('Cadeia íntegra', session('status'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'audit.integrity_checked', 'actor_id' => (string) $auditor->id]);
    }

    public function test_verify_detects_tampering_and_shows_a_prominent_warning(): void
    {
        app(AuditLogger::class)->log('test.original');
        app(AuditLogger::class)->log('test.depois');
        DB::unprepared('DROP TRIGGER audit_logs_no_update');           // atacante com acesso direto ao banco
        DB::table('audit_logs')->where('action', 'test.original')->update(['action' => 'test.adulterado']);
        $auditor = $this->auditor();

        $this->actingAs($auditor)->post('/audit/verify')->assertRedirect('/audit')->assertSessionHas('integrity_error');

        $this->followingRedirects()->actingAs($auditor)->post('/audit/verify')->assertSee('Integridade comprometida')->assertSee('Hash inválido');
    }

    public function test_the_command_and_the_screen_share_the_same_verifier(): void
    {
        app(AuditLogger::class)->log('test.um');

        $this->artisan('audit:verify')->expectsOutputToContain('Cadeia íntegra')->assertSuccessful();
    }
}
