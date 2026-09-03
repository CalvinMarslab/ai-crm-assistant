<?php

namespace Database\Seeders;

use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Identity\Models\Role;
use App\Domain\Opportunity\Models\LeadSource;
use App\Domain\Organization\Models\Organization;
use App\Domain\Pipeline\Enums\StageType;
use App\Domain\Pipeline\Models\Pipeline;
use App\Domain\Pipeline\Models\PipelineStage;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Database\Seeder;

/**
 * Bootstraps the single V1 organization with its default pipeline, lead
 * sources, and an owner account.
 */
class OrganizationSeeder extends Seeder
{
    /** PRD section 6 default stages, mapped to agent-facing statuses per CRM_WORKFLOW.md section 7. */
    private const STAGES = [
        ['New Lead', 'new_lead', StageType::Open, 10, 'New'],
        ['Contacted', 'contacted', StageType::Open, 20, 'In Discussion'],
        ['Requirement Gathering', 'requirement_gathering', StageType::Open, 30, 'In Discussion'],
        ['Qualified', 'qualified', StageType::Open, 40, 'In Discussion'],
        ['Proposal / Quotation Preparation', 'proposal_preparation', StageType::Open, 50, 'Proposal Stage'],
        ['Proposal Sent', 'proposal_sent', StageType::Open, 60, 'Proposal Stage'],
        ['Follow-up / Negotiation', 'negotiation', StageType::Open, 75, 'Negotiation'],
        ['Won', 'won', StageType::Won, 100, 'Won'],
        ['Lost', 'lost', StageType::Lost, 0, 'Lost'],
        ['On Hold', 'on_hold', StageType::Hold, null, 'In Discussion'],
    ];

    private const LEAD_SOURCES = [
        ['Referral Agent', 'referral_agent'],
        ['Friend Referral', 'friend_referral'],
        ['BNI', 'bni'],
        ['Facebook', 'facebook'],
        ['Website', 'website'],
        ['WhatsApp', 'whatsapp'],
        ['Existing Customer', 'existing_customer'],
        ['Other', 'other'],
    ];

    public function run(): void
    {
        OrganizationContext::withoutScope(function () {
            $organization = Organization::firstOrCreate(
                ['name' => 'Nexora Solutions'],
                ['status' => 'active', 'timezone' => 'Asia/Kuala_Lumpur', 'settings' => ['inactivity_threshold_days' => 7]],
            );

            OrganizationContext::set($organization->id);

            $this->seedPipeline($organization->id);
            $this->seedLeadSources($organization->id);
            $this->seedOwner($organization->id);
        });
    }

    private function seedPipeline(int $organizationId): void
    {
        $pipeline = Pipeline::firstOrCreate(
            ['organization_id' => $organizationId, 'name' => 'Default Sales Pipeline'],
            ['is_default' => true],
        );

        foreach (self::STAGES as $sequence => [$name, $code, $type, $probability, $agentStatus]) {
            PipelineStage::updateOrCreate(
                ['pipeline_id' => $pipeline->id, 'code' => $code],
                [
                    'name' => $name,
                    'sequence' => ($sequence + 1) * 10,
                    'stage_type' => $type,
                    'probability_default' => $probability,
                    'agent_facing_status' => $agentStatus,
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedLeadSources(int $organizationId): void
    {
        foreach (self::LEAD_SOURCES as [$name, $code]) {
            LeadSource::updateOrCreate(
                ['organization_id' => $organizationId, 'code' => $code],
                ['name' => $name, 'is_active' => true],
            );
        }
    }

    private function seedOwner(int $organizationId): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'owner@aicrm.test'],
            [
                'organization_id' => $organizationId,
                'name' => 'System Owner',
                'password' => 'password',
                'status' => 'active',
            ],
        );

        $ownerRole = Role::where('code', RoleCode::Owner->value)->first();

        if ($ownerRole !== null) {
            $owner->roles()->syncWithoutDetaching([$ownerRole->id]);
        }
    }
}
