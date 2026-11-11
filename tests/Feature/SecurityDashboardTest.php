<?php

use App\Models\Incident;
use App\Models\IncidentAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('dashboard renders beginner-friendly secure status when no threats exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Pusat Keamanan &amp; Analisis AI', false)
        ->assertSee('Kondisi Sistem Aman Terkendali')
        ->assertSee('Alarm Palsu (Aktivitas Normal)');
});

test('dashboard displays warning status when high threat incident exists', function () {
    $user = User::factory()->create();

    $incident = Incident::factory()->create([
        'hypervisor' => 'node-alpha',
        'signature' => 'ET EXPLOIT Suspicious Command Execution',
    ]);

    IncidentAnalysis::factory()->create([
        'incident_id' => $incident->id,
        'threat_level' => 'critical',
        'is_false_positive' => false,
        'confidence_score' => 0.98,
        'summary' => 'Percobaan eksploitasi berbahaya terdeteksi pada server.',
        'recommendations' => ['Isolasi server segera', 'Ganti kredensial'],
        'status' => 'completed',
    ]);

    $this->actingAs($user);

    Livewire::test('security-dashboard')
        ->assertSee('Perhatian: Ada Ancaman Berisiko Tinggi Terdeteksi!')
        ->assertSee('Bahaya Kritis')
        ->assertSee('node-alpha')
        ->assertSee('Percobaan eksploitasi berbahaya terdeteksi pada server.')
        ->assertSee('Keyakinan AI:')
        ->assertSee('98%')
        ->assertSee('Langkah Tindakan Pertama:')
        ->assertSee('Isolasi server segera');
});

test('dashboard clearly distinguishes false positives as safe activities', function () {
    $user = User::factory()->create();

    $incident = Incident::factory()->create([
        'hypervisor' => 'backup-node',
        'signature' => 'ET INFO Scheduled System Backup Probe',
    ]);

    IncidentAnalysis::factory()->create([
        'incident_id' => $incident->id,
        'threat_level' => 'low',
        'is_false_positive' => true,
        'confidence_score' => 0.95,
        'summary' => 'Aktivitas rutin pencadangan data sistem yang normal.',
        'status' => 'completed',
    ]);

    $this->actingAs($user);

    Livewire::test('security-dashboard')
        ->assertSee('Aman (Alarm Palsu)')
        ->assertSee('Aktivitas rutin pencadangan data sistem yang normal.')
        ->assertSet('stats.false_positives', 1);
});

test('dashboard filter by threats only shows real threats', function () {
    $user = User::factory()->create();

    $realIncident = Incident::factory()->create(['signature' => 'Real Threat']);
    IncidentAnalysis::factory()->create([
        'incident_id' => $realIncident->id,
        'threat_level' => 'high',
        'is_false_positive' => false,
        'summary' => 'Ini ancaman nyata berbahaya',
        'status' => 'completed',
    ]);

    $safeIncident = Incident::factory()->create(['signature' => 'False Alarm']);
    IncidentAnalysis::factory()->create([
        'incident_id' => $safeIncident->id,
        'threat_level' => 'low',
        'is_false_positive' => true,
        'summary' => 'Ini alarm palsu aman',
        'status' => 'completed',
    ]);

    $this->actingAs($user);

    Livewire::test('security-dashboard')
        ->set('filter', 'threats')
        ->assertSee('Ini ancaman nyata berbahaya')
        ->assertDontSee('Ini alarm palsu aman');
});

test('dashboard filter by false positives only shows safe false alarms', function () {
    $user = User::factory()->create();

    $realIncident = Incident::factory()->create(['signature' => 'Real Threat']);
    IncidentAnalysis::factory()->create([
        'incident_id' => $realIncident->id,
        'threat_level' => 'high',
        'is_false_positive' => false,
        'summary' => 'Ini ancaman nyata berbahaya',
        'status' => 'completed',
    ]);

    $safeIncident = Incident::factory()->create(['signature' => 'False Alarm']);
    IncidentAnalysis::factory()->create([
        'incident_id' => $safeIncident->id,
        'threat_level' => 'low',
        'is_false_positive' => true,
        'summary' => 'Ini alarm palsu aman',
        'status' => 'completed',
    ]);

    $this->actingAs($user);

    Livewire::test('security-dashboard')
        ->set('filter', 'false_positives')
        ->assertSee('Ini alarm palsu aman')
        ->assertDontSee('Ini ancaman nyata berbahaya');
});

test('dashboard open detail modal displays full AI guidance and recommendations', function () {
    $user = User::factory()->create();

    $incident = Incident::factory()->create([
        'incident_id' => 'INC-999',
        'signature' => 'ET SCAN SSH Brute Force',
    ]);

    $analysis = IncidentAnalysis::factory()->create([
        'incident_id' => $incident->id,
        'threat_level' => 'high',
        'is_false_positive' => false,
        'summary' => 'Serangan tebak password bertubi-tubi pada port SSH.',
        'detailed_analysis' => 'Penyerang mencoba 500 kombinasi password dalam 2 menit.',
        'recommendations' => [
            'Blokir IP penyerang pada firewall',
            'Matikan login SSH dengan kata sandi, gunakan SSH key',
        ],
        'status' => 'completed',
    ]);

    $this->actingAs($user);

    Livewire::test('security-dashboard')
        ->call('openDetail', $analysis->id)
        ->assertSet('showDetailModal', true)
        ->assertSee('Panduan Solusi &amp; Analisis AI', false)
        ->assertSee('Serangan tebak password bertubi-tubi pada port SSH.')
        ->assertSee('Penyerang mencoba 500 kombinasi password dalam 2 menit.')
        ->assertSee('Blokir IP penyerang pada firewall')
        ->assertSee('Matikan login SSH dengan kata sandi, gunakan SSH key')
        ->assertSee('INC-999')
        ->call('closeDetail')
        ->assertSet('showDetailModal', false);
});

test('manual analysis trigger processes unanalyzed incidents', function () {
    config()->set('ai.default', 'mock');

    $user = User::factory()->create();

    Incident::factory()->create([
        'signature' => 'Pending Incident 1',
    ]);

    $this->actingAs($user);

    // Initial state: 1 pending incident
    Livewire::test('security-dashboard')
        ->assertSet('stats.pending_count', 1)
        ->call('runManualAnalysis')
        ->assertSet('stats.pending_count', 0)
        ->assertSet('stats.analyzed_count', 1);

    expect(IncidentAnalysis::count())->toBe(1);
});
