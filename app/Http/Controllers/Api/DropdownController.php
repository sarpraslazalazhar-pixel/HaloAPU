<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrgUnit;
use App\Models\SubUnit;
use App\Models\FormField;

class DropdownController extends Controller
{
    public function orgUnits($divisiId)
    {
        return OrgUnit::where('divisi_id', $divisiId)->orderBy('nama_unit_organisasi')->get();
    }

    public function subUnits($unitId)
    {
        return SubUnit::where('unit_id', $unitId)->where('aktif', true)->orderBy('nama_layanan')->get();
    }

    public function formFields($subUnitId)
    {
        return FormField::where('sub_unit_id', $subUnitId)->orderBy('urutan')->get();
    }
}
