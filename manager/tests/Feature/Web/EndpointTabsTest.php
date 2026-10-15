<?php

namespace Tests\Feature\Web;

use App\Enums\RoleKey;
use App\Models\AgentInstallation;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use Tests\Support\FakeAgent;
use Tests\Support\Payloads;
use Tests\TestCase;

class EndpointTabsTest extends TestCase
{
    private FakeAgent $agent;

    private Endpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->enrolledAgent();
        $this->endpoint = AgentInstallation::firstOrFail()->endpoint;
        $this->endpoint->update(['current_user' => 'EMPRESA\\maria', 'ip_addresses' => ['10.9.8.7']]);
    }

    private function url(string $tab, array $query = []): string
    {
        return route('endpoints.show', ['uuid' => $this->endpoint->uuid, 'tab' => $tab] + $query);
    }

    private function sendInventory(array $sections = []): void
    {
        $this->agent->post('/api/agent/v1/inventory', Payloads::inventory($sections))->assertStatus(202);
    }

    public function test_full_access_roles_see_every_tab(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator);

        $this->actingAs($operator)->get($this->url('details'))->assertOk()
            ->assertSeeInOrder(['Detalhes', 'Hardware', 'Software', 'Rede', 'Métricas']);
    }

    public function test_viewer_profile_cannot_see_software_network_or_personal_data(): void
    {
        $this->sendInventory();
        $viewer = $this->userWithRole(RoleKey::Viewer);

        $details = $this->actingAs($viewer)->get($this->url('details'))->assertOk();
        $details->assertDontSee('EMPRESA\\maria', false)->assertDontSee('10.9.8.7')->assertDontSee('Usuário atual');
        $details->assertSee('Hardware')->assertSee('Métricas')->assertDontSee('>Software<', false)->assertDontSee('>Rede<', false);

        $this->actingAs($viewer)->get($this->url('software'))->assertForbidden();
        $this->actingAs($viewer)->get($this->url('network'))->assertForbidden();
        $this->actingAs($viewer)->get($this->url('hardware'))->assertOk()->assertSee('Intel Core i7-1355U');
        $this->actingAs($viewer)->get($this->url('metrics'))->assertOk();
    }

    public function test_operator_sees_logged_user_and_ips_in_details(): void
    {
        $this->actingAs($this->userWithRole(RoleKey::Operator))->get($this->url('details'))
            ->assertSee('EMPRESA\\maria', false)->assertSee('10.9.8.7');
    }

    public function test_unknown_tab_falls_back_to_details(): void
    {
        $this->actingAs($this->userWithRole(RoleKey::Operator))->get($this->url('inexistente'))->assertOk()->assertSee('Identificação');
    }

    public function test_tabs_do_not_leak_across_groups(): void
    {
        $other = EndpointGroup::factory()->create();
        $operatorOther = $this->userWithRole(RoleKey::Operator, $other);

        foreach (['details', 'hardware', 'software', 'network', 'metrics'] as $tab) {
            $this->actingAs($operatorOther)->get($this->url($tab))->assertNotFound();
        }
    }

    public function test_hardware_tab_empty_state_and_real_data(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator);

        $this->actingAs($operator)->get($this->url('hardware'))->assertOk()->assertSee('Nenhum inventário recebido');

        $this->sendInventory();
        $this->actingAs($operator)->get($this->url('hardware'))->assertOk()
            ->assertSee('Intel Core i7-1355U')->assertSee('16,0 GB')->assertDontSee('Nenhum inventário recebido')
            ->assertSee('HP LaserJet')->assertSee('DELL U2723QE');
    }

    public function test_hardware_tab_distinguishes_unavailable_from_detected_components(): void
    {
        $this->sendInventory();
        $this->sendInventory([
            'hardware' => ['status' => 'unavailable', 'reason' => 'access_denied'],
            'peripherals' => ['status' => 'ok', 'data' => ['monitors' => [], 'printers' => [], 'not_detected' => [['component' => 'printers', 'reason' => 'access_denied']]]],
        ]);

        $html = $this->actingAs($this->userWithRole(RoleKey::Operator))->get($this->url('hardware'))->assertOk()->getContent();

        $this->assertStringContainsString('Indisponível (access_denied)', $html);
        $this->assertStringContainsString('Intel Core i7-1355U', $html, 'último dado bom continua exibido');
        $this->assertStringContainsString('Nenhum monitor detectado', $html);
    }

    public function test_software_tab_search_state_filter_and_escaping(): void
    {
        $this->sendInventory(['software' => ['status' => 'ok', 'data' => ['items' => [
            ['name' => 'Google Chrome', 'version' => '130', 'source' => 'HKLM64', 'arch' => 'x64'],
            ['name' => '<script>alert(1)</script>', 'version' => '1', 'source' => 'HKLM32', 'arch' => 'x86'],
            ['name' => '7-Zip', 'version' => '24', 'source' => 'HKLM64', 'arch' => 'x64'],
        ]]]]);
        $this->sendInventory(['software' => ['status' => 'ok', 'data' => ['items' => [
            ['name' => 'Google Chrome', 'version' => '130', 'source' => 'HKLM64', 'arch' => 'x64'],
            ['name' => '<script>alert(1)</script>', 'version' => '1', 'source' => 'HKLM32', 'arch' => 'x86'],
        ]]]]);
        $operator = $this->userWithRole(RoleKey::Operator);

        $all = $this->actingAs($operator)->get($this->url('software'))->assertOk();
        $all->assertSee('Google Chrome')->assertDontSee('7-Zip')->assertDontSee('<script>alert', false)->assertSee('&lt;script&gt;', false);

        $this->actingAs($operator)->get($this->url('software', ['state' => 'removed']))->assertSee('7-Zip')->assertSee('removido em')->assertDontSee('Google Chrome');
        $this->actingAs($operator)->get($this->url('software', ['q' => 'chro', 'state' => 'all']))->assertSee('Google Chrome')->assertDontSee('7-Zip');
        $this->actingAs($operator)->get($this->url('software', ['q' => '%']))->assertSee('Nenhum software encontrado');
        $this->actingAs($operator)->get($this->url('software', ['state' => 'hack']))->assertSessionHasErrors('state');
    }

    public function test_software_tab_paginates(): void
    {
        $items = array_map(fn ($i) => ['name' => sprintf('App %03d', $i), 'version' => '1', 'source' => 'HKLM64'], range(1, 120));
        $this->sendInventory(['software' => ['status' => 'ok', 'data' => ['items' => $items]]]);
        $operator = $this->userWithRole(RoleKey::Operator);

        $this->actingAs($operator)->get($this->url('software'))->assertSee('App 050')->assertDontSee('App 051');
        $this->actingAs($operator)->get($this->url('software', ['page' => 2]))->assertSee('App 051')->assertDontSee('App 050');
    }

    public function test_network_tab_lists_interfaces(): void
    {
        $this->sendInventory();

        $this->actingAs($this->userWithRole(RoleKey::Operator))->get($this->url('network'))->assertOk()
            ->assertSee('Ethernet')->assertSee('AA:BB:CC:DD:EE:FF')->assertSee('10.0.0.5')->assertSee('1000 Mb/s');
    }

    public function test_metrics_tab_empty_state_and_chart(): void
    {
        $operator = $this->userWithRole(RoleKey::Operator);
        $this->actingAs($operator)->get($this->url('metrics'))->assertOk()->assertSee('Nenhuma métrica recebida');

        $samples = [];
        foreach (range(1, 30) as $i) {
            $samples[] = Payloads::sample(['at' => now()->subMinutes(31 - $i)->toIso8601String(), 'cpu_percent' => $i * 3.0, 'mem_available_mb' => 8000]);
        }
        $this->agent->post('/api/agent/v1/metrics', ['samples' => $samples])->assertStatus(202);

        $html = $this->actingAs($operator)->get($this->url('metrics'))->assertOk()->getContent();
        $this->assertStringContainsString('30 amostras', $html);
        $this->assertStringContainsString('<polyline points="0,', $html);
        $this->assertStringContainsString('87 %', $html);          // CPU da última amostra (i = 29 → 87)
        $this->assertStringContainsString('C:', $html);
        $this->assertStringContainsString('51 %', $html);           // memória: 1 − 8000/16384 ≈ 51,2 %
    }

    public function test_metrics_range_is_validated_and_filters_by_time(): void
    {
        $this->agent->post('/api/agent/v1/metrics', ['samples' => [
            Payloads::sample(['at' => now()->subHours(3)->toIso8601String()]),
            Payloads::sample(['at' => now()->subMinutes(10)->toIso8601String()]),
        ]])->assertStatus(202);
        $operator = $this->userWithRole(RoleKey::Operator);

        $this->actingAs($operator)->get($this->url('metrics', ['range' => '1h']))->assertSee('(1 amostras)');
        $this->actingAs($operator)->get($this->url('metrics', ['range' => '6h']))->assertSee('(2 amostras)');
        $this->actingAs($operator)->get($this->url('metrics', ['range' => '; DROP TABLE']))->assertOk()->assertSee('24h');
    }

    public function test_metrics_of_another_endpoint_never_appear(): void
    {
        $other = $this->enrolledAgent();
        $other->post('/api/agent/v1/metrics', ['samples' => [Payloads::sample(['cpu_percent' => 99.9])]])->assertStatus(202);

        $this->actingAs($this->userWithRole(RoleKey::Operator))->get($this->url('metrics'))->assertSee('Nenhuma métrica recebida');
    }

    /** Segunda coleta com mudanças de memória, SO, rede e software (a primeira é a base). */
    private function sendInventoryWithChanges(): void
    {
        $this->sendInventory();

        $base = Payloads::inventory()['sections'];
        $hardware = $base['hardware']['data'];
        $hardware['memory_total_mb'] = 32768;
        $system = $base['system']['data'];
        $system['os']['version'] = '10.0.26200';
        $network = ['interfaces' => [
            $base['network']['data']['interfaces'][0],
            ['name' => 'Wi-Fi', 'type' => 'Wireless', 'mac' => '11:22:33:44:55:66', 'ips' => [], 'up' => false],
        ]];
        $software = ['items' => [
            $base['software']['data']['items'][0],
            ['name' => '<b>Evil</b>', 'version' => '9.9', 'publisher' => null, 'install_date' => null, 'arch' => 'x64', 'source' => 'HKLM64'],
        ], 'truncated' => false];

        $this->sendInventory([
            'hardware' => ['status' => 'ok', 'data' => $hardware],
            'system' => ['status' => 'ok', 'data' => $system],
            'network' => ['status' => 'ok', 'data' => $network],
            'software' => ['status' => 'ok', 'data' => $software],
        ]);
    }

    public function test_history_tab_empty_state(): void
    {
        $this->actingAs($this->userWithRole(RoleKey::Operator))->get($this->url('history'))
            ->assertOk()->assertSee('Nenhuma mudança registrada');
    }

    public function test_history_tab_shows_initial_collection_and_hardware_software_network_changes(): void
    {
        $this->sendInventoryWithChanges();

        $html = $this->actingAs($this->userWithRole(RoleKey::Operator))->get($this->url('history'))->assertOk()
            ->assertSee('Primeira coleta')
            ->assertSee('16,0 GB')->assertSee('32,0 GB')
            ->assertSee('10.0.26200')
            ->assertSee('Wi-Fi')
            ->assertSee('7-Zip 24.08')
            ->getContent();

        $this->assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt; 9.9', $html, 'nome vindo do endpoint é escapado');
        $this->assertStringNotContainsString('<b>Evil</b>', $html);
    }

    public function test_history_tab_hides_software_and_network_from_the_viewer_profile(): void
    {
        $this->sendInventoryWithChanges();

        $this->actingAs($this->userWithRole(RoleKey::Viewer))->get($this->url('history'))->assertOk()
            ->assertSee('32,0 GB')->assertSee('10.0.26200')
            ->assertDontSee('Wi-Fi')->assertDontSee('7-Zip')->assertDontSee('Evil')->assertDontSee('11:22:33:44:55:66');
    }

    public function test_history_tab_is_listed_for_everyone_and_is_scoped_by_group(): void
    {
        $this->actingAs($this->userWithRole(RoleKey::Viewer))->get($this->url('details'))->assertSee('Histórico');

        $operatorOther = $this->userWithRole(RoleKey::Operator, EndpointGroup::factory()->create());
        $this->actingAs($operatorOther)->get($this->url('history'))->assertNotFound();
    }

    public function test_history_tab_paginates_newest_first(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->sendInventory(['software' => ['status' => 'ok', 'data' => ['items' => [
                ['name' => sprintf('Pacote %02d', $i), 'version' => '1', 'arch' => 'x64', 'source' => 'HKLM64'],
            ], 'truncated' => false]]]);
        }
        $operator = $this->userWithRole(RoleKey::Operator);

        $this->actingAs($operator)->get($this->url('history'))->assertOk()->assertSee('Pacote 25')->assertDontSee('Pacote 01');
        $this->actingAs($operator)->get($this->url('history', ['page' => 2]))->assertOk()->assertSee('Pacote 01');
    }
}
