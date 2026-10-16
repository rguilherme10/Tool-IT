<?php

namespace Tests\Unit;

use App\Enums\Permission;
use App\Enums\TaskRisk;
use App\Models\SystemSetting;
use App\Support\Tasks\TaskCatalog;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Estende Tests\TestCase (e não o TestCase do PHPUnit) porque lê a allowlist/lista de protegidos de SystemSetting. */
class TaskCatalogTest extends TestCase
{
    public function test_risk_by_type(): void
    {
        $this->assertSame(TaskRisk::Medium, TaskCatalog::risk('service.restart', ['name' => 'Spooler']));
        $this->assertSame(TaskRisk::High, TaskCatalog::risk('service.restart', ['name' => 'Foo']));
        $this->assertSame(TaskRisk::High, TaskCatalog::risk('process.kill', ['pid' => 1234, 'name' => 'notepad.exe']));
        $this->assertSame(TaskRisk::High, TaskCatalog::risk('agent.restart', []));
        $this->assertSame(TaskRisk::Low, TaskCatalog::risk('process.list', []));
        $this->assertSame(TaskRisk::Low, TaskCatalog::risk('system.info', []));
    }

    public function test_definitions_and_types(): void
    {
        $this->assertNull(TaskCatalog::definition('shell.exec'));
        $this->assertContains('service.restart', TaskCatalog::types());
        $this->assertNotContains('powershell.run', TaskCatalog::types());

        $restart = TaskCatalog::definition('service.restart');
        $this->assertFalse($restart->idempotent);
        $this->assertSame(1, $restart->maxAttempts);
        $this->assertSame(60, $restart->timeoutSeconds);

        $stop = TaskCatalog::definition('service.stop');
        $this->assertTrue($stop->idempotent);
        $this->assertSame(3, $stop->maxAttempts);

        $this->assertSame(30, TaskCatalog::definition('process.list')->timeoutSeconds);
        $this->assertSame(15, TaskCatalog::definition('process.kill')->timeoutSeconds);
        $this->assertSame(30, TaskCatalog::definition('agent.restart')->timeoutSeconds);
        $this->assertSame(120, TaskCatalog::definition('inventory.refresh')->timeoutSeconds);
    }

    public function test_validate_normalizes_valid_params(): void
    {
        $this->assertSame(['name' => 'Spooler'], TaskCatalog::validate('service.start', ['name' => 'Spooler']));
        $this->assertSame(['pid' => 4321, 'name' => 'notepad.exe'], TaskCatalog::validate('process.kill', ['pid' => 4321, 'name' => 'notepad.exe']));
        $this->assertSame([], TaskCatalog::validate('process.list', []));
    }

    public function test_rejects_hostile_service_names(): void
    {
        foreach (['a b', 'a;b', '../x', str_repeat('a', 257), '', 'a|b', "a\nb", str_repeat('a', 129), "Spooler\n", "RpcSs\n", "RpcSs\r\n", "Spooler\r"] as $name) {
            $this->expectsValidationFailure(
                fn () => TaskCatalog::validate('service.start', ['name' => $name]),
                'nome '.json_encode($name)
            );
        }

        foreach ([123, null, ['x'], true] as $name) {
            $this->expectsValidationFailure(
                fn () => TaskCatalog::validate('service.start', ['name' => $name]),
                'nome '.json_encode($name)
            );
        }

        $this->expectsValidationFailure(fn () => TaskCatalog::validate('service.start', []), 'sem name');
    }

    public function test_rejects_bad_pid(): void
    {
        foreach (['4 ', -1, 3, '1234', 12.0, 12.5, null, 0, 4] as $pid) {
            $this->expectsValidationFailure(
                fn () => TaskCatalog::validate('process.kill', ['pid' => $pid, 'name' => 'notepad.exe']),
                'pid '.json_encode($pid)
            );
        }

        $this->expectsValidationFailure(fn () => TaskCatalog::validate('process.kill', ['pid' => 1234]), 'sem name');
        $this->expectsValidationFailure(fn () => TaskCatalog::validate('process.kill', ['pid' => 1234, 'name' => '']), 'name vazio');
        $this->expectsValidationFailure(fn () => TaskCatalog::validate('process.kill', ['pid' => 1234, 'name' => str_repeat('a', 129)]), 'name longo');
        $this->assertSame(5, TaskCatalog::validate('process.kill', ['pid' => 5, 'name' => 'x'])['pid']);
    }

    public function test_rejects_unknown_type_and_extra_params(): void
    {
        $this->expectsValidationFailure(fn () => TaskCatalog::validate('shell.exec', ['cmd' => 'dir']), 'tipo desconhecido');
        $this->expectsValidationFailure(fn () => TaskCatalog::validate('process.list', ['x' => 1]), 'extra em tipo sem params');
        $this->expectsValidationFailure(fn () => TaskCatalog::validate('service.start', ['name' => 'Spooler', 'extra' => 'x']), 'extra em service');
        $this->expectsValidationFailure(fn () => TaskCatalog::validate('process.kill', ['pid' => 99, 'name' => 'x', 'force' => true]), 'extra em kill');
    }

    public function test_protected_service_is_rejected_for_stop_and_restart(): void
    {
        foreach (['service.stop', 'service.restart'] as $type) {
            foreach (['RpcSs', 'rpcss', 'WinDefend', "RpcSs\n", "RpcSs\r\n"] as $name) {
                $this->expectsValidationFailure(fn () => TaskCatalog::validate($type, ['name' => $name]), "$type $name");
            }
        }

        // Iniciar um serviço crítico não derruba nada: segue o fluxo normal (alto risco, pois fora da allowlist).
        $this->assertSame(['name' => 'RpcSs'], TaskCatalog::validate('service.start', ['name' => 'RpcSs']));
        $this->assertSame(TaskRisk::High, TaskCatalog::risk('service.start', ['name' => 'RpcSs']));
    }

    public function test_service_lists_default_to_config_and_can_be_overridden_by_system_settings(): void
    {
        $this->assertSame(config('toolit.tasks.service_allowlist'), TaskCatalog::serviceAllowlist());
        $this->assertContains('Spooler', TaskCatalog::serviceAllowlist());
        $this->assertContains('ToolitAgent', TaskCatalog::serviceProtected());

        SystemSetting::create(['key' => 'tasks.service_allowlist', 'value' => ['Foo']]);
        SystemSetting::create(['key' => 'tasks.service_protected', 'value' => ['Bar']]);

        $this->assertSame(['Foo'], TaskCatalog::serviceAllowlist());
        $this->assertSame(['Bar'], TaskCatalog::serviceProtected());
        $this->assertSame(TaskRisk::Medium, TaskCatalog::risk('service.restart', ['name' => 'foo']));
        $this->assertSame(TaskRisk::High, TaskCatalog::risk('service.restart', ['name' => 'Spooler']));
        $this->expectsValidationFailure(fn () => TaskCatalog::validate('service.stop', ['name' => 'Bar']), 'protegido por setting');
    }

    public function test_permissions_for_high_risk_kill(): void
    {
        $this->assertSame(
            [Permission::TasksCreateHighRisk, Permission::ProcessesKill],
            TaskCatalog::permissionsFor('process.kill', ['pid' => 1234, 'name' => 'notepad.exe'])
        );
    }

    public function test_permissions_for_medium_service(): void
    {
        $this->assertSame(
            [Permission::TasksCreate, Permission::ServicesControl],
            TaskCatalog::permissionsFor('service.restart', ['name' => 'Spooler'])
        );
    }

    public function test_permissions_for_high_risk_service_outside_allowlist(): void
    {
        $this->assertSame(
            [Permission::TasksCreateHighRisk, Permission::ServicesControl],
            TaskCatalog::permissionsFor('service.restart', ['name' => 'Foo'])
        );
    }

    public function test_list_types_need_resource_view(): void
    {
        $this->assertSame([Permission::TasksCreate, Permission::ProcessesView], TaskCatalog::permissionsFor('process.list', []));
        $this->assertSame([Permission::TasksCreate, Permission::ServicesView], TaskCatalog::permissionsFor('service.list', []));
        $this->assertSame([Permission::TasksCreate], TaskCatalog::permissionsFor('system.info', []));
        $this->assertSame([Permission::TasksCreateHighRisk], TaskCatalog::permissionsFor('agent.restart', []));
    }

    private function expectsValidationFailure(callable $fn, string $message = ''): void
    {
        try {
            $fn();
        } catch (ValidationException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('ValidationException esperada. '.$message);
    }
}
