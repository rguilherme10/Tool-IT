<?php

namespace App\Support\Inventory;

/**
 * Diferença entre duas coletas da mesma seção do inventário (docs/tasks/006: histórico de mudanças).
 *
 * Compara "descritores" estáveis (texto legível) e não o JSON bruto, para que dados que mudam a cada coleta
 * (espaço livre, uso de disco, IPs por DHCP, horário de boot) não gerem ruído. Cada entrada é
 * `{category, type: added|removed|changed, before, after}`. Puro: sem banco, sem relógio.
 */
final class InventoryDiff
{
    /** Teto de entradas por seção: o payload vem do endpoint (não confiável) e o diff é gravado em JSON. */
    public const MAX_ITEMS = 100;

    /** Categorias que exigem `inventory.view_sensitive` para serem exibidas. */
    public const SENSITIVE_CATEGORIES = ['network'];

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<array{category: string, type: string, before: ?string, after: ?string}>
     */
    public static function system(array $old, array $new): array
    {
        $describe = fn (array $s): array => [
            'os' => self::words([$s['os']['name'] ?? null, $s['os']['version'] ?? null, $s['os']['arch'] ?? null]),
            'device' => self::words([$s['manufacturer'] ?? null, $s['model'] ?? null]),
            'serial' => self::words([$s['serial'] ?? null]),
            'domain' => self::words([$s['domain'] ?? ($s['workgroup'] ?? null)]),
            'hostname' => self::words([$s['hostname'] ?? null]),
        ];

        return self::compareAll($describe($old), $describe($new));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<array{category: string, type: string, before: ?string, after: ?string}>
     */
    public static function hardware(array $old, array $new): array
    {
        $describe = fn (array $h): array => [
            'cpu' => array_map(fn ($c) => self::words([$c['name'] ?? null, isset($c['cores']) ? "({$c['cores']} núcleos / ".($c['threads'] ?? '?').' threads)' : null]), $h['cpus'] ?? []),
            'memory' => isset($h['memory_total_mb']) ? [number_format($h['memory_total_mb'] / 1024, 1, ',', '.').' GB'] : [],
            'disk' => array_map(fn ($d) => self::join([$d['model'] ?? null, self::gb($d['size_gb'] ?? null)]), $h['disks'] ?? []),
            // total_gb entra (partição redimensionada é mudança); free_gb/used_percent não.
            'volume' => array_map(fn ($v) => self::words([$v['mount'] ?? null, $v['label'] ?? null, $v['file_system'] ?? null, self::gb($v['total_gb'] ?? null)]), $h['volumes'] ?? []),
            'gpu' => array_map(fn ($g) => self::join([$g['name'] ?? null, isset($g['driver_version']) ? 'driver '.$g['driver_version'] : null]), $h['gpus'] ?? []),
            'baseboard' => self::words([$h['baseboard']['manufacturer'] ?? null, $h['baseboard']['product'] ?? null]),
            'bios' => self::words([$h['bios']['vendor'] ?? null, $h['bios']['version'] ?? null]),
        ];

        return self::compareAll($describe($old), $describe($new));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<array{category: string, type: string, before: ?string, after: ?string}>
     */
    public static function peripherals(array $old, array $new): array
    {
        $describe = fn (array $p): array => [
            'monitor' => array_map(fn ($m) => self::words([$m['name'] ?? null, isset($m['manufacturer']) ? "({$m['manufacturer']})" : null]), $p['monitors'] ?? []),
            'printer' => array_map(fn ($x) => self::words([$x['name'] ?? null]), $p['printers'] ?? []),
        ];

        return self::compareAll($describe($old), $describe($new));
    }

    /**
     * Só aparecem/desaparecem interfaces (nome + MAC). IP muda por DHCP e geraria ruído a cada coleta.
     *
     * @param  list<array<string, mixed>>  $old
     * @param  list<array<string, mixed>>  $new
     * @return list<array{category: string, type: string, before: ?string, after: ?string}>
     */
    public static function network(array $old, array $new): array
    {
        $describe = fn (array $list): array => ['network' => array_map(
            fn ($i) => self::join([$i['name'] ?? null, isset($i['mac']) ? strtoupper($i['mac']) : null]),
            $list,
        )];

        return self::compareAll($describe($old), $describe($new));
    }

    /**
     * @param  array<string, list<string>|string>  $old
     * @param  array<string, list<string>|string>  $new
     * @return list<array{category: string, type: string, before: ?string, after: ?string}>
     */
    private static function compareAll(array $old, array $new): array
    {
        $entries = [];
        foreach ($new as $category => $after) {
            $entries = [...$entries, ...self::compare($category, self::items($old[$category] ?? []), self::items($after))];
        }

        return array_slice($entries, 0, self::MAX_ITEMS);
    }

    /**
     * Um item saiu e outro entrou na mesma categoria = troca ("changed"); caso contrário, adicionado/removido.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<array{category: string, type: string, before: ?string, after: ?string}>
     */
    private static function compare(string $category, array $before, array $after): array
    {
        $removed = self::subtract($before, $after);
        $added = self::subtract($after, $before);

        if (count($removed) === 1 && count($added) === 1) {
            return [['category' => $category, 'type' => 'changed', 'before' => $removed[0], 'after' => $added[0]]];
        }

        return [
            ...array_map(fn ($v) => ['category' => $category, 'type' => 'removed', 'before' => $v, 'after' => null], $removed),
            ...array_map(fn ($v) => ['category' => $category, 'type' => 'added', 'before' => null, 'after' => $v], $added),
        ];
    }

    /**
     * Diferença de multiconjuntos (dois discos iguais contam duas vezes).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private static function subtract(array $a, array $b): array
    {
        foreach ($b as $item) {
            $i = array_search($item, $a, true);
            if ($i !== false) {
                unset($a[$i]);
            }
        }

        return array_values($a);
    }

    /**
     * @param  list<string>|string  $value
     * @return list<string>
     */
    private static function items(array|string $value): array
    {
        return array_values(array_filter((array) $value, fn ($v) => $v !== ''));
    }

    /** @param list<mixed> $parts */
    private static function words(array $parts): string
    {
        return trim(implode(' ', array_filter($parts, fn ($p) => $p !== null && $p !== '')));
    }

    /** @param list<mixed> $parts */
    private static function join(array $parts): string
    {
        return implode(' · ', array_filter($parts, fn ($p) => $p !== null && $p !== ''));
    }

    private static function gb(int|float|null $value): ?string
    {
        return $value === null ? null : number_format($value, 0, ',', '.').' GB';
    }
}
