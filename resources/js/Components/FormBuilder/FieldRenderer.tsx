import React from 'react';
import { FormField } from '@/types';
import { CheckCircle2 } from 'lucide-react';

interface FieldRendererProps {
    field: FormField;
    value: any;
    onChange: (fieldId: number, value: any) => void;
    errors?: Record<string, string>;
}

export default function FieldRenderer({ field, value, onChange, errors }: FieldRendererProps) {
    const error = errors?.[`form_data.${field.id}`];
    const errorClass = error ? 'border-red-500' : 'border-gray-300';

    const renderInput = () => {
        switch (field.tipe_field) {
            case 'teks_pendek':
                return (
                    <input
                        type="text"
                        className={`w-full border rounded-md p-2 ${errorClass}`}
                        value={value || ''}
                        onChange={e => onChange(field.id, e.target.value)}
                        required={field.wajib}
                    />
                );
            case 'teks_panjang':
                return (
                    <textarea
                        className={`w-full border rounded-md p-2 ${errorClass}`}
                        rows={4}
                        value={value || ''}
                        onChange={e => onChange(field.id, e.target.value)}
                        required={field.wajib}
                    />
                );
            case 'angka':
                return (
                    <input
                        type="number"
                        className={`w-full border rounded-md p-2 ${errorClass}`}
                        value={value || ''}
                        onChange={e => onChange(field.id, e.target.value)}
                        required={field.wajib}
                    />
                );
            case 'tanggal':
                return (
                    <input
                        type="date"
                        className={`w-full border rounded-md p-2 ${errorClass}`}
                        value={value || ''}
                        onChange={e => onChange(field.id, e.target.value)}
                        required={field.wajib}
                    />
                );
            case 'waktu':
                return (
                    <input
                        type="time"
                        className={`w-full border rounded-md p-2 ${errorClass}`}
                        value={value || ''}
                        onChange={e => onChange(field.id, e.target.value)}
                        required={field.wajib}
                    />
                );
            case 'datetime':
                return (
                    <input
                        type="datetime-local"
                        className={`w-full border rounded-md p-2 ${errorClass}`}
                        value={value || ''}
                        onChange={e => onChange(field.id, e.target.value)}
                        required={field.wajib}
                    />
                );
            case 'dropdown':
                return (
                    <select
                        className={`w-full border rounded-md p-2 ${errorClass}`}
                        value={value || ''}
                        onChange={e => onChange(field.id, e.target.value)}
                        required={field.wajib}
                    >
                        <option value="">Pilih...</option>
                        {(field.opsi || []).map((opt, i) => (
                            <option key={i} value={opt}>{opt}</option>
                        ))}
                    </select>
                );
            case 'radio':
                return (
                    <div className="flex flex-wrap justify-center gap-3">
                        {(field.opsi || []).map((opt, i) => {
                            const isSelected = value === opt;
                            return (
                                <button
                                    key={i}
                                    type="button"
                                    onClick={() => onChange(field.id, opt)}
                                    className={`flex-1 basis-[150px] max-w-[220px] p-3 border rounded-lg text-center flex flex-col items-center justify-center gap-1 transition-all font-medium text-sm
                                        ${isSelected ? 'bg-blue-600 border-blue-600 text-white shadow-md' : 
                                        'bg-white border-blue-600 text-blue-600 hover:bg-blue-50 hover:shadow-sm'}
                                    `}
                                >
                                    {isSelected && <CheckCircle2 className="w-5 h-5" />}
                                    <span>{opt}</span>
                                </button>
                            );
                        })}
                    </div>
                );
            case 'checkbox':
                return (
                    <input
                        type="checkbox"
                        className="rounded"
                        checked={!!value}
                        onChange={e => onChange(field.id, e.target.checked)}
                    />
                );
            case 'multi_pilih':
                return (
                    <div className="flex flex-wrap justify-center gap-3">
                        {(field.opsi || []).map((opt, i) => {
                            const current = value || [];
                            const isSelected = current.includes(opt);
                            return (
                                <button
                                    key={i}
                                    type="button"
                                    onClick={() => {
                                        const newValue = isSelected
                                            ? current.filter((v: string) => v !== opt)
                                            : [...current, opt];
                                        onChange(field.id, newValue);
                                    }}
                                    className={`flex-1 basis-[150px] max-w-[220px] p-3 border rounded-lg text-center flex flex-col items-center justify-center gap-1 transition-all font-medium text-sm
                                        ${isSelected ? 'bg-blue-600 border-blue-600 text-white shadow-md' : 
                                        'bg-white border-blue-600 text-blue-600 hover:bg-blue-50 hover:shadow-sm'}
                                    `}
                                >
                                    {isSelected && <CheckCircle2 className="w-5 h-5" />}
                                    <span>{opt}</span>
                                </button>
                            );
                        })}
                    </div>
                );

            case 'nominal_rp': {
                const displayValue = value ? new Intl.NumberFormat('id-ID').format(Number(value)) : '';
                return (
                    <div className="relative">
                        <span className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">Rp</span>
                        <input
                            type="text"
                            className={`w-full border rounded-md p-2 pl-10 ${errorClass}`}
                            value={displayValue}
                            onChange={e => {
                                const raw = e.target.value.replace(/\D/g, '');
                                onChange(field.id, raw);
                            }}
                            required={field.wajib}
                        />
                    </div>
                );
            }
            case 'upload_gambar':
            case 'upload_file':
                return (
                    <input
                        type="file"
                        className={`w-full ${errorClass}`}
                        accept={field.tipe_field === 'upload_gambar' ? 'image/*' : undefined}
                        onChange={e => onChange(field.id, e.target.files?.[0] || null)}
                        required={field.wajib}
                    />
                );
            default:
                return (
                    <input
                        type="text"
                        className={`w-full border rounded-md p-2 ${errorClass}`}
                        value={value || ''}
                        onChange={e => onChange(field.id, e.target.value)}
                    />
                );
        }
    };

    if (field.tipe_field === 'info_peraturan') {
        let title = "Informasi Peraturan";
        let content = field.label;
        
        if (field.label.includes(':')) {
            const parts = field.label.split(':');
            title = parts[0].trim();
            content = parts.slice(1).join(':').trim();
        }

        const sentences = content.split(/\.\s+|\n/).filter(s => s.trim().length > 0);

        return (
            <div className="bg-[#fffbeb] border border-[#fde68a] rounded-lg p-4 text-sm text-[#92400e] shadow-sm">
                <div className="flex items-center gap-2 mb-2 font-bold text-[#b45309] text-base">
                    <span className="text-lg">⚠️</span>
                    <span>{title}</span>
                </div>
                {sentences.length > 1 ? (
                    <ol className="list-decimal list-outside ml-5 space-y-1 text-[#92400e]">
                        {sentences.map((s, i) => (
                            <li key={i}>{s.trim()}{s.trim().endsWith('.') ? '' : '.'}</li>
                        ))}
                    </ol>
                ) : (
                    <p className="text-[#92400e]">{content}</p>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-1">
            <label className="block text-sm font-medium text-gray-700">
                {field.label}
                {field.wajib && <span className="text-red-500 ml-1">*</span>}
            </label>
            {renderInput()}
            {error && <p className="text-red-500 text-xs">{error}</p>}
        </div>
    );
}
