<?php

namespace App\Http\Requests;

use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Models\AlertRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validação das regras de alerta, dependente do tipo (limites em % para métricas, segundos para "sem comunicação"). */
class AlertRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;   // permissão de sistema (escopo global)
    }

    public function ruleType(): AlertRuleType
    {
        /** @var AlertRule|null $existing */
        $existing = $this->route('rule');

        // Na edição o tipo é fixo (trocar o tipo mudaria o significado do limite e dos alertas já abertos).
        return $existing?->type ?? AlertRuleType::from((string) $this->input('type'));
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $type = $this->input('type') !== null || $this->route('rule') ? $this->safeType() : null;

        $threshold = match ($type) {
            AlertRuleType::AgentOffline => ['required', 'numeric', 'between:60,604800'],
            AlertRuleType::AgentOutdated => ['nullable'],
            default => ['required', 'numeric', 'between:1,100'],
        };
        $clear = match ($type) {
            AlertRuleType::AgentOutdated => ['nullable'],
            default => ['nullable', 'numeric', 'min:0', 'lt:threshold'],   // histerese: sempre abaixo do limite
        };

        return [
            'type' => [$this->route('rule') ? 'nullable' : 'required', Rule::in(array_column(AlertRuleType::cases(), 'value'))],
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'severity' => ['required', Rule::in(array_column(AlertSeverity::cases(), 'value'))],
            'threshold' => $threshold,
            'clear_threshold' => $clear,
            'min_duration_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'clear_duration_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'repeat_interval_minutes' => ['nullable', 'integer', 'between:0,10080'],
            'min_version' => [$type === AlertRuleType::AgentOutdated ? 'required' : 'nullable', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/'],
            'group' => ['nullable', 'string', 'exists:endpoint_groups,uuid'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }

    private function safeType(): ?AlertRuleType
    {
        try {
            return $this->ruleType();
        } catch (\ValueError) {
            return null;   // 'type' inválido: a regra Rule::in acima reporta o erro
        }
    }
}
