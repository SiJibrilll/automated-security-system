<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\DTOs\AiPrompt;
use App\Services\Ai\DTOs\AiResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenAiDriver extends AbstractAiDriver
{
    public function getName(): string
    {
        return 'openai';
    }

    public function analyze(AiPrompt $prompt): AiResponse
    {
        if (! $this->isConfigured()) {
            return AiResponse::failure(
                'OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.',
                $this->getName(),
                $this->getModel()
            );
        }

        $endpoint = "{$this->getBaseUrl()}/chat/completions";

        $messages = [];

        if ($prompt->systemPrompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $prompt->systemPrompt,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt->compilePrompt(),
        ];

        $body = [
            'model' => $this->getModel(),
            'messages' => $messages,
        ];

        if ($prompt->temperature !== null) {
            $body['temperature'] = $prompt->temperature;
        }

        if ($prompt->maxTokens !== null) {
            $body['max_tokens'] = $prompt->maxTokens;
        }

        if ($prompt->responseSchema !== null) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = $this->createHttpClient()
                ->withToken($this->getApiKey() ?? '')
                ->post($endpoint, $body);

            if ($response->failed()) {
                $errorData = $response->json();
                $errorMessage = $errorData['error']['message'] ?? ($response->body() ?: 'OpenAI API request failed.');

                Log::error('OpenAI API Error', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                ]);

                return AiResponse::failure(
                    "OpenAI API Error ({$response->status()}): {$errorMessage}",
                    $this->getName(),
                    $this->getModel(),
                    $errorData ?? []
                );
            }

            $data = $response->json() ?? [];
            $content = $data['choices'][0]['message']['content'] ?? '';
            $tokensUsed = $data['usage']['total_tokens'] ?? null;

            return AiResponse::success(
                content: $content,
                provider: $this->getName(),
                model: $this->getModel(),
                tokensUsed: $tokensUsed,
                raw: $data,
            );
        } catch (Throwable $e) {
            Log::error('OpenAI Exception', ['message' => $e->getMessage()]);

            return AiResponse::failure(
                "OpenAI Connection Exception: {$e->getMessage()}",
                $this->getName(),
                $this->getModel()
            );
        }
    }
}
