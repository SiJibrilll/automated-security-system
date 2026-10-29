<?php

namespace App\Services\Ai\DTOs;

class AiPrompt
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $responseSchema
     */
    public function __construct(
        public string $userPrompt,
        public ?string $systemPrompt = null,
        public array $context = [],
        public ?float $temperature = 0.2,
        public ?int $maxTokens = 2048,
        public ?array $responseSchema = null,
    ) {}

    /**
     * Get the formatted user prompt combined with context data.
     */
    public function compilePrompt(): string
    {
        if (empty($this->context)) {
            return $this->userPrompt;
        }

        $formattedContext = json_encode($this->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "{$this->userPrompt}\n\n### DATA CONTEXT (JSON):\n```json\n{$formattedContext}\n```";
    }

    /**
     * Return a new instance with additional context.
     *
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): self
    {
        return new self(
            userPrompt: $this->userPrompt,
            systemPrompt: $this->systemPrompt,
            context: array_merge($this->context, $context),
            temperature: $this->temperature,
            maxTokens: $this->maxTokens,
            responseSchema: $this->responseSchema,
        );
    }

    /**
     * Return a new instance with a system prompt.
     */
    public function withSystemPrompt(string $systemPrompt): self
    {
        return new self(
            userPrompt: $this->userPrompt,
            systemPrompt: $systemPrompt,
            context: $this->context,
            temperature: $this->temperature,
            maxTokens: $this->maxTokens,
            responseSchema: $this->responseSchema,
        );
    }
}
