<?php

namespace App\Domain\Opportunity\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Services\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LeadIntakeService
{
    public function __construct(
        private readonly OpportunityService $opportunities,
        private readonly TaskService $tasks,
    ) {}

    /** @param array<string, mixed> $data */
    public function ingest(User $user, array $data): array
    {
        Auth::setUser($user);

        return DB::transaction(function () use ($user, $data): array {
            $agent = $this->resolveAgent($user, $data);
            [$company, $companyCreated] = $this->resolveCompany($user, $data);
            $contact = $this->resolveContact($user, $company, $data);

            Gate::forUser($user)->authorize('create', Opportunity::class);
            $opportunity = $this->opportunities->create([
                'company_id' => $company->id,
                'primary_contact_id' => $contact?->id,
                'referral_agent_id' => $agent?->id,
                'owner_user_id' => $user->id,
                'title' => $data['lead_title'],
                'summary' => $data['summary'] ?? null,
                'requirements' => $data['requirements'],
                'estimated_value' => $data['estimated_value'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'next_action' => 'Prepare proposal',
                'next_follow_up_at' => $data['proposal_due_at'] ?? null,
            ]);

            Gate::forUser($user)->authorize('create', Task::class);
            $task = $this->tasks->create([
                'title' => 'Prepare proposal: '.$opportunity->title,
                'description' => $data['requirements'],
                'due_at' => $data['proposal_due_at'] ?? null,
                'priority' => $data['priority'] ?? 'high',
                'created_by_user_id' => $user->id,
                'assigned_user_id' => $user->id,
                'subject_type' => $opportunity->getMorphClass(),
                'subject_id' => $opportunity->id,
                'source' => 'ai',
            ]);

            return [
                'company_created' => $companyCreated,
                'company' => ['reference' => $company->uuid, 'name' => $company->name],
                'agent' => $agent === null ? null : ['reference' => $agent->uuid, 'name' => $agent->name],
                'opportunity' => ['reference' => $opportunity->uuid, 'title' => $opportunity->title],
                'proposal_task' => ['reference' => $task->uuid, 'title' => $task->title],
            ];
        });
    }

    /** @param array<string, mixed> $data */
    private function resolveAgent(User $user, array $data): ?Agent
    {
        if (blank($data['agent_name'] ?? null)) {
            return null;
        }

        if (blank($data['agent_email'] ?? null) && blank($data['agent_phone'] ?? null)) {
            throw ValidationException::withMessages(['agent' => 'Agent email or phone is required for safe duplicate checking.']);
        }

        $emailMatch = filled($data['agent_email'] ?? null)
            ? Agent::query()->whereRaw('LOWER(email) = ?', [strtolower($data['agent_email'])])->first()
            : null;
        $phoneMatch = filled($data['agent_phone'] ?? null)
            ? Agent::query()->where('phone', $data['agent_phone'])->first()
            : null;

        if ($emailMatch !== null && $phoneMatch !== null && $emailMatch->id !== $phoneMatch->id) {
            throw ValidationException::withMessages(['agent' => 'Agent email and phone match different records. Please verify the details.']);
        }

        $agent = $emailMatch ?? $phoneMatch;

        if ($agent !== null) {
            return $agent;
        }

        Gate::forUser($user)->authorize('create', Agent::class);

        return Agent::create([
            'name' => $data['agent_name'],
            'company_name' => $data['agent_company'] ?? null,
            'email' => $data['agent_email'] ?? null,
            'phone' => $data['agent_phone'] ?? null,
            'status' => 'active',
            'joined_at' => now()->toDateString(),
        ]);
    }

    /** @param array<string, mixed> $data @return array{Company, bool} */
    private function resolveCompany(User $user, array $data): array
    {
        $query = Company::query();
        if (filled($data['company_registration_no'] ?? null)) {
            $query->where('registration_no', $data['company_registration_no']);
        } elseif (filled($data['company_email'] ?? null)) {
            $query->whereRaw('LOWER(email) = ?', [strtolower($data['company_email'])]);
        } elseif (filled($data['company_phone'] ?? null)) {
            $query->where('phone', $data['company_phone']);
        } else {
            $query->whereRaw('LOWER(name) = ?', [strtolower($data['company_name'])]);
        }

        $matches = $query->limit(2)->get();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['company' => 'Multiple clients matched. Add registration number, email, or phone.']);
        }
        if ($matches->count() === 1) {
            return [$matches->first(), false];
        }

        Gate::forUser($user)->authorize('create', Company::class);

        return [Company::create([
            'name' => $data['company_name'],
            'registration_no' => $data['company_registration_no'] ?? null,
            'email' => $data['company_email'] ?? null,
            'phone' => $data['company_phone'] ?? null,
            'industry' => $data['company_industry'] ?? null,
        ]), true];
    }

    /** @param array<string, mixed> $data */
    private function resolveContact(User $user, Company $company, array $data): ?Contact
    {
        if (blank($data['contact_name'] ?? null)) {
            return null;
        }

        $contact = $company->contacts()
            ->when(filled($data['contact_email'] ?? null), fn ($q) => $q->whereRaw('LOWER(email) = ?', [strtolower($data['contact_email'])]))
            ->when(blank($data['contact_email'] ?? null) && filled($data['contact_phone'] ?? null), fn ($q) => $q->where('phone', $data['contact_phone']))
            ->when(blank($data['contact_email'] ?? null) && blank($data['contact_phone'] ?? null), fn ($q) => $q->whereRaw('LOWER(name) = ?', [strtolower($data['contact_name'])]))
            ->first();

        if ($contact !== null) {
            return $contact;
        }

        Gate::forUser($user)->authorize('create', Contact::class);

        return Contact::create([
            'company_id' => $company->id,
            'name' => $data['contact_name'],
            'email' => $data['contact_email'] ?? null,
            'phone' => $data['contact_phone'] ?? null,
            'job_title' => $data['contact_job_title'] ?? null,
            'is_primary' => ! $company->contacts()->exists(),
        ]);
    }
}
