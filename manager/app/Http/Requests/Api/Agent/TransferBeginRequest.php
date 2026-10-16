<?php

namespace App\Http\Requests\Api\Agent;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TransferBeginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // já autenticado por VerifyAgentSignature; escopo conferido no controller
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // O teto (max_bytes da política) é conferido pelo TransferService: acima dele a transferência falha.
            'size' => ['required', 'integer', 'min:0'],
        ];
    }
}
