import React, { useEffect } from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormField } from '@/types';

interface FieldConfigDialogProps {
 open: boolean;
 onOpenChange: (open: boolean) => void;
 subUnitId: number;
 editField?: FormField | null;
 tipeFields: string[];
 tipeDenganOpsi: string[];
 allFields: FormField[];
}

export default function FieldConfigDialog({
 open, onOpenChange, subUnitId, editField, tipeFields, tipeDenganOpsi, allFields
}: FieldConfigDialogProps) {
 const { data, setData, post, put, reset, processing, errors } = useForm({
 label: '',
 tipe_field: 'teks_pendek',
 wajib: false,
 opsi: [] as string[],
 parent_field_id: '' as string | number | null,
 trigger_value: '',
 opsiString: '',
 });

 useEffect(() => {
 if (editField) {
 setData({
 label: editField.label,
 tipe_field: editField.tipe_field,
 wajib: editField.wajib,
 opsi: editField.opsi || [],
 parent_field_id: editField.parent_field_id || '',
 trigger_value: editField.trigger_value || '',
 opsiString: (editField.opsi || []).join('\n'),
 });
 } else {
 reset();
 setData('tipe_field', 'teks_pendek');
 }
 }, [editField, open]);

 const handleSubmit = (e: React.FormEvent) => {
 e.preventDefault();
 const payload = { ...data };
 if (tipeDenganOpsi.includes(payload.tipe_field) && payload.opsiString) {
 payload.opsi = payload.opsiString.split('\n').map(s => s.trim()).filter(s => s !== '');
 } else {
 payload.opsi = [];
 }
 payload.parent_field_id = payload.parent_field_id ? Number(payload.parent_field_id) : null;

 if (editField) {
 put(route('admin.peraturan-form.update', editField.id), {
 onSuccess: () => onOpenChange(false),
 preserveScroll: true,
 });
 } else {
 post(route('admin.peraturan-form.store', subUnitId), {
 onSuccess: () => onOpenChange(false),
 preserveScroll: true,
 });
 }
 };

 return (
 <Dialog open={open} onOpenChange={onOpenChange}>
 <DialogContent className="sm:max-w-md">
 <DialogHeader>
 <DialogTitle>{editField ? 'Edit Field' : 'Tambah Field Baru'}</DialogTitle>
 </DialogHeader>
 <form onSubmit={handleSubmit} className="space-y-4">
 <div>
 <Label>Label / Pertanyaan</Label>
 <Input value={data.label} onChange={e => setData('label', e.target.value)} required />
 {errors.label && <p className="text-red-500 text-xs mt-1">{errors.label}</p>}
 </div>
 <div>
 <Label>Tipe Field</Label>
 <select className="w-full border rounded-md p-2" value={data.tipe_field} onChange={e => setData('tipe_field', e.target.value)}>
 {tipeFields.map(t => <option key={t} value={t}>{t.replace('_', ' ').toUpperCase()}</option>)}
 </select>
 </div>
 <label className="flex items-center gap-2 cursor-pointer">
 <input type="checkbox" checked={data.wajib} onChange={e => setData('wajib', e.target.checked)} className="rounded" />
 <span className="text-sm font-medium">Wajib Diisi</span>
 </label>
 {tipeDenganOpsi.includes(data.tipe_field) && (
 <div>
 <Label>Opsi (satu per baris)</Label>
 <textarea className="w-full border rounded-md p-2" rows={4} value={data.opsiString}
 onChange={e => setData('opsiString', e.target.value)} placeholder="Opsi 1&#10;Opsi 2" />
 </div>
 )}
 <div className="border-t pt-4">
 <h4 className="text-sm font-semibold mb-2">Logika Kondisional</h4>
 <div>
 <Label className="text-xs text-muted-foreground">Tampil jika parent:</Label>
 <select className="w-full border rounded-md p-2 text-sm" value={data.parent_field_id || ''}
 onChange={e => setData('parent_field_id', e.target.value)}>
 <option value="">-- Selalu Tampil --</option>
 {allFields.filter(f => tipeDenganOpsi.includes(f.tipe_field) && f.id !== editField?.id).map(f => (
 <option key={f.id} value={f.id}>{f.label}</option>
 ))}
 </select>
 </div>
 {data.parent_field_id && (
 <div className="mt-2">
 <Label className="text-xs text-muted-foreground">Nilai pemicu:</Label>
 <Input value={data.trigger_value} onChange={e => setData('trigger_value', e.target.value)} placeholder="Contoh: Ya" />
 </div>
 )}
 </div>
 <div className="flex gap-2 justify-end pt-2">
 <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Batal</Button>
 <Button type="submit" disabled={processing}>{processing ? 'Menyimpan...' : 'Simpan'}</Button>
 </div>
 </form>
 </DialogContent>
 </Dialog>
 );
}
