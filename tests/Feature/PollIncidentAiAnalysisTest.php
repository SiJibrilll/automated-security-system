<?php

use App\Jobs\AnalyzeIncidentJob;
use App\Models\Incident;
use App\Models\IncidentAnalysis;
use App\Services\Ai\IncidentAnalysisService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('polling command skips AI request when no incidents exist in database', function () {
    Http::fake();

    $this->artisan('incidents:poll-analysis')
        ->expectsOutput('No new incidents found. Skipping AI request to save tokens.')
        ->assertSuccessful();

    Http::assertNothingSent();
    $this->assertDatabaseEmpty('incident_analyses');
});

test('polling command skips AI request when all incidents have already been analyzed', function () {
    Http::fake();

    $incident = Incident::factory()->create([
        'incident_id' => 'INC-001',
        'signature' => 'ET SCAN Potential SSH Brute Force',
    ]);

    IncidentAnalysis::factory()->create([
        'incident_id' => $incident->id,
        'provider' => 'mock',
        'status' => 'completed',
    ]);

    $this->artisan('incidents:poll-analysis')
        ->expectsOutput('No new incidents found. Skipping AI request to save tokens.')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(IncidentAnalysis::count())->toBe(1);
});

test('polling command processes new unanalyzed incidents and skips subsequent runs', function () {
    $incidentA = Incident::factory()->create([
        'incident_id' => 'INC-001',
        'signature' => 'ET EXPLOIT Suspicious Command Execution',
    ]);

    $incidentB = Incident::factory()->create([
        'incident_id' => 'INC-002',
        'signature' => 'ET SCAN Potential SSH Brute Force',
    ]);

    // First run should find and analyze both incidents
    $this->artisan('incidents:poll-analysis --provider=mock')
        ->expectsOutput('Found 2 new incident(s) waiting for AI analysis.')
        ->expectsOutput('Successfully analyzed 2 incident(s).')
        ->assertSuccessful();

    expect(IncidentAnalysis::count())->toBe(2);
    $this->assertDatabaseHas('incident_analyses', ['incident_id' => $incidentA->id]);
    $this->assertDatabaseHas('incident_analyses', ['incident_id' => $incidentB->id]);

    // Second run should detect no new incidents and skip AI requests to save tokens
    $this->artisan('incidents:poll-analysis --provider=mock')
        ->expectsOutput('No new incidents found. Skipping AI request to save tokens.')
        ->assertSuccessful();

    expect(IncidentAnalysis::count())->toBe(2);
});

test('polling command respects the limit option', function () {
    Incident::factory()->count(5)->create();

    $this->artisan('incidents:poll-analysis --provider=mock --limit=2')
        ->expectsOutput('Found 5 new incident(s) waiting for AI analysis.')
        ->expectsOutput('Successfully analyzed 2 incident(s).')
        ->assertSuccessful();

    expect(IncidentAnalysis::count())->toBe(2);
    expect(Incident::unanalyzed()->count())->toBe(3);
});

test('polling command dispatches jobs to queue when queue option is provided', function () {
    Queue::fake();

    $incidents = Incident::factory()->count(3)->create();

    $this->artisan('incidents:poll-analysis --queue --provider=mock')
        ->expectsOutput('Found 3 new incident(s) waiting for AI analysis.')
        ->expectsOutput('Dispatched 3 incident analysis job(s) to the queue.')
        ->assertSuccessful();

    Queue::assertPushed(AnalyzeIncidentJob::class, 3);
});

test('analyze incident job executes analysis successfully', function () {
    $incident = Incident::factory()->create([
        'signature' => 'ET TROJAN Generic Trojan Download Activity',
    ]);

    $job = new AnalyzeIncidentJob($incident, provider: 'mock');
    $service = app(IncidentAnalysisService::class);

    $analysis = $job->handle($service);

    expect($analysis)->toBeInstanceOf(IncidentAnalysis::class)
        ->and($analysis->incident_id)->toBe($incident->id)
        ->and($analysis->status)->toBe('completed');

    $this->assertDatabaseHas('incident_analyses', [
        'incident_id' => $incident->id,
        'provider' => 'mock',
    ]);
});

test('incident model scopes unanalyzed and pending analysis work correctly', function () {
    $incidentUnanalyzed = Incident::factory()->create(['incident_id' => 'INC-NEW']);
    $incidentCompleted = Incident::factory()->create(['incident_id' => 'INC-DONE']);
    $incidentFailed = Incident::factory()->create(['incident_id' => 'INC-FAILED']);

    IncidentAnalysis::factory()->create([
        'incident_id' => $incidentCompleted->id,
        'status' => 'completed',
    ]);

    IncidentAnalysis::factory()->create([
        'incident_id' => $incidentFailed->id,
        'status' => 'failed',
    ]);

    // Unanalyzed only includes the incident without any analysis record
    expect(Incident::unanalyzed()->pluck('incident_id')->all())
        ->toBe(['INC-NEW']);

    // Default pending analysis (exclude failed) only includes unanalyzed
    expect(Incident::pendingAnalysis()->pluck('incident_id')->all())
        ->toBe(['INC-NEW']);

    // Pending analysis with includeFailed includes both unanalyzed and failed
    expect(Incident::pendingAnalysis(includeFailed: true)->pluck('incident_id')->all())
        ->toContain('INC-NEW')
        ->toContain('INC-FAILED')
        ->not->toContain('INC-DONE');
});

test('incident analysis service analyzePendingIncidents skips AI calls when no pending incidents', function () {
    Http::fake();

    $service = app(IncidentAnalysisService::class);

    expect($service->hasPendingIncidents())->toBeFalse()
        ->and($service->countPendingIncidents())->toBe(0);

    $result = $service->analyzePendingIncidents();

    expect($result)->toBeEmpty();
    Http::assertNothingSent();
});

test('incident analysis service analyzePendingIncidents processes unanalyzed incidents', function () {
    Incident::factory()->create(['signature' => 'Incident 1']);
    Incident::factory()->create(['signature' => 'Incident 2']);

    $service = app(IncidentAnalysisService::class);

    expect($service->hasPendingIncidents())->toBeTrue()
        ->and($service->countPendingIncidents())->toBe(2);

    $results = $service->analyzePendingIncidents(provider: 'mock');

    expect($results)->toHaveCount(2)
        ->and($service->hasPendingIncidents())->toBeFalse();

    expect(IncidentAnalysis::count())->toBe(2);
});

test('scheduled incident polling task is registered in scheduler', function () {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())->filter(function (Event $event) {
        return str_contains($event->command, 'incidents:poll-analysis');
    });

    expect($events)->not->toBeEmpty();
    expect($events->first()->expression)->toBe('*/5 * * * *');
});
