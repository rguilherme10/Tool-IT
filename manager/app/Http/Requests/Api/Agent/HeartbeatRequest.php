<?php

namespace App\Http\Requests\Api\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // já autenticado por VerifyAgentSignature
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'uptime_seconds' => ['sometimes', 'integer', 'min:0'],
            'service_state' => ['sometimes', 'string', 'in:starting,running,stopping'],
            'agent_version' => ['sometimes', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/'],
            'current_user' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[^\x00-\x1F\x7F]*$/u'],
            'ip_addresses' => ['sometimes', 'array', 'max:32'],
            'ip_addresses.*' => ['ip'],
            'pending_events' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
