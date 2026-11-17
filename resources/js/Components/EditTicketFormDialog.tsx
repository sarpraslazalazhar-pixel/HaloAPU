import React from 'react';
import { useForm } from '@inertiajs/react';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import DynamicField from '@/Components/DynamicForm/DynamicField';
import { FormField } from '@/types';
import type { DynamicFieldValue } from '@/Components/DynamicForm/DynamicField';
import { Save } from 'lucide-react';

const PRIORITIES = ['Rendah', 'Sedang', 'Tinggi', 'Urgen'];
const NON_EDITABLE_TYPES = ['upload_gambar', 'upload_file', 'info_peraturan'];

interface EditTicketFormDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  ticket: { id: number; priority?: string | null; form_data?: Record<string, unknown> | null };
  formFields: FormField[];
}

export default function EditTicketFormDialog({ open, onOpenChange, ticket, formFields }: EditTicketFormDialogProps) {
  const editableFields = formFields.filter((f) => !NON_EDITABLE_TYPES.includes(f.tipe_field));

  const { data, setData, patch, processing, errors, reset, clearErrors } = useForm({
    form_data: (ticket.form_data ?? {}) as Record<string, DynamicFieldValue>,
    priority: ticket.priority || '',
    edit_reason: '',
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    patch(route('admin.tiket.form', ticket.id), {
      preserveScroll: true,
      onSuccess: () => {
        onOpenChange(false);
        reset();
      },
    });
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) clearErrors();
        onOpenChange(next);
      }}
    >
      <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Edit Formulir Tiket</DialogTitle>
        </DialogHeader>

        <form onSubmit={submit} className="space-y-4 pt-2">
          <DynamicField
            fields={editableFields}
            values={data.form_data}
            onChange={(fieldId, value) => setData('form_data', { ...data.form_data, [String(fieldId)]: value })}
            errors={errors as Record<string, string>}
          />

          <div className="space-y-2">
            <label className="text-sm font-medium">Prioritas</label>
            <select
              className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
              value={data.priority}
              onChange={(e) => setData('priority', e.target.value)}
            >
              <option value="">Tidak diubah</option>
              {PRIORITIES.map((p) => (
                <option key={p} value={p}>{p}</option>
              ))}
            </select>
            {errors.priority && <p className="text-sm text-red-500">{errors.priority}</p>}
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium">
              Alasan Perubahan <span className="text-red-500">*</span>
            </label>
            <textarea
              className="min-h-[90px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
              value={data.edit_reason}
              onChange={(e) => setData('edit_reason', e.target.value)}
              placeholder="Contoh: Melengkapi detail lokasi karena informasi awal kurang spesifik."
              required
              minLength={5}
              maxLength={500}
            />
            {errors.edit_reason && <p className="text-sm text-red-500">{errors.edit_reason}</p>}
          </div>

          <div className="flex justify-end gap-2 border-t pt-3">
            <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>Batal</Button>
            <Button type="submit" disabled={processing}>
              <Save className="mr-1 h-4 w-4" /> {processing ? 'Menyimpan...' : 'Simpan Perubahan'}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
