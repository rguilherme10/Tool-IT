<?php

namespace Database\Factories;

use App\Enums\EndpointStatus;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Apenas para testes/desenvolvimento local. Não é usada em nenhum seeder de produção.
 *
 * @extends Factory<Endpoint>
 */
class EndpointFactory extends Factory
{
    public function definition(): array
    {
        return [
            'hostname' => strtoupper(fake()->unique()->bothify('PC-####')),
            'endpoint_group_id' => null,
            'status' => EndpointStatus::Active,
            'os_name' => 'Windows 11 Pro',
            'os_version' => '10.0.26100',
            'os_arch' => 'x64',
            'agent_version' => '0.1.0',
        ];
    }

    public function inGroup(EndpointGroup $group): static
    {
        return $this->state(fn () => ['endpoint_group_id' => $group->id]);
    }
}
