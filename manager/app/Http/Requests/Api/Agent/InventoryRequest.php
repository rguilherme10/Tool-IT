<?php

namespace App\Http\Requests\Api\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Inventário enviado pelo Agent. Tudo que vem do equipamento é dado não confiável: tipos, tamanhos e
 * quantidades são limitados e `validated()` descarta qualquer campo não listado aqui.
 */
class InventoryRequest extends FormRequest
{
    public const SECTIONS = ['system', 'hardware', 'software', 'network', 'peripherals'];

    public const MAX_SOFTWARE = 5000;

    private const TEXT = 'regex:/^[^\x00-\x1F\x7F]*$/u';

    public function authorize(): bool
    {
        return true; // já autenticado por VerifyAgentSignature
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $t = fn (int $max, bool $nullable = true) => [$nullable ? 'nullable' : 'required', 'string', "max:$max", self::TEXT];
        $n = ['nullable', 'numeric', 'min:0'];

        $rules = [
            'snapshot_id' => ['required', 'uuid'],
            'collected_at' => ['required', 'date', 'before:+10 minutes', 'after:-30 days'],
            'sections' => ['required', 'array', 'min:1', 'max:'.count(self::SECTIONS), function ($attr, $value, $fail) {
                if (array_diff(array_keys($value), self::SECTIONS) !== []) {
                    $fail('Seção desconhecida.');
                }
            }],
        ];

        foreach (self::SECTIONS as $section) {
            $rules["sections.$section"] = ['sometimes', 'array'];
            $rules["sections.$section.status"] = ["required_with:sections.$section", 'in:ok,unavailable,error'];
            $rules["sections.$section.reason"] = $t(120);
            $rules["sections.$section.data"] = ["required_if:sections.$section.status,ok", 'nullable', 'array'];
        }

        // system
        $d = 'sections.system.data';
        $rules += [
            "$d.hostname" => $t(255), "$d.manufacturer" => $t(120), "$d.model" => $t(120), "$d.serial" => $t(120),
            "$d.os" => ['nullable', 'array'], "$d.os.name" => $t(120), "$d.os.version" => $t(64), "$d.os.build" => $t(32),
            "$d.os.arch" => ['nullable', 'in:x64,x86,arm64'],
            "$d.boot_time" => ['nullable', 'date'], "$d.domain" => $t(255), "$d.workgroup" => $t(255),
        ];

        // hardware
        $d = 'sections.hardware.data';
        $rules += [
            "$d.memory_total_mb" => ['nullable', 'integer', 'min:0', 'max:16777216'],
            "$d.cpus" => ['nullable', 'array', 'max:64'], "$d.cpus.*.name" => $t(255), "$d.cpus.*.cores" => $n, "$d.cpus.*.threads" => $n, "$d.cpus.*.max_mhz" => $n,
            "$d.disks" => ['nullable', 'array', 'max:64'], "$d.disks.*.model" => $t(255), "$d.disks.*.serial" => $t(120), "$d.disks.*.size_gb" => $n,
            "$d.disks.*.interface_type" => $t(32), "$d.disks.*.media_type" => $t(64),
            "$d.volumes" => ['nullable', 'array', 'max:64'], "$d.volumes.*.mount" => $t(16), "$d.volumes.*.label" => $t(64), "$d.volumes.*.file_system" => $t(16),
            "$d.volumes.*.total_gb" => $n, "$d.volumes.*.free_gb" => $n, "$d.volumes.*.used_percent" => $n,
            "$d.gpus" => ['nullable', 'array', 'max:16'], "$d.gpus.*.name" => $t(255), "$d.gpus.*.driver_version" => $t(64), "$d.gpus.*.vram_mb" => $n,
            "$d.baseboard" => ['nullable', 'array'], "$d.baseboard.manufacturer" => $t(120), "$d.baseboard.product" => $t(120), "$d.baseboard.serial" => $t(120),
            "$d.bios" => ['nullable', 'array'], "$d.bios.vendor" => $t(120), "$d.bios.version" => $t(120), "$d.bios.release_date" => $t(16),
            "$d.not_detected" => ['nullable', 'array', 'max:32'], "$d.not_detected.*.component" => $t(32), "$d.not_detected.*.reason" => $t(64),
        ];

        // software
        $d = 'sections.software.data';
        $rules += [
            "$d.items" => ['nullable', 'array', 'max:'.self::MAX_SOFTWARE],
            "$d.items.*.name" => $t(255, nullable: false), "$d.items.*.version" => $t(64), "$d.items.*.publisher" => $t(255),
            "$d.items.*.install_date" => ['nullable', 'date_format:Y-m-d'], "$d.items.*.arch" => ['nullable', 'in:x64,x86'],
            "$d.items.*.source" => ['required', 'in:HKLM64,HKLM32,HKU'],
            "$d.truncated" => ['nullable', 'boolean'],
        ];

        // network
        $d = 'sections.network.data';
        $rules += [
            "$d.interfaces" => ['nullable', 'array', 'max:64'],
            "$d.interfaces.*.name" => $t(255, nullable: false), "$d.interfaces.*.description" => $t(255), "$d.interfaces.*.type" => $t(32),
            "$d.interfaces.*.mac" => ['nullable', 'string', 'regex:/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/i'],
            "$d.interfaces.*.ips" => ['nullable', 'array', 'max:32'], "$d.interfaces.*.ips.*" => ['ip'],
            "$d.interfaces.*.up" => ['nullable', 'boolean'], "$d.interfaces.*.speed_mbps" => $n,
        ];

        // peripherals
        $d = 'sections.peripherals.data';
        $rules += [
            "$d.monitors" => ['nullable', 'array', 'max:16'], "$d.monitors.*.name" => $t(120), "$d.monitors.*.manufacturer" => $t(64),
            "$d.monitors.*.serial" => $t(64), "$d.monitors.*.year" => $n,
            "$d.printers" => ['nullable', 'array', 'max:128'], "$d.printers.*.name" => $t(255), "$d.printers.*.driver" => $t(255),
            "$d.printers.*.port" => $t(255), "$d.printers.*.is_default" => ['nullable', 'boolean'], "$d.printers.*.is_network" => ['nullable', 'boolean'],
            "$d.not_detected" => ['nullable', 'array', 'max:8'], "$d.not_detected.*.component" => $t(32), "$d.not_detected.*.reason" => $t(64),
        ];

        return $rules;
    }
}
