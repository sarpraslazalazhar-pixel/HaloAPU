import React, { useEffect } from 'react';
import { FormField } from '@/types';

interface ConditionalFieldProps {
    field: FormField;
    value: any;
    onChange: (fieldId: number, value: any) => void;
    errors?: Record<string, string>;
}

export default function ConditionalField({ field, value, onChange, errors }: ConditionalFieldProps) {
    const error = errors?.[`form_data.${field.id}`];
    const errorClass = error ? 'border-red-500' : 'border-gray-300';

    return (
        <div className="ml-6 pl-4 border-l-2 border-orange-300 space-y-1">
            <label className="block text-sm font-medium text-gray-700">
                {field.label}
                {field.wajib && <span className="text-red-500 ml-1">*</span>}
            </label>
            {field.tipe_field === 'teks_pendek' && (
                <input type="text" className={`w-full border rounded-md p-2 ${errorClass}`} value={value || ''}
                    onChange={e => onChange(field.id, e.target.value)} required={field.wajib} />
            )}
            {field.tipe_field === 'teks_panjang' && (
                <textarea className={`w-full border rounded-md p-2 ${errorClass}`} rows={3} value={value || ''}
                    onChange={e => onChange(field.id, e.target.value)} required={field.wajib} />
            )}
            {field.tipe_field === 'dropdown' && (
                <select className={`w-full border rounded-md p-2 ${errorClass}`} value={value || ''}
                    onChange={e => onChange(field.id, e.target.value)} required={field.wajib}>
                    <option value="">Pilih...</option>
                    {(field.opsi || []).map((opt, i) => <option key={i} value={opt}>{opt}</option>)}
                </select>
            )}
            {field.tipe_field === 'upload_gambar' || field.tipe_field === 'upload_file' ? (
                <input type="file" className={`w-full ${errorClass}`}
                    onChange={e => onChange(field.id, e.target.files?.[0] || null)} required={field.wajib} />
            ) : null}
            {error && <p className="text-red-500 text-xs">{error}</p>}
        </div>
    );
}
