<?php

use App\Facades\Ai;
use App\Services\Ai\AiManager;
use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Drivers\AnthropicAiDriver;
use App\Services\Ai\Drivers\GeminiAiDriver;
use App\Services\Ai\Drivers\MockAiDriver;
use App\Services\Ai\Drivers\OpenAiDriver;
use App\Services\Ai\DTOs\AiPrompt;
use Illuminate\Support\Facades\Http;

test('ai manager resolves default and named drivers', function () {
    $manager = app(AiManager::class);

    expect($manager->driver())->toBeInstanceOf(AiProviderContract::class)
        ->and($manager->driver('gemini'))->toBeInstanceOf(GeminiAiDriver::class)
        ->and($manager->driver('anthropic'))->toBeInstanceOf(AnthropicAiDriver::class)
        ->and($manager->driver('openai'))->toBeInstanceOf(OpenAiDriver::class)
        ->and($manager->driver('mock'))->toBeInstanceOf(MockAiDriver::class);
});

test('mock driver returns successful structured security analysis', function () {
    $prompt = new AiPrompt(
        userPrompt: 'Analyze test incident',
        context: [
            'signature' => 'ET SCAN Potential SSH Brute Force',
            'severity_label' => 'high',
        ]
    );

    $response = Ai::driver('mock')->analyze($prompt);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->provider)->toBe('mock')
        ->and($response->tokensUsed)->toBeGreaterThan(0);

    $json = $response->json();
    expect($json)->toBeArray()
        ->and($json)->toHaveKeys(['summary', 'threat_level', 'recommendations'])
        ->and($json['threat_level'])->toBe('high');
});

test('gemini driver sends valid payload and handles response', function () {
    config()->set('ai.providers.gemini.api_key', 'test-gemini-key');

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'steps' => [
                [
                    'type' => 'model_output',
                    'content' => [
                        ['type' => 'text', 'text' => '{"summary": "Suspicious login attempt detected", "threat_level": "medium", "recommendations": ["Block IP"]}'],
                    ],
                ],
            ],
            'usage' => [
                'total_tokens' => 85,
            ],
        ], 200),
    ]);

    $prompt = new AiPrompt(
        userPrompt: 'Analyze suspicious traffic',
        systemPrompt: 'You are a security analyst.',
        context: ['ip' => '192.168.1.50']
    );

    $response = Ai::driver('gemini')->analyze($prompt);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->provider)->toBe('gemini')
        ->and($response->tokensUsed)->toBe(85);

    $parsed = $response->json();
    expect($parsed['threat_level'])->toBe('medium');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'interactions')
            && $request->header('x-goog-api-key')[0] === 'test-gemini-key';
    });
});

test('anthropic driver sends valid payload and handles response', function () {
    config()->set('ai.providers.anthropic.api_key', 'test-anthropic-key');

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['text' => '{"summary": "Port scanning activity detected", "threat_level": "low"}'],
            ],
            'usage' => [
                'input_tokens' => 40,
                'output_tokens' => 30,
            ],
        ], 200),
    ]);

    $prompt = new AiPrompt(
        userPrompt: 'Analyze port scan',
        systemPrompt: 'SOC assistant'
    );

    $response = Ai::driver('anthropic')->analyze($prompt);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->provider)->toBe('anthropic')
        ->and($response->tokensUsed)->toBe(70);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/messages')
            && $request->header('x-api-key')[0] === 'test-anthropic-key';
    });
});

test('openai driver sends valid payload and handles response', function () {
    config()->set('ai.providers.openai.api_key', 'test-openai-key');

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => '{"summary": "SQL injection attempt", "threat_level": "critical"}'],
                ],
            ],
            'usage' => [
                'total_tokens' => 110,
            ],
        ], 200),
    ]);

    $prompt = new AiPrompt(
        userPrompt: 'Analyze SQL injection',
        systemPrompt: 'SOC assistant'
    );

    $response = Ai::driver('openai')->analyze($prompt);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->provider)->toBe('openai')
        ->and($response->tokensUsed)->toBe(110);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/chat/completions')
            && $request->header('Authorization')[0] === 'Bearer test-openai-key';
    });
});

test('gemini driver returns failure when api key is missing', function () {
    config()->set('ai.providers.gemini.api_key', null);

    $prompt = new AiPrompt('Analyze');
    $response = Ai::driver('gemini')->analyze($prompt);

    expect($response->failed())->toBeTrue()
        ->and($response->errorMessage)->toContain('API key is not configured');
});
