<?php

namespace App\Domain\Ai\Data;

/**
 * A tool as advertised to the model: what it is called, what it does, and the
 * shape of its arguments.
 */
final readonly class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $parameters  JSON Schema for the arguments
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->parameters,
        ];
    }
}
