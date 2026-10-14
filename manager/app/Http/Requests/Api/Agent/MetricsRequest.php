<?php

namespace App\Http\Requests\Api\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MetricsRequest extends FormRequest
{
    public const MAX_SAMPLES = 120;

    public function authorize(): bool
    {
        return true; // já autenticado por VerifyAgentSignature
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'samples' => ['required', 'array', 'min:1', 'max:'.self::MAX_SAMPLES],
            'samples.*.sample_id' => ['required', 'uuid'],
            'samples.*.at' => ['required', 'date'],
            'samples.*.cpu_percent' => ['nullable', 'numeric', 'between:0,100'],
            'samples.*.mem_total_mb' => ['required', 'integer', 'between:0,16777216'],
            'samples.*.mem_available_mb' => ['required', 'integer', 'between:0,16777216'],
            'samples.*.uptime_seconds' => ['required', 'integer', 'min:0'],
            'samples.*.volumes' => ['present', 'array', 'max:32'],
            'samples.*.volumes.*.mount' => ['required', 'string', 'max:16', 'regex:/^[^\x00-\x1F\x7F]*$/u'],
            'samples.*.volumes.*.total_gb' => ['required', 'numeric', 'min:0'],
            'samples.*.volumes.*.free_gb' => ['required', 'numeric', 'min:0'],
            'samples.*.temperature_c' => ['nullable', 'numeric', 'between:-50,150'],
        ];
    }
}
