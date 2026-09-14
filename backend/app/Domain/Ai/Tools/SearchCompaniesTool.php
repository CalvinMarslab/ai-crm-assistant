<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SearchCompaniesTool extends ReadTool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'search_companies',
            description: 'Find customer companies by name, industry, or contact details.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string'],
                    'industry' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'description' => 'Default 20, capped at 50.'],
                ],
            ],
        );
    }

    public function permission(): ?string
    {
        return PermissionCode::CompanyViewAll->value;
    }

    public function execute(User $user, array $arguments): array
    {
        $companies = Company::query()
            ->when(filled($arguments['search'] ?? null), fn (Builder $q) => $q->where('name', 'like', '%'.$arguments['search'].'%'))
            ->when(filled($arguments['industry'] ?? null), fn (Builder $q) => $q->where('industry', 'like', '%'.$arguments['industry'].'%'))
            ->withCount(['opportunities', 'contacts'])
            ->orderBy('name')
            ->limit(min((int) ($arguments['limit'] ?? 20), 50))
            ->get();

        return [
            'count' => $companies->count(),
            'companies' => $companies->map(fn (Company $c) => [
                'reference' => $c->uuid,
                'name' => $c->name,
                'industry' => $c->industry,
                'opportunities' => $c->opportunities_count,
                'contacts' => $c->contacts_count,
            ])->all(),
        ];
    }
}
