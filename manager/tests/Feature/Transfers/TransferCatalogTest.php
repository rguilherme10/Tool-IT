<?php

namespace Tests\Feature\Transfers;

use App\Enums\Permission;
use App\Enums\RoleKey;
use App\Enums\TaskRisk;
use App\Support\Authorization\AccessResolver;
use App\Support\Tasks\TaskCatalog;
use App\Support\Tasks\TaskForm;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransferCatalogTest extends TestCase
{
    public function test_definitions_of_file_types(): void
    {
        foreach (['file.send' => Permission::FilesTransferSend, 'file.fetch' => Permission::FilesTransferFetch] as $type => $permission) {
            $definition = TaskCatalog::definition($type);
            $this->assertNotNull($definition, $type);
            $this->assertTrue($definition->idempotent);
            $this->assertSame(900, $definition->timeoutSeconds);
            $this->assertSame(3, $definition->maxAttempts);
            $this->assertSame([$permission], $definition->createPermissions);
            $this->assertContains($type, TaskCatalog::types());

            $params = ['transfer_id' => (string) Str::uuid()];
            $this->assertSame(TaskRisk::Medium, TaskCatalog::risk($type, $params));
            $this->assertSame([Permission::TasksCreate, $permission], TaskCatalog::permissionsFor($type, $params));
        }
    }

    public function test_validate_accepts_only_a_uuid_transfer_id(): void
    {
        $uuid = (string) Str::uuid();
        $this->assertSame(['transfer_id' => $uuid], TaskCatalog::validate('file.send', ['transfer_id' => $uuid]));
        $this->assertSame(['transfer_id' => $uuid], TaskCatalog::validate('file.fetch', ['transfer_id' => $uuid]));

        $invalid = [
            [],
            ['transfer_id' => null],
            ['transfer_id' => 123],
            ['transfer_id' => ''],
            ['transfer_id' => 'nao-e-uuid'],
            ['transfer_id' => $uuid."\n"],
            ['transfer_id' => strtoupper($uuid)],
            ['transfer_id' => [$uuid]],
            ['transfer_id' => $uuid, 'path' => 'C:\\Windows\\x.txt'],
            ['path' => 'C:\\ProgramData\\Toolit\\Inbox\\a.txt'],
        ];
        foreach (['file.send', 'file.fetch'] as $type) {
            foreach ($invalid as $params) {
                try {
                    TaskCatalog::validate($type, $params);
                    $this->fail($type.' deveria recusar '.json_encode($params));
                } catch (ValidationException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_file_types_are_not_offered_by_the_generic_form(): void
    {
        $admin = $this->userWithRole(RoleKey::Admin);
        $endpoint = $this->activeEndpoint();

        $types = TaskForm::creatableTypes($admin, $endpoint, app(AccessResolver::class));

        $this->assertNotContains('file.send', $types);
        $this->assertNotContains('file.fetch', $types);
        $this->assertContains('process.list', $types);
        $this->assertNotContains('file.send', TaskForm::types());
        $this->assertSame('Enviar arquivo', TaskForm::label('file.send'));
        $this->assertSame('Recuperar arquivo', TaskForm::label('file.fetch'));
    }

    public function test_generic_task_route_refuses_file_types(): void
    {
        $admin = $this->withTwoFactor($this->userWithRole(RoleKey::Admin));
        $endpoint = $this->activeEndpoint();

        $this->actingAs($admin)->post(route('tasks.store', ['uuid' => $endpoint->uuid]), [
            'type' => 'file.send', 'transfer_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('type');

        $this->assertDatabaseCount('remote_tasks', 0);
    }
}
