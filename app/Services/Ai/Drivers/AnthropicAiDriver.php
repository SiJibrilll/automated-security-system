<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\DTOs\AiPrompt;
use App\Services\Ai\DTOs\AiResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnthropicAiDriver extends AbstractAiDriver
{
    public function getName(): string
    {
        return 'anthropic';
    }

    public function analyze(AiPrompt $prompt): AiResponse
    {
        if (! $this->isConfigured()) {
            return AiResponse::failure(
                'Anthropic API key is not configured. Please set ANTHROPIC_API_KEY in your .env file.',
                $this->getName(),
                $this->getModel()
            );
        }

        $endpoint = "{$this->getBaseUrl()}/messages";

        $body = [
            'model' => $this->getModel(),
            'max_tokens' => $prompt->maxTokens ?? 2048,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt->compilePrompt(),
                ],
            ],
        ];

        if ($prompt->systemPrompt) {
            $body['system'] = $prompt->systemPrompt;
        }

        if ($prompt->temperature !== null) {
            $body['temperature'] = $prompt->temperature;
        }

        $version = $this->config['version'] ?? '2023-06-01';

        try {
            $response = $this->createHttpClient()
                ->withHeaders([
                    'x-api-key' => $this->getApiKey(),
                    'anthropic-version' => $version,
                ])
                ->post($endpoint, $body);

            if ($response->failed()) {
                $errorData = $response->json();
                $errorMessage = $errorData['error']['message'] ?? ($response->body() ?: 'Anthropic API request failed.');

                Log::error('Anthropic AI API Error', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                ]);

                return AiResponse::failure(
                    "Anthropic API Error ({$response->status()}): {$errorMessage}",
                    $this->getName(),
                    $this->getModel(),
                    $errorData ?? []
                );
            }

            $data = $response->json() ?? [];
            $content = $data['content'][0]['text'] ?? '';
            $tokensUsed = isset($data['usage'])
                ? ((int) ($data['usage']['input_tokens'] ?? 0) + (int) ($data['usage']['output_tokens'] ?? 0))
                : null;

            return AiResponse::success(
                content: $content,
                provider: $this->getName(),
                model: $this->getModel(),
                tokensUsed: $tokensUsed,
                raw: $data,
            );
        } catch (Throwable $e) {
            Log::error('Anthropic AI Exception', ['message' => $e->getMessage()]);

            return AiResponse::failure(
                "Anthropic Connection Exception: {$e->getMessage()}",
                $this->getName(),
                $this->getModel()
            );
        }
    }
}
