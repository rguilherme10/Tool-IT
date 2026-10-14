<?php

namespace App\Enums;

/**
 * Fonte da verdade das permissões. Mantida em sincronia com docs/permissions-matrix.md
 * (o teste RolePermissionMatrixTest compara papel × permissão).
 */
enum Permission: string
{
    case InventoryView = 'inventory.view';
    /** Software instalado completo, IPs, MACs e usuário logado (dados pessoais/sensíveis; fora do perfil Consulta). */
    case InventoryViewSensitive = 'inventory.view_sensitive';
    case MetricsView = 'metrics.view';
    case AlertsView = 'alerts.view';
    case AlertsManage = 'alerts.manage';
    case ProcessesView = 'processes.view';
    case ProcessesKill = 'processes.kill';
    case ServicesView = 'services.view';
    case ServicesControl = 'services.control';
    case TasksView = 'tasks.view';
    case TasksCreate = 'tasks.create';
    case TasksCreateHighRisk = 'tasks.create_high_risk';
    case TasksApprove = 'tasks.approve';
    case TasksCancel = 'tasks.cancel';
    case PowershellExecute = 'powershell.execute';
    case FilesInventoryView = 'files.inventory_view';
    case FilesTransferSend = 'files.transfer_send';
    case FilesTransferFetch = 'files.transfer_fetch';
    case FilesExecute = 'files.execute';
    case ScreenView = 'screen.view';
    case EndpointsManage = 'endpoints.manage';
    case EndpointsRevoke = 'endpoints.revoke';
    case EnrollmentCreate = 'enrollment.create';
    case EnrollmentCreateMulti = 'enrollment.create_multi';
    case AgentsVersionsManage = 'agents.versions_manage';
    case AuditView = 'audit.view';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case SettingsManage = 'settings.manage';

    /** Permissões de nível de sistema exigem atribuição GLOBAL (não valem por grupo). */
    public function isSystemLevel(): bool
    {
        return in_array($this, [
            self::UsersManage, self::RolesManage, self::SettingsManage,
            self::AuditView, self::AgentsVersionsManage, self::EnrollmentCreateMulti,
        ], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
