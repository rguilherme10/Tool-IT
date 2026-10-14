<?php

namespace Database\Factories;

use App\Models\EndpointGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EndpointGroup>
 */
class EndpointGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Grupo '.fake()->unique()->bothify('??-##'),
            'description' => null,
            'parent_id' => null,
        ];
    }
}
