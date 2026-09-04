<?php

namespace App\Domain\Activity\Enums;

/**
 * Timeline event vocabulary (PRD section 9). Kept as an enum so the timeline UI
 * and the Phase 3 AI summariser share one list.
 */
enum ActivityType: string
{
    case OpportunityCreated = 'opportunity.created';
    case OpportunityUpdated = 'opportunity.updated';
    case StageChanged = 'opportunity.stage_changed';
    case OwnerChanged = 'opportunity.owner_changed';
    case AgentChanged = 'opportunity.agent_changed';
    case NextActionChanged = 'opportunity.next_action_changed';
    case FollowUpChanged = 'opportunity.follow_up_changed';
    case QuotationUpdated = 'opportunity.quotation_updated';
    case OpportunityWon = 'opportunity.won';
    case OpportunityLost = 'opportunity.lost';
    case NoteAdded = 'note.added';
    case CallLogged = 'call.logged';
    case MeetingLogged = 'meeting.logged';
    case CustomerReplyNoted = 'customer.reply_noted';
    case TaskCreated = 'task.created';
    case TaskCompleted = 'task.completed';
    case TaskReopened = 'task.reopened';
    // Projects (Phase 2)
    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case ProjectStatusChanged = 'project.status_changed';
    case ProjectManagerAssigned = 'project.manager_assigned';
    case ProjectCompleted = 'project.completed';
    case HandoverItemUpdated = 'project.handover_item_updated';

    case CompanyCreated = 'company.created';
    case ContactCreated = 'contact.created';

    public function label(): string
    {
        return str(str_replace(['.', '_'], ' ', $this->value))->headline()->toString();
    }
}
