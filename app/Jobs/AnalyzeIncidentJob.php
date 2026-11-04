<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Models\IncidentAnalysis;
use App\Services\Ai\IncidentAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AnalyzeIncidentJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * The backoff delays in seconds between retries.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Incident $incident,
        public ?string $provider = null,
        public ?string $customInstructions = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(IncidentAnalysisService $service): IncidentAnalysis
    {
        Log::info("Processing AI incident analysis job for incident ID #{$this->incident->id} ({$this->incident->incident_id})");

        return $service->analyze(
            $this->incident,
            $this->provider,
            $this->customInstructions
        );
    }
}
