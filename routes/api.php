<?php

use App\Http\Controllers\IncidentsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/incidents', [IncidentsController::class, 'store']);

    Route::get('/incidents', [IncidentsController::class, 'index']);
});
