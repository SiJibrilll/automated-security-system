<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Drivers\AnthropicAiDriver;
use App\Services\Ai\Drivers\GeminiAiDriver;
use App\Services\Ai\Drivers\MockAiDriver;
use App\Services\Ai\Drivers\OpenAiDriver;
use App\Services\Ai\DTOs\AiPrompt;
use App\Services\Ai\DTOs\AiResponse;
use Illuminate\Support\Manager;

/**
 * @mixin AiProviderContract
 */
class AiManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('ai.default', 'gemini');
    }

    /**
     * Get a driver instance.
     *
     * @param  string|null  $driver
     */
    public function driver($driver = null): AiProviderContract
    {
        return parent::driver($driver);
    }

    /**
     * Create the Gemini driver instance.
     */
    public function createGeminiDriver(): GeminiAiDriver
    {
        $config = (array) $this->config->get('ai.providers.gemini', []);

        return new GeminiAiDriver($config);
    }

    /**
     * Create the Anthropic (Claude) driver instance.
     */
    public function createAnthropicDriver(): AnthropicAiDriver
    {
        $config = (array) $this->config->get('ai.providers.anthropic', []);

        return new AnthropicAiDriver($config);
    }

    /**
     * Create the OpenAI driver instance.
     */
    public function createOpenaiDriver(): OpenAiDriver
    {
        $config = (array) $this->config->get('ai.providers.openai', []);

        return new OpenAiDriver($config);
    }

    /**
     * Create the Mock driver instance.
     */
    public function createMockDriver(): MockAiDriver
    {
        $config = (array) $this->config->get('ai.providers.mock', []);

        return new MockAiDriver($config);
    }

    /**
     * Send a prompt to the default AI driver.
     */
    public function analyze(AiPrompt $prompt): AiResponse
    {
        return $this->driver()->analyze($prompt);
    }
}
