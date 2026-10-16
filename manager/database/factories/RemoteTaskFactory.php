<?php

namespace Database\Factories;

use App\Enums\TaskRisk;
use App\Enums\TaskState;
use App\Models\Endpoint;
use App\Models\RemoteTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Somente para testes: tarefas reais nascem de App\Services\Tasks\TaskService (com validação, risco e auditoria).
 * (O Factory cria os models com Model::unguarded, então o guarded do model não atrapalha.)
 *
 * @extends Factory<RemoteTask>
 */
class RemoteTaskFactory extends Factory
{
    protected $model = RemoteTask::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'endpoint_id' => Endpoint::factory(),
            'type' => 'process.list',
            'params' => [],
            'risk' => TaskRisk::Low,
            'state' => TaskState::Available,
            'created_by' => User::factory(),
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function forEndpoint(Endpoint $endpoint): static
    {
        return $this->state(fn () => ['endpoint_id' => $endpoint->id]);
    }

    public function inState(TaskState $state): static
    {
        return $this->state(fn () => ['state' => $state]);
    }
}
