<?php

namespace Tests\Unit;

use App\Support\Inventory\InventoryDiff;
use PHPUnit\Framework\TestCase;

class InventoryDiffTest extends TestCase
{
    /** @return array<string, mixed> */
    private function hardware(array $override = []): array
    {
        return array_replace([
            'cpus' => [['name' => 'Intel Core i7', 'cores' => 10, 'threads' => 12]],
            'memory_total_mb' => 16384,
            'disks' => [['model' => 'NVMe 512GB', 'size_gb' => 476.9]],
            'volumes' => [['mount' => 'C:', 'label' => 'Windows', 'file_system' => 'NTFS', 'total_gb' => 475.9, 'free_gb' => 210.4, 'used_percent' => 55.8]],
            'gpus' => [['name' => 'Intel Iris Xe', 'driver_version' => '31.0.1']],
            'baseboard' => ['manufacturer' => 'Dell Inc.', 'product' => '0ABC'],
            'bios' => ['vendor' => 'Dell Inc.', 'version' => '1.12.0'],
        ], $override);
    }

    public function test_identical_hardware_yields_no_changes(): void
    {
        $this->assertSame([], InventoryDiff::hardware($this->hardware(), $this->hardware()));
    }

    public function test_free_space_and_usage_are_ignored(): void
    {
        $new = $this->hardware(['volumes' => [['mount' => 'C:', 'label' => 'Windows', 'file_system' => 'NTFS', 'total_gb' => 475.9, 'free_gb' => 12.0, 'used_percent' => 97.4]]]);

        $this->assertSame([], InventoryDiff::hardware($this->hardware(), $new));
    }

    public function test_memory_upgrade_is_a_change_with_before_and_after(): void
    {
        $diff = InventoryDiff::hardware($this->hardware(), $this->hardware(['memory_total_mb' => 32768]));

        $this->assertSame([['category' => 'memory', 'type' => 'changed', 'before' => '16,0 GB', 'after' => '32,0 GB']], $diff);
    }

    public function test_added_and_removed_components(): void
    {
        $new = $this->hardware(['disks' => [
            ['model' => 'NVMe 512GB', 'size_gb' => 476.9],
            ['model' => 'Samsung SSD', 'size_gb' => 931.5],
        ]]);

        $this->assertSame(
            [['category' => 'disk', 'type' => 'added', 'before' => null, 'after' => 'Samsung SSD · 932 GB']],
            InventoryDiff::hardware($this->hardware(), $new),
        );
        $this->assertSame(
            [['category' => 'disk', 'type' => 'removed', 'before' => 'Samsung SSD · 932 GB', 'after' => null]],
            InventoryDiff::hardware($new, $this->hardware()),
        );
    }

    public function test_one_replaced_component_reads_as_changed(): void
    {
        $new = $this->hardware(['gpus' => [['name' => 'NVIDIA RTX 4060', 'driver_version' => '552.1']]]);

        $this->assertSame(
            [['category' => 'gpu', 'type' => 'changed', 'before' => 'Intel Iris Xe · driver 31.0.1', 'after' => 'NVIDIA RTX 4060 · driver 552.1']],
            InventoryDiff::hardware($this->hardware(), $new),
        );
    }

    public function test_missing_fields_do_not_break_the_diff(): void
    {
        $this->assertSame([], InventoryDiff::hardware([], []));
        $this->assertCount(1, InventoryDiff::hardware([], ['memory_total_mb' => 8192]));
    }

    public function test_system_diff_tracks_os_but_not_boot_time(): void
    {
        $old = ['hostname' => 'PC-01', 'manufacturer' => 'Dell Inc.', 'model' => 'Latitude', 'serial' => 'S1', 'domain' => 'empresa.local',
            'os' => ['name' => 'Windows 11 Pro', 'version' => '10.0.26100', 'arch' => 'x64'], 'boot_time' => '2026-10-14T08:00:00Z'];
        $new = ['os' => ['version' => '10.0.26200'] + $old['os'], 'boot_time' => '2026-10-15T08:00:00Z'] + $old;

        $this->assertSame(
            [['category' => 'os', 'type' => 'changed', 'before' => 'Windows 11 Pro 10.0.26100 x64', 'after' => 'Windows 11 Pro 10.0.26200 x64']],
            InventoryDiff::system($old, $new),
        );
    }

    public function test_network_diff_tracks_interfaces_but_not_ip_changes(): void
    {
        $eth = ['name' => 'Ethernet', 'mac' => 'aa:bb:cc:dd:ee:ff', 'ips' => ['10.0.0.5']];
        $wifi = ['name' => 'Wi-Fi', 'mac' => '11:22:33:44:55:66', 'ips' => []];

        $this->assertSame([], InventoryDiff::network([$eth], [['ips' => ['10.0.0.99']] + $eth]));
        $this->assertSame(
            [['category' => 'network', 'type' => 'added', 'before' => null, 'after' => 'Wi-Fi · 11:22:33:44:55:66']],
            InventoryDiff::network([$eth], [$eth, $wifi]),
        );
    }

    public function test_peripherals_diff(): void
    {
        $old = ['monitors' => [['name' => 'DELL U2723QE', 'manufacturer' => 'DEL']], 'printers' => [['name' => 'HP LaserJet']]];
        $new = ['monitors' => [['name' => 'DELL U2723QE', 'manufacturer' => 'DEL']], 'printers' => []];

        $this->assertSame(
            [['category' => 'printer', 'type' => 'removed', 'before' => 'HP LaserJet', 'after' => null]],
            InventoryDiff::peripherals($old, $new),
        );
    }

    public function test_diff_is_capped(): void
    {
        $many = fn (int $n) => array_map(fn ($i) => ['name' => "Impressora $i"], range(1, $n));

        $diff = InventoryDiff::peripherals(['printers' => []], ['printers' => $many(InventoryDiff::MAX_ITEMS + 20)]);

        $this->assertCount(InventoryDiff::MAX_ITEMS, $diff);
    }
}
