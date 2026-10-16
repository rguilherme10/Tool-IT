<?php

namespace Database\Factories;

use App\Models\EndpointGroup;
use App\Models\FileTransferPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Somente para testes. Por padrão cria a política global (uma só por escopo: o índice único em scope_key
 * recusa a segunda); use forGroup() para as demais.
 *
 * @extends Factory<FileTransferPolicy>
 */
class FileTransferPolicyFactory extends Factory
{
    protected $model = FileTransferPolicy::class;

    public function definition(): array
    {
        return [
            'endpoint_group_id' => null,
            'send_dirs' => ['C:\\ProgramData\\Toolit\\Inbox'],
            'fetch_dirs' => ['C:\\ProgramData\\Toolit\\Outbox', 'C:\\ProgramData\\Toolit\\Logs'],
            'allowed_extensions' => ['txt', 'log', 'csv', 'json', 'xml', 'pdf', 'zip', '7z', 'png', 'jpg', 'docx', 'xlsx'],
            'max_bytes' => 104857600,
            'allow_overwrite' => false,
        ];
    }

    public function global(): static
    {
        return $this->state(['endpoint_group_id' => null]);
    }

    public function forGroup(EndpointGroup $group): static
    {
        return $this->state(['endpoint_group_id' => $group->id]);
    }
}
