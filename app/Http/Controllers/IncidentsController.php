<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use Illuminate\Http\Request;

class IncidentsController extends Controller
{
    function store(Request $request) {
        $json = $request->json()->all();

        $incident = Incident::create($request->all());

        $incident['incident_json'] = $json;
        $incident->save();

        return response()->json(['message' => 'success'], 200);
    }

    function index() {
        $incidents = Incident::all();

        $cleanIncidents = $incidents->map(function ($item) {
            // If the item is an array:
            if (is_array($item)) {
                $item['incident_json'] = json_decode($item['incident_json']);
            }
            // If the item is an object:
            else if (is_object($item)) {
                $item->incident_json = json_decode($item->incident_json);
            }
            return $item;
        });

        return response()->json([
            'message' => "success",
            'data' => $cleanIncidents
        ], 200);
    }
}
