<?php

namespace Database\Factories;

use App\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => 'INC-'.fake()->unique()->numerify('#####'),
            'timestamp' => now(),
            'hypervisor' => fake()->randomElement(['proxmox-node-01', 'esxi-cluster-a', 'kvm-host-04']),
            'signature' => fake()->randomElement([
                'ET MALWARE Suspicious TLS Traffic',
                'ET SCAN Potential SSH Brute Force',
                'ET POLICY Outbound Tor Connection',
                'ET EXPLOIT Suspicious Command Execution',
            ]),
            'severity_label' => fake()->randomElement(['critical', 'high', 'medium', 'low']),
            'incident_json' => [
                'source_ip' => fake()->ipv4(),
                'destination_ip' => fake()->ipv4(),
                'destination_port' => fake()->randomElement([22, 80, 443, 3389, 8080]),
                'protocol' => 'TCP',
                'alert_type' => 'network_intrusion',
            ],
        ];
    }
}
