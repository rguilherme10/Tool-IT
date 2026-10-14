<?php

namespace App\Http\Requests\Api\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EnrollRequest extends FormRequest
{
    /** Sem caracteres de controle (dados do endpoint nunca são confiáveis). */
    private const TEXT = '/^[^\x00-\x1F\x7F]*$/u';

    public function authorize(): bool
    {
        return true; // autenticação é o próprio token de enrollment, verificado no serviço
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'enrollment_token' => ['required', 'string', 'max:128'],
            'public_key' => ['required', 'string', 'max:1024'],
            'hostname' => ['required', 'string', 'max:255', 'regex:'.self::TEXT],
            'os' => ['sometimes', 'array'],
            'os.name' => ['nullable', 'string', 'max:120', 'regex:'.self::TEXT],
            'os.version' => ['nullable', 'string', 'max:64', 'regex:'.self::TEXT],
            'os.build' => ['nullable', 'string', 'max:32', 'regex:'.self::TEXT],
            'os.arch' => ['nullable', 'string', 'in:x64,x86,arm64'],
            'agent_version' => ['sometimes', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/'],
            'machine_fingerprint' => ['sometimes', 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }
}
