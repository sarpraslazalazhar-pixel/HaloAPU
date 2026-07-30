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
        return Cache::remember("org_units_{$divisiId}", 300, function () use ($divisiId) {
            return OrgUnit::where('divisi_id', $divisiId)->orderBy('nama_unit_organisasi')->get();
        });
    }

    public function subUnits($unitId)
    {
        return Cache::remember("sub_units_{$unitId}", 300, function () use ($unitId) {
            return SubUnit::where('unit_id', $unitId)->where('aktif', true)->orderBy('nama_layanan')->get();
        });
    }

    public function formFields($subUnitId)
    {
        return Cache::remember("form_fields_{$subUnitId}", 300, function () use ($subUnitId) {
            return FormField::where('sub_unit_id', $subUnitId)->orderBy('urutan')->get();
        });
    }
}
