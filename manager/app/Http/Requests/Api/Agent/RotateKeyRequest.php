<?php

namespace App\Http\Requests\Api\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RotateKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // já autenticado por VerifyAgentSignature
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'new_public_key' => ['required', 'string', 'max:1024'],
        ];
    }
}
