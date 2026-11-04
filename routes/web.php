<?php

use App\Models\Incident;
use App\Services\Ai\IncidentAnalysisService;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::get('/analysis', function () {
        $incident = Incident::find(4);
        $service = app(IncidentAnalysisService::class);

        $analysis = $service->analyze($incident);

        return response()->json($analysis);
    });
});

require __DIR__.'/settings.php';
