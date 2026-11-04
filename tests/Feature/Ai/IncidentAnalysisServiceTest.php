<?php

use App\Models\Incident;
use App\Models\IncidentAnalysis;
use App\Services\Ai\IncidentAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('incident analysis service compiles incident context into prompt', function () {
    $incident = Incident::factory()->create([
        'incident_id' => 'INC-12345',
        'hypervisor' => 'proxmox-node-01',
        'signature' => 'ET EXPLOIT Suspicious Command Execution',
        'severity_label' => 'high',
        'incident_json' => ['command' => 'whoami', 'pid' => 4567],
    ]);

    $service = app(IncidentAnalysisService::class);
    $prompt = $service->buildPrompt($incident);

    expect($prompt->context['incident_id'])->toBe('INC-12345')
        ->and($prompt->context['hypervisor'])->toBe('proxmox-node-01')
        ->and($prompt->context['signature'])->toBe('ET EXPLOIT Suspicious Command Execution')
        ->and($prompt->compilePrompt())->toContain('INC-12345')
        ->and($prompt->compilePrompt())->toContain('proxmox-node-01')
        ->and($prompt->systemPrompt)->toContain('(SOC) Analyst');
});

test('incident analysis service analyzes and persists analysis with mock driver', function () {
    $incident = Incident::factory()->create([
        'signature' => 'ET SCAN Potential SSH Brute Force',
        'severity_label' => 'high',
    ]);

    $service = app(IncidentAnalysisService::class);
    $analysis = $service->analyze($incident, provider: 'mock');

    expect($analysis)->toBeInstanceOf(IncidentAnalysis::class)
        ->and($analysis->incident_id)->toBe($incident->id)
        ->and($analysis->provider)->toBe('mock')
        ->and($analysis->status)->toBe('completed')
        ->and($analysis->threat_level)->toBe('high')
        ->and($analysis->recommendations)->toBeArray()
        ->and($analysis->recommendations)->not->toBeEmpty();

    $this->assertDatabaseHas('incident_analyses', [
        'id' => $analysis->id,
        'incident_id' => $incident->id,
        'provider' => 'mock',
        'status' => 'completed',
    ]);

    expect($incident->fresh()->analyses)->toHaveCount(1)
        ->and($incident->fresh()->latestAnalysis->id)->toBe($analysis->id);
});

test('incident analysis service analyzes and persists analysis with gemini driver', function () {
    config()->set('ai.providers.gemini.api_key', 'test-gemini-key');

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'steps' => [
                [
                    'type' => 'model_output',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode([
                                'summary' => 'Critical credential dumping attempt on hypervisor.',
                                'threat_level' => 'critical',
                                'is_false_positive' => false,
                                'confidence_score' => 0.98,
                                'detailed_analysis' => 'Attack pattern matches Mimikatz LSASS memory dump attempt.',
                                'recommendations' => [
                                    'Isolate VM immediately',
                                    'Rotate privileged credentials',
                                ],
                            ]),
                        ],
                    ],
                ],
            ],
            'usage' => [
                'total_tokens' => 240,
            ],
        ], 200),
    ]);

    $incident = Incident::factory()->create([
        'signature' => 'ET TROJAN Mimikatz In-Memory Execution',
        'severity_label' => 'critical',
    ]);

    $service = app(IncidentAnalysisService::class);
    $analysis = $service->analyze($incident, provider: 'gemini');

    expect($analysis->provider)->toBe('gemini')
        ->and($analysis->status)->toBe('completed')
        ->and($analysis->threat_level)->toBe('critical')
        ->and($analysis->is_false_positive)->toBeFalse()
        ->and($analysis->confidence_score)->toBe(0.98)
        ->and($analysis->tokens_used)->toBe(240)
        ->and($analysis->recommendations)->toContain('Isolate VM immediately');
});

test('incident analysis service persists failed analysis when provider returns error', function () {
    config()->set('ai.providers.gemini.api_key', 'test-gemini-key');

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'message' => 'Rate limit exceeded for resource.',
            ],
        ], 429),
    ]);

    $incident = Incident::factory()->create();

    $service = app(IncidentAnalysisService::class);
    $analysis = $service->analyze($incident, provider: 'gemini');

    expect($analysis->status)->toBe('failed')
        ->and($analysis->detailed_analysis)->toContain('Rate limit exceeded');

    $this->assertDatabaseHas('incident_analyses', [
        'id' => $analysis->id,
        'incident_id' => $incident->id,
        'status' => 'failed',
    ]);
});
