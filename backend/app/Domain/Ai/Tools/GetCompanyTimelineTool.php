<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Activity\Models\Activity;
use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GetCompanyTimelineTool extends ReadTool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_company_timeline',
            description: 'The unified history for one company, covering the company itself and every '
                .'opportunity belonging to it. Use for "what has happened with this customer" questions.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'reference' => ['type' => 'string', 'description' => 'Company reference from search_companies.'],
                    'limit' => ['type' => 'integer', 'description' => 'Default 30, capped at 100.'],
                ],
                'required' => ['reference'],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::CompanyViewAll->value;
    }

    public function execute(User $user, array $arguments): array
    {
        $company = Company::query()->where('uuid', $arguments['reference'] ?? '')->first();

        if ($company === null) {
            throw ValidationException::withMessages(['reference' => 'No company with that reference.']);
        }

        Gate::forUser($user)->authorize('view', $company);

        $activities = Activity::query()
            ->where('company_id', $company->id)
            ->visibleTo($user)
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->limit(min((int) ($arguments['limit'] ?? 30), 100))
            ->get();

        return [
            'company' => $company->name,
            'events' => $activities->map(fn (Activity $a) => [
                'at' => $a->occurred_at?->toDateTimeString(),
                'type' => $a->activity_type->value,
                'title' => $a->title,
                'note' => $a->body,
                'by' => $a->actor?->name,
            ])->all(),
        ];
    }
}
