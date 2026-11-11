<?php

use App\Models\Incident;
use App\Services\Ai\IncidentAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::view('incidents', 'incidents.index')->name('incidents.index');

    Route::get('/analysis', function (Request $request, IncidentAnalysisService $service) {
        $id = $request->query('id');
        $incident = $id ? Incident::find($id) : Incident::latest()->first();

        if (! $incident) {
            return response()->json(['message' => 'No incidents available to analyze.'], 404);
        }

        $analysis = $service->analyze($incident);

        return response()->json($analysis);
    });
});

require __DIR__.'/settings.php';
