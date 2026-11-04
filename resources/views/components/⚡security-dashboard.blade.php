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

    #[Url(as: 'filter')]
    public string $filter = 'all';

    #[Url(as: 'search')]
    public string $search = '';

    public ?int $selectedAnalysisId = null;

    public bool $showDetailModal = false;

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Compute system-wide security overview statistics in plain language.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function stats(): array
    {
        $totalIncidents = Incident::count();
        $completedAnalyses = IncidentAnalysis::where('status', 'completed');
        $analyzedCount = (clone $completedAnalyses)->count();
        $pendingCount = Incident::unanalyzed()->count();

        $realThreats = (clone $completedAnalyses)
            ->where('is_false_positive', false)
            ->whereIn('threat_level', ['critical', 'high'])
            ->count();

        $mediumThreats = (clone $completedAnalyses)
            ->where('is_false_positive', false)
            ->where('threat_level', 'medium')
            ->count();

        $falsePositives = (clone $completedAnalyses)
            ->where('is_false_positive', true)
            ->count();

        if ($realThreats > 0) {
            $condition = 'danger';
            $title = 'Perhatian: Ada Ancaman Berisiko Tinggi Terdeteksi!';
            $description = "Kecerdasan Buatan (AI) menemukan {$realThreats} aktivitas berisiko tinggi yang membutuhkan penanganan. Silakan ikuti rekomendasi langkah solusi di bawah.";
        } elseif ($mediumThreats > 0 || $pendingCount > 0) {
            $condition = 'warning';
            $title = 'Kondisi Waspada: Perlu Sedikit Perhatian';
            $description = $pendingCount > 0
                ? "Terdapat {$pendingCount} insiden baru yang sedang mengantre untuk diperiksa AI. Sistem aman, namun disarankan untuk meninjau temuan."
                : "Ditemukan {$mediumThreats} aktivitas dengan risiko sedang. Sistem masih aman, namun disarankan untuk meninjau panduan AI.";
        } else {
            $condition = 'secure';
            $title = 'Kondisi Sistem Aman Terkendali';
            $description = 'Tidak ada ancaman berbahaya yang aktif. Seluruh server terpantau aman dan AI terus mengawasi sistem Anda secara otomatis.';
        }

        return [
            'total_incidents' => $totalIncidents,
            'analyzed_count' => $analyzedCount,
            'pending_count' => $pendingCount,
            'real_threats' => $realThreats,
            'medium_threats' => $mediumThreats,
            'false_positives' => $falsePositives,
            'condition' => $condition,
            'title' => $title,
            'description' => $description,
        ];
    }

    /**
     * Get paginated completed analyses.
     */
    #[Computed]
    public function analyses(): LengthAwarePaginator
    {
        $query = IncidentAnalysis::with('incident')
            ->where('status', 'completed')
            ->latest('id');

        if ($this->filter === 'threats') {
            $query->where('is_false_positive', false)
                ->whereIn('threat_level', ['critical', 'high', 'medium']);
        } elseif ($this->filter === 'false_positives') {
            $query->where('is_false_positive', true);
        }

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('summary', 'like', $term)
                    ->orWhere('detailed_analysis', 'like', $term)
                    ->orWhereHas('incident', function ($iq) use ($term) {
                        $iq->where('signature', 'like', $term)
                            ->orWhere('hypervisor', 'like', $term)
                            ->orWhere('incident_id', 'like', $term);
                    });
            });
        }

        return $query->paginate(6);
    }

    /**
     * Get unanalyzed incidents waiting for AI processing.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Incident>
     */
    #[Computed]
    public function pendingIncidents(): \Illuminate\Database\Eloquent\Collection
    {
        return Incident::unanalyzed()->latest('id')->take(10)->get();
    }

    /**
     * Get currently selected analysis for modal view.
     */
    #[Computed]
    public function selectedAnalysis(): ?IncidentAnalysis
    {
        if (! $this->selectedAnalysisId) {
            return null;
        }

        return IncidentAnalysis::with('incident')->find($this->selectedAnalysisId);
    }

    /**
     * Open detail modal for specific analysis.
     */
    public function openDetail(int $analysisId): void
    {
        $this->selectedAnalysisId = $analysisId;
        $this->showDetailModal = true;
    }

    /**
     * Close detail modal.
     */
    public function closeDetail(): void
    {
        $this->showDetailModal = false;
        $this->selectedAnalysisId = null;
    }

    /**
     * Run manual analysis on pending incidents.
     */
    public function runManualAnalysis(IncidentAnalysisService $service): void
    {
        $pendingCount = Incident::unanalyzed()->count();

        if ($pendingCount === 0) {
            Flux::toast(variant: 'warning', text: 'Tidak ada data insiden baru. Kuota token AI Anda tetap hemat!');

            return;
        }

        $processed = $service->analyzePendingIncidents(limit: 5);

        Flux::toast(
            variant: 'success',
            text: "Berhasil! AI telah menganalisis {$processed->count()} insiden baru."
        );

        unset($this->stats);
        unset($this->analyses);
        unset($this->pendingIncidents);
    }
};
?>

<div class="space-y-6 pb-12" wire:poll.30s>
    {{-- Header Section --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2.5">
                <flux:heading size="xl" level="1" class="font-bold tracking-tight">
                    {{ __('Pusat Keamanan & Analisis AI') }}
                </flux:heading>
                <flux:badge color="blue" size="sm" icon="sparkles" class="font-medium">
                    {{ __('AI Terkoneksi') }}
                </flux:badge>
            </div>
            <flux:subheading class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Pantau status kesehatan dan keamanan server Anda dengan penjelasan bahasa awam yang jelas dan mudah dipahami.') }}
            </flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:button
                wire:click="runManualAnalysis"
                wire:loading.attr="disabled"
                icon="arrow-path"
                variant="primary"
                size="sm"
            >
                <span wire:loading.remove wire:target="runManualAnalysis">{{ __('Periksa Insiden Sekarang') }}</span>
                <span wire:loading wire:target="runManualAnalysis">{{ __('Sedang Menganalisis...') }}</span>
            </flux:button>
        </div>
    </div>

    {{-- Overall Condition Banner (Ramah Orang Awam) --}}
    @php
        $cond = $this->stats['condition'];
    @endphp
    <div @class([
        'relative overflow-hidden rounded-2xl border p-5 sm:p-6 transition-all',
        'bg-gradient-to-r from-emerald-50 to-teal-50/60 dark:from-emerald-950/40 dark:to-teal-950/20 border-emerald-200 dark:border-emerald-800/60' => $cond === 'secure',
        'bg-gradient-to-r from-amber-50 to-yellow-50/60 dark:from-amber-950/40 dark:to-yellow-950/20 border-amber-200 dark:border-amber-800/60' => $cond === 'warning',
        'bg-gradient-to-r from-rose-50 to-red-50/60 dark:from-rose-950/40 dark:to-red-950/20 border-rose-200 dark:border-rose-800/60' => $cond === 'danger',
    ])>
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div class="flex items-start gap-4">
                <div @class([
                    'flex size-12 shrink-0 items-center justify-center rounded-xl shadow-sm',
                    'bg-emerald-500 text-white' => $cond === 'secure',
                    'bg-amber-500 text-white' => $cond === 'warning',
                    'bg-rose-600 text-white animate-pulse' => $cond === 'danger',
                ])>
                    @if($cond === 'secure')
                        <flux:icon name="shield-check" class="size-7" />
                    @elseif($cond === 'warning')
                        <flux:icon name="exclamation-triangle" class="size-7" />
                    @else
                        <flux:icon name="shield-exclamation" class="size-7" />
                    @endif
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 @class([
                            'text-lg font-bold sm:text-xl',
                            'text-emerald-950 dark:text-emerald-100' => $cond === 'secure',
                            'text-amber-950 dark:text-amber-100' => $cond === 'warning',
                            'text-rose-950 dark:text-rose-100' => $cond === 'danger',
                        ])>
                            {{ $this->stats['title'] }}
                        </h2>
                        <span @class([
                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold uppercase tracking-wider',
                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200' => $cond === 'secure',
                            'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200' => $cond === 'warning',
                            'bg-rose-100 text-rose-800 dark:bg-rose-900/60 dark:text-rose-200' => $cond === 'danger',
                        ])>
                            {{ $cond === 'secure' ? 'Aman' : ($cond === 'warning' ? 'Waspada' : 'Bahaya') }}
                        </span>
                    </div>
                    <p @class([
                        'mt-1.5 text-sm leading-relaxed max-w-3xl',
                        'text-emerald-800/90 dark:text-emerald-200/80' => $cond === 'secure',
                        'text-amber-800/90 dark:text-amber-200/80' => $cond === 'warning',
                        'text-rose-800/90 dark:text-rose-200/80' => $cond === 'danger',
                    ])>
                        {{ $this->stats['description'] }}
                    </p>
                </div>
            </div>

            <div class="flex flex-row sm:flex-col items-center sm:items-end justify-between w-full sm:w-auto shrink-0 pt-2 sm:pt-0 border-t sm:border-t-0 border-zinc-200/60 dark:border-zinc-800/60">
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                    {{ __('Pembaruan Otomatis') }}
                </span>
                <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300 flex items-center gap-1.5 mt-0.5">
                    <span class="size-2 rounded-full bg-emerald-500 inline-block animate-ping"></span>
                    {{ __('Tiap 5-10 Menit') }}
                </span>
            </div>
        </div>
    </div>

    {{-- 4 Metric Cards (Kamus Sederhana & Status) --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {{-- Card 1: Status Sistem --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Kondisi Keseluruhan') }}</span>
                <div @class([
                    'flex size-8 items-center justify-center rounded-lg',
                    'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400' => $cond === 'secure',
                    'bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400' => $cond === 'warning',
                    'bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400' => $cond === 'danger',
                ])>
                    <flux:icon name="shield-check" class="size-5" />
                </div>
            </div>
            <div class="mt-3">
                <span @class([
                    'text-2xl font-bold tracking-tight',
                    'text-emerald-600 dark:text-emerald-400' => $cond === 'secure',
                    'text-amber-600 dark:text-amber-400' => $cond === 'warning',
                    'text-rose-600 dark:text-rose-400' => $cond === 'danger',
                ])>
                    {{ $cond === 'secure' ? 'Aman' : ($cond === 'warning' ? 'Perlu Tinjauan' : 'Bahaya Kritis') }}
                </span>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Berdasarkan evaluasi AI terkini') }}
                </p>
            </div>
        </div>

        {{-- Card 2: Ancaman Nyata --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Ancaman Nyata (Tinggi/Kritis)') }}</span>
                <div class="flex size-8 items-center justify-center rounded-lg bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400">
                    <flux:icon name="shield-exclamation" class="size-5" />
                </div>
            </div>
            <div class="mt-3">
                <span @class([
                    'text-2xl font-bold tracking-tight',
                    'text-rose-600 dark:text-rose-400' => $this->stats['real_threats'] > 0,
                    'text-zinc-900 dark:text-white' => $this->stats['real_threats'] === 0,
                ])>
                    {{ $this->stats['real_threats'] }}
                </span>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ $this->stats['real_threats'] > 0 ? 'Perlu tindakan penanganan segera' : 'Tidak ada ancaman berbahaya' }}
                </p>
            </div>
        </div>

        {{-- Card 3: Alarm Palsu (Aktivitas Normal) --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Alarm Palsu (Aktivitas Normal)') }}</span>
                <div class="flex size-8 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400">
                    <flux:icon name="check-circle" class="size-5" />
                </div>
            </div>
            <div class="mt-3">
                <span class="text-2xl font-bold tracking-tight text-blue-600 dark:text-blue-400">
                    {{ $this->stats['false_positives'] }}
                </span>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Disaring oleh AI sebagai aktivitas wajar') }}
                </p>
            </div>
        </div>

        {{-- Card 4: Total Insiden & Antrean --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Analisis AI Selesai') }}</span>
                <div class="flex size-8 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                    <flux:icon name="cpu-chip" class="size-5" />
                </div>
            </div>
            <div class="mt-3">
                <span class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                    {{ $this->stats['analyzed_count'] }} <span class="text-sm font-normal text-zinc-400">/ {{ $this->stats['total_incidents'] }}</span>
                </span>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    @if($this->stats['pending_count'] > 0)
                        <span class="font-semibold text-amber-600 dark:text-amber-400">{{ $this->stats['pending_count'] }} antrean baru</span>
                    @else
                        {{ __('Semua insiden telah dianalisis') }}
                    @endif
                </p>
            </div>
        </div>
    </div>

    {{-- Panduan Bantuan Awam (Collapsible / Information Tip) --}}
    <div x-data="{ openGuide: false }" class="rounded-xl border border-blue-200/80 bg-blue-50/50 p-4 dark:border-blue-900/60 dark:bg-blue-950/20">
        <button
            type="button"
            @click="openGuide = !openGuide"
            class="flex w-full items-center justify-between text-left focus:outline-hidden"
        >
            <div class="flex items-center gap-2.5">
                <flux:icon name="information-circle" class="size-5 text-blue-600 dark:text-blue-400 shrink-0" />
                <span class="text-sm font-semibold text-blue-950 dark:text-blue-200">
                    {{ __('Panduan Membaca Laporan untuk Pengguna Baru') }}
                </span>
            </div>
            <span class="text-xs font-medium text-blue-700 dark:text-blue-300 flex items-center gap-1">
                <span x-text="openGuide ? 'Tutup Panduan' : 'Lihat Penjelasan'"></span>
                <flux:icon name="chevron-down" class="size-3.5 transition-transform duration-200" ::class="openGuide ? 'rotate-180' : ''" />
            </span>
        </button>

        <div x-show="openGuide" x-collapse x-cloak class="mt-3.5 grid gap-3 pt-3 border-t border-blue-200/60 dark:border-blue-900/60 text-xs sm:text-sm text-blue-900/90 dark:text-blue-200/80">
            <div class="grid sm:grid-cols-3 gap-3">
                <div class="rounded-lg bg-white/70 dark:bg-zinc-900/60 p-3 border border-blue-100 dark:border-blue-900/40">
                    <p class="font-bold text-zinc-900 dark:text-white flex items-center gap-1.5 mb-1">
                        <span class="size-2 rounded-full bg-emerald-500"></span>
                        {{ __('Apa itu Alarm Palsu?') }}
                    </p>
                    <p class="text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        {{ __('Sering kali aktivitas teknis biasa (seperti update rutin atau backup) memicu sensor keamanan. AI memeriksa secara detail dan menandainya sebagai "Alarm Palsu" agar Anda tidak perlu panik.') }}
                    </p>
                </div>

                <div class="rounded-lg bg-white/70 dark:bg-zinc-900/60 p-3 border border-blue-100 dark:border-blue-900/40">
                    <p class="font-bold text-zinc-900 dark:text-white flex items-center gap-1.5 mb-1">
                        <span class="size-2 rounded-full bg-rose-500"></span>
                        {{ __('Apa itu Ancaman Nyata?') }}
                    </p>
                    <p class="text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        {{ __('Insiden dengan label Kritis atau Tinggi yang telah dikonfirmasi AI sebagai serangan asli (misal percobaan pembobolan password atau eksekusi program asing).') }}
                    </p>
                </div>

                <div class="rounded-lg bg-white/70 dark:bg-zinc-900/60 p-3 border border-blue-100 dark:border-blue-900/40">
                    <p class="font-bold text-zinc-900 dark:text-white flex items-center gap-1.5 mb-1">
                        <span class="size-2 rounded-full bg-blue-500"></span>
                        {{ __('Bagaimana Menanganinya?') }}
                    </p>
                    <p class="text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        {{ __('Cukup klik tombol "Lihat Solusi AI" pada kartu insiden. AI akan memberikan instruksi langkah 1, 2, 3 yang bisa langsung Anda ikuti atau teruskan ke tim teknis.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter & Search Controls --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between pt-2">
        {{-- Filter Buttons --}}
        <div class="flex flex-wrap items-center gap-1.5">
            <button
                type="button"
                wire:click="$set('filter', 'all')"
                @class([
                    'inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-medium transition-colors',
                    'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900 shadow-xs' => $filter === 'all',
                    'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' => $filter !== 'all',
                ])
            >
                {{ __('Semua Hasil') }} ({{ $this->stats['analyzed_count'] }})
            </button>

            <button
                type="button"
                wire:click="$set('filter', 'threats')"
                @class([
                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors',
                    'bg-rose-600 text-white shadow-xs' => $filter === 'threats',
                    'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' => $filter !== 'threats',
                ])
            >
                {{ __('Perlu Ditangani Segera') }}
                @if($this->stats['real_threats'] + $this->stats['medium_threats'] > 0)
                    <span @class([
                        'rounded-full px-1.5 py-0.2 text-[10px] font-bold',
                        'bg-white text-rose-600' => $filter === 'threats',
                        'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300' => $filter !== 'threats',
                    ])>
                        {{ $this->stats['real_threats'] + $this->stats['medium_threats'] }}
                    </span>
                @endif
            </button>

            <button
                type="button"
                wire:click="$set('filter', 'false_positives')"
                @class([
                    'inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-medium transition-colors',
                    'bg-blue-600 text-white shadow-xs' => $filter === 'false_positives',
                    'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' => $filter !== 'false_positives',
                ])
            >
                {{ __('Aktivitas Wajar (Alarm Palsu)') }} ({{ $this->stats['false_positives'] }})
            </button>

            @if($this->stats['pending_count'] > 0)
                <button
                    type="button"
                    wire:click="$set('filter', 'pending')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors',
                        'bg-amber-600 text-white shadow-xs' => $filter === 'pending',
                        'bg-amber-100 text-amber-800 hover:bg-amber-200 dark:bg-amber-950/60 dark:text-amber-200 dark:hover:bg-amber-900/60' => $filter !== 'pending',
                    ])
                >
                    <span class="size-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                    {{ __('Antrean Belum Dianalisis') }} ({{ $this->stats['pending_count'] }})
                </button>
            @endif
        </div>

        {{-- Search Input --}}
        <div class="w-full sm:w-72">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="Cari insiden atau server..."
                icon="magnifying-glass"
                size="sm"
                clearable
            />
        </div>
    </div>

    {{-- Content Area --}}
    @if($filter === 'pending')
        {{-- List of Pending Incidents --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between pb-4 border-b border-zinc-100 dark:border-zinc-800">
                <div>
                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">
                        {{ __('Insiden Menunggu Pemeriksaan AI') }}
                    </h3>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('Insiden ini baru masuk dari server dan akan otomatis diperiksa pada jadwal polling berikutnya (atau klik periksa sekarang).') }}
                    </p>
                </div>
                <flux:button wire:click="runManualAnalysis" size="sm" variant="primary" icon="sparkles">
                    {{ __('Analisis Semua Sekarang') }}
                </flux:button>
            </div>

            <div class="divide-y divide-zinc-100 dark:divide-zinc-800 mt-2">
                @forelse($this->pendingIncidents as $pending)
                    <div class="py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-sm text-zinc-900 dark:text-white">
                                    {{ $pending->signature ?: 'Aktivitas Keamanan Terdeteksi' }}
                                </span>
                                <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 font-mono">
                                    {{ $pending->incident_id ?: "#{$pending->id}" }}
                                </span>
                            </div>
                            <div class="flex items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400">
                                <span class="flex items-center gap-1">
                                    <flux:icon name="server" class="size-3.5" />
                                    {{ $pending->hypervisor ?: 'Server Utama' }}
                                </span>
                                <span>•</span>
                                <span>{{ $pending->created_at?->diffForHumans() ?: 'Baru saja' }}</span>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-amber-600 dark:text-amber-400">
                            <flux:icon name="clock" class="size-4" />
                            {{ __('Menunggu Antrean AI') }}
                        </span>
                    </div>
                @empty
                    <div class="py-8 text-center text-sm text-zinc-500">
                        {{ __('Tidak ada insiden yang mengantre.') }}
                    </div>
                @endforelse
            </div>
        </div>
    @else
        {{-- List of Completed Analyses --}}
        @if($this->analyses->isEmpty())
            {{-- Empty State --}}
            <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-zinc-200 bg-zinc-50/50 py-16 px-4 text-center dark:border-zinc-800 dark:bg-zinc-900/50">
                <div class="flex size-16 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400 mb-4 shadow-xs">
                    <flux:icon name="shield-check" class="size-9" />
                </div>
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">
                    @if(trim($search) !== '')
                        {{ __('Tidak Ada Hasil yang Cocok') }}
                    @elseif($filter === 'threats')
                        {{ __('Hebat! Tidak Ada Ancaman Berbahaya') }}
                    @elseif($filter === 'false_positives')
                        {{ __('Belum Ada Data Alarm Palsu') }}
                    @else
                        {{ __('Belum Ada Insiden Terdeteksi') }}
                    @endif
                </h3>
                <p class="mt-1.5 max-w-md text-sm text-zinc-500 dark:text-zinc-400">
                    @if(trim($search) !== '')
                        {{ __('Coba gunakan kata kunci pencarian yang lain atau hapus filter untuk melihat semua data.') }}
                    @elseif($filter === 'threats')
                        {{ __('Sistem tidak mendeteksi adanya ancaman berbahaya aktif. Semua sistem terpantau aman dan normal.') }}
                    @else
                        {{ __('Sistem AI Anda aktif mengawasi. Bila ada aktivitas mencurigakan dari server, laporan bahasa awam akan otomatis muncul di sini.') }}
                    @endif
                </p>
                @if(trim($search) !== '' || $filter !== 'all')
                    <div class="mt-4">
                        <flux:button wire:click="$set('filter', 'all'); $set('search', '')" size="sm" variant="subtle">
                            {{ __('Kembali ke Semua Temuan') }}
                        </flux:button>
                    </div>
                @endif
            </div>
        @else
            {{-- Grid of Incident Cards --}}
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                @foreach($this->analyses as $item)
                    @php
                        $level = strtolower($item->threat_level ?? 'info');
                        $isFP = (bool) $item->is_false_positive;
                    @endphp
                    <div @class([
                        'flex flex-col justify-between rounded-xl border bg-white p-5 shadow-xs transition-all hover:shadow-md dark:bg-zinc-900',
                        'border-emerald-200 dark:border-emerald-800/60' => $isFP,
                        'border-rose-200 dark:border-rose-800/60' => ! $isFP && in_array($level, ['critical', 'high']),
                        'border-amber-200 dark:border-amber-800/60' => ! $isFP && $level === 'medium',
                        'border-zinc-200 dark:border-zinc-800' => ! $isFP && in_array($level, ['low', 'info']),
                    ])>
                        <div>
                            {{-- Card Header: Badges & Info --}}
                            <div class="flex items-start justify-between gap-2 pb-3 border-b border-zinc-100 dark:border-zinc-800/80">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    {{-- Status Badge --}}
                                    @if($isFP)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                            <flux:icon name="check-circle" class="size-3.5" />
                                            {{ __('Aman (Alarm Palsu)') }}
                                        </span>
                                    @elseif($level === 'critical')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-800 dark:bg-rose-950 dark:text-rose-300 animate-pulse">
                                            <flux:icon name="shield-exclamation" class="size-3.5" />
                                            {{ __('Bahaya Kritis') }}
                                        </span>
                                    @elseif($level === 'high')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-800 dark:bg-rose-950 dark:text-rose-300">
                                            <flux:icon name="exclamation-circle" class="size-3.5" />
                                            {{ __('Risiko Tinggi') }}
                                        </span>
                                    @elseif($level === 'medium')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                            <flux:icon name="exclamation-triangle" class="size-3.5" />
                                            {{ __('Risiko Sedang') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold text-blue-800 dark:bg-blue-950 dark:text-blue-300">
                                            <flux:icon name="information-circle" class="size-3.5" />
                                            {{ __('Informasi Biasa') }}
                                        </span>
                                    @endif

                                    {{-- Hypervisor / Server --}}
                                    <span class="inline-flex items-center gap-1 rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        <flux:icon name="server" class="size-3" />
                                        {{ $item->incident?->hypervisor ?: 'Server' }}
                                    </span>
                                </div>

                                {{-- Time --}}
                                <span class="text-xs text-zinc-400 shrink-0">
                                    {{ $item->incident?->timestamp?->diffForHumans() ?: $item->created_at?->diffForHumans() }}
                                </span>
                            </div>

                            {{-- Executive Summary (Bahasa Awam) --}}
                            <div class="mt-3.5">
                                <h4 class="text-sm font-semibold text-zinc-900 dark:text-white leading-snug">
                                    {{ $item->summary ?: 'Ringkasan insiden telah selesai dianalisis.' }}
                                </h4>

                                {{-- Confidence score badge --}}
                                <div class="mt-2 flex items-center gap-2">
                                    @if($item->confidence_score !== null)
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400 flex items-center gap-1">
                                            <flux:icon name="sparkles" class="size-3.5 text-blue-500" />
                                            {{ __('Tingkat Keyakinan AI:') }}
                                            <strong class="text-zinc-700 dark:text-zinc-200">{{ round($item->confidence_score * 100) }}%</strong>
                                        </span>
                                    @endif
                                </div>
                            </div>

                            {{-- Preview of Top Recommendation --}}
                            @if(!empty($item->recommendations) && is_array($item->recommendations))
                                <div class="mt-3.5 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 p-3 border border-zinc-100 dark:border-zinc-800">
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 flex items-center gap-1 mb-1">
                                        <flux:icon name="check" class="size-3.5 text-emerald-500" />
                                        {{ __('Langkah Tindakan Pertama:') }}
                                    </p>
                                    <p class="text-xs text-zinc-700 dark:text-zinc-300 line-clamp-2">
                                        {{ $item->recommendations[0] }}
                                    </p>
                                </div>
                            @endif
                        </div>

                        {{-- Card Footer --}}
                        <div class="mt-5 flex items-center justify-between pt-3 border-t border-zinc-100 dark:border-zinc-800/80">
                            <span class="text-[11px] text-zinc-400 font-mono">
                                {{ $item->incident?->incident_id ?: "#{$item->incident_id}" }}
                            </span>

                            <flux:button
                                wire:click="openDetail({{ $item->id }})"
                                size="sm"
                                variant="subtle"
                                icon-trailing="chevron-right"
                            >
                                {{ __('Lihat Solusi AI') }}
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Pagination Links --}}
            <div class="pt-4">
                {{ $this->analyses->links() }}
            </div>
        @endif
    @endif

    {{-- Detail & Action Modal (Ramah Orang Awam) --}}
    <flux:modal wire:model="showDetailModal" class="max-w-2xl space-y-6">
        @if($this->selectedAnalysis)
            @php
                $item = $this->selectedAnalysis;
                $level = strtolower($item->threat_level ?? 'info');
                $isFP = (bool) $item->is_false_positive;
            @endphp

            <div>
                {{-- Modal Header --}}
                <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <div @class([
                            'flex size-9 items-center justify-center rounded-lg',
                            'bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300' => $isFP,
                            'bg-rose-100 text-rose-600 dark:bg-rose-950 dark:text-rose-300' => ! $isFP && in_array($level, ['critical', 'high']),
                            'bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-300' => ! $isFP && $level === 'medium',
                            'bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-300' => ! $isFP && in_array($level, ['low', 'info']),
                        ])>
                            @if($isFP)
                                <flux:icon name="check-circle" class="size-5" />
                            @elseif(in_array($level, ['critical', 'high']))
                                <flux:icon name="shield-exclamation" class="size-5" />
                            @else
                                <flux:icon name="information-circle" class="size-5" />
                            @endif
                        </div>
                        <div>
                            <flux:heading size="lg">
                                {{ __('Panduan Solusi & Analisis AI') }}
                            </flux:heading>
                            <p class="text-xs text-zinc-500">
                                {{ __('Server:') }} {{ $item->incident?->hypervisor ?: 'Utama' }} • {{ $item->created_at?->translatedFormat('d F Y, H:i') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Status Banner inside Modal --}}
                <div @class([
                    'mt-4 rounded-xl p-4 border',
                    'bg-emerald-50 border-emerald-200 text-emerald-900 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-200' => $isFP,
                    'bg-rose-50 border-rose-200 text-rose-900 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-200' => ! $isFP && in_array($level, ['critical', 'high']),
                    'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-200' => ! $isFP && $level === 'medium',
                    'bg-blue-50 border-blue-200 text-blue-900 dark:bg-blue-950/40 dark:border-blue-800 dark:text-blue-200' => ! $isFP && in_array($level, ['low', 'info']),
                ])>
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-sm">
                            @if($isFP)
                                {{ __('Status: Aman (Alarm Palsu / Aktivitas Wajar)') }}
                            @elseif($level === 'critical')
                                {{ __('Status: Bahaya Kritis (Harus Segera Ditangani)') }}
                            @elseif($level === 'high')
                                {{ __('Status: Risiko Tinggi (Perlu Tindakan Cepat)') }}
                            @elseif($level === 'medium')
                                {{ __('Status: Risiko Sedang (Waspada)') }}
                            @else
                                {{ __('Status: Informasi Rendah') }}
                            @endif
                        </span>

                        @if($item->confidence_score !== null)
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-white/80 dark:bg-zinc-800/80 shadow-xs">
                                {{ __('Keyakinan AI:') }} {{ round($item->confidence_score * 100) }}%
                            </span>
                        @endif
                    </div>
                    <p class="mt-1 text-xs leading-relaxed opacity-90">
                        @if($isFP)
                            {{ __('AI menyimpulkan bahwa aktivitas ini tidak membahayakan server dan merupakan operasional wajar.') }}
                        @else
                            {{ __('Aktivitas ini berpotensi membahayakan keamanan server Anda. Harap ikuti langkah-langkah penanganan di bawah ini.') }}
                        @endif
                    </p>
                </div>

                {{-- Bagian 1: Ringkasan Situasi --}}
                <div class="mt-5 space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                        {{ __('1. Apa yang Sebenarnya Terjadi?') }}
                    </h4>
                    <div class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/60 border border-zinc-200/60 dark:border-zinc-800">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white leading-relaxed">
                            {{ $item->summary }}
                        </p>
                        @if($item->detailed_analysis && $item->detailed_analysis !== $item->summary)
                            <p class="mt-2.5 text-xs text-zinc-600 dark:text-zinc-300 leading-relaxed border-t border-zinc-200/60 dark:border-zinc-700/60 pt-2.5">
                                {{ $item->detailed_analysis }}
                            </p>
                        @endif
                    </div>
                </div>

                {{-- Bagian 2: Langkah Penanganan --}}
                <div class="mt-5 space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                        {{ __('2. Langkah Tindakan yang Disarankan AI') }}
                    </h4>

                    @if(!empty($item->recommendations) && is_array($item->recommendations))
                        <div class="space-y-2">
                            @foreach($item->recommendations as $index => $rec)
                                <div class="flex items-start gap-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                        {{ $index + 1 }}
                                    </span>
                                    <p class="text-xs sm:text-sm text-zinc-800 dark:text-zinc-200 leading-relaxed">
                                        {{ $rec }}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-zinc-500 italic">
                            {{ __('Tidak ada langkah khusus yang diperlukan untuk insiden ini.') }}
                        </p>
                    @endif
                </div>

                {{-- Bagian 3: Detail Teknis (Tersembunyi secara Default untuk Orang Awam) --}}
                <div x-data="{ openTech: false }" class="mt-5 rounded-xl border border-zinc-200 bg-zinc-50/50 p-3.5 dark:border-zinc-800 dark:bg-zinc-800/30">
                    <button
                        type="button"
                        @click="openTech = !openTech"
                        class="flex w-full items-center justify-between text-left text-xs font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200"
                    >
                        <span class="flex items-center gap-1.5 font-semibold">
                            <flux:icon name="cpu-chip" class="size-4 text-zinc-500" />
                            {{ __('Data Teknis (Untuk Administrator / Tim IT)') }}
                        </span>
                        <flux:icon name="chevron-down" class="size-3.5 transition-transform" ::class="openTech ? 'rotate-180' : ''" />
                    </button>

                    <div x-show="openTech" x-collapse x-cloak class="mt-3 space-y-2.5 pt-3 border-t border-zinc-200 dark:border-zinc-700 text-xs text-zinc-600 dark:text-zinc-300">
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <span class="text-zinc-400">{{ __('ID Insiden:') }}</span>
                                <span class="font-mono font-medium text-zinc-800 dark:text-zinc-200">{{ $item->incident?->incident_id ?: '-' }}</span>
                            </div>
                            <div>
                                <span class="text-zinc-400">{{ __('Model AI:') }}</span>
                                <span class="font-mono font-medium text-zinc-800 dark:text-zinc-200">{{ $item->model }}</span>
                            </div>
                            <div class="col-span-2">
                                <span class="text-zinc-400">{{ __('Signature Sensor:') }}</span>
                                <span class="font-mono text-zinc-800 dark:text-zinc-200 block truncate">{{ $item->incident?->signature ?: '-' }}</span>
                            </div>
                        </div>

                        @if(!empty($item->incident?->incident_json))
                            <div>
                                <span class="text-zinc-400 block mb-1">{{ __('Payload Mentah:') }}</span>
                                <pre class="max-h-36 overflow-auto rounded-lg bg-zinc-900 p-2 text-[11px] text-zinc-200 font-mono leading-tight">{{ json_encode($item->incident->incident_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Modal Footer --}}
                <div class="mt-6 flex justify-end gap-2 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                    <flux:modal.close>
                        <flux:button variant="filled" wire:click="closeDetail">
                            {{ __('Tutup Panduan') }}
                        </flux:button>
                    </flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
