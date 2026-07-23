import React, { useState, useEffect } from 'react';
import { Head, useForm } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import { Stepper } from '@/Components/Stepper';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { RadioCardGrid } from '@/Components/RadioCardGrid';
import { useDependentDropdown } from '@/hooks/useDependentDropdown';
import DynamicField from '@/Components/DynamicForm/DynamicField';
import axios from 'axios';
import { FormField as FormFieldType } from '@/types';
import { FileDropzone } from '@/Components/FileDropzone';
import { Trash2, FileText, CheckCircle2, AlertCircle, Info } from 'lucide-react';
import Swal from 'sweetalert2';

const STEPS = [
    { label: 'Pilih Layanan', description: 'Kanal layanan dan jenis layanan' },
    { label: 'Isi Form', description: 'Formulir layanan' },
    { label: 'Lampiran', description: 'Unggah dokumen pendukung' },
    { label: 'Tinjau & Kirim', description: 'Periksa dan kirim tiket' },
];

interface WizardProps {
    unitList: any[];
}

export default function Wizard({ unitList }: WizardProps) {
    const [activeStep, setActiveStep] = useState(0);
    const [formFields, setFormFields] = useState<FormFieldType[]>([]);
    const [fieldsLoading, setFieldsLoading] = useState(false);
    const [canSubmit, setCanSubmit] = useState(false);

    const { data, setData, post, transform, processing, errors, setError, clearErrors } = useForm({
        unit_id: '',
        sub_unit_id: '',
        form_data: {} as Record<string, any>,
        attachments: {} as Record<string, File[]>,
        general_attachments: [] as File[],
    });

    const subUnits = useDependentDropdown(route('api.sub-units', { unitId: '{id}' }));

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

    const handleFileChange = (fieldId: number, files: FileList | null) => {
        const currentFiles = data.attachments[String(fieldId)] || [];
        const newAttachments = { ...data.attachments };
        
        if (files && files.length > 0) {
            const incomingFiles = Array.from(files);
            
            if (currentFiles.length + incomingFiles.length > 3) {
                Swal.fire({
                    icon: 'error',
                    title: 'Batas Lampiran',
                    text: 'Maksimal hanya 3 lampiran per isian.',
                    confirmButtonColor: '#3b82f6'
                });
                return;
            }

            const validFiles = incomingFiles.filter(file => {
                if (file.size > 3 * 1024 * 1024) {
                    Swal.fire({
                        icon: 'error',
                        title: 'File Terlalu Besar',
                        text: `Ukuran file ${file.name} melebihi 3 MB.`,
                        confirmButtonColor: '#3b82f6'
                    });
                    return false;
                }
                return true;
            });
            
            newAttachments[String(fieldId)] = [...currentFiles, ...validFiles];
        }
        setData('attachments', newAttachments);
    };

    const removeFile = (fieldId: number, indexToRemove: number) => {
        const currentFiles = data.attachments[String(fieldId)] || [];
        const newAttachments = { ...data.attachments };
        newAttachments[String(fieldId)] = currentFiles.filter((_, i) => i !== indexToRemove);
        setData('attachments', newAttachments);
    };

    const validateStep = (step: number) => {
        clearErrors();
        let isValid = true;
        
        if (step === 0) {
            if (!data.unit_id) { setError('unit_id', 'Kanal Layanan wajib dipilih'); isValid = false; }
            if (!data.sub_unit_id) { setError('sub_unit_id', 'Jenis Layanan wajib dipilih'); isValid = false; }
        } else if (step === 1) {
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
        } else if (step === 2) {
            uploadFields.forEach(field => {
                const isVisible = !field.parent_field_id || data.form_data[field.parent_field_id] === field.trigger_value;
                if (isVisible && field.wajib) {
                    const files = data.attachments[String(field.id)];
                    if (!files || files.length === 0) {
                        setError(`attachments.${field.id}`, `${field.label} wajib diunggah`);
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

function ReviewSection({ title, icon, children }: { title: string; icon?: React.ReactNode; children: React.ReactNode }) {
    return (
        <Card className="border-green-200">
            <CardHeader className="py-3">
                <CardTitle className="text-sm flex items-center gap-2">
                    {icon}
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent className="py-2 space-y-1 text-sm">
                {children}
            </CardContent>
        </Card>
    );
}

function ReviewRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex justify-between">
            <span className="text-slate-500">{label}</span>
            <span className="font-medium text-right max-w-[60%] truncate">{value}</span>
        </div>
    );
}

    const getSelectedName = (list: any[], id: string | number) => {
        const item = list.find((i: any) => String(i.id) === String(id));
        return item ? (item.nama_unit || item.nama_layanan || '-') : '-';
    };

    const renderFilePreview = (fieldId: number, field: FormFieldType) => {
        const files = data.attachments[String(fieldId)] || [];
        const isImage = field.tipe_field === 'upload_gambar';
        const acceptedFormatText = isImage ? 'Format: JPG, JPEG, PNG.' : 'Format: JPG, PNG, PDF, DOC, DOCX.';
        const acceptFormats = isImage ? '.jpg,.jpeg,.png' : '.jpg,.jpeg,.png,.pdf,.doc,.docx';

        return (
            <div className="mt-1 space-y-3">
                <FileDropzone
                    accept={acceptFormats}
                    multiple={true}
                    onFilesSelected={(files) => handleFileChange(fieldId, files)}
                    description={`Dapat mengunggah hingga 3 file (maks. 3MB per file). ${acceptedFormatText}`}
                />
                
                {errors[`attachments.${fieldId}`] && (
                    <p className="text-red-500 text-xs">{errors[`attachments.${fieldId}`]}</p>
                )}

                {files.length > 0 && (
                    <div className="space-y-2">
                        {files.map((file, idx) => (
                            <div key={idx} className="flex items-center gap-3 p-3 bg-gray-50 rounded-lg border">
                                {isImage ? (
                                    <img src={URL.createObjectURL(file)} alt={file.name} className="w-16 h-16 object-cover rounded" />
                                ) : (
                                    <FileText className="w-10 h-10 text-gray-400" />
                                )}
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm font-medium truncate">{file.name}</p>
                                    <p className="text-xs text-gray-500">{(file.size / 1024).toFixed(1)} KB</p>
                                </div>
                                <Button type="button" variant="ghost" size="icon" onClick={() => removeFile(fieldId, idx)}>
                                    <Trash2 className="w-4 h-4 text-red-500" />
                                </Button>
                            </div>
                        ))}
                    </div>
                )}
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
                                    <Label className="mb-3 block text-base font-semibold">Kanal Layanan <span className="text-red-500">*</span></Label>
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
                                    <Label className="mb-3 block text-base font-semibold">Jenis Layanan <span className="text-red-500">*</span></Label>
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

                        {activeStep === 1 && (
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

                        {activeStep === 2 && (
                            <div>
                                {fieldsLoading ? (
                                    <p className="text-sm text-slate-500">Memuat...</p>
                                ) : uploadFields.length === 0 ? (
                                    <p className="text-sm text-slate-500 mt-2">Tidak ada lampiran yang diperlukan untuk layanan ini.</p>
                                ) : (
                                    <div className="space-y-6">
                                        {uploadFields
                                            .filter(field => !field.parent_field_id || data.form_data[field.parent_field_id] === field.trigger_value)
                                            .map(field => (
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

                        {activeStep === 3 && (
                            <div className="space-y-4">
                                <ReviewSection title="Pilihan Layanan" icon={<CheckCircle2 className="w-4 h-4 text-green-500" />}>
                                    <ReviewRow label="Kanal Layanan" value={getSelectedName(unitList, data.unit_id)} />
                                    <ReviewRow label="Jenis Layanan" value={subUnits.options.find((s: any) => String(s.id) === String(data.sub_unit_id))?.nama_layanan || '-'} />
                                </ReviewSection>

                                {nonUploadFields.length > 0 && (
                                    <ReviewSection title="Isian Form" icon={<CheckCircle2 className="w-4 h-4 text-green-500" />}>
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
                                                            : field.tipe_field === 'nominal_rp' && value
                                                                ? new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value))
                                                                : value ?? '-';
                                                return <ReviewRow key={field.id} label={field.label} value={displayValue} />;
                                            })}
                                    </ReviewSection>
                                )}

                                {(uploadFields.length > 0) && (
                                    <ReviewSection title="Lampiran" icon={<CheckCircle2 className="w-4 h-4 text-green-500" />}>
                                        {uploadFields
                                            .filter(field => !field.parent_field_id || data.form_data[field.parent_field_id] === field.trigger_value)
                                            .map(field => {
                                                const files = data.attachments[String(field.id)] || [];
                                                return (
                                                    <ReviewRow 
                                                        key={field.id} 
                                                        label={field.label} 
                                                        value={files.length > 0 
                                                            ? files.map(f => f.name).join(', ') 
                                                            : <span className="text-red-400">Belum diunggah</span>} 
                                                    />
                                                );
                                            })}
                                    </ReviewSection>
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
