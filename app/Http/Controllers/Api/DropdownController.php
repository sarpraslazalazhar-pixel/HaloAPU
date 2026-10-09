<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrgUnit;
use App\Models\SubUnit;
use App\Models\FormField;
use Illuminate\Support\Facades\Cache;

class DropdownController extends Controller
{
    public function orgUnits($divisiId)
    {
        return response()->json(OrgUnit::where('divisi_id', $divisiId)->orderBy('nama_unit_organisasi')->get()->toArray())->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    public function subUnits($unitId)
    {
        return response()->json(SubUnit::where('unit_id', $unitId)->aktif()->orderBy('nama_layanan')->get()->toArray())->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    public function formFields($subUnitId)
    {
        return response()->json(FormField::where('sub_unit_id', $subUnitId)->orderBy('urutan')->get()->toArray())->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }
}
