<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\DTOs\AiPrompt;
use App\Services\Ai\DTOs\AiResponse;

interface AiProviderContract
{
    /**
     * Send a prompt with context to the AI provider for analysis.
     */
    public function analyze(AiPrompt $prompt): AiResponse;

    /**
     * Determine if the provider has necessary credentials/keys configured.
     */
    public function isConfigured(): bool;

    /**
     * Get the identifier name of this provider.
     */
    public function getName(): string;
}
