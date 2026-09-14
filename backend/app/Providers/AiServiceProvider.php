<?php

namespace App\Providers;

use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\Providers\NullProvider;
use App\Domain\Ai\Providers\OpenAiCompatibleProvider;
use App\Domain\Ai\Services\ToolRegistry;
use App\Domain\Ai\Tools;
use App\Domain\Integration\Telegram\TelegramClient;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Every tool the assistant may be offered. Permission filtering happens
     * per user in the registry, so listing one here does not expose it.
     *
     * @var array<int, class-string>
     */
    private const TOOLS = [
        // Read: run immediately.
        Tools\SearchOpportunitiesTool::class,
        Tools\GetOpportunityTool::class,
        Tools\SearchCompaniesTool::class,
        Tools\GetCompanyTimelineTool::class,
        Tools\GetTasksTool::class,
        Tools\GetPipelineSummaryTool::class,
        Tools\GetAgentPerformanceTool::class,
        Tools\GetProjectsTool::class,

        // Write: only ever proposed for confirmation.
        Tools\CreateTaskTool::class,
        Tools\UpdateOpportunityNextActionTool::class,
        Tools\UpdateOpportunityStageTool::class,
        Tools\AddNoteTool::class,
    ];

    public function register(): void
    {
        $this->app->singleton(LlmProvider::class, function () {
            $config = config('ai.openai_compatible');

            // No key means no assistant, rather than a provider that fails
            // deep inside a request. The brief does not depend on this.
            if (config('ai.provider') !== 'openai_compatible' || blank($config['api_key'])) {
                return new NullProvider;
            }

            return new OpenAiCompatibleProvider(
                baseUrl: $config['base_url'],
                apiKey: $config['api_key'],
                model: $config['model'],
                timeout: $config['timeout'],
            );
        });

        $this->app->singleton(TelegramClient::class, fn () => new TelegramClient(
            botToken: (string) config('ai.telegram.bot_token'),
        ));

        $this->app->singleton(ToolRegistry::class, fn ($app) => new ToolRegistry(
            array_map(fn (string $tool) => $app->make($tool), self::TOOLS),
        ));
    }
}
