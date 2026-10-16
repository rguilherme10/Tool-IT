<?php

namespace Tests\Feature\Console;

use App\Enums\RoleKey;
use App\Enums\TaskState;
use App\Models\RemoteTask;
use App\Services\Tasks\TaskService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ExpireTasksTest extends TestCase
{
    public function test_command_expires_unclaimed_tasks_and_reopens_idempotent_leases(): void
    {
        $admin = $this->userWithRole(RoleKey::Admin);
        $endpoint = $this->activeEndpoint();
        $tasks = app(TaskService::class);

        $stale = $tasks->create($admin, $endpoint, 'process.list', []);
        RemoteTask::whereKey($stale->id)->update(['expires_at' => now()->subSecond()]);

        $running = $tasks->create($admin, $endpoint, 'system.info', []); // idempotente
        $this->assertNotNull($tasks->claim($endpoint));
        $this->assertSame(TaskState::Running, $running->fresh()->state);

        $this->artisan('tasks:expire')->expectsOutputToContain('1')->assertSuccessful();
        $this->assertSame(TaskState::Expired, $stale->fresh()->state);
        $this->assertSame(TaskState::Running, $running->fresh()->state, 'lease ainda vigente');

        $this->travel(61)->seconds(); // timeout 30 s + folga 30 s
        $this->artisan('tasks:expire')->expectsOutputToContain('tratadas: 1')->assertSuccessful();
        $this->assertSame(TaskState::Available, $running->fresh()->state, 'idempotente volta para a fila');

        $this->artisan('tasks:expire')->expectsOutputToContain('tratadas: 0')->assertSuccessful();
    }

    public function test_command_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains($e->command, 'tasks:expire'));

        $this->assertNotNull($event, 'tasks:expire precisa estar agendado');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
