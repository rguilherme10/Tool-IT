<?php

namespace App\Http\Requests\Api\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TaskProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // já autenticado por VerifyAgentSignature
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'attempt_id' => ['required', 'uuid'],
            'percent' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
        ];
    }
}
