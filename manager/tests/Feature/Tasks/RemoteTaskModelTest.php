<?php

namespace Tests\Feature\Tasks;

use App\Enums\TaskRisk;
use App\Enums\TaskState;
use App\Models\RemoteTask;
use App\Models\RemoteTaskAttempt;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class RemoteTaskModelTest extends TestCase
{
    public function test_factory_creates_task_with_casts_and_uuid_route_key(): void
    {
        $task = RemoteTask::factory()->create(['type' => 'service.restart', 'params' => ['name' => 'Spooler'], 'risk' => TaskRisk::Medium]);
        $task = $task->fresh();

        $this->assertSame(TaskState::Available, $task->state);
        $this->assertSame(TaskRisk::Medium, $task->risk);
        $this->assertSame(['name' => 'Spooler'], $task->params);
        $this->assertSame('uuid', $task->getRouteKeyName());
        $this->assertNotEmpty($task->uuid);
        $this->assertNotNull($task->endpoint);
        $this->assertNotNull($task->creator);
        $this->assertNull($task->approver);
    }

    public function test_mass_assignment_is_blocked(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new RemoteTask)->fill(['state' => 'completed']);
    }

    public function test_attempts_and_current_attempt(): void
    {
        $task = RemoteTask::factory()->create();
        foreach ([1, 2] as $number) {
            (new RemoteTaskAttempt)->forceFill([
                'remote_task_id' => $task->id, 'number' => $number,
                'claimed_at' => now(), 'lease_expires_at' => now()->addMinute(),
            ])->save();
        }

        $this->assertCount(2, $task->attempts);
        $this->assertSame(2, $task->currentAttempt->number);
        $this->assertFalse($task->currentAttempt->truncated);
    }

    public function test_attempt_number_is_unique_per_task_and_idempotency_key_per_creator(): void
    {
        $task = RemoteTask::factory()->create();
        $attempt = fn () => (new RemoteTaskAttempt)->forceFill([
            'remote_task_id' => $task->id, 'number' => 1, 'claimed_at' => now(), 'lease_expires_at' => now()->addMinute(),
        ])->save();
        $attempt();

        $this->expectException(QueryException::class);
        $attempt();
    }

    public function test_idempotency_key_is_unique_per_creator(): void
    {
        $first = RemoteTask::factory()->create(['idempotency_key' => 'abc']);

        // Mesma chave para outro criador é permitida.
        RemoteTask::factory()->create(['idempotency_key' => 'abc']);

        $this->expectException(QueryException::class);
        RemoteTask::factory()->create(['idempotency_key' => 'abc', 'created_by' => $first->created_by]);
    }
}
