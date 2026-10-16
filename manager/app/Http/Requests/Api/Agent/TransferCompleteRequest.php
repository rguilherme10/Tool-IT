<?php

namespace App\Http\Requests\Api\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TransferCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // já autenticado por VerifyAgentSignature; escopo conferido no controller
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}\z/'],
            'size' => ['required', 'integer', 'min:0'],
        ];
    }
}
