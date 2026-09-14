<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Contracts\AiTool;
use App\Domain\Ai\Data\ToolDefinition;
use App\Models\User;

/**
 * Which tools exist, and which of them a given user may use.
 *
 * Permission is applied when the list is built, so a tool the user cannot run
 * is never even described to the model. That way the assistant does not offer
 * to do things it will then be refused, and a model that invents a tool name
 * finds nothing to call.
 */
class ToolRegistry
{
    /** @var array<string, AiTool> */
    private array $tools = [];

    /**
     * @param  iterable<AiTool>  $tools
     */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(AiTool $tool): void
    {
        $this->tools[$tool->definition()->name] = $tool;
    }

    /**
     * @return array<string, AiTool>
     */
    public function availableTo(User $user): array
    {
        return array_filter(
            $this->tools,
            fn (AiTool $tool) => $tool->permission() === null || $user->canDo($tool->permission()),
        );
    }

    /**
     * @return array<int, ToolDefinition>
     */
    public function definitionsFor(User $user): array
    {
        return array_values(array_map(
            fn (AiTool $tool) => $tool->definition(),
            $this->availableTo($user),
        ));
    }

    /**
     * Resolved through the permitted set, so an unavailable tool is
     * indistinguishable from one that does not exist.
     */
    public function resolveFor(User $user, string $name): ?AiTool
    {
        return $this->availableTo($user)[$name] ?? null;
    }

    public function get(string $name): ?AiTool
    {
        return $this->tools[$name] ?? null;
    }
}
