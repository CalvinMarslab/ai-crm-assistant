<?php

namespace App\Domain\Identity\Enums;

/**
 * Granular permission codes. The UI exposes three roles, but authorization is
 * always decided on codes (USER_ROLES_PERMISSION.md, "Permission Design
 * Requirement") so new roles do not require touching policies.
 */
enum PermissionCode: string
{
    // Companies & contacts
    case CompanyViewAll = 'company.view.all';
    case CompanyManage = 'company.manage';
    case ContactViewAll = 'contact.view.all';
    case ContactManage = 'contact.manage';

    // Agents
    case AgentViewAll = 'agent.view.all';
    case AgentViewOwn = 'agent.view.own';
    case AgentManage = 'agent.manage';

    // Opportunities
    case OpportunityViewAll = 'opportunity.view.all';
    case OpportunityViewOwn = 'opportunity.view.own';
    case OpportunityViewOwnReferrals = 'opportunity.view.own_referrals';
    case OpportunityCreate = 'opportunity.create';
    case OpportunityUpdate = 'opportunity.update';
    case OpportunityDelete = 'opportunity.delete';
    case OpportunityChangeStage = 'opportunity.stage.change';
    case OpportunityAssignOwner = 'opportunity.assign.owner';
    case OpportunityAssignAgent = 'opportunity.assign.agent';
    case OpportunityViewFinancials = 'opportunity.financials.view';
    case OpportunityViewInternalNotes = 'opportunity.notes.internal.view';

    // Pipeline configuration
    case PipelineView = 'pipeline.view';
    case PipelineManage = 'pipeline.manage';

    // Tasks
    case TaskViewAll = 'task.view.all';
    case TaskViewOwn = 'task.view.own';
    case TaskManage = 'task.manage';

    // Users & roles
    case UserViewAll = 'user.view.all';
    case UserManage = 'user.manage';

    // Projects and handover (Phase 2)
    case ProjectViewAll = 'project.view.all';
    case ProjectViewAssigned = 'project.view.assigned';
    case ProjectViewOwnReferrals = 'project.view.own_referrals';
    case ProjectCreate = 'project.create';
    case ProjectUpdate = 'project.update';
    case ProjectDelete = 'project.delete';
    case ProjectAssignManager = 'project.assign.manager';
    case ProjectUpdateStatus = 'project.status.update';
    case ProjectManageHandover = 'project.handover.manage';
    case ProjectViewFinancials = 'project.financials.view';

    // Documents (Phase 2)
    case DocumentView = 'document.view';
    case DocumentUpload = 'document.upload';
    case DocumentDelete = 'document.delete';

    // Agent portal (Phase 2)
    case PortalAccess = 'portal.access';

    // Dashboard, audit, notifications
    case DashboardViewOwner = 'dashboard.view.owner';
    case AuditView = 'audit.view';

    public function label(): string
    {
        return str(str_replace('.', ' ', $this->value))->headline()->toString();
    }

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }
}
