<?php

namespace App\Enums;

enum RoleKey: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Support = 'support';
    case Auditor = 'auditor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Operator => 'Operador de TI',
            self::Support => 'Suporte',
            self::Auditor => 'Auditor',
            self::Viewer => 'Consulta',
        };
    }

    /**
     * Permissões do papel (docs/permissions-matrix.md). Negado por padrão.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Operator => [
                Permission::InventoryView, Permission::InventoryViewSensitive, Permission::MetricsView, Permission::AlertsView, Permission::AlertsManage,
                Permission::ProcessesView, Permission::ProcessesKill, Permission::ServicesView, Permission::ServicesControl,
                Permission::TasksView, Permission::TasksCreate, Permission::TasksCreateHighRisk, Permission::TasksCancel,
                Permission::FilesInventoryView, Permission::FilesTransferSend, Permission::FilesTransferFetch,
                Permission::EndpointsManage, Permission::EndpointsRevoke, Permission::EnrollmentCreate,
            ],
            self::Support => [
                Permission::InventoryView, Permission::InventoryViewSensitive, Permission::MetricsView, Permission::AlertsView, Permission::AlertsManage,
                Permission::ProcessesView, Permission::ServicesView,
                Permission::TasksView, Permission::TasksCreate, Permission::TasksCancel,
                Permission::FilesInventoryView, Permission::FilesTransferFetch, Permission::ScreenView,
            ],
            self::Auditor => [
                Permission::InventoryView, Permission::InventoryViewSensitive, Permission::MetricsView, Permission::AlertsView,
                Permission::TasksView, Permission::FilesInventoryView, Permission::AuditView,
            ],
            self::Viewer => [
                Permission::InventoryView, Permission::MetricsView, Permission::AlertsView,
            ],
        };
    }
}
