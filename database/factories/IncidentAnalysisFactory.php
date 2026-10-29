<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\IncidentAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentAnalysis>
 */
class IncidentAnalysisFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'status' => 'completed',
            'threat_level' => fake()->randomElement(['critical', 'high', 'medium', 'low', 'info']),
            'is_false_positive' => fake()->boolean(20),
            'confidence_score' => fake()->randomFloat(2, 0.70, 0.99),
            'summary' => fake()->sentence(),
            'detailed_analysis' => fake()->paragraph(),
            'recommendations' => [
                fake()->sentence(),
                fake()->sentence(),
            ],
            'prompt' => fake()->sentence(),
            'raw_response' => ['simulated' => true],
            'tokens_used' => fake()->numberBetween(100, 500),
        ];
    }
}
