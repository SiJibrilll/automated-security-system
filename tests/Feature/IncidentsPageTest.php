<?php

use App\Models\Incident;
use App\Models\IncidentAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to login from incidents page', function () {
    $this->get(route('incidents.index'))->assertRedirect(route('login'));
});

test('authenticated user can view incidents page with raw data table', function () {
    $user = User::factory()->create();

    $incident = Incident::factory()->create([
        'incident_id' => 'INC-RAW-001',
        'hypervisor' => 'proxmox-pve-alpha',
        'signature' => 'ET EXPLOIT Suspicious Command Execution',
        'severity_label' => 'critical',
        'incident_json' => [
            'source_ip' => '192.168.10.45',
            'destination_ip' => '10.0.0.8',
            'destination_port' => 443,
            'protocol' => 'TCP',
        ],
    ]);

    $this->actingAs($user)
        ->get(route('incidents.index'))
        ->assertOk()
        ->assertSee('Log &amp; Data Asli Insiden Keamanan', false)
        ->assertSee('INC-RAW-001')
        ->assertSee('proxmox-pve-alpha')
        ->assertSee('ET EXPLOIT Suspicious Command Execution')
        ->assertSee('192.168.10.45')
        ->assertSee('10.0.0.8:443')
        ->assertSee('TCP');
});

test('incidents table filters by severity and hypervisor', function () {
    $user = User::factory()->create();

    $critIncident = Incident::factory()->create([
        'incident_id' => 'INC-CRIT',
        'severity_label' => 'critical',
        'hypervisor' => 'cluster-1',
    ]);

    $lowIncident = Incident::factory()->create([
        'incident_id' => 'INC-LOW',
        'severity_label' => 'low',
        'hypervisor' => 'cluster-2',
    ]);

    $this->actingAs($user);

    Livewire::test('incidents-index')
        ->set('severity', 'critical')
        ->assertSee('INC-CRIT')
        ->assertDontSee('INC-LOW')
        ->set('severity', 'all')
        ->set('hypervisor', 'cluster-2')
        ->assertSee('INC-LOW')
        ->assertDontSee('INC-CRIT');
});

test('incidents table filters by AI status', function () {
    $user = User::factory()->create();

    $analyzedIncident = Incident::factory()->create(['incident_id' => 'INC-ANALYZED']);
    IncidentAnalysis::factory()->create([
        'incident_id' => $analyzedIncident->id,
        'status' => 'completed',
        'is_false_positive' => false,
    ]);

    $pendingIncident = Incident::factory()->create(['incident_id' => 'INC-PENDING']);

    $this->actingAs($user);

    Livewire::test('incidents-index')
        ->set('aiStatus', 'pending')
        ->assertSee('INC-PENDING')
        ->assertDontSee('INC-ANALYZED')
        ->set('aiStatus', 'analyzed')
        ->assertSee('INC-ANALYZED')
        ->assertDontSee('INC-PENDING');
});

test('incident inspector modal shows technical data and raw json payload', function () {
    $user = User::factory()->create();

    $incident = Incident::factory()->create([
        'incident_id' => 'INC-SPEC-123',
        'hypervisor' => 'hyperv-node-9',
        'signature' => 'ET SCAN Potential SSH Brute Force',
        'severity_label' => 'high',
        'incident_json' => [
            'src_ip' => '10.10.10.20',
            'dest_ip' => '172.16.0.5',
            'dest_port' => 22,
            'protocol' => 'TCP',
            'command' => 'ssh root@172.16.0.5',
        ],
    ]);

    $this->actingAs($user);

    Livewire::test('incidents-index')
        ->call('openDetail', $incident->id, 'technical')
        ->assertSet('showDetailModal', true)
        ->assertSet('activeDetailTab', 'technical')
        ->assertSee('IP Sumber (Source IP):')
        ->assertSee('10.10.10.20')
        ->assertSee('172.16.0.5')
        ->assertSee('22')
        ->assertSee('ET SCAN Potential SSH Brute Force')
        // Switch to raw JSON tab
        ->set('activeDetailTab', 'raw')
        ->assertSee('ssh root@172.16.0.5')
        ->assertSee('Salin JSON');
});

test('single incident can be analyzed on demand from table', function () {
    config()->set('ai.default', 'mock');

    $user = User::factory()->create();
    $incident = Incident::factory()->create(['incident_id' => 'INC-ON-DEMAND']);

    $this->actingAs($user);

    Livewire::test('incidents-index')
        ->call('analyzeSingleIncident', $incident->id)
        ->assertSet('stats.pending', 0)
        ->assertSet('stats.analyzed', 1);

    expect($incident->fresh()->analyses)->toHaveCount(1)
        ->and($incident->fresh()->latestAnalysis->status)->toBe('completed');
});
