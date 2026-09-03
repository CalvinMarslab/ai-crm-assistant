<?php

namespace App\Providers;

use App\Domain\Agent\Models\Agent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Task\Models\Task;
use App\Models\User;
use App\Support\OrganizationClock;
use App\Policies\AgentPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\ContactPolicy;
use App\Policies\OpportunityPolicy;
use App\Policies\TaskPolicy;
use App\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request so the organization's timezone is looked up
        // once; SetOrganizationContext resets it as the acting tenant changes.
        $this->app->singleton(OrganizationClock::class);
    }

    public function boot(): void
    {
        $this->registerMorphMap();
        $this->registerPolicies();
        $this->registerFactoryResolver();
        $this->registerTenantGuard();
        $this->registerRateLimiters();
    }

    private function registerRateLimiters(): void
    {
        // Authenticated traffic is generous; the login endpoint is not, and is
        // keyed on both address and submitted email so one attacker cannot lock
        // out a legitimate user by guessing against their account.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);
    }

    /**
     * Defence in depth for multi-tenancy. The global query scope is the primary
     * mechanism, but it depends on the request context having been set. This
     * check runs on every authorization call and refuses outright whenever the
     * subject belongs to a different organization than the acting user, so a
     * record reached by any means still cannot be acted upon.
     */
    private function registerTenantGuard(): void
    {
        Gate::before(function (User $user, string $ability, array $arguments = []) {
            foreach ($arguments as $argument) {
                if (! $argument instanceof Model) {
                    continue;
                }

                $organizationId = $argument->getAttribute('organization_id');

                if ($organizationId !== null && (int) $organizationId !== (int) $user->organization_id) {
                    return false;
                }
            }

            return null;
        });
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
