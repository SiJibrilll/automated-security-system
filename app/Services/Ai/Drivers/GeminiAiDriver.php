<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\DTOs\AiPrompt;
use App\Services\Ai\DTOs\AiResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeminiAiDriver extends AbstractAiDriver
{
    public function getName(): string
    {
        return 'gemini';
    }

    public function analyze(AiPrompt $prompt): AiResponse
    {
        if (! $this->isConfigured()) {
            return AiResponse::failure(
                'Gemini API key is not configured. Please set GEMINI_API_KEY in your .env file.',
                $this->getName(),
                $this->getModel()
            );
        }

        $useInteractions = $this->isInteractionsApi();

        if ($useInteractions) {
            $endpoint = "{$this->getBaseUrl()}/interactions";
            $body = $this->buildInteractionsPayload($prompt);
        } else {
            $endpoint = "{$this->getBaseUrl()}/models/{$this->getModel()}:generateContent";
            $body = $this->buildGenerateContentPayload($prompt);
        }

        try {
            $response = $this->createHttpClient()
                ->withHeaders([
                    'x-goog-api-key' => $this->getApiKey(),
                ])
                ->post($endpoint, $body);

            if ($response->failed()) {
                $errorData = $response->json();
                $errorMessage = $errorData['error']['message'] ?? ($response->body() ?: 'Gemini API request failed.');

                Log::error('Gemini AI API Error', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                    'endpoint' => $endpoint,
                ]);

                return AiResponse::failure(
                    "Gemini API Error ({$response->status()}): {$errorMessage}",
                    $this->getName(),
                    $this->getModel(),
                    is_array($errorData) ? $errorData : []
                );
            }

            $data = $response->json() ?? [];
            $content = $this->extractContent($data);
            $tokensUsed = $this->extractTokensUsed($data);

            return AiResponse::success(
                content: $content,
                provider: $this->getName(),
                model: $this->getModel(),
                tokensUsed: $tokensUsed,
                raw: $data,
            );
        } catch (Throwable $e) {
            Log::error('Gemini AI Exception', ['message' => $e->getMessage()]);

            return AiResponse::failure(
                "Gemini Connection Exception: {$e->getMessage()}",
                $this->getName(),
                $this->getModel()
            );
        }
    }

    /**
     * Determine if the modern Interactions API should be used.
     */
    protected function isInteractionsApi(): bool
    {
        $api = $this->config['api'] ?? null;
        if ($api === 'generateContent' || $api === 'generate-content') {
            return false;
        }

        if ($api === 'interactions') {
            return true;
        }

        // Modern Gemini 3.* models require the Interactions API
        return str_starts_with($this->getModel(), 'gemini-3') || ! str_contains($this->getModel(), '1.5');
    }

    /**
     * Build the request body for the modern Interactions API.
     *
     * @return array<string, mixed>
     */
    protected function buildInteractionsPayload(AiPrompt $prompt): array
    {
        $payload = [
            'model' => $this->getModel(),
            'input' => $prompt->compilePrompt(),
        ];

        if ($prompt->systemPrompt) {
            $payload['system_instruction'] = $prompt->systemPrompt;
        }

        if ($prompt->responseSchema !== null) {
            $payload['response_mime_type'] = 'application/json';
            $payload['response_format'] = [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => $prompt->responseSchema,
            ];
        }

        $generationConfig = array_filter([
            'temperature' => $prompt->temperature,
            'max_output_tokens' => $prompt->maxTokens,
            'thinking_level' => $this->config['thinking_level'] ?? 'low',
        ], fn ($val) => $val !== null);

        $payload['generation_config'] = $generationConfig;

        return $payload;
    }

    /**
     * Build the request body for legacy generateContent API.
     *
     * @return array<string, mixed>
     */
    protected function buildGenerateContentPayload(AiPrompt $prompt): array
    {
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt->compilePrompt()],
                    ],
                ],
            ],
        ];

        if ($prompt->systemPrompt) {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $prompt->systemPrompt],
                ],
            ];
        }

        $generationConfig = array_filter([
            'temperature' => $prompt->temperature,
            'maxOutputTokens' => $prompt->maxTokens,
            'responseMimeType' => $prompt->responseSchema !== null ? 'application/json' : null,
        ], fn ($val) => $val !== null);

        if (! empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        return $payload;
    }

    /**
     * Extract generated content from either Interactions or generateContent response.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractContent(array $data): string
    {
        // 1. Interactions API format: steps -> model_output -> content -> text
        if (isset($data['steps']) && is_array($data['steps'])) {
            $text = '';
            foreach ($data['steps'] as $step) {
                if (is_array($step) && ($step['type'] ?? '') === 'model_output' && isset($step['content']) && is_array($step['content'])) {
                    foreach ($step['content'] as $part) {
                        if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text']) && is_string($part['text'])) {
                            $text .= $part['text'];
                        }
                    }
                }
            }

            if (! empty($text)) {
                return $text;
            }
        }

        // 2. Interactions API format: outputs
        if (isset($data['outputs']) && is_array($data['outputs'])) {
            $text = '';
            foreach ($data['outputs'] as $output) {
                if (is_array($output) && ($output['type'] ?? '') === 'text' && isset($output['text']) && is_string($output['text'])) {
                    $text .= $output['text'];
                }
            }

            if (! empty($text)) {
                return $text;
            }
        }

        // 3. Legacy generateContent format: candidates -> content -> parts -> text
        if (isset($data['candidates'][0]['content']['parts'][0]['text']) && is_string($data['candidates'][0]['content']['parts'][0]['text'])) {
            return $data['candidates'][0]['content']['parts'][0]['text'];
        }

        return '';
    }

    /**
     * Extract token usage from either response format.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractTokensUsed(array $data): ?int
    {
        if (isset($data['usage']['total_tokens']) && is_numeric($data['usage']['total_tokens'])) {
            return (int) $data['usage']['total_tokens'];
        }

        if (isset($data['usageMetadata']['totalTokenCount']) && is_numeric($data['usageMetadata']['totalTokenCount'])) {
            return (int) $data['usageMetadata']['totalTokenCount'];
        }

        return null;
    }
}
