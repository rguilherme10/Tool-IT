<?php

namespace App\Services\Inventory;

use App\Models\Endpoint;
use App\Models\EndpointInventory;
use App\Models\InventorySnapshot;
use App\Models\SoftwareItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ingestão do inventário (docs/tasks/006). Regras:
 *  - idempotente por snapshot_id (reenvio do mesmo snapshot não reprocessa);
 *  - seção `ok` substitui o dado; seção `unavailable`/`error` mantém o último dado bom e só atualiza o estado da coleta;
 *  - software que sumiu de uma coleta COMPLETA recebe removed_at (e volta a ativo se reaparecer).
 */
class InventoryIngestService
{
    private const CHUNK = 500;

    /**
     * @param  array<string, mixed>  $data  payload validado por InventoryRequest
     * @return array{duplicate: bool}
     */
    public function ingest(Endpoint $endpoint, array $data): array
    {
        if ($this->isDuplicate($endpoint, $data['snapshot_id'])) {
            return ['duplicate' => true];
        }

        try {
            DB::transaction(fn () => $this->apply($endpoint, $data));
        } catch (UniqueConstraintViolationException) {
            // Duas entregas simultâneas do mesmo snapshot: a outra venceu.
            return ['duplicate' => true];
        }

        return ['duplicate' => false];
    }

    private function isDuplicate(Endpoint $endpoint, string $snapshotId): bool
    {
        $existing = InventorySnapshot::where('snapshot_id', $snapshotId)->first();
        if ($existing === null) {
            return false;
        }

        if ($existing->endpoint_id !== $endpoint->id) {
            throw ValidationException::withMessages(['snapshot_id' => 'Identificador de snapshot já utilizado.']);
        }

        return true;
    }

    /** @param array<string, mixed> $data */
    private function apply(Endpoint $endpoint, array $data): void
    {
        $now = CarbonImmutable::now('UTC');
        $sections = $data['sections'];

        // O INSERT do snapshot vem primeiro: a UNIQUE(snapshot_id) é o árbitro de concorrência.
        $snapshot = InventorySnapshot::create([
            'endpoint_id' => $endpoint->id,
            'snapshot_id' => $data['snapshot_id'],
            'collected_at' => CarbonImmutable::parse($data['collected_at'])->utc(),
            'received_at' => $now,
            'section_status' => array_map(fn ($s) => ['status' => $s['status'], 'reason' => $s['reason'] ?? null], $sections),
            'payload_hash' => hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE)),
        ]);

        $inventory = EndpointInventory::where('endpoint_id', $endpoint->id)->lockForUpdate()->first()
            ?? new EndpointInventory(['endpoint_id' => $endpoint->id, 'section_status' => []]);

        $status = $inventory->section_status ?? [];
        $changes = [];

        foreach ($sections as $name => $section) {
            $status[$name] = ['status' => $section['status'], 'reason' => $section['reason'] ?? null, 'at' => $now->toIso8601String()];

            if ($section['status'] !== 'ok') {
                continue;
            }

            $payload = $section['data'] ?? [];
            match ($name) {
                'system', 'hardware', 'peripherals' => $inventory->{$name} = $payload,
                'software' => $changes += $this->syncSoftware($endpoint, $payload, $now),
                'network' => $this->replaceNetwork($endpoint, $payload['interfaces'] ?? [], $now),
            };

            if ($name === 'system') {
                $this->updateEndpoint($endpoint, $payload, $now);
            }
        }

        $inventory->forceFill([
            'section_status' => $status,
            'last_snapshot_id' => $data['snapshot_id'],
            'collected_at' => $snapshot->collected_at,
        ])->save();

        $endpoint->forceFill(['last_inventory_at' => $now])->save();
        $snapshot->update(['changes' => $changes === [] ? null : $changes]);
    }

    /** @param array<string, mixed> $system */
    private function updateEndpoint(Endpoint $endpoint, array $system, CarbonImmutable $now): void
    {
        $os = $system['os'] ?? [];

        $endpoint->forceFill(array_filter([
            'manufacturer' => $system['manufacturer'] ?? null,
            'model' => $system['model'] ?? null,
            'serial_number' => $system['serial'] ?? null,
            'domain' => $system['domain'] ?? ($system['workgroup'] ?? null),
            'os_name' => $os['name'] ?? null,
            'os_version' => $os['version'] ?? null,
            'os_build' => $os['build'] ?? null,
            'os_arch' => $os['arch'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int>
     */
    private function syncSoftware(Endpoint $endpoint, array $payload, CarbonImmutable $now): array
    {
        $incoming = [];
        foreach ($payload['items'] ?? [] as $item) {
            $hash = SoftwareItem::identityHash($item['name'], $item['version'] ?? null, $item['arch'] ?? null, $item['source']);
            $incoming[$hash] = $item;
        }

        $existing = SoftwareItem::where('endpoint_id', $endpoint->id)->pluck('removed_at', 'identity_hash')->all();
        $stamp = $now->format('Y-m-d H:i:s');

        $new = array_diff_key($incoming, $existing);
        foreach (array_chunk($new, self::CHUNK, preserve_keys: true) as $chunk) {
            DB::table('software_items')->insert(array_map(fn ($item, $hash) => [
                'endpoint_id' => $endpoint->id,
                'identity_hash' => $hash,
                'name' => $item['name'],
                'version' => $item['version'] ?? null,
                'publisher' => $item['publisher'] ?? null,
                'install_date' => $item['install_date'] ?? null,
                'arch' => $item['arch'] ?? null,
                'source' => $item['source'],
                'first_seen_at' => $stamp,
                'last_seen_at' => $stamp,
                'removed_at' => null,
            ], $chunk, array_keys($chunk)));
        }

        $present = array_keys(array_intersect_key($incoming, $existing));
        $reappeared = count(array_filter($present, fn ($h) => $existing[$h] !== null));
        foreach (array_chunk($present, self::CHUNK) as $chunk) {
            DB::table('software_items')->where('endpoint_id', $endpoint->id)->whereIn('identity_hash', $chunk)
                ->update(['last_seen_at' => $stamp, 'removed_at' => null]);
        }

        // Lista truncada pelo Agent = incompleta: não dá para concluir que algo foi removido.
        $removed = 0;
        if (! ($payload['truncated'] ?? false)) {
            $gone = array_keys(array_filter(array_diff_key($existing, $incoming), fn ($removedAt) => $removedAt === null));
            foreach (array_chunk($gone, self::CHUNK) as $chunk) {
                $removed += DB::table('software_items')->where('endpoint_id', $endpoint->id)->whereIn('identity_hash', $chunk)
                    ->update(['removed_at' => $stamp]);
            }
        }

        return ['software_added' => count($new), 'software_removed' => $removed, 'software_reappeared' => $reappeared];
    }

    /** @param list<array<string, mixed>> $interfaces */
    private function replaceNetwork(Endpoint $endpoint, array $interfaces, CarbonImmutable $now): void
    {
        DB::table('network_interfaces')->where('endpoint_id', $endpoint->id)->delete();

        $stamp = $now->format('Y-m-d H:i:s');
        $rows = array_map(fn (array $i) => [
            'endpoint_id' => $endpoint->id,
            'name' => $i['name'],
            'description' => $i['description'] ?? null,
            'type' => $i['type'] ?? null,
            'mac' => isset($i['mac']) ? strtoupper($i['mac']) : null,
            'ips' => json_encode($i['ips'] ?? []),
            'up' => (bool) ($i['up'] ?? false),
            'speed_mbps' => $i['speed_mbps'] ?? null,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ], $interfaces);

        if ($rows !== []) {
            DB::table('network_interfaces')->insert($rows);
        }
    }
}
