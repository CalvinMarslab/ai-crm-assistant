<?php

namespace App\Providers;

use App\Domain\Agent\Models\Agent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Task\Models\Task;
use App\Models\User;
use App\Policies\AgentPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\ContactPolicy;
use App\Policies\OpportunityPolicy;
use App\Policies\TaskPolicy;
use App\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerMorphMap();
        $this->registerPolicies();
        $this->registerFactoryResolver();
    }

    /**
     * Domain models live under App\Domain\<Module>\Models, so the default
     * App\Models -> Database\Factories convention needs widening.
     */
    private function registerFactoryResolver(): void
    {
        Factory::guessFactoryNamesUsing(
            fn (string $model) => 'Database\\Factories\\'.class_basename($model).'Factory',
        );
    }

    /**
     * Stable, non-leaking aliases for polymorphic subject_type columns.
     * Class names never appear in the database or the API.
     */
    private function registerMorphMap(): void
    {
        Relation::enforceMorphMap([
            'opportunity' => Opportunity::class,
            'company' => Company::class,
            'contact' => Contact::class,
            'agent' => Agent::class,
            'task' => Task::class,
            'user' => User::class,
        ]);
    }

    /**
     * Domain models live outside App\Models, so policies are mapped explicitly
     * rather than relying on Laravel's naming convention.
     */
    private function registerPolicies(): void
    {
        Gate::policy(Opportunity::class, OpportunityPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(Agent::class, AgentPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
    }
}
