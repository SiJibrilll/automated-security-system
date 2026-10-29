<?php

namespace App\Services\Ai;

use App\Models\Incident;
use App\Models\IncidentAnalysis;
use App\Services\Ai\DTOs\AiPrompt;
use App\Services\Ai\DTOs\AiResponse;

class IncidentAnalysisService
{
    public function __construct(
        protected AiManager $ai
    ) {}

    /**
     * Analyze an incident using the specified or default AI provider.
     */
    public function analyze(
        Incident $incident,
        ?string $provider = null,
        ?string $customInstructions = null
    ): IncidentAnalysis {
        $prompt = $this->buildPrompt($incident, $customInstructions);

        $driver = $this->ai->driver($provider);
        $response = $driver->analyze($prompt);

        return $this->persistAnalysis($incident, $prompt, $response);
    }

    /**
     * Build the structured prompt for security incident analysis.
     */
    public function buildPrompt(Incident $incident, ?string $customInstructions = null): AiPrompt
    {
        $context = [
            'incident_id' => $incident->incident_id,
            'timestamp' => $incident->timestamp?->toIso8601String(),
            'hypervisor' => $incident->hypervisor,
            'signature' => $incident->signature,
            'severity_label' => $incident->severity_label,
            'raw_incident_data' => $incident->incident_json,
        ];

        $systemPrompt = <<<'PROMPT'
You are a Senior Cyber Security Operations Center (SOC) Analyst and Threat Intelligence specialist.
Your task is to analyze the provided security incident data and return an objective threat assessment.

You MUST respond strictly with a valid JSON object matching the following structure:
{
  "summary": "Concise 1-2 sentence executive summary of the incident",
  "threat_level": "critical|high|medium|low|info",
  "is_false_positive": false,
  "confidence_score": 0.95,
  "detailed_analysis": "Technical analysis of the attack indicators, threat actor TTPs, and potential impact",
  "recommendations": [
    "Immediate containment action 1",
    "Remediation action 2",
    "Hardening advice 3"
  ]
}
PROMPT;

        $userPrompt = "Analyze this security incident detected on hypervisor '{$incident->hypervisor}' with signature '{$incident->signature}'.";

        if ($customInstructions) {
            $userPrompt .= "\n\nAdditional Analyst Instructions: {$customInstructions}";
        }

        return new AiPrompt(
            userPrompt: $userPrompt,
            systemPrompt: $systemPrompt,
            context: $context,
            temperature: 0.2,
            responseSchema: [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'threat_level' => ['type' => 'string'],
                    'is_false_positive' => ['type' => 'boolean'],
                    'confidence_score' => ['type' => 'number'],
                    'detailed_analysis' => ['type' => 'string'],
                    'recommendations' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => [
                    'summary',
                    'threat_level',
                    'is_false_positive',
                    'confidence_score',
                    'detailed_analysis',
                    'recommendations',
                ],
            ]
        );
    }

    /**
     * Persist the AI analysis results to the database.
     */
    protected function persistAnalysis(
        Incident $incident,
        AiPrompt $prompt,
        AiResponse $response
    ): IncidentAnalysis {
        $parsed = $response->json();

        if ($response->failed() || $parsed === null) {
            return $incident->analyses()->create([
                'provider' => $response->provider,
                'model' => $response->model,
                'status' => $response->failed() ? 'failed' : 'completed',
                'threat_level' => null,
                'is_false_positive' => false,
                'confidence_score' => null,
                'summary' => $response->failed() ? 'AI analysis request failed' : 'AI analysis completed without structured JSON',
                'detailed_analysis' => $response->failed() ? $response->errorMessage : $response->content,
                'recommendations' => [],
                'prompt' => $prompt->compilePrompt(),
                'raw_response' => $response->raw ?: ['error' => $response->errorMessage, 'content' => $response->content],
                'tokens_used' => $response->tokensUsed,
            ]);
        }

        return $incident->analyses()->create([
            'provider' => $response->provider,
            'model' => $response->model,
            'status' => 'completed',
            'threat_level' => $parsed['threat_level'] ?? null,
            'is_false_positive' => (bool) ($parsed['is_false_positive'] ?? false),
            'confidence_score' => isset($parsed['confidence_score']) ? (float) $parsed['confidence_score'] : null,
            'summary' => $parsed['summary'] ?? null,
            'detailed_analysis' => $parsed['detailed_analysis'] ?? ($parsed['analysis'] ?? null),
            'recommendations' => (array) ($parsed['recommendations'] ?? []),
            'prompt' => $prompt->compilePrompt(),
            'raw_response' => $response->raw ?: $parsed,
            'tokens_used' => $response->tokensUsed,
        ]);
    }
}
