<?php

namespace App\Http\Requests\Api\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TaskResultRequest extends FormRequest
{
    /** Aceito na validação; o TaskService corta em TaskService::OUTPUT_LIMIT (16 KiB). */
    public const MAX_OUTPUT = 65536;

    public function authorize(): bool
    {
        return true; // já autenticado por VerifyAgentSignature
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'attempt_id' => ['required', 'uuid'],
            'status' => ['required', 'string', 'in:completed,failed,rejected,cancelled'],
            'exit_code' => ['sometimes', 'nullable', 'integer'],
            'stdout' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_OUTPUT],
            'stderr' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_OUTPUT],
            'truncated' => ['sometimes', 'nullable', 'boolean'],
            // Horários e duração do Agent são informativos (o servidor descarta o que destoar do relógio dele).
            'started_at' => ['sometimes', 'nullable', 'string', 'max:64'],
            'finished_at' => ['sometimes', 'nullable', 'string', 'max:64'],
            'duration_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'error' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ];
    }
}
