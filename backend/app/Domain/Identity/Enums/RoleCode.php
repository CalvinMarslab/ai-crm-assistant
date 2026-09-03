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
            self::ReferralAgent => [
                PermissionCode::AgentViewOwn,
                PermissionCode::OpportunityViewOwnReferrals,
                PermissionCode::OpportunityCreate,
            ],

            // PMs get delivery context; sales visibility is granted per project in Phase 2.
            self::ProjectManager => [
                PermissionCode::CompanyViewAll,
                PermissionCode::ContactViewAll,
                PermissionCode::OpportunityViewOwn,
                PermissionCode::TaskViewOwn,
                PermissionCode::TaskManage,
                PermissionCode::PipelineView,
            ],
        };
    }
}
