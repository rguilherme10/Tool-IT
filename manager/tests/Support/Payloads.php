<?php

namespace Tests\Support;

use Illuminate\Support\Str;

/** Payloads realistas do protocolo (mesma forma que o Agent C# envia). */
class Payloads
{
    /** @return array<string, mixed> */
    public static function inventory(array $sections = [], ?string $snapshotId = null): array
    {
        $defaults = [
            'system' => ['status' => 'ok', 'data' => [
                'hostname' => 'PC-TESTE-01', 'manufacturer' => 'Dell Inc.', 'model' => 'Latitude 5440', 'serial' => 'ABC1234',
                'os' => ['name' => 'Windows 11 Pro', 'version' => '10.0.26100', 'build' => '26100', 'arch' => 'x64'],
                'boot_time' => '2026-10-14T08:00:00Z', 'domain' => 'empresa.local',
            ]],
            'hardware' => ['status' => 'ok', 'data' => [
                'cpus' => [['name' => 'Intel Core i7-1355U', 'cores' => 10, 'threads' => 12, 'max_mhz' => 1700]],
                'memory_total_mb' => 16384,
                'disks' => [['model' => 'NVMe 512GB', 'serial' => 'S1', 'size_gb' => 476.9, 'interface_type' => 'SCSI', 'media_type' => 'Fixed hard disk media']],
                'volumes' => [['mount' => 'C:', 'label' => 'Windows', 'file_system' => 'NTFS', 'total_gb' => 475.9, 'free_gb' => 210.4, 'used_percent' => 55.8]],
                'gpus' => [['name' => 'Intel Iris Xe', 'driver_version' => '31.0.101', 'vram_mb' => 128]],
                'baseboard' => ['manufacturer' => 'Dell Inc.', 'product' => '0ABC', 'serial' => 'BB1'],
                'bios' => ['vendor' => 'Dell Inc.', 'version' => '1.12.0', 'release_date' => '2024-03-01'],
                'not_detected' => [],
            ]],
            'software' => ['status' => 'ok', 'data' => ['items' => [
                ['name' => 'Google Chrome', 'version' => '130.0', 'publisher' => 'Google LLC', 'install_date' => '2026-09-01', 'arch' => 'x64', 'source' => 'HKLM64'],
                ['name' => '7-Zip', 'version' => '24.08', 'publisher' => 'Igor Pavlov', 'install_date' => null, 'arch' => 'x64', 'source' => 'HKLM64'],
            ], 'truncated' => false]],
            'network' => ['status' => 'ok', 'data' => ['interfaces' => [
                ['name' => 'Ethernet', 'description' => 'Intel I219-V', 'type' => 'Ethernet', 'mac' => 'AA:BB:CC:DD:EE:FF', 'ips' => ['10.0.0.5'], 'up' => true, 'speed_mbps' => 1000],
            ]]],
            'peripherals' => ['status' => 'ok', 'data' => [
                'monitors' => [['name' => 'DELL U2723QE', 'manufacturer' => 'DEL', 'serial' => 'M1', 'year' => 2023]],
                'printers' => [['name' => 'HP LaserJet', 'driver' => 'HP Universal', 'port' => 'IP_10.0.0.9', 'is_default' => true, 'is_network' => true]],
                'not_detected' => [],
            ]],
        ];

        return [
            'snapshot_id' => $snapshotId ?? (string) Str::uuid(),
            'collected_at' => now()->subMinute()->toIso8601String(),
            'sections' => array_replace($defaults, $sections),
        ];
    }

    /** @return array<string, mixed> */
    public static function sample(array $override = []): array
    {
        return array_replace([
            'sample_id' => (string) Str::uuid(),
            'at' => now()->subSeconds(5)->toIso8601String(),
            'cpu_percent' => 12.5,
            'mem_total_mb' => 16384,
            'mem_available_mb' => 8000,
            'uptime_seconds' => 3600,
            'volumes' => [['mount' => 'C:', 'total_gb' => 475.9, 'free_gb' => 210.4]],
            'temperature_c' => null,
        ], $override);
    }
}
