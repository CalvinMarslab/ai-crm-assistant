<?php

namespace Database\Seeders;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Identity\Models\Role;
use App\Domain\Opportunity\Data\StageChangeData;
use App\Domain\Opportunity\Models\LeadSource;
use App\Domain\Opportunity\Services\OpportunityService;
use App\Domain\Opportunity\Services\StageTransitionService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Pipeline\Models\Pipeline;
use App\Domain\Pipeline\Models\PipelineStage;
use App\Domain\Task\Models\Task;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Local development data shaped so every dashboard section has something in it:
 * overdue tasks, follow-ups due today, opportunities with no next action,
 * proposals awaiting a reply, and deals that have gone quiet.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::first();
        OrganizationContext::set($organization->id);

        $owner = User::where('email', 'owner@aicrm.test')->firstOrFail();
        Auth::login($owner);

        $salesUser = $this->user($organization, 'Amirah Yusof', 'amirah@aicrm.test', RoleCode::Owner);
        $pm = $this->user($organization, 'Daniel Lim', 'daniel@aicrm.test', RoleCode::ProjectManager);
        $agentUser = $this->user($organization, 'Brian Tan', 'brian@aicrm.test', RoleCode::ReferralAgent);

        $brian = Agent::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Brian Tan'],
            ['user_id' => $agentUser->id, 'company_name' => 'BT Consulting', 'email' => 'brian@aicrm.test',
                'phone' => '+60 12-345 6789', 'status' => 'active', 'joined_at' => now()->subYear()],
        );

        $siti = Agent::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Siti Rahman'],
            ['company_name' => 'SR Ventures', 'email' => 'siti@partners.test',
                'phone' => '+60 19-876 5432', 'status' => 'active', 'joined_at' => now()->subMonths(5)],
        );

        $pipeline = Pipeline::default();
        $opportunities = app(OpportunityService::class);
        $transitions = app(StageTransitionService::class);

        foreach ($this->companies() as $spec) {
            $company = Company::firstOrCreate(
                ['organization_id' => $organization->id, 'name' => $spec['name']],
                ['industry' => $spec['industry'], 'phone' => $spec['phone'], 'email' => $spec['email'],
                    'address' => $spec['address']],
            );

            $contact = Contact::firstOrCreate(
                ['organization_id' => $organization->id, 'company_id' => $company->id, 'name' => $spec['contact']],
                ['job_title' => $spec['contact_title'], 'email' => $spec['contact_email'],
                    'phone' => $spec['phone'], 'is_primary' => true],
            );

            foreach ($spec['opportunities'] as $opportunitySpec) {
                $this->seedOpportunity(
                    $opportunities, $transitions, $pipeline, $company, $contact,
                    $opportunitySpec, [$owner, $salesUser], ['brian' => $brian, 'siti' => $siti], $pm,
                );
            }
        }

        Auth::logout();
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<int, User>  $owners
     * @param  array<string, Agent>  $agents
     */
    private function seedOpportunity(
        OpportunityService $opportunities,
        StageTransitionService $transitions,
        Pipeline $pipeline,
        Company $company,
        Contact $contact,
        array $spec,
        array $owners,
        array $agents,
        User $pm,
    ): void {
        $existing = \App\Domain\Opportunity\Models\Opportunity::where('company_id', $company->id)
            ->where('title', $spec['title'])
            ->exists();

        if ($existing) {
            return;
        }

        $opportunity = $opportunities->create([
            'company_id' => $company->id,
            'primary_contact_id' => $contact->id,
            'pipeline_id' => $pipeline->id,
            'owner_user_id' => $owners[$spec['owner']]->id,
            'referral_agent_id' => isset($spec['agent']) ? $agents[$spec['agent']]->id : null,
            'lead_source_id' => LeadSource::where('code', $spec['source'])->value('id'),
            'title' => $spec['title'],
            'summary' => $spec['summary'],
            'requirements' => $spec['requirements'] ?? null,
            'estimated_value' => $spec['value'],
            'priority' => $spec['priority'] ?? 'normal',
            'expected_close_date' => $spec['close_in_days'] === null ? null : now()->addDays($spec['close_in_days']),
            'next_action' => $spec['next_action'] ?? null,
            'next_follow_up_at' => isset($spec['follow_up_in_days'])
                ? now()->addDays($spec['follow_up_in_days'])
                : null,
            'no_action_reason' => $spec['no_action_reason'] ?? null,
            'quotation_status' => $spec['quotation_status'] ?? null,
            'quotation_amount' => $spec['quotation_amount'] ?? null,
            'quotation_sent_at' => isset($spec['quotation_sent_days_ago'])
                ? now()->subDays($spec['quotation_sent_days_ago'])
                : null,
            'last_contact_at' => isset($spec['last_contact_days_ago'])
                ? now()->subDays($spec['last_contact_days_ago'])
                : now(),
        ]);

        // Walk the deal to its current stage so stage history reads realistically.
        foreach ($spec['stage_path'] ?? [] as $code) {
            $stage = PipelineStage::where('pipeline_id', $pipeline->id)->where('code', $code)->first();

            if ($stage === null) {
                continue;
            }

            $transitions->change($opportunity, new StageChangeData(
                toStageId: $stage->id,
                note: 'Progressed during discovery.',
                lossReason: $stage->isLost() ? ($spec['loss_reason'] ?? 'Price') : null,
                finalValue: $stage->isWon() ? ($spec['final_value'] ?? $spec['value']) : null,
            ));

            $opportunity->refresh();
        }

        foreach ($spec['notes'] ?? [] as $note) {
            $opportunities->addNote($opportunity, $note['body'], $note['internal'] ?? false, ActivityType::NoteAdded);
        }

        foreach ($spec['tasks'] ?? [] as $task) {
            Task::create([
                'organization_id' => $company->organization_id,
                'assigned_user_id' => $task['assign_pm'] ?? false ? $pm->id : $owners[$spec['owner']]->id,
                'created_by_user_id' => $owners[$spec['owner']]->id,
                'subject_type' => 'opportunity',
                'subject_id' => $opportunity->id,
                'title' => $task['title'],
                'due_at' => now()->addDays($task['due_in_days']),
                'priority' => $task['priority'] ?? 'normal',
                'status' => $task['status'] ?? 'to_do',
            ]);
        }
    }

    private function user(Organization $organization, string $name, string $email, RoleCode $role): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['organization_id' => $organization->id, 'name' => $name, 'password' => 'password', 'status' => 'active'],
        );

        $user->roles()->syncWithoutDetaching([Role::firstWhere('code', $role->value)->id]);

        return $user;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function companies(): array
    {
        return [
            [
                'name' => 'ABC Manufacturing Sdn Bhd',
                'industry' => 'Manufacturing',
                'phone' => '+60 3-7788 1122',
                'email' => 'enquiry@abcmfg.test',
                'address' => 'Lot 12, Jalan Perindustrian 3, 40150 Shah Alam, Selangor',
                'contact' => 'Wong Mei Ling',
                'contact_title' => 'Operations Director',
                'contact_email' => 'meiling@abcmfg.test',
                'opportunities' => [
                    [
                        'title' => 'Website Revamp 2026',
                        'summary' => 'Corporate site rebuild with a product catalogue and enquiry funnel.',
                        'requirements' => 'Bilingual site, CMS, product catalogue, enquiry routing to sales inbox.',
                        'value' => 68000, 'owner' => 0, 'agent' => 'brian', 'source' => 'referral_agent',
                        'priority' => 'high', 'close_in_days' => 30,
                        'stage_path' => ['contacted', 'requirement_gathering', 'proposal_preparation', 'proposal_sent'],
                        'quotation_status' => 'sent', 'quotation_amount' => 66500, 'quotation_sent_days_ago' => 6,
                        'next_action' => 'Chase the quotation response', 'follow_up_in_days' => 0,
                        'last_contact_days_ago' => 6,
                        'notes' => [
                            ['body' => 'Mei Ling confirmed budget is approved for this financial year.'],
                            ['body' => 'Margin is tight at this price — do not discount further.', 'internal' => true],
                        ],
                        'tasks' => [
                            ['title' => 'Call Mei Ling about the quotation', 'due_in_days' => -2, 'priority' => 'high'],
                        ],
                    ],
                    [
                        'title' => 'Maintenance Contract 2027',
                        'summary' => 'Annual support retainer following the website project.',
                        'value' => 24000, 'owner' => 1, 'source' => 'existing_customer',
                        'close_in_days' => 90, 'stage_path' => ['contacted'],
                        'no_action_reason' => 'Waiting until the website project is signed off',
                        'last_contact_days_ago' => 3,
                    ],
                ],
            ],
            [
                'name' => 'Nusantara Logistics Bhd',
                'industry' => 'Logistics',
                'phone' => '+60 3-5566 7788',
                'email' => 'hello@nusantaralog.test',
                'address' => 'Level 8, Menara Timur, Jalan Ampang, 50450 Kuala Lumpur',
                'contact' => 'Farid Abdullah',
                'contact_title' => 'Head of Technology',
                'contact_email' => 'farid@nusantaralog.test',
                'opportunities' => [
                    [
                        'title' => 'Fleet Tracking Mobile App',
                        'summary' => 'Driver-facing Android app with live job dispatch.',
                        'requirements' => 'Offline-capable job list, proof of delivery photos, route history.',
                        'value' => 185000, 'owner' => 0, 'agent' => 'siti', 'source' => 'bni',
                        'priority' => 'urgent', 'close_in_days' => 45,
                        'stage_path' => ['contacted', 'requirement_gathering', 'qualified'],
                        // Deliberately left without a next action so the dashboard flags it.
                        'last_contact_days_ago' => 12,
                        'notes' => [
                            ['body' => 'Farid wants a phased rollout starting with the Klang depot.'],
                        ],
                        'tasks' => [
                            ['title' => 'Prepare phased rollout proposal', 'due_in_days' => -4, 'priority' => 'urgent'],
                            ['title' => 'Confirm integration scope with their ERP vendor', 'due_in_days' => 5],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Seri Delima Retail',
                'industry' => 'Retail',
                'phone' => '+60 4-229 3344',
                'email' => 'admin@seridelima.test',
                'address' => '88 Lebuh Chulia, 10200 George Town, Pulau Pinang',
                'contact' => 'Priya Nair',
                'contact_title' => 'General Manager',
                'contact_email' => 'priya@seridelima.test',
                'opportunities' => [
                    [
                        'title' => 'POS Integration Project',
                        'summary' => 'Integrate in-store POS with the accounting system.',
                        'value' => 42000, 'owner' => 1, 'agent' => 'brian', 'source' => 'friend_referral',
                        'close_in_days' => -5,
                        'stage_path' => ['contacted', 'requirement_gathering', 'qualified', 'proposal_preparation', 'proposal_sent', 'negotiation', 'won'],
                        'final_value' => 44500,
                        'last_contact_days_ago' => 2,
                        'notes' => [['body' => 'Signed off by Priya. Kick-off scheduled for next month.']],
                        'tasks' => [
                            ['title' => 'Hand over to delivery team', 'due_in_days' => 2, 'assign_pm' => true],
                        ],
                    ],
                    [
                        'title' => 'E-commerce Storefront',
                        'summary' => 'Online storefront with click-and-collect.',
                        'value' => 55000, 'owner' => 0, 'source' => 'website',
                        'close_in_days' => 20,
                        'stage_path' => ['contacted', 'requirement_gathering', 'proposal_preparation', 'proposal_sent', 'negotiation', 'lost'],
                        'loss_reason' => 'Went with a competitor',
                        'last_contact_days_ago' => 15,
                    ],
                ],
            ],
            [
                'name' => 'Harmoni Food Industries',
                'industry' => 'F&B',
                'phone' => '+60 7-334 5566',
                'email' => 'contact@harmonifood.test',
                'address' => 'PLO 4, Kawasan Perindustrian Pasir Gudang, 81700 Johor',
                'contact' => 'Tan Wei Jie',
                'contact_title' => 'Managing Director',
                'contact_email' => 'weijie@harmonifood.test',
                'opportunities' => [
                    [
                        'title' => 'Production Dashboard',
                        'summary' => 'Real-time line output dashboard for the plant floor.',
                        'value' => 96000, 'owner' => 0, 'agent' => 'siti', 'source' => 'whatsapp',
                        'priority' => 'high', 'close_in_days' => 60,
                        'stage_path' => ['contacted'],
                        'next_action' => 'Arrange the plant floor walkthrough',
                        'follow_up_in_days' => 0,
                        // Quiet for long enough to trip the inactivity threshold.
                        'last_contact_days_ago' => 21,
                    ],
                ],
            ],
        ];
    }
}
