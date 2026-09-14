<?php

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\Data\ToolDefinition;
use App\Models\User;

/**
 * One backend capability the assistant may ask for.
 *
 * The model never reaches the database; it names a tool and passes arguments,
 * and this layer decides whether that is allowed and what it means. A tool
 * that reads runs immediately. A tool that writes does not run at all here —
 * it produces a request the user has to confirm first.
 */
interface AiTool
{
    public function definition(): ToolDefinition;

    /** Read-only tools run straight away; the rest need confirming. */
    public function isReadOnly(): bool;

    /** Checked against the acting user before the tool is offered or run. */
    public function permission(): ?string;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>  Facts for the model; never free prose.
     */
    public function execute(User $user, array $arguments): array;

    /**
     * A sentence describing the pending change, shown to the user for
     * confirmation. Write tools must make this specific enough to judge.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function summarise(User $user, array $arguments): string;
}
