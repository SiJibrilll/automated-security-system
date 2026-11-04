<?php

use App\Models\Incident;
use App\Models\IncidentAnalysis;
use App\Services\Ai\IncidentAnalysisService;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'severity')]
    public string $severity = 'all';

    #[Url(as: 'ai_status')]
    public string $aiStatus = 'all';

    #[Url(as: 'hypervisor')]
    public string $hypervisor = 'all';

    #[Url(as: 'sort')]
    public string $sortField = 'timestamp';

    #[Url(as: 'direction')]
    public string $sortDirection = 'desc';

    public ?int $selectedIncidentId = null;

    public bool $showDetailModal = false;

    public string $activeDetailTab = 'overview';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSeverity(): void
    {
        $this->resetPage();
    }

    public function updatedAiStatus(): void
    {
        $this->resetPage();
    }

    public function updatedHypervisor(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'desc';
        }

        $this->resetPage();
    }

    /**
     * Compute summary metrics for incidents.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function stats(): array
    {
        $total = Incident::count();
        $analyzed = Incident::whereHas('analyses')->count();
        $pending = Incident::whereDoesntHave('analyses')->count();
        $criticalOrHigh = Incident::whereIn('severity_label', ['critical', 'high'])->count();
        $hypervisorsCount = Incident::whereNotNull('hypervisor')->distinct()->count('hypervisor');

        return [
            'total' => $total,
            'analyzed' => $analyzed,
            'pending' => $pending,
            'critical_high' => $criticalOrHigh,
            'nodes_count' => $hypervisorsCount,
        ];
    }

    /**
     * Get distinct list of hypervisors for filter dropdown.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function hypervisorsList(): array
    {
        return Incident::whereNotNull('hypervisor')
            ->distinct()
            ->orderBy('hypervisor')
            ->pluck('hypervisor')
            ->toArray();
    }

    /**
     * Get paginated incidents query.
     */
    #[Computed]
    public function incidents(): LengthAwarePaginator
    {
        $query = Incident::query()
            ->with(['latestAnalysis']);

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('incident_id', 'like', $term)
                    ->orWhere('signature', 'like', $term)
                    ->orWhere('hypervisor', 'like', $term)
                    ->orWhere('incident_json', 'like', $term);
            });
        }

        if ($this->severity !== 'all') {
            $query->where('severity_label', $this->severity);
        }

        if ($this->hypervisor !== 'all') {
            $query->where('hypervisor', $this->hypervisor);
        }

        if ($this->aiStatus === 'analyzed') {
            $query->whereHas('analyses');
        } elseif ($this->aiStatus === 'pending') {
            $query->whereDoesntHave('analyses');
        } elseif ($this->aiStatus === 'threats') {
            $query->whereHas('analyses', function ($q) {
                $q->where('is_false_positive', false)
                    ->whereIn('threat_level', ['critical', 'high', 'medium']);
            });
        } elseif ($this->aiStatus === 'false_positive') {
            $query->whereHas('analyses', function ($q) {
                $q->where('is_false_positive', true);
            });
        }

        $validSortFields = ['timestamp', 'id', 'hypervisor', 'severity_label', 'created_at'];
        $field = in_array($this->sortField, $validSortFields) ? $this->sortField : 'timestamp';
        $direction = in_array(strtolower($this->sortDirection), ['asc', 'desc']) ? $this->sortDirection : 'desc';

        return $query->orderBy($field, $direction)
            ->orderByDesc('id')
            ->paginate(12);
    }

    /**
     * Get currently selected incident.
     */
    #[Computed]
    public function selectedIncident(): ?Incident
    {
        if (! $this->selectedIncidentId) {
            return null;
        }

        return Incident::with(['latestAnalysis', 'analyses'])->find($this->selectedIncidentId);
    }

    /**
     * Open detail modal.
     */
    public function openDetail(int $incidentId, string $tab = 'overview'): void
    {
        $this->selectedIncidentId = $incidentId;
        $this->activeDetailTab = $tab;
        $this->showDetailModal = true;
    }

    /**
     * Close detail modal.
     */
    public function closeDetail(): void
    {
        $this->showDetailModal = false;
        $this->selectedIncidentId = null;
        $this->activeDetailTab = 'overview';
    }

    /**
     * Analyze a single incident on demand.
     */
    public function analyzeSingleIncident(int $incidentId, IncidentAnalysisService $service): void
    {
        $incident = Incident::find($incidentId);

        if (! $incident) {
            Flux::toast(variant: 'danger', text: 'Data insiden tidak ditemukan.');

            return;
        }

        $analysis = $service->analyze($incident);

        Flux::toast(
            variant: 'success',
            text: "Insiden #{$incident->id} ({$incident->incident_id}) berhasil dianalisis oleh AI!"
        );

        unset($this->incidents);
        unset($this->selectedIncident);
        unset($this->stats);
    }

    /**
     * Reset all filters.
     */
    public function resetFilters(): void
    {
        $this->search = '';
        $this->severity = 'all';
        $this->aiStatus = 'all';
        $this->hypervisor = 'all';
        $this->resetPage();
    }
};
?>

<div class="space-y-6 pb-12">
    {{-- Header Section --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2.5">
                <flux:heading size="xl" level="1" class="font-bold tracking-tight">
                    {{ __('Log & Data Asli Insiden Keamanan') }}
                </flux:heading>
                <flux:badge color="zinc" size="sm" class="font-mono">
                    {{ $this->stats['total'] }} {{ __('Data') }}
                </flux:badge>
            </div>
            <flux:subheading class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Daftar rekaman mentah dari sensor keamanan dan hypervisor, disajikan dengan data teknis lengkap namun tetap mudah dipahami.') }}
            </flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:button
                :href="route('dashboard')"
                icon="home"
                variant="subtle"
                size="sm"
                wire:navigate
            >
                {{ __('Ke Ringkasan Dashboard') }}
            </flux:button>
        </div>
    </div>

    {{-- Quick Stat Pill Counters --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Total Rekaman Insiden') }}</span>
            <div class="mt-1.5 flex items-baseline gap-2">
                <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ $this->stats['total'] }}</span>
                <span class="text-xs text-zinc-400">{{ __('peristiwa') }}</span>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Sensor Kritis / Tinggi') }}</span>
            <div class="mt-1.5 flex items-baseline gap-2">
                <span class="text-xl font-bold tracking-tight text-rose-600 dark:text-rose-400">{{ $this->stats['critical_high'] }}</span>
                <span class="text-xs text-zinc-400">{{ __('butuh cek') }}</span>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Evaluasi AI Selesai') }}</span>
            <div class="mt-1.5 flex items-baseline gap-2">
                <span class="text-xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">{{ $this->stats['analyzed'] }}</span>
                <span class="text-xs text-zinc-400">/ {{ $this->stats['total'] }}</span>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Node Hypervisor Aktif') }}</span>
            <div class="mt-1.5 flex items-baseline gap-2">
                <span class="text-xl font-bold tracking-tight text-blue-600 dark:text-blue-400">{{ $this->stats['nodes_count'] }}</span>
                <span class="text-xs text-zinc-400">{{ __('server') }}</span>
            </div>
        </div>
    </div>

    {{-- Filter & Search Toolbar --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-3">
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
            {{-- Search Bar --}}
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari ID insiden, nama aktivitas/signature, server, IP address..."
                    icon="magnifying-glass"
                    size="sm"
                    clearable
                />
            </div>

            {{-- Reset button if filters active --}}
            @if($search !== '' || $severity !== 'all' || $aiStatus !== 'all' || $hypervisor !== 'all')
                <button
                    type="button"
                    wire:click="resetFilters"
                    class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 shrink-0"
                >
                    <flux:icon name="arrow-path" class="size-3.5" />
                    {{ __('Reset Semua Filter') }}
                </button>
            @endif
        </div>

        {{-- Dropdowns Filter Row --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 pt-2 border-t border-zinc-100 dark:border-zinc-800">
            {{-- Severity Filter --}}
            <div>
                <label class="block text-[11px] font-semibold text-zinc-500 dark:text-zinc-400 mb-1">
                    {{ __('Tingkat Keparahan Sensor:') }}
                </label>
                <select
                    wire:model.live="severity"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-800 focus:border-blue-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                >
                    <option value="all">{{ __('Semua Tingkat (Kritis - Rendah)') }}</option>
                    <option value="critical">{{ __('Kritis (Critical)') }}</option>
                    <option value="high">{{ __('Tinggi (High)') }}</option>
                    <option value="medium">{{ __('Sedang (Medium)') }}</option>
                    <option value="low">{{ __('Rendah (Low)') }}</option>
                </select>
            </div>

            {{-- AI Status Filter --}}
            <div>
                <label class="block text-[11px] font-semibold text-zinc-500 dark:text-zinc-400 mb-1">
                    {{ __('Status Analisis AI:') }}
                </label>
                <select
                    wire:model.live="aiStatus"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-800 focus:border-blue-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                >
                    <option value="all">{{ __('Semua Status AI') }}</option>
                    <option value="analyzed">{{ __('Sudah Dianalisis AI') }}</option>
                    <option value="pending">{{ __('Menunggu Analisis AI') }}</option>
                    <option value="threats">{{ __('Ancaman Dikonfirmasi (Bukan Alarm Palsu)') }}</option>
                    <option value="false_positive">{{ __('Alarm Palsu (Aktivitas Normal)') }}</option>
                </select>
            </div>

            {{-- Hypervisor Filter --}}
            <div>
                <label class="block text-[11px] font-semibold text-zinc-500 dark:text-zinc-400 mb-1">
                    {{ __('Server / Hypervisor Asal:') }}
                </label>
                <select
                    wire:model.live="hypervisor"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-800 focus:border-blue-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                >
                    <option value="all">{{ __('Semua Server / Hypervisor') }}</option>
                    @foreach($this->hypervisorsList as $node)
                        <option value="{{ $node }}">{{ $node }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- Table of Incidents --}}
    <div class="rounded-xl border border-zinc-200 bg-white shadow-xs overflow-hidden dark:border-zinc-800 dark:bg-zinc-900">
        @if($this->incidents->isEmpty())
            <div class="py-16 px-4 text-center">
                <div class="mx-auto flex size-12 items-center justify-center rounded-xl bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400 mb-3">
                    <flux:icon name="document-text" class="size-6" />
                </div>
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">
                    {{ __('Tidak Ada Data Insiden Ditemukan') }}
                </h3>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 max-w-sm mx-auto">
                    {{ __('Data insiden tidak ditemukan berdasarkan filter atau pencarian Anda.') }}
                </p>
                @if($search !== '' || $severity !== 'all' || $aiStatus !== 'all' || $hypervisor !== 'all')
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="mt-3 inline-flex items-center rounded-lg bg-zinc-100 px-3 py-1.5 text-xs font-medium text-zinc-800 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700"
                    >
                        {{ __('Hapus Filter') }}
                    </button>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-zinc-600 dark:text-zinc-300">
                    <thead class="bg-zinc-50/80 text-[11px] font-semibold uppercase tracking-wider text-zinc-500 border-b border-zinc-200 dark:bg-zinc-800/50 dark:border-zinc-800 dark:text-zinc-400">
                        <tr>
                            <th scope="col" class="py-3 px-4 cursor-pointer hover:text-zinc-900 dark:hover:text-white" wire:click="sortBy('timestamp')">
                                <span class="flex items-center gap-1">
                                    {{ __('Waktu Kejadian') }}
                                    @if($sortField === 'timestamp')
                                        <span>{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                    @endif
                                </span>
                            </th>
                            <th scope="col" class="py-3 px-4">{{ __('ID & Server') }}</th>
                            <th scope="col" class="py-3 px-4">{{ __('Aktivitas / Signature Sensor') }}</th>
                            <th scope="col" class="py-3 px-4">{{ __('Jaringan (Sumber → Tujuan)') }}</th>
                            <th scope="col" class="py-3 px-4 cursor-pointer hover:text-zinc-900 dark:hover:text-white" wire:click="sortBy('severity_label')">
                                <span class="flex items-center gap-1">
                                    {{ __('Severity Sensor') }}
                                    @if($sortField === 'severity_label')
                                        <span>{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                    @endif
                                </span>
                            </th>
                            <th scope="col" class="py-3 px-4">{{ __('Hasil Evaluasi AI') }}</th>
                            <th scope="col" class="py-3 px-4 text-right">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/80">
                        @foreach($this->incidents as $incident)
                            @php
                                $analysis = $incident->latestAnalysis;
                                $sev = strtolower($incident->severity_label ?? 'info');
                            @endphp
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition-colors">
                                {{-- 1. Timestamp --}}
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <div class="font-medium text-zinc-900 dark:text-white">
                                        {{ $incident->timestamp?->diffForHumans() ?: $incident->created_at?->diffForHumans() }}
                                    </div>
                                    <div class="text-[11px] text-zinc-400 font-mono">
                                        {{ $incident->timestamp?->format('d/m/Y H:i:s') ?: $incident->created_at?->format('d/m/Y H:i:s') }}
                                    </div>
                                </td>

                                {{-- 2. ID & Server --}}
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <div class="font-mono font-semibold text-zinc-900 dark:text-zinc-100">
                                        {{ $incident->incident_id ?: "#{$incident->id}" }}
                                    </div>
                                    <div class="flex items-center gap-1 text-[11px] text-zinc-500 dark:text-zinc-400 mt-0.5">
                                        <flux:icon name="server" class="size-3 text-zinc-400" />
                                        <span>{{ $incident->hypervisor ?: '-' }}</span>
                                    </div>
                                </td>

                                {{-- 3. Signature & AI Summary snippet --}}
                                <td class="py-3 px-4 max-w-xs">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100 truncate" title="{{ $incident->signature }}">
                                        {{ $incident->signature ?: 'Aktivitas Keamanan Tanpa Judul' }}
                                    </div>
                                    @if($analysis && $analysis->summary)
                                        <div class="text-[11px] text-zinc-500 dark:text-zinc-400 truncate mt-0.5" title="{{ $analysis->summary }}">
                                            <span class="font-semibold text-blue-600 dark:text-blue-400">AI:</span> {{ $analysis->summary }}
                                        </div>
                                    @endif
                                </td>

                                {{-- 4. Network Tuple --}}
                                <td class="py-3 px-4 whitespace-nowrap font-mono text-[11px]">
                                    @if($incident->source_ip || $incident->destination_ip)
                                        <div class="flex items-center gap-1 text-zinc-700 dark:text-zinc-300">
                                            <span>{{ $incident->source_ip ?: 'any' }}</span>
                                            <span class="text-zinc-400">→</span>
                                            <span>{{ $incident->destination_ip ?: 'host' }}{{ $incident->destination_port ? ":{$incident->destination_port}" : '' }}</span>
                                        </div>
                                        @if($incident->protocol)
                                            <div class="text-[10px] text-zinc-400">
                                                {{ strtoupper($incident->protocol) }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-zinc-400 italic font-sans text-xs">{{ __('Log Host / Peristiwa') }}</span>
                                    @endif
                                </td>

                                {{-- 5. Severity Sensor --}}
                                <td class="py-3 px-4 whitespace-nowrap">
                                    @if($sev === 'critical')
                                        <span class="inline-flex items-center gap-1 rounded-md bg-rose-100 px-2 py-0.5 text-[11px] font-bold text-rose-800 dark:bg-rose-950 dark:text-rose-300">
                                            {{ __('Kritis') }}
                                        </span>
                                    @elseif($sev === 'high')
                                        <span class="inline-flex items-center gap-1 rounded-md bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-700 dark:bg-rose-950 dark:text-rose-300">
                                            {{ __('Tinggi') }}
                                        </span>
                                    @elseif($sev === 'medium')
                                        <span class="inline-flex items-center gap-1 rounded-md bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                            {{ __('Sedang') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-md bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-800 dark:bg-blue-950 dark:text-blue-300">
                                            {{ __('Rendah') }}
                                        </span>
                                    @endif
                                </td>

                                {{-- 6. AI Analysis Status --}}
                                <td class="py-3 px-4 whitespace-nowrap">
                                    @if($analysis)
                                        @if($analysis->is_false_positive)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                                <flux:icon name="check-circle" class="size-3" />
                                                {{ __('Aman (Alarm Palsu)') }}
                                            </span>
                                        @elseif(in_array(strtolower($analysis->threat_level ?? ''), ['critical', 'high']))
                                            <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-800 dark:bg-rose-950 dark:text-rose-300">
                                                <flux:icon name="shield-exclamation" class="size-3" />
                                                {{ __('Bahaya Nyata (AI)') }}
                                            </span>
                                        @elseif(strtolower($analysis->threat_level ?? '') === 'medium')
                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                                <flux:icon name="exclamation-triangle" class="size-3" />
                                                {{ __('Risiko Sedang (AI)') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-800 dark:bg-blue-950 dark:text-blue-300">
                                                {{ __('Info Aman (AI)') }}
                                            </span>
                                        @endif
                                    @else
                                        <button
                                            type="button"
                                            wire:click="analyzeSingleIncident({{ $incident->id }})"
                                            wire:loading.attr="disabled"
                                            class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 hover:bg-amber-100 border border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800/60"
                                            title="Klik untuk memproses analisis AI sekarang"
                                        >
                                            <flux:icon name="sparkles" class="size-3 text-amber-500" />
                                            <span>{{ __('Analisis AI') }}</span>
                                        </button>
                                    @endif
                                </td>

                                {{-- 7. Action --}}
                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                    <button
                                        type="button"
                                        wire:click="openDetail({{ $incident->id }})"
                                        class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-semibold text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-zinc-800 transition-colors"
                                    >
                                        <span>{{ __('Rincian') }}</span>
                                        <flux:icon name="chevron-right" class="size-3" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pagination footer --}}
            <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
                {{ $this->incidents->links() }}
            </div>
        @endif
    </div>

    {{-- Detail Inspector Modal --}}
    <flux:modal wire:model="showDetailModal" class="max-w-3xl space-y-5">
        @if($this->selectedIncident)
            @php
                $item = $this->selectedIncident;
                $analysis = $item->latestAnalysis;
                $sev = strtolower($item->severity_label ?? 'info');
            @endphp

            <div>
                {{-- Modal Header --}}
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800 gap-2">
                    <div>
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg" class="font-bold">
                                {{ __('Inspeksi Data Insiden') }}
                            </flux:heading>
                            <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs font-mono font-bold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                {{ $item->incident_id ?: "#{$item->id}" }}
                            </span>
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                            {{ __('Server / Node:') }} <strong>{{ $item->hypervisor ?: '-' }}</strong> • {{ $item->timestamp?->translatedFormat('d F Y, H:i:s') ?: $item->created_at?->translatedFormat('d F Y, H:i:s') }}
                        </p>
                    </div>

                    {{-- AI Quick Status Badge --}}
                    <div>
                        @if($analysis)
                            @if($analysis->is_false_positive)
                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                    <flux:icon name="check-circle" class="size-3.5" />
                                    {{ __('Diverifikasi AI: Aman') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-800 dark:bg-rose-950 dark:text-rose-300">
                                    <flux:icon name="shield-exclamation" class="size-3.5" />
                                    {{ __('Ancaman AI: ') }} {{ strtoupper($analysis->threat_level ?? 'Bahaya') }}
                                </span>
                            @endif
                        @else
                            <flux:button
                                wire:click="analyzeSingleIncident({{ $item->id }})"
                                size="sm"
                                variant="primary"
                                icon="sparkles"
                            >
                                {{ __('Jalankan Analisis AI') }}
                            </flux:button>
                        @endif
                    </div>
                </div>

                {{-- Tab Selection Navigation --}}
                <div class="mt-4 flex border-b border-zinc-200 dark:border-zinc-800 text-xs font-semibold">
                    <button
                        type="button"
                        wire:click="$set('activeDetailTab', 'overview')"
                        @class([
                            'pb-2.5 px-3 border-b-2 transition-colors flex items-center gap-1.5',
                            'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400 font-bold' => $activeDetailTab === 'overview',
                            'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeDetailTab !== 'overview',
                        ])
                    >
                        <flux:icon name="sparkles" class="size-3.5" />
                        {{ __('1. Penjelasan AI (Awam)') }}
                    </button>

                    <button
                        type="button"
                        wire:click="$set('activeDetailTab', 'technical')"
                        @class([
                            'pb-2.5 px-3 border-b-2 transition-colors flex items-center gap-1.5',
                            'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400 font-bold' => $activeDetailTab === 'technical',
                            'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeDetailTab !== 'technical',
                        ])
                    >
                        <flux:icon name="cpu-chip" class="size-3.5" />
                        {{ __('2. Rincian Jaringan (Teknis)') }}
                    </button>

                    <button
                        type="button"
                        wire:click="$set('activeDetailTab', 'raw')"
                        @class([
                            'pb-2.5 px-3 border-b-2 transition-colors flex items-center gap-1.5',
                            'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400 font-bold' => $activeDetailTab === 'raw',
                            'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeDetailTab !== 'raw',
                        ])
                    >
                        <flux:icon name="document-text" class="size-3.5" />
                        {{ __('3. Payload JSON Asli') }}
                    </button>
                </div>

                {{-- Tab 1: Ikhtisar Awam & AI --}}
                @if($activeDetailTab === 'overview')
                    <div class="mt-4 space-y-4">
                        @if($analysis)
                            {{-- Ringkasan AI --}}
                            <div class="rounded-xl bg-zinc-50 p-4 border border-zinc-200 dark:bg-zinc-800/60 dark:border-zinc-700/60">
                                <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-1.5">
                                    {{ __('Apa yang Sebenarnya Terjadi?') }}
                                </h4>
                                <p class="text-sm font-semibold text-zinc-900 dark:text-white leading-relaxed">
                                    {{ $analysis->summary }}
                                </p>
                                @if($analysis->detailed_analysis && $analysis->detailed_analysis !== $analysis->summary)
                                    <p class="mt-2 text-xs text-zinc-600 dark:text-zinc-300 leading-relaxed border-t border-zinc-200/60 dark:border-zinc-700/60 pt-2">
                                        {{ $analysis->detailed_analysis }}
                                    </p>
                                @endif
                            </div>

                            {{-- Rekomendasi Solusi --}}
                            <div>
                                <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">
                                    {{ __('Langkah Solusi yang Disarankan AI:') }}
                                </h4>
                                @if(!empty($analysis->recommendations) && is_array($analysis->recommendations))
                                    <div class="space-y-2">
                                        @foreach($analysis->recommendations as $idx => $step)
                                            <div class="flex items-start gap-2.5 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                                                <span class="flex size-5 shrink-0 items-center justify-center rounded-full bg-blue-100 text-[11px] font-bold text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                                                    {{ $idx + 1 }}
                                                </span>
                                                <p class="text-xs text-zinc-800 dark:text-zinc-200 leading-relaxed">
                                                    {{ $step }}
                                                </p>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-xs text-zinc-500 italic">{{ __('Tidak ada langkah khusus yang diperlukan.') }}</p>
                                @endif
                            </div>
                        @else
                            {{-- State Belum Dianalisis --}}
                            <div class="rounded-xl border border-dashed border-amber-300 bg-amber-50/50 p-6 text-center dark:border-amber-900 dark:bg-amber-950/20">
                                <flux:icon name="sparkles" class="size-8 mx-auto text-amber-500 mb-2" />
                                <h4 class="text-sm font-bold text-amber-950 dark:text-amber-200">
                                    {{ __('Insiden Ini Belum Dianalisis Oleh AI') }}
                                </h4>
                                <p class="mt-1 text-xs text-amber-800/90 dark:text-amber-300/80 max-w-md mx-auto">
                                    {{ __('Jalankan analisis AI sekarang untuk mendapatkan ringkasan bahasa awam, status alarm palsu, dan rekomendasi solusi instan.') }}
                                </p>
                                <div class="mt-4">
                                    <flux:button
                                        wire:click="analyzeSingleIncident({{ $item->id }})"
                                        variant="primary"
                                        size="sm"
                                        icon="sparkles"
                                    >
                                        {{ __('Mulai Analisis AI Sekarang') }}
                                    </flux:button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Tab 2: Parameter Jaringan & Teknis --}}
                @if($activeDetailTab === 'technical')
                    <div class="mt-4 space-y-4">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="rounded-lg bg-zinc-50 p-3 border border-zinc-200/80 dark:bg-zinc-800/50 dark:border-zinc-800">
                                <span class="text-[11px] font-medium text-zinc-400 block">{{ __('IP Sumber (Source IP):') }}</span>
                                <span class="font-mono text-xs font-bold text-zinc-900 dark:text-white">{{ $item->source_ip ?: 'Tidak tercatat / Host-level' }}</span>
                            </div>

                            <div class="rounded-lg bg-zinc-50 p-3 border border-zinc-200/80 dark:bg-zinc-800/50 dark:border-zinc-800">
                                <span class="text-[11px] font-medium text-zinc-400 block">{{ __('Port Sumber (Source Port):') }}</span>
                                <span class="font-mono text-xs font-bold text-zinc-900 dark:text-white">{{ $item->source_port ?: '-' }}</span>
                            </div>

                            <div class="rounded-lg bg-zinc-50 p-3 border border-zinc-200/80 dark:bg-zinc-800/50 dark:border-zinc-800">
                                <span class="text-[11px] font-medium text-zinc-400 block">{{ __('IP Tujuan (Destination IP):') }}</span>
                                <span class="font-mono text-xs font-bold text-zinc-900 dark:text-white">{{ $item->destination_ip ?: 'Tidak tercatat / Host-level' }}</span>
                            </div>

                            <div class="rounded-lg bg-zinc-50 p-3 border border-zinc-200/80 dark:bg-zinc-800/50 dark:border-zinc-800">
                                <span class="text-[11px] font-medium text-zinc-400 block">{{ __('Port Tujuan (Destination Port):') }}</span>
                                <span class="font-mono text-xs font-bold text-zinc-900 dark:text-white">{{ $item->destination_port ?: '-' }}</span>
                            </div>

                            <div class="rounded-lg bg-zinc-50 p-3 border border-zinc-200/80 dark:bg-zinc-800/50 dark:border-zinc-800">
                                <span class="text-[11px] font-medium text-zinc-400 block">{{ __('Protokol Jaringan:') }}</span>
                                <span class="font-mono text-xs font-bold text-zinc-900 dark:text-white">{{ strtoupper($item->protocol ?: '-') }}</span>
                            </div>

                            <div class="rounded-lg bg-zinc-50 p-3 border border-zinc-200/80 dark:bg-zinc-800/50 dark:border-zinc-800">
                                <span class="text-[11px] font-medium text-zinc-400 block">{{ __('Severity Sensor:') }}</span>
                                <span class="font-bold text-xs uppercase text-zinc-900 dark:text-white">{{ $item->severity_label ?: 'info' }}</span>
                            </div>
                        </div>

                        <div class="rounded-lg bg-zinc-50 p-3 border border-zinc-200/80 dark:bg-zinc-800/50 dark:border-zinc-800">
                            <span class="text-[11px] font-medium text-zinc-400 block">{{ __('Aturan Signature Sensor Asli:') }}</span>
                            <span class="font-mono text-xs font-bold text-zinc-900 dark:text-white break-all">{{ $item->signature ?: '-' }}</span>
                        </div>
                    </div>
                @endif

                {{-- Tab 3: Payload JSON Mentah --}}
                @if($activeDetailTab === 'raw')
                    <div class="mt-4 space-y-3" x-data="{ copied: false }">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                                {{ __('Payload data mentah yang dikirimkan oleh hypervisor / IDS:') }}
                            </span>

                            <button
                                type="button"
                                @click="navigator.clipboard.writeText(JSON.stringify({{ json_encode($item->incident_json) }}, null, 2)); copied = true; setTimeout(() => copied = false, 2000)"
                                class="inline-flex items-center gap-1.5 rounded-md border border-zinc-300 bg-white px-2.5 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700 transition-colors"
                            >
                                <span x-show="!copied" class="flex items-center gap-1">
                                    <flux:icon name="document-text" class="size-3.5" />
                                    {{ __('Salin JSON') }}
                                </span>
                                <span x-show="copied" x-cloak class="flex items-center gap-1 text-emerald-600 font-bold">
                                    <flux:icon name="check" class="size-3.5" />
                                    {{ __('Tersalin!') }}
                                </span>
                            </button>
                        </div>

                        <div class="relative rounded-xl bg-zinc-950 p-4 border border-zinc-800 overflow-hidden">
                            <pre class="max-h-80 overflow-auto font-mono text-[11px] leading-relaxed text-emerald-400"><code>{{ json_encode($item->incident_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                        </div>
                    </div>
                @endif

                {{-- Modal Footer --}}
                <div class="mt-6 flex justify-end gap-2 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                    <flux:modal.close>
                        <flux:button variant="filled" wire:click="closeDetail">
                            {{ __('Tutup') }}
                        </flux:button>
                    </flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
