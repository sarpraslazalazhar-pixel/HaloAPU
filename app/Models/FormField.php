<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormField extends Model
{
    const TIPE_FIELDS = [
        'teks_pendek', 'teks_panjang', 'angka', 'tanggal', 'waktu', 'datetime',
        'dropdown', 'radio', 'checkbox', 'multi_pilih', 'upload_gambar',
        'upload_file', 'nominal_rp', 'info_peraturan'
    ];

    const TIPE_DENGAN_OPSI = ['dropdown', 'radio', 'multi_pilih'];

    protected $fillable = [
        'sub_unit_id', 'label', 'tipe_field', 'wajib', 'opsi',
        'parent_field_id', 'trigger_value', 'urutan'
    ];

    protected $casts = [
        'opsi' => 'array',
        'wajib' => 'boolean',
    ];

    public function subUnit()
    {
        return $this->belongsTo(SubUnit::class);
    }

    public function parentField()
    {
        return $this->belongsTo(FormField::class, 'parent_field_id');
    }

    public function childFields()
    {
        return $this->hasMany(FormField::class, 'parent_field_id')->orderBy('urutan');
    }

    public function isUpload(): bool
    {
        return in_array($this->tipe_field, ['upload_gambar', 'upload_file']);
    }
}
