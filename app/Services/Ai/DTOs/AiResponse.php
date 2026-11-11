<?php

namespace App\Services\Ai\DTOs;

class AiResponse
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public bool $successful = true,
        public ?string $errorMessage = null,
        public ?int $tokensUsed = null,
        public array $raw = [],
    ) {}

    /**
     * Create a successful response instance.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function success(
        string $content,
        string $provider,
        string $model,
        ?int $tokensUsed = null,
        array $raw = [],
    ): self {
        return new self(
            content: $content,
            provider: $provider,
            model: $model,
            successful: true,
            errorMessage: null,
            tokensUsed: $tokensUsed,
            raw: $raw,
        );
    }

    /**
     * Create a failed response instance.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function failure(
        string $errorMessage,
        string $provider,
        string $model = 'unknown',
        array $raw = [],
    ): self {
        return new self(
            content: '',
            provider: $provider,
            model: $model,
            successful: false,
            errorMessage: $errorMessage,
            tokensUsed: 0,
            raw: $raw,
        );
    }

    /**
     * Check if the request was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * Check if the request failed.
     */
    public function failed(): bool
    {
        return ! $this->successful;
    }

    /**
     * Attempt to parse the response text as JSON.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $text = trim($this->content);

        // Strip markdown code fences if model returned ```json ... ```
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $matches)) {
            $text = $matches[1];
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }
}
