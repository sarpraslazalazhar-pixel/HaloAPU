<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FormField;
use App\Models\SubUnit;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FormFieldController extends Controller
{
    /**
     * Halaman utama Peraturan Form — daftar sub unit dengan link ke builder
     */
    public function index(Request $request)
    {
        $units = Unit::with(['subUnits' => function ($q) {
            $q->where('aktif', true)->withCount('formFields');
        }])->where('aktif', true)->orderBy('nama_unit')->get();

        return Inertia::render('Admin/PeraturanForm/Index', [
            'units' => $units,
            'selectedUnitId' => $request->get('unit_id'),
        ]);
    }

    /**
     * Form Builder untuk sub unit tertentu
     */
    public function builder(SubUnit $subUnit)
    {
        $fields = $subUnit->formFields()
            ->with('childFields')
            ->whereNull('parent_field_id') // hanya root fields
            ->orderBy('urutan')
            ->get();

        // Ambil semua fields (termasuk children) untuk referensi parent
        $allFields = $subUnit->formFields()->orderBy('urutan')->get();

        return Inertia::render('Admin/PeraturanForm/Builder', [
            'subUnit' => $subUnit->load('unit'),
            'fields' => $fields,
            'allFields' => $allFields,
            'tipeFields' => FormField::TIPE_FIELDS,
            'tipeDenganOpsi' => FormField::TIPE_DENGAN_OPSI,
        ]);
    }

    /**
     * Tambah field baru
     */
    public function store(Request $request, SubUnit $subUnit)
    {
        $tipeFields = FormField::TIPE_FIELDS;

        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'tipe_field' => 'required|in:' . implode(',', $tipeFields),
            'wajib' => 'boolean',
            'opsi' => 'nullable|array',
            'opsi.*' => 'string|max:255',
            'parent_field_id' => 'nullable|exists:form_fields,id',
            'trigger_value' => 'nullable|string|max:255',
        ]);

        // Set urutan otomatis (terakhir)
        $maxUrutan = $subUnit->formFields()->max('urutan') ?? 0;
        $validated['urutan'] = $maxUrutan + 1;
        $validated['sub_unit_id'] = $subUnit->id;

        FormField::create($validated);

        return redirect()->back()->with('success', 'Field berhasil ditambahkan.');
    }

    /**
     * Update field
     */
    public function update(Request $request, FormField $formField)
    {
        $tipeFields = FormField::TIPE_FIELDS;

        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'tipe_field' => 'required|in:' . implode(',', $tipeFields),
            'wajib' => 'boolean',
            'opsi' => 'nullable|array',
            'opsi.*' => 'string|max:255',
            'parent_field_id' => 'nullable|exists:form_fields,id',
            'trigger_value' => 'nullable|string|max:255',
        ]);

        $formField->update($validated);

        return redirect()->back()->with('success', 'Field berhasil diperbarui.');
    }

    /**
     * Hapus field (cascade: children juga terhapus via FK)
     */
    public function destroy(FormField $formField)
    {
        $formField->delete();

        return redirect()->back()->with('success', 'Field berhasil dihapus.');
    }

    /**
     * Reorder fields via drag-and-drop
     */
    public function reorder(Request $request, SubUnit $subUnit)
    {
        $validated = $request->validate([
            'order' => 'required|array',
            'order.*.id' => 'required|exists:form_fields,id',
            'order.*.urutan' => 'required|integer|min:0',
        ]);

        foreach ($validated['order'] as $item) {
            FormField::where('id', $item['id'])
                ->where('sub_unit_id', $subUnit->id)
                ->update(['urutan' => $item['urutan']]);
        }

        return redirect()->back()->with('success', 'Urutan field berhasil diperbarui.');
    }
}
