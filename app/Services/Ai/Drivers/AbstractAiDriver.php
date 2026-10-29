<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\Contracts\AiProviderContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

abstract class AbstractAiDriver implements AiProviderContract
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config = []
    ) {}

    /**
     * Get configured API key.
     */
    protected function getApiKey(): ?string
    {
        return $this->config['api_key'] ?? null;
    }

    /**
     * Get model name.
     */
    protected function getModel(): string
    {
        return $this->config['model'] ?? 'default';
    }

    /**
     * Get base URL.
     */
    protected function getBaseUrl(): string
    {
        return rtrim($this->config['base_url'] ?? '', '/');
    }

    /**
     * Get request timeout in seconds.
     */
    protected function getTimeout(): int
    {
        return (int) ($this->config['timeout'] ?? 30);
    }

    /**
     * Check if the API key is configured.
     */
    public function isConfigured(): bool
    {
        $key = $this->getApiKey();

        return ! empty($key);
    }

    /**
     * Get an HTTP client pre-configured with timeouts and proxy.
     */
    protected function createHttpClient(): PendingRequest
    {
        $client = Http::timeout($this->getTimeout())
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 10))
            ->retry(1, 200, throw: false)
            ->acceptJson();

        $proxy = $this->config['proxy'] ?? null;
        if (! empty($proxy)) {
            $client = $client->withOptions(['proxy' => $proxy]);
        }

        return $client;
    }
}
