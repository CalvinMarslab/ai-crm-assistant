<?php

namespace App\Domain\Identity\Enums;

enum RoleCode: string
{
    case Owner = 'owner';
    case ReferralAgent = 'referral_agent';
    case ProjectManager = 'project_manager';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner / Admin',
            self::ReferralAgent => 'Referral Agent',
            self::ProjectManager => 'Project Manager',
        };
    }

    /**
     * @return array<int, PermissionCode>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => PermissionCode::cases(),

            // Agents see only what they introduced, and never internal material.
            // Project visibility is high-level status only.
            self::ReferralAgent => [
                PermissionCode::PortalAccess,
                PermissionCode::AgentViewOwn,
                PermissionCode::OpportunityViewOwnReferrals,
                PermissionCode::OpportunityCreate,
                PermissionCode::ProjectViewOwnReferrals,
            ],

            // PMs get delivery context for the projects assigned to them, and
            // the sales history behind those projects — but no wider pipeline.
            self::ProjectManager => [
                PermissionCode::CompanyViewAll,
                PermissionCode::ContactViewAll,
                PermissionCode::OpportunityViewOwn,
                PermissionCode::TaskViewOwn,
                PermissionCode::TaskManage,
                PermissionCode::PipelineView,
                PermissionCode::ProjectViewAssigned,
                PermissionCode::ProjectUpdate,
                PermissionCode::ProjectUpdateStatus,
                PermissionCode::ProjectManageHandover,
                PermissionCode::DocumentView,
                PermissionCode::DocumentUpload,
            ],
        };
    }
}
