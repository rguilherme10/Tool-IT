<?php

namespace Tests\Feature\Api\Agent;

use App\Models\AgentInstallation;
use App\Models\Endpoint;
use App\Models\EndpointInventory;
use App\Models\InventorySnapshot;
use App\Models\SoftwareItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Payloads;
use Tests\TestCase;

class InventoryIngestTest extends TestCase
{
    private const URL = '/api/agent/v1/inventory';

    public function test_full_inventory_is_stored_as_current_state(): void
    {
        $agent = $this->enrolledAgent();
        $endpoint = Endpoint::firstOrFail();

        $agent->post(self::URL, Payloads::inventory())->assertStatus(202)->assertJson(['accepted' => true]);

        $inv = EndpointInventory::where('endpoint_id', $endpoint->id)->firstOrFail();
        $this->assertSame('Dell Inc.', $inv->system['manufacturer']);
        $this->assertSame(16384, $inv->hardware['memory_total_mb']);
        $this->assertSame('Intel Core i7-1355U', $inv->hardware['cpus'][0]['name']);
        $this->assertSame('HP LaserJet', $inv->peripherals['printers'][0]['name']);
        $this->assertSame('ok', $inv->section_status['software']['status']);

        $endpoint->refresh();
        $this->assertSame('Latitude 5440', $endpoint->model);
        $this->assertSame('ABC1234', $endpoint->serial_number);
        $this->assertSame('Windows 11 Pro', $endpoint->os_name);
        $this->assertSame('empresa.local', $endpoint->domain);
        $this->assertNotNull($endpoint->last_inventory_at);

        $this->assertSame(2, SoftwareItem::where('endpoint_id', $endpoint->id)->count());
        $this->assertDatabaseHas('network_interfaces', ['endpoint_id' => $endpoint->id, 'mac' => 'AA:BB:CC:DD:EE:FF', 'up' => 1]);
        $this->assertDatabaseHas('inventory_snapshots', ['endpoint_id' => $endpoint->id]);
    }

    public function test_resending_the_same_snapshot_is_idempotent(): void
    {
        $agent = $this->enrolledAgent();
        $payload = Payloads::inventory();

        $agent->post(self::URL, $payload)->assertStatus(202);
        $agent->post(self::URL, $payload)->assertStatus(200)->assertJson(['accepted' => true]);

        $this->assertSame(1, InventorySnapshot::count());
        $this->assertSame(2, SoftwareItem::count());
    }

    public function test_snapshot_id_cannot_be_squatted_across_endpoints(): void
    {
        $a = $this->enrolledAgent();
        $b = $this->enrolledAgent();
        $payload = Payloads::inventory();

        $a->post(self::URL, $payload)->assertStatus(202);
        $b->post(self::URL, $payload)->assertUnprocessable();

        $this->assertSame(1, InventorySnapshot::count());
    }

    public function test_unavailable_section_keeps_last_good_data_and_records_status(): void
    {
        $agent = $this->enrolledAgent();
        $agent->post(self::URL, Payloads::inventory())->assertStatus(202);

        $agent->post(self::URL, Payloads::inventory([
            'hardware' => ['status' => 'unavailable', 'reason' => 'access_denied'],
            'peripherals' => ['status' => 'error', 'reason' => 'TimeoutException'],
        ]))->assertStatus(202);

        $inv = EndpointInventory::firstOrFail();
        $this->assertSame(16384, $inv->hardware['memory_total_mb'], 'último dado bom preservado');
        $this->assertSame('unavailable', $inv->section_status['hardware']['status']);
        $this->assertSame('access_denied', $inv->section_status['hardware']['reason']);
        $this->assertSame('error', $inv->section_status['peripherals']['status']);
        $this->assertSame('ok', $inv->section_status['system']['status']);
    }

    public function test_software_added_removed_and_reappeared_are_tracked(): void
    {
        $agent = $this->enrolledAgent();
        $chrome = ['name' => 'Google Chrome', 'version' => '130.0', 'publisher' => 'Google LLC', 'install_date' => null, 'arch' => 'x64', 'source' => 'HKLM64'];
        $zip = ['name' => '7-Zip', 'version' => '24.08', 'publisher' => null, 'install_date' => null, 'arch' => 'x64', 'source' => 'HKLM64'];
        $code = ['name' => 'VS Code', 'version' => '1.95', 'publisher' => 'Microsoft', 'install_date' => null, 'arch' => 'x64', 'source' => 'HKU'];
        $sw = fn (array $items, bool $truncated = false) => ['software' => ['status' => 'ok', 'data' => ['items' => $items, 'truncated' => $truncated]]];

        $agent->post(self::URL, Payloads::inventory($sw([$chrome, $zip])))->assertStatus(202);

        // 7-Zip some, VS Code entra.
        $agent->post(self::URL, Payloads::inventory($sw([$chrome, $code])))->assertStatus(202);
        $this->assertNotNull(SoftwareItem::where('name', '7-Zip')->firstOrFail()->removed_at);
        $this->assertNull(SoftwareItem::where('name', 'VS Code')->firstOrFail()->removed_at);
        $changes = InventorySnapshot::orderByDesc('id')->firstOrFail()->changes;
        $this->assertSame(
            ['software_added' => 1, 'software_removed' => 1],
            Arr::only($changes, ['software_added', 'software_removed', 'software_reappeared']),
        );
        $this->assertSame(['VS Code 1.95'], $changes['software_added_names']);
        $this->assertSame(['7-Zip 24.08'], $changes['software_removed_names']);

        // 7-Zip volta.
        $agent->post(self::URL, Payloads::inventory($sw([$chrome, $code, $zip])))->assertStatus(202);
        $this->assertNull(SoftwareItem::where('name', '7-Zip')->firstOrFail()->removed_at);
        $this->assertSame(1, InventorySnapshot::orderByDesc('id')->firstOrFail()->changes['software_reappeared']);
        $this->assertSame(3, SoftwareItem::count(), 'sem duplicar linhas');
    }

    public function test_first_snapshot_is_flagged_as_initial_without_hardware_diff(): void
    {
        $this->enrolledAgent()->post(self::URL, Payloads::inventory())->assertStatus(202);

        $changes = InventorySnapshot::firstOrFail()->changes;
        $this->assertTrue($changes['initial']);
        $this->assertArrayNotHasKey('items', $changes);
    }

    public function test_hardware_system_and_network_changes_are_recorded_in_the_snapshot(): void
    {
        $agent = $this->enrolledAgent();
        $agent->post(self::URL, Payloads::inventory())->assertStatus(202);

        $base = Payloads::inventory();
        $hardware = $base['sections']['hardware']['data'];
        $hardware['memory_total_mb'] = 32768;
        $hardware['volumes'][0]['free_gb'] = 1.5; // ruído: não deve aparecer
        $system = $base['sections']['system']['data'];
        $system['os']['version'] = '10.0.26200';
        $network = ['interfaces' => [
            $base['sections']['network']['data']['interfaces'][0],
            ['name' => 'Wi-Fi', 'type' => 'Wireless', 'mac' => '11:22:33:44:55:66', 'ips' => [], 'up' => false],
        ]];

        $agent->post(self::URL, Payloads::inventory([
            'hardware' => ['status' => 'ok', 'data' => $hardware],
            'system' => ['status' => 'ok', 'data' => $system],
            'network' => ['status' => 'ok', 'data' => $network],
        ]))->assertStatus(202);

        $changes = InventorySnapshot::orderByDesc('id')->firstOrFail()->changes;
        $this->assertArrayNotHasKey('initial', $changes);
        $this->assertEqualsCanonicalizing(['memory', 'os', 'network'], array_column($changes['items'], 'category'));
        $memory = Arr::first($changes['items'], fn ($i) => $i['category'] === 'memory');
        $this->assertSame(['changed', '16,0 GB', '32,0 GB'], [$memory['type'], $memory['before'], $memory['after']]);
    }

    public function test_unchanged_inventory_records_no_diff_items(): void
    {
        $agent = $this->enrolledAgent();
        $agent->post(self::URL, Payloads::inventory())->assertStatus(202);
        $agent->post(self::URL, Payloads::inventory())->assertStatus(202);

        $this->assertNull(InventorySnapshot::orderByDesc('id')->firstOrFail()->changes, 'sem mudança = changes nulo');
    }

    public function test_unavailable_section_produces_no_diff(): void
    {
        $agent = $this->enrolledAgent();
        $agent->post(self::URL, Payloads::inventory())->assertStatus(202);
        $agent->post(self::URL, Payloads::inventory(['hardware' => ['status' => 'unavailable', 'reason' => 'access_denied']]))->assertStatus(202);

        $this->assertNull(InventorySnapshot::orderByDesc('id')->firstOrFail()->changes);
    }

    public function test_software_name_lists_are_capped(): void
    {
        $agent = $this->enrolledAgent();
        $agent->post(self::URL, Payloads::inventory(['software' => ['status' => 'ok', 'data' => ['items' => [], 'truncated' => false]]]))->assertStatus(202);

        $items = array_map(fn ($i) => ['name' => "App $i", 'version' => '1', 'arch' => 'x64', 'source' => 'HKLM64'], range(1, 80));
        $agent->post(self::URL, Payloads::inventory(['software' => ['status' => 'ok', 'data' => ['items' => $items, 'truncated' => false]]]))->assertStatus(202);

        $changes = InventorySnapshot::orderByDesc('id')->firstOrFail()->changes;
        $this->assertSame(80, $changes['software_added']);
        $this->assertCount(50, $changes['software_added_names']);
    }

    public function test_truncated_software_list_never_marks_items_as_removed(): void
    {
        $agent = $this->enrolledAgent();
        $agent->post(self::URL, Payloads::inventory())->assertStatus(202);

        $agent->post(self::URL, Payloads::inventory(['software' => ['status' => 'ok', 'data' => [
            'items' => [['name' => 'Google Chrome', 'version' => '130.0', 'arch' => 'x64', 'source' => 'HKLM64']],
            'truncated' => true,
        ]]]))->assertStatus(202);

        $this->assertSame(0, SoftwareItem::whereNotNull('removed_at')->count());
    }

    public function test_network_interfaces_are_replaced_not_accumulated(): void
    {
        $agent = $this->enrolledAgent();
        $agent->post(self::URL, Payloads::inventory())->assertStatus(202);
        $agent->post(self::URL, Payloads::inventory(['network' => ['status' => 'ok', 'data' => ['interfaces' => [
            ['name' => 'Wi-Fi', 'type' => 'Wireless80211', 'mac' => 'aa:bb:cc:00:11:22', 'ips' => ['192.168.0.10'], 'up' => true],
        ]]]]))->assertStatus(202);

        $this->assertSame(1, DB::table('network_interfaces')->count());
        $this->assertDatabaseHas('network_interfaces', ['name' => 'Wi-Fi', 'mac' => 'AA:BB:CC:00:11:22']);
    }

    public function test_agent_can_only_write_to_its_own_endpoint(): void
    {
        $a = $this->enrolledAgent();
        $b = $this->enrolledAgent();
        $a->post(self::URL, Payloads::inventory(['system' => ['status' => 'ok', 'data' => ['model' => 'SOMENTE-A']]]))->assertStatus(202);

        $endpointB = AgentInstallation::where('uuid', $b->agentId)->firstOrFail()->endpoint;
        $this->assertNull(EndpointInventory::where('endpoint_id', $endpointB->id)->first());
        $this->assertNull($endpointB->fresh()->model);
    }

    public function test_validation_rejects_malformed_payloads(): void
    {
        $agent = $this->enrolledAgent();
        $base = Payloads::inventory();
        $bad = [
            'snapshot não-uuid' => ['snapshot_id' => 'x'] + $base,
            'seção desconhecida' => ['sections' => $base['sections'] + ['rootkit' => ['status' => 'ok', 'data' => []]]] + $base,
            'status inválido' => Payloads::inventory(['software' => ['status' => 'talvez']]),
            'ok sem data' => Payloads::inventory(['network' => ['status' => 'ok']]),
            'mac inválido' => Payloads::inventory(['network' => ['status' => 'ok', 'data' => ['interfaces' => [['name' => 'x', 'mac' => 'zz']]]]]),
            'ip inválido' => Payloads::inventory(['network' => ['status' => 'ok', 'data' => ['interfaces' => [['name' => 'x', 'ips' => ['999.1.1.1']]]]]]),
            'origem desconhecida' => Payloads::inventory(['software' => ['status' => 'ok', 'data' => ['items' => [['name' => 'x', 'source' => 'HKCR']]]]]),
            'nome com controle' => Payloads::inventory(['software' => ['status' => 'ok', 'data' => ['items' => [['name' => "a\x00b", 'source' => 'HKLM64']]]]]),
            'nome gigante' => Payloads::inventory(['software' => ['status' => 'ok', 'data' => ['items' => [['name' => str_repeat('a', 256), 'source' => 'HKLM64']]]]]),
            'data futura' => ['collected_at' => now()->addDay()->toIso8601String()] + $base,
        ];

        foreach ($bad as $name => $payload) {
            $agent->post(self::URL, $payload)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
            $this->assertSame(0, InventorySnapshot::count(), $name);
        }
    }

    public function test_unknown_fields_are_discarded_not_stored(): void
    {
        $agent = $this->enrolledAgent();
        $payload = Payloads::inventory();
        $payload['sections']['system']['data']['evil'] = '<script>alert(1)</script>';
        $payload['sections']['hardware']['data']['cpus'][0]['injected'] = 'x';

        $agent->post(self::URL, $payload)->assertStatus(202);

        $inv = EndpointInventory::firstOrFail();
        $this->assertArrayNotHasKey('evil', $inv->system);
        $this->assertArrayNotHasKey('injected', $inv->hardware['cpus'][0]);
    }

    public function test_software_list_limit_is_enforced(): void
    {
        $agent = $this->enrolledAgent();
        $items = array_map(fn ($i) => ['name' => "App $i", 'version' => '1', 'source' => 'HKLM64'], range(1, 5001));

        $agent->post(self::URL, Payloads::inventory(['software' => ['status' => 'ok', 'data' => ['items' => $items]]]))->assertUnprocessable();
    }

    public function test_large_but_valid_software_list_is_ingested(): void
    {
        $agent = $this->enrolledAgent();
        $items = array_map(fn ($i) => ['name' => "App $i", 'version' => "1.$i", 'publisher' => 'Vendor', 'source' => 'HKLM64', 'arch' => 'x64'], range(1, 1500));

        $agent->post(self::URL, Payloads::inventory(['software' => ['status' => 'ok', 'data' => ['items' => $items]]]))->assertStatus(202);

        $this->assertSame(1500, SoftwareItem::count());
    }

    public function test_payload_above_limit_is_refused_before_signature_verification(): void
    {
        $agent = $this->enrolledAgent();
        $huge = ['snapshot_id' => (string) Str::uuid(), 'pad' => str_repeat('a', 4 * 1024 * 1024 + 10)];

        $agent->post(self::URL, $huge)->assertStatus(413)->assertJsonPath('error.code', 'payload.too_large');
        $agent->post('/api/agent/v1/metrics', ['pad' => str_repeat('a', 1024 * 1024 + 10)])->assertStatus(413);
    }

    public function test_revoked_agent_cannot_send_inventory(): void
    {
        $agent = $this->enrolledAgent();
        AgentInstallation::query()->update(['status' => 'revoked', 'revoked_at' => now()]);

        $agent->post(self::URL, Payloads::inventory())->assertForbidden()->assertJsonPath('error.code', 'agent.revoked');
        $this->assertSame(0, InventorySnapshot::count());
    }
}
