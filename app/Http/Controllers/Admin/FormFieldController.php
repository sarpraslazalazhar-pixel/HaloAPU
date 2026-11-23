<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FormField;
use App\Models\SubUnit;
use App\Models\Unit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FormFieldController extends Controller
{
    private const SLA_FLAG_CONFLICT = 'Sudah ada field deadline SLA di layanan ini. Nonaktifkan field tersebut terlebih dahulu.';

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
            'extends_resolution_sla' => 'nullable|boolean',
        ]);

        // Set urutan otomatis (terakhir)
        $maxUrutan = $subUnit->formFields()->max('urutan') ?? 0;
        $validated['urutan'] = $maxUrutan + 1;
        $validated['sub_unit_id'] = $subUnit->id;
        $validated = $this->normalizeSlaFlag($request, $validated, $subUnit->id);

        $this->saveWithSlaGuard(fn () => FormField::create($validated));

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
            'extends_resolution_sla' => 'nullable|boolean',
        ]);

        $validated = $this->normalizeSlaFlag($request, $validated, $formField->sub_unit_id, $formField);

        $this->saveWithSlaGuard(fn () => $formField->update($validated));

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

        \Illuminate\Support\Facades\Cache::forget("form_fields_{$subUnit->id}");

        return redirect()->back()->with('success', 'Urutan field berhasil diperbarui.');
    }

    /**
     * Normalisasi penanda perpanjangan SLA: true untuk field terpilih, NULL untuk lainnya.
     */
    private function normalizeSlaFlag(Request $request, array $validated, int $subUnitId, ?FormField $existing = null): array
    {
        $isDate = ($validated['tipe_field'] ?? null) === 'tanggal';

        if ($request->has('extends_resolution_sla')) {
            $flag = $request->boolean('extends_resolution_sla');
        } else {
            // Tidak dikirim: pertahankan pilihan lama (hanya bila tetap bertipe tanggal)
            $flag = (bool) ($existing?->extends_resolution_sla);
        }

        if ($flag && ! $isDate) {
            if ($request->boolean('extends_resolution_sla')) {
                throw ValidationException::withMessages([
                    'extends_resolution_sla' => 'Hanya field bertipe tanggal yang dapat memperpanjang SLA penyelesaian.',
                ]);
            }
            $flag = false; // tipe diganti dari tanggal: kosongkan
        }

        if ($flag) {
            $conflict = FormField::where('sub_unit_id', $subUnitId)
                ->where('extends_resolution_sla', true)
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages(['extends_resolution_sla' => self::SLA_FLAG_CONFLICT]);
            }
        }

        $validated['extends_resolution_sla'] = $flag ? true : null;

        return $validated;
    }

    /**
     * Terjemahkan bentrok indeks unique (simpan bersamaan) menjadi error validasi.
     */
    private function saveWithSlaGuard(callable $save): void
    {
        try {
            $save();
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['extends_resolution_sla' => self::SLA_FLAG_CONFLICT]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'form_fields_subunit_extends_sla_unique')
                || str_contains(strtolower($e->getMessage()), 'unique')) {
                throw ValidationException::withMessages(['extends_resolution_sla' => self::SLA_FLAG_CONFLICT]);
            }
            throw $e;
        }
    }
}
