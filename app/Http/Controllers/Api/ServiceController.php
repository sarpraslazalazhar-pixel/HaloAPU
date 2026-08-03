<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\SubUnit;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    /**
     * Get all active Units along with their active SubUnits.
     */
    public function index()
    {
        $units = Unit::with(['subUnits' => function ($query) {
            $query->where('aktif', true);
        }])->where('aktif', true)->get();

        return response()->json([
            'data' => $units
        ]);
    }

    /**
     * Get form fields for a specific SubUnit.
     */
    public function fields($subUnitId)
    {
        $subUnit = SubUnit::with(['formFields' => function ($query) {
            $query->orderBy('urutan');
        }])->findOrFail($subUnitId);

        return response()->json([
            'data' => $subUnit->formFields
        ]);
    }
}
