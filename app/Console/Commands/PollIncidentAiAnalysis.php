<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeIncidentJob;
use App\Models\Incident;
use App\Services\Ai\IncidentAnalysisService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('incidents:poll-analysis {--limit= : Maximum number of incidents to analyze in one run} {--provider= : AI provider to use (gemini, anthropic, openai, mock)} {--include-failed : Include incidents whose previous analysis failed} {--queue : Dispatch incident analysis to the queue instead of running synchronously} {--daemon : Run continuously as a background polling daemon} {--interval= : Polling interval in minutes when running in daemon mode}')]
#[Description('Poll for unanalyzed security incidents and run AI analysis, skipping requests if no new incidents exist to save tokens.')]
class PollIncidentAiAnalysis extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(IncidentAnalysisService $service): int
    {
        if ($this->option('daemon')) {
            return $this->runDaemon($service);
        }

        return $this->pollOnce($service);
    }

    /**
     * Run a single polling pass.
     */
    public function pollOnce(IncidentAnalysisService $service): int
    {
        $limit = $this->option('limit') !== null
            ? (int) $this->option('limit')
            : (int) config('ai.polling.batch_limit', 10);

        $provider = $this->option('provider') ? (string) $this->option('provider') : null;
        $includeFailed = (bool) $this->option('include-failed');
        $useQueue = (bool) $this->option('queue');

        $pendingCount = Incident::pendingAnalysis($includeFailed)->count();

        if ($pendingCount === 0) {
            $this->info('No new incidents found. Skipping AI request to save tokens.');

            return Command::SUCCESS;
        }

        $this->info("Found {$pendingCount} new incident(s) waiting for AI analysis.");

        if ($useQueue) {
            $query = Incident::pendingAnalysis($includeFailed)->orderBy('id', 'asc');
            if ($limit > 0) {
                $query->limit($limit);
            }
            $incidents = $query->get();

            foreach ($incidents as $incident) {
                AnalyzeIncidentJob::dispatch($incident, $provider);
            }

            $this->info("Dispatched {$incidents->count()} incident analysis job(s) to the queue.");

            return Command::SUCCESS;
        }

        $this->output->write('Running AI analysis on incidents... ');
        $analyses = $service->analyzePendingIncidents(
            limit: $limit,
            provider: $provider,
            includeFailed: $includeFailed
        );
        $this->output->writeln('done!');

        $this->table(
            ['Incident ID', 'Signature', 'Threat Level', 'False Positive', 'Status', 'Tokens'],
            $analyses->map(fn ($analysis) => [
                $analysis->incident?->incident_id ?? "#{$analysis->incident_id}",
                Str::limit($analysis->incident?->signature ?? '-', 35),
                $analysis->threat_level ?? 'N/A',
                $analysis->is_false_positive ? 'Yes' : 'No',
                $analysis->status,
                $analysis->tokens_used ?? 0,
            ])
        );

        $this->info("Successfully analyzed {$analyses->count()} incident(s).");

        return Command::SUCCESS;
    }

    /**
     * Run as a continuous background daemon.
     */
    protected function runDaemon(IncidentAnalysisService $service): int
    {
        $interval = (int) ($this->option('interval') ?: config('ai.polling.interval', 5));
        if ($interval < 1) {
            $interval = 5;
        }

        $this->info("Starting AI incident auto-polling daemon (interval: {$interval} minute(s)).");
        $this->comment('Press Ctrl+C to terminate.');

        while (true) {
            $this->pollOnce($service);
            $this->line("Sleeping for {$interval} minute(s) until next poll...");
            sleep($interval * 60);
        }

        return Command::SUCCESS;
    }
}
