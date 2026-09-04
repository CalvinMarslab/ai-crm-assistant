<?php

namespace App\Domain\Project\Services;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectHandoverItem;

/**
 * The default handover checklist, drawn from what CRM_WORKFLOW.md section 6
 * says a project manager must receive. Stored per project rather than
 * referenced, so editing the template never rewrites history on live projects.
 */
class HandoverChecklist
{
    /**
     * @var array<int, array{title: string, description: string}>
     */
    private const ITEMS = [
        [
            'title' => 'Confirm company and contact details',
            'description' => 'Verify the customer name, address, and who the day-to-day contact will be during delivery.',
        ],
        [
            'title' => 'Review the opportunity summary and requirements',
            'description' => 'Read the sales history so the customer is not asked to repeat what they already explained.',
        ],
        [
            'title' => 'Confirm the quotation reference and agreed scope',
            'description' => 'Check the final value and what it does and does not cover, so scope creep is visible early.',
        ],
        [
            'title' => 'Collect relevant documents',
            'description' => 'Gather the proposal, signed acceptance, and any specifications shared during the sale.',
        ],
        [
            'title' => 'Hold the internal sales-to-delivery handover',
            'description' => 'Sales walks the project manager through commitments, risks, and anything promised verbally.',
        ],
        [
            'title' => 'Agree the project timeline with the customer',
            'description' => 'Set a start date and a target end date both sides have accepted.',
        ],
        [
            'title' => 'Introduce the project manager to the customer',
            'description' => 'Make the handover explicit so the customer knows who to contact from now on.',
        ],
    ];

    /**
     * @return array<int, ProjectHandoverItem>
     */
    public function createFor(Project $project): array
    {
        $created = [];

        foreach (self::ITEMS as $index => $item) {
            $created[] = ProjectHandoverItem::create([
                'project_id' => $project->id,
                'title' => $item['title'],
                'description' => $item['description'],
                'sequence' => ($index + 1) * 10,
                'assigned_user_id' => $project->project_manager_user_id,
            ]);
        }

        return $created;
    }
}
