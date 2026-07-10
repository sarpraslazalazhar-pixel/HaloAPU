import React, { useState, useEffect } from 'react';
import { Head, useForm } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import { Stepper } from '@/Components/Stepper';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Input } from '@/Components/ui/input';
import { useDependentDropdown } from '@/hooks/useDependentDropdown';
import DynamicField from '@/Components/DynamicForm/DynamicField';
import axios from 'axios';
import { FormField as FormFieldType } from '@/types';
import { Trash2, FileText, FileImage, CheckCircle2, AlertCircle, Info, Upload, Building2, HeartHandshake, Coins, Building } from 'lucide-react';
import Swal from 'sweetalert2';

const getIconForDivisi = (nama: string) => {
    const lower = nama.toLowerCase();
    if (lower.includes('sekretariat')) return <Building2 className="w-6 h-6 mb-2" />;
    if (lower.includes('laz')) return <HeartHandshake className="w-6 h-6 mb-2" />;
    if (lower.includes('keuangan')) return <Coins className="w-6 h-6 mb-2" />;
    return <Building className="w-6 h-6 mb-2" />;
};

const RadioCardGrid = ({ options, value, onChange, labelKey, valueKey = 'id', disabled = false, showIcon = false }: any) => {
    if (!options || options.length === 0) {
        return <p className="text-sm text-gray-500 italic p-3 bg-slate-50 border rounded-lg">Tidak ada pilihan tersedia.</p>;
    }
    
    return (
        <div className="flex flex-wrap justify-center gap-3">
            {options.map((opt: any) => {
                const optValue = String(opt[valueKey]);
                const isSelected = value === optValue;
                
                return (
                    <button
                        key={optValue}
                        type="button"
                        disabled={disabled}
                        onClick={() => onChange(optValue)}
                        className={`flex-1 basis-[150px] max-w-[220px] p-3 border rounded-lg text-center flex flex-col items-center justify-center transition-all min-h-[4rem] font-medium text-sm
                            ${disabled ? 'opacity-50 cursor-not-allowed bg-gray-50 border-gray-200 text-gray-400' :
                            isSelected ? 'bg-blue-600 border-blue-600 text-white shadow-md' : 
                            'bg-white border-blue-600 text-blue-600 hover:bg-blue-50 hover:shadow-sm'}
                        `}
                    >
                        {showIcon && getIconForDivisi(opt[labelKey])}
                        <span>{opt[labelKey]}</span>
                    </button>
                );
            })}
        </div>
    );
};

const STEPS = [
    { label: 'Data Pengaju', description: 'Divisi, unit organisasi, jabatan' },
    { label: 'Pilih Layanan', description: 'Unit tujuan dan sub unit' },
    { label: 'Isi Form', description: 'Formulir layanan' },
    { label: 'Lampiran', description: 'Upload dokumen pendukung' },
    { label: 'Review & Kirim', description: 'Periksa dan kirim tiket' },
];

interface WizardProps {
    divisiList: any[];
    jabatanList: any[];
    unitList: any[];
}

export default function Wizard({ divisiList, jabatanList, unitList }: WizardProps) {
    const [activeStep, setActiveStep] = useState(0);
    const [formFields, setFormFields] = useState<FormFieldType[]>([]);
    const [fieldsLoading, setFieldsLoading] = useState(false);
    const [canSubmit, setCanSubmit] = useState(false);

    const { data, setData, post, transform, processing, errors, setError, clearErrors } = useForm({
        divisi_id: '',
        org_unit_id: '',
        jabatan_id: '',
        unit_id: '',
        sub_unit_id: '',
        form_data: {} as Record<string, any>,
        attachments: {} as Record<string, File>,
    });

    const orgUnits = useDependentDropdown(route('api.org-units', { divisiId: '{id}' }));
    const subUnits = useDependentDropdown(route('api.sub-units', { unitId: '{id}' }));

    useEffect(() => {
        if (data.divisi_id) {
            orgUnits.load(data.divisi_id);
        }
    }, [data.divisi_id]);

    useEffect(() => {
        if (data.unit_id) {
            subUnits.load(data.unit_id);
        }
    }, [data.unit_id]);

    useEffect(() => {
        if (data.sub_unit_id) {
            setFieldsLoading(true);
            axios.get(route('api.form-fields', { subUnitId: data.sub_unit_id }))
                .then(res => {
                    setFormFields(res.data);
                    setData('form_data', {});
                    setData('attachments', {});
                })
                .catch(() => setFormFields([]))
                .finally(() => setFieldsLoading(false));
        } else {
            setFormFields([]);
        }
    }, [data.sub_unit_id]);

    useEffect(() => {
        if (activeStep === STEPS.length - 1) {
            setCanSubmit(false);
            const timer = setTimeout(() => setCanSubmit(true), 1000);
            return () => clearTimeout(timer);
        }
    }, [activeStep]);

    const uploadFields = formFields.filter(f => f.tipe_field === 'upload_gambar' || f.tipe_field === 'upload_file');
    const nonUploadFields = formFields.filter(f => f.tipe_field !== 'upload_gambar' && f.tipe_field !== 'upload_file');

    const handleFieldChange = (fieldId: number, value: any) => {
        setData(data => ({
            ...data,
            form_data: {
                ...data.form_data,
                [fieldId]: value
            }
        }));
    };

    const handleFileChange = (fieldId: number, file: File | null) => {
        const newAttachments = { ...data.attachments };
        if (file) {
            newAttachments[String(fieldId)] = file;
        } else {
            delete newAttachments[String(fieldId)];
        }
        setData('attachments', newAttachments);
    };

    const validateStep = (step: number) => {
        clearErrors();
        let isValid = true;
        
        if (step === 0) {
            if (!data.divisi_id) { setError('divisi_id', 'Divisi wajib dipilih'); isValid = false; }
            if (!data.org_unit_id) { setError('org_unit_id', 'Unit Organisasi wajib dipilih'); isValid = false; }
            if (!data.jabatan_id) { setError('jabatan_id', 'Jabatan wajib dipilih'); isValid = false; }
        } else if (step === 1) {
            if (!data.unit_id) { setError('unit_id', 'Unit Tujuan wajib dipilih'); isValid = false; }
            if (!data.sub_unit_id) { setError('sub_unit_id', 'Sub Unit (Layanan) wajib dipilih'); isValid = false; }
        } else if (step === 2) {
            nonUploadFields.forEach(field => {
                const isVisible = !field.parent_field_id || data.form_data[field.parent_field_id] === field.trigger_value;
                if (isVisible && field.wajib && field.tipe_field !== 'info_peraturan') {
                    const val = data.form_data[field.id];
                    if (val === undefined || val === null || val === '' || (Array.isArray(val) && val.length === 0)) {
                        setError(`form_data.${field.id}`, `${field.label} wajib diisi`);
                        isValid = false;
                    }
                }
            });
        } else if (step === 3) {
            uploadFields.forEach(field => {
                const isVisible = !field.parent_field_id || data.form_data[field.parent_field_id] === field.trigger_value;
                if (isVisible && field.wajib) {
                    const file = data.attachments[String(field.id)];
                    if (!file) {
                        setError(`attachments.${field.id}`, `${field.label} wajib diupload`);
                        isValid = false;
                    }
                }
            });
        }
        
        return isValid;
    };

    const nextStep = () => {
        if (fieldsLoading) return;
        if (validateStep(activeStep)) {
            setActiveStep(prev => Math.min(prev + 1, STEPS.length - 1));
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Data Belum Lengkap',
                text: 'Harap lengkapi semua isian yang wajib sebelum melanjutkan.',
                confirmButtonColor: '#3b82f6'
            });
        }
    };
    
    const prevStep = () => {
        clearErrors();
        setActiveStep(prev => Math.max(prev - 1, 0));
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (activeStep !== STEPS.length - 1) return;
        transform((data) => ({
            ...data,
            form_data: JSON.stringify(data.form_data),
        }));
        post(route('tiket.store'));
    };

    const getSelectedName = (list: any[], id: string | number) => {
        const item = list.find((i: any) => String(i.id) === String(id));
        return item ? (item.nama_unit || item.nama_divisi || item.nama_jabatan || item.nama_unit_organisasi || item.nama_layanan || '-') : '-';
    };

    const renderFilePreview = (fieldId: number, field: FormFieldType) => {
        const file = data.attachments[String(fieldId)];
        const isImage = field.tipe_field === 'upload_gambar';

        if (!file) {
            return (
                <div className="mt-2">
                    <div className="relative">
                        <Input
                            type="file"
                            accept={isImage ? 'image/*' : undefined}
                            onChange={e => handleFileChange(fieldId, e.target.files?.[0] || null)}
                            className="file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                        />
                    </div>
                    {errors[`attachments.${fieldId}`] && (
                        <p className="text-red-500 text-xs mt-1">{errors[`attachments.${fieldId}`]}</p>
                    )}
                </div>
            );
        }

        return (
            <div className="mt-2">
                <div className="flex items-center gap-3 p-3 bg-gray-50 rounded-lg border">
                    {isImage ? (
                        <img src={URL.createObjectURL(file)} alt={file.name} className="w-16 h-16 object-cover rounded" />
                    ) : (
                        <FileText className="w-10 h-10 text-gray-400" />
                    )}
                    <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium truncate">{file.name}</p>
                        <p className="text-xs text-gray-500">{(file.size / 1024).toFixed(1)} KB</p>
                    </div>
                    <Button type="button" variant="ghost" size="icon" onClick={() => handleFileChange(fieldId, null)}>
                        <Trash2 className="w-4 h-4 text-red-500" />
                    </Button>
                </div>
            </div>
        );
    };

    return (
        <UserLayout title="Buat Tiket Baru">
            <div className="max-w-4xl mx-auto py-8 px-4">
                <Head title="Buat Tiket Baru" />
                <h1 className="text-2xl font-bold mb-6">Buat Tiket Baru</h1>

            <Stepper steps={STEPS} activeStep={activeStep} className="mb-8" />

            <Card>
                <CardHeader>
                    <CardTitle>{STEPS[activeStep].label}</CardTitle>
                    <CardDescription>{STEPS[activeStep].description}</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={handleSubmit}>
                        {activeStep === 0 && (
                            <div className="space-y-6">
                                <div>
                                    <Label className="mb-3 block text-base font-semibold">Divisi <span className="text-red-500">*</span></Label>
                                    <RadioCardGrid
                                        options={divisiList}
                                        value={data.divisi_id}
                                        onChange={(val: string) => { setData('divisi_id', val); setData('org_unit_id', ''); }}
                                        labelKey="nama_divisi"
                                        showIcon={true}
                                    />
                                    {errors.divisi_id && <p className="text-red-500 text-sm mt-1">{errors.divisi_id}</p>}
                                </div>
                                <div>
                                    <Label className="mb-3 block text-base font-semibold">Unit Organisasi <span className="text-red-500">*</span></Label>
                                    {orgUnits.loading ? <p className="text-sm text-gray-500 italic">Memuat...</p> : (
                                        <RadioCardGrid
                                            options={orgUnits.options}
                                            value={data.org_unit_id}
                                            onChange={(val: string) => setData('org_unit_id', val)}
                                            labelKey="nama_unit_organisasi"
                                            disabled={!data.divisi_id}
                                        />
                                    )}
                                    {errors.org_unit_id && <p className="text-red-500 text-sm mt-1">{errors.org_unit_id}</p>}
                                </div>
                                <div>
                                    <Label className="mb-3 block text-base font-semibold">Jabatan <span className="text-red-500">*</span></Label>
                                    <RadioCardGrid
                                        options={jabatanList}
                                        value={data.jabatan_id}
                                        onChange={(val: string) => setData('jabatan_id', val)}
                                        labelKey="nama_jabatan"
                                    />
                                    {errors.jabatan_id && <p className="text-red-500 text-sm mt-1">{errors.jabatan_id}</p>}
                                </div>
                            </div>
                        )}

                        {activeStep === 1 && (
                            <div className="space-y-6">
                                <div>
                                    <Label className="mb-3 block text-base font-semibold">Unit Tujuan <span className="text-red-500">*</span></Label>
                                    <RadioCardGrid
                                        options={unitList}
                                        value={data.unit_id}
                                        onChange={(val: string) => { setData('unit_id', val); setData('sub_unit_id', ''); }}
                                        labelKey="nama_unit"
                                        showIcon={true}
                                    />
                                    {errors.unit_id && <p className="text-red-500 text-sm mt-1">{errors.unit_id}</p>}
                                </div>
                                <div>
                                    <Label className="mb-3 block text-base font-semibold">Sub Unit (Layanan) <span className="text-red-500">*</span></Label>
                                    {subUnits.loading ? <p className="text-sm text-gray-500 italic">Memuat...</p> : (
                                        <RadioCardGrid
                                            options={subUnits.options}
                                            value={data.sub_unit_id}
                                            onChange={(val: string) => setData('sub_unit_id', val)}
                                            labelKey="nama_layanan"
                                            disabled={!data.unit_id}
                                        />
                                    )}
                                    {errors.sub_unit_id && <p className="text-red-500 text-sm mt-1">{errors.sub_unit_id}</p>}
                                </div>
                            </div>
                        )}

                        {activeStep === 2 && (
                            <div>
                                {fieldsLoading ? (
                                    <p className="text-sm text-slate-500">Memuat form...</p>
                                ) : !data.sub_unit_id ? (
                                    <p className="text-sm text-slate-500">Silakan pilih layanan terlebih dahulu.</p>
                                ) : nonUploadFields.length === 0 ? (
                                    <p className="text-sm text-slate-500">Tidak ada field form untuk layanan ini.</p>
                                ) : (
                                    <DynamicField
                                        fields={nonUploadFields}
                                        values={data.form_data}
                                        onChange={handleFieldChange}
                                        errors={errors}
                                    />
                                )}
                            </div>
                        )}

                        {activeStep === 3 && (
                            <div>
                                {fieldsLoading ? (
                                    <p className="text-sm text-slate-500">Memuat...</p>
                                ) : uploadFields.length === 0 ? (
                                    <div className="flex flex-col items-center gap-2 py-8 text-slate-400">
                                        <Upload className="w-12 h-12" />
                                        <p className="text-sm">Tidak ada lampiran yang diperlukan untuk layanan ini.</p>
                                    </div>
                                ) : (
                                    <div className="space-y-6">
                                        {uploadFields.map(field => (
                                            <div key={field.id}>
                                                <div className="flex items-center gap-2">
                                                    <Label className="text-sm font-medium">{field.label}</Label>
                                                    {field.wajib ? (
                                                        <span className="text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded">Wajib</span>
                                                    ) : (
                                                        <span className="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded">Opsional</span>
                                                    )}
                                                </div>
                                                {renderFilePreview(field.id, field)}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}

                        {activeStep === 4 && (
                            <div className="space-y-4">
                                <Card className="border-green-200">
                                    <CardHeader className="py-3">
                                        <CardTitle className="text-sm flex items-center gap-2">
                                            <CheckCircle2 className="w-4 h-4 text-green-500" />
                                            Data Pengaju
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="py-2 space-y-1 text-sm">
                                        <div className="flex justify-between">
                                            <span className="text-slate-500">Divisi</span>
                                            <span className="font-medium">{getSelectedName(divisiList, data.divisi_id)}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-slate-500">Unit Organisasi</span>
                                            <span className="font-medium">{orgUnits.options.find((u: any) => String(u.id) === String(data.org_unit_id))?.nama_unit_organisasi || '-'}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-slate-500">Jabatan</span>
                                            <span className="font-medium">{getSelectedName(jabatanList, data.jabatan_id)}</span>
                                        </div>
                                    </CardContent>
                                </Card>

                                <Card className="border-green-200">
                                    <CardHeader className="py-3">
                                        <CardTitle className="text-sm flex items-center gap-2">
                                            <CheckCircle2 className="w-4 h-4 text-green-500" />
                                            Layanan Tujuan
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="py-2 space-y-1 text-sm">
                                        <div className="flex justify-between">
                                            <span className="text-slate-500">Unit</span>
                                            <span className="font-medium">{getSelectedName(unitList, data.unit_id)}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-slate-500">Sub Unit</span>
                                            <span className="font-medium">{subUnits.options.find((s: any) => String(s.id) === String(data.sub_unit_id))?.nama_layanan || '-'}</span>
                                        </div>
                                    </CardContent>
                                </Card>

                                {nonUploadFields.length > 0 && (
                                    <Card className="border-green-200">
                                        <CardHeader className="py-3">
                                            <CardTitle className="text-sm flex items-center gap-2">
                                                <CheckCircle2 className="w-4 h-4 text-green-500" />
                                                Isian Form
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="py-2 space-y-2 text-sm">
                                            {nonUploadFields
                                                .filter(field => !field.parent_field_id || data.form_data[field.parent_field_id] === field.trigger_value)
                                                .map(field => {
                                                const value = data.form_data[field.id];
                                                const displayValue = field.tipe_field === 'checkbox'
                                                    ? (value ? 'Ya' : 'Tidak')
                                                    : field.tipe_field === 'multi_pilih'
                                                        ? (value?.length ? value.join(', ') : '-')
                                                        : field.tipe_field === 'upload_gambar' || field.tipe_field === 'upload_file'
                                                            ? (value?.name || '-')
                                                            : value ?? '-';
                                                return (
                                                    <div key={field.id} className="flex justify-between">
                                                        <span className="text-slate-500">{field.label}</span>
                                                        <span className="font-medium text-right max-w-[60%] truncate">{displayValue}</span>
                                                    </div>
                                                );
                                            })}
                                        </CardContent>
                                    </Card>
                                )}

                                {uploadFields.length > 0 && (
                                    <Card className="border-green-200">
                                        <CardHeader className="py-3">
                                            <CardTitle className="text-sm flex items-center gap-2">
                                                <CheckCircle2 className="w-4 h-4 text-green-500" />
                                                Lampiran
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="py-2 space-y-2 text-sm">
                                            {uploadFields.map(field => {
                                                const file = data.attachments[String(field.id)];
                                                return (
                                                    <div key={field.id} className="flex justify-between">
                                                        <span className="text-slate-500">{field.label}</span>
                                                        <span className="font-medium">{file?.name || <span className="text-red-400">Belum diupload</span>}</span>
                                                    </div>
                                                );
                                            })}
                                        </CardContent>
                                    </Card>
                                )}

                                {Object.keys(errors).length > 0 && (
                                    <div className="flex items-start gap-2 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
                                        <AlertCircle className="w-5 h-5 flex-shrink-0 mt-0.5" />
                                        <div>
                                            <p className="font-medium">Terdapat kesalahan:</p>
                                            <ul className="list-disc list-inside mt-1">
                                                {Object.entries(errors).map(([key, msg]) => (
                                                    <li key={key}>{msg as string}</li>
                                                ))}
                                            </ul>
                                        </div>
                                    </div>
                                )}

                                <p className="text-xs text-slate-400 flex items-center gap-1">
                                    <Info className="w-3 h-3" />
                                    Pastikan semua data sudah benar sebelum mengirim tiket.
                                </p>
                            </div>
                        )}

                        <div className="mt-8 flex justify-between">
                            <Button type="button" variant="outline" onClick={prevStep} disabled={activeStep === 0}>
                                Kembali
                            </Button>
                            {activeStep < STEPS.length - 1 ? (
                                <Button type="button" onClick={nextStep} disabled={fieldsLoading}>
                                    {fieldsLoading ? "Memuat..." : "Lanjut"}
                                </Button>
                            ) : (
                                <Button type="submit" disabled={processing || !canSubmit}>
                                    {processing ? "Mengirim..." : !canSubmit ? "Mohon Tunggu..." : "Kirim Tiket"}
                                </Button>
                            )}
                        </div>
                    </form>
                </CardContent>
            </Card>
            </div>
        </UserLayout>
    );
}
