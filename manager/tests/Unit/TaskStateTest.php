<?php

namespace Tests\Unit;

use App\Enums\TaskState;
use PHPUnit\Framework\TestCase;

class TaskStateTest extends TestCase
{
    public function test_transition_table(): void
    {
        $allowed = [
            'pending_approval' => ['available', 'cancelled', 'expired'],
            'available' => ['running', 'cancelled', 'expired'],
            'running' => ['completed', 'failed', 'cancelled', 'available'],
            'completed' => [],
            'failed' => [],
            'cancelled' => [],
            'expired' => [],
        ];

        foreach (TaskState::cases() as $from) {
            foreach (TaskState::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, $allowed[$from->value], true),
                    $from->canTransitionTo($to),
                    "{$from->value} -> {$to->value}"
                );
            }
        }
    }

    public function test_final_states(): void
    {
        $final = [TaskState::Completed, TaskState::Failed, TaskState::Cancelled, TaskState::Expired];

        foreach (TaskState::cases() as $state) {
            $this->assertSame(in_array($state, $final, true), $state->isFinal(), $state->value);
        }
    }
}
