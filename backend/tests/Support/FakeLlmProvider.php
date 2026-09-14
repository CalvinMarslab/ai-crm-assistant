<?php

namespace Tests\Support;

use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\Data\LlmMessage;
use App\Domain\Ai\Data\ToolDefinition;

/**
 * A scripted model. Tests decide what it "replies" so the assistant's own
 * behaviour — permission filtering, confirmation, guardrails — is what is
 * under test, not a live model's mood.
 */
class FakeLlmProvider implements LlmProvider
{
    /** @var array<int, LlmMessage> */
    private array $script = [];

    /** @var array<int, array{messages: array<int, LlmMessage>, tools: array<int, ToolDefinition>}> */
    public array $calls = [];

    private bool $configured = true;

    public function reply(string $content): self
    {
        $this->script[] = LlmMessage::assistant($content);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function callsTool(string $name, array $arguments = [], ?string $id = null): self
    {
        $this->script[] = LlmMessage::assistant(null, [
            ['id' => $id ?? 'call_'.count($this->script), 'name' => $name, 'arguments' => $arguments],
        ]);

        return $this;
    }

    public function unconfigured(): self
    {
        $this->configured = false;

        return $this;
    }

    public function chat(array $messages, array $tools = []): LlmMessage
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $tools];

        return array_shift($this->script) ?? LlmMessage::assistant('No further scripted reply.');
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function name(): string
    {
        return 'fake';
    }

    /** The tool names offered on the most recent call. */
    public function lastOfferedTools(): array
    {
        $last = end($this->calls);

        return $last === false ? [] : array_map(fn (ToolDefinition $t) => $t->name, $last['tools']);
    }

    /** Everything the model was told, flattened, for leak checks. */
    public function lastPromptText(): string
    {
        $last = end($this->calls);

        if ($last === false) {
            return '';
        }

        return collect($last['messages'])->map(fn (LlmMessage $m) => $m->content ?? '')->implode("\n");
    }
}
