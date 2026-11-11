<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\DTOs\AiPrompt;
use App\Services\Ai\DTOs\AiResponse;

class MockAiDriver extends AbstractAiDriver
{
    public function getName(): string
    {
        return 'mock';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function analyze(AiPrompt $prompt): AiResponse
    {
        $context = $prompt->context;
        $signature = $context['signature'] ?? 'Security Event';
        $severity = strtolower((string) ($context['severity_label'] ?? 'medium'));

        $mockAnalysis = [
            'summary' => "Simulated AI Analysis for security event: {$signature}.",
            'threat_level' => in_array($severity, ['critical', 'high', 'medium', 'low', 'info']) ? $severity : 'medium',
            'is_false_positive' => false,
            'confidence_score' => 0.95,
            'analysis' => "Automated security analysis evaluated event [{$signature}]. Telemetry indicates typical behavioral anomaly pattern.",
            'recommendations' => [
                'Review hypervisor network traffic and firewall rules.',
                'Verify authenticity of reported process activity.',
                'Keep host monitoring rules updated.',
            ],
        ];

        $content = json_encode($mockAnalysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';

        return AiResponse::success(
            content: $content,
            provider: $this->getName(),
            model: $this->getModel(),
            tokensUsed: 120,
            raw: ['mock' => true, 'data' => $mockAnalysis]
        );
    }
}
