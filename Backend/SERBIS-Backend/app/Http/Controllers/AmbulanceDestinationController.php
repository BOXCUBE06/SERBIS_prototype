<?php

namespace App\Http\Controllers;

use App\Models\AmbulanceDestination;

class AmbulanceDestinationController extends Controller
{
    /**
     * The ambulance form's destination dropdown (MDRRMO feedback,
     * 2026-09-19). Read-only on purpose — see the seeder for why the list
     * starts at exactly one real entry, and docs/ambulance-destinations-audit.md
     * for the count this was seeded from.
     */
    public function index()
    {
        return response()->json(['data' => AmbulanceDestination::orderBy('name')->pluck('name')]);
    }
}
