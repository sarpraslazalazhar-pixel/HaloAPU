import React, { useState, useEffect } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Link, router, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import Swal from 'sweetalert2';
import { GripVertical, Edit, Trash, Plus, ArrowLeft } from 'lucide-react';
import {
    DndContext,
    closestCenter,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
    DragEndEvent,
} from '@dnd-kit/core';
import {
    SortableContext,
    sortableKeyboardCoordinates,
    verticalListSortingStrategy,
    useSortable,
    arrayMove,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';

interface Unit {
    id: number;
    nama_unit: string;
}

interface SubUnit {
    id: number;
    unit_id: number;
    nama_layanan: string;
    unit: Unit;
}

interface FormField {
    id: number;
    sub_unit_id: number;
    label: string;
    tipe_field: string;
    wajib: boolean;
    opsi: string[] | null;
    parent_field_id: number | null;
    trigger_value: string | null;
    urutan: number;
    child_fields?: FormField[];
}

interface FormBuilderProps {
    subUnit: SubUnit;
    fields: FormField[];
    allFields: FormField[];
    tipeFields: string[];
    tipeDenganOpsi: string[];
}

function SortableField({ field, onEdit, onDelete }: { field: FormField, onEdit: (f: FormField) => void, onDelete: (id: number) => void }) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
    } = useSortable({ id: field.id });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
    };

    return (
        <div
            ref={setNodeRef}
            style={style}
            className="flex items-center gap-4 bg-white border p-4 mb-2 rounded-lg shadow-sm"
        >
            <div {...attributes} {...listeners} className="cursor-grab text-gray-400 hover:text-gray-600">
                <GripVertical size={20} />
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                    <span className="font-medium text-gray-800">{field.label}</span>
                    {field.wajib && (
                        <span className="bg-red-100 text-red-700 text-xs px-2 py-0.5 rounded-full font-medium">
                            Wajib
                        </span>
                    )}
                </div>
                <div className="text-sm text-gray-500 mt-1 flex items-center gap-2">
                    <span className="bg-blue-50 text-blue-700 px-2 py-0.5 rounded text-xs border border-blue-200">
                        {field.tipe_field.replace('_', ' ')}
                    </span>
                    {field.parent_field_id && (
                        <span className="text-xs text-orange-600 bg-orange-50 px-2 py-0.5 rounded border border-orange-200">
                            Cabang jika: {field.trigger_value}
                        </span>
                    )}
                </div>
                {field.opsi && field.opsi.length > 0 && (
                    <div className="text-xs text-gray-400 mt-1 truncate">
                        Opsi: {field.opsi.join(', ')}
                    </div>
                )}
            </div>

            <div className="flex gap-2">
                <Button variant="outline" size="icon" onClick={() => onEdit(field)} title="Edit">
                    <Edit size={16} />
                </Button>
                <Button variant="outline" size="icon" className="text-red-600 hover:text-red-700 hover:bg-red-50" onClick={() => onDelete(field.id)} title="Hapus">
                    <Trash size={16} />
                </Button>
            </div>
        </div>
    );
}

export default function Builder({ subUnit, fields: initialFields, allFields, tipeFields, tipeDenganOpsi }: FormBuilderProps) {
    const [fields, setFields] = useState(initialFields);
    const [isFormOpen, setIsFormOpen] = useState(false);
    const [editMode, setEditMode] = useState(false);
    const [currentFieldId, setCurrentFieldId] = useState<number | null>(null);

    const { data, setData, post, put, delete: destroy, processing, errors, reset } = useForm({
        label: '',
        tipe_field: 'teks_pendek',
        wajib: false,
        opsi: [] as string[],
        parent_field_id: '' as string | number,
        trigger_value: '',
        opsiString: '' // helper for text area options
    });

    // Sync fields when initialFields prop changes
    useEffect(() => {
        setFields(initialFields);
    }, [initialFields]);

    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
    );

    function handleDragEnd(event: DragEndEvent) {
        const { active, over } = event;

        if (over && active.id !== over.id) {
            setFields((items) => {
                const oldIndex = items.findIndex((f) => f.id === active.id);
                const newIndex = items.findIndex((f) => f.id === over.id);
                const newFields = arrayMove(items, oldIndex, newIndex);

                // Send to server without waiting
                router.post(route('admin.peraturan-form.reorder', subUnit.id), {
                    order: newFields.map((f, i) => ({ id: f.id, urutan: i + 1 })),
                }, { preserveScroll: true, preserveState: true });

                return newFields;
            });
        }
    }

    const openAddForm = () => {
        setEditMode(false);
        setCurrentFieldId(null);
        reset();
        setData('tipe_field', 'teks_pendek');
        setIsFormOpen(true);
    };

    const openEditForm = (field: FormField) => {
        setEditMode(true);
        setCurrentFieldId(field.id);
        setData({
            label: field.label,
            tipe_field: field.tipe_field,
            wajib: field.wajib,
            opsi: field.opsi || [],
            parent_field_id: field.parent_field_id || '',
            trigger_value: field.trigger_value || '',
            opsiString: (field.opsi || []).join('\n')
        });
        setIsFormOpen(true);
    };

    const handleDelete = (id: number) => {
        Swal.fire({
            title: 'Yakin ingin menghapus field ini?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'OK',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                destroy(route('admin.peraturan-form.destroy', id), {
                    preserveScroll: true
                });
            }
        });
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // Process options from string
        const currentData = { ...data };
        if (tipeDenganOpsi.includes(currentData.tipe_field) && currentData.opsiString) {
            currentData.opsi = currentData.opsiString.split('\n').map(s => s.trim()).filter(s => s !== '');
        } else {
            currentData.opsi = [];
        }

        // Parent parsing
        const payload = {
            ...currentData,
            parent_field_id: currentData.parent_field_id ? Number(currentData.parent_field_id) : null
        };

        if (editMode && currentFieldId) {
            router.put(route('admin.peraturan-form.update', currentFieldId), payload, {
                onSuccess: () => setIsFormOpen(false),
                preserveScroll: true
            });
        } else {
            router.post(route('admin.peraturan-form.store', subUnit.id), payload, {
                onSuccess: () => setIsFormOpen(false),
                preserveScroll: true
            });
        }
    };

    // Render children nicely (just a visual indentation in this simple version, normally requires tree dnd)
    const renderFieldWithChildren = (field: FormField) => {
        return (
            <div key={field.id} className="mb-2">
                <SortableField field={field} onEdit={openEditForm} onDelete={handleDelete} />
                {field.child_fields && field.child_fields.length > 0 && (
                    <div className="pl-12">
                        {field.child_fields.map(child => (
                            <div key={child.id} className="opacity-80">
                                {/* Simply render child without drag to keep it simple, or add nested sortable if time permits */}
                                <div className="flex items-center gap-4 bg-gray-50 border p-3 mb-2 rounded-lg shadow-sm border-dashed">
                                    <div className="w-6 text-center text-gray-400 pl-1 border-l-2 border-orange-300">↳</div>
                                    <div className="flex-1">
                                        <div className="flex items-center gap-2 text-sm">
                                            <span className="font-medium text-gray-700">{child.label}</span>
                                            {child.wajib && <span className="bg-red-100 text-red-700 text-[10px] px-1.5 py-0.5 rounded-full font-medium">Wajib</span>}
                                        </div>
                                        <div className="text-xs text-gray-500 mt-1">
                                            {child.tipe_field.replace('_', ' ')} • Pemicu: <strong>{child.trigger_value}</strong>
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button variant="ghost" size="icon" onClick={() => openEditForm(child)} title="Edit" className="h-8 w-8">
                                            <Edit size={14} />
                                        </Button>
                                        <Button variant="ghost" size="icon" className="h-8 w-8 text-red-600" onClick={() => handleDelete(child.id)} title="Hapus">
                                            <Trash size={14} />
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        );
    };

    return (
        <AdminLayout title={`Form Builder - ${subUnit.nama_layanan}`}>
            <div className="flex items-center justify-between mb-6">
                <div>
                    <div className="flex items-center gap-2 text-sm text-gray-500 mb-2">
                        <Link href={route('admin.peraturan-form.index')} className="hover:text-blue-600 flex items-center gap-1">
                            <ArrowLeft size={16} /> Kembali
                        </Link>
                        <span>/</span>
                        <span>{subUnit.unit?.nama_unit}</span>
                    </div>
                    <h2 className="text-3xl font-bold tracking-tight">
                        Builder: {subUnit.nama_layanan}
                    </h2>
                </div>
                <Button onClick={openAddForm} className="gap-2">
                    <Plus size={18} /> Tambah Field
                </Button>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2">
                    <div className="bg-gray-50 p-6 rounded-xl border min-h-[500px]">
                        {fields.length === 0 ? (
                            <div className="flex flex-col items-center justify-center h-64 text-gray-400">
                                <div className="bg-white p-4 rounded-full shadow-sm mb-4">
                                    <Plus size={32} className="text-gray-300" />
                                </div>
                                <p>Belum ada field di form ini.</p>
                                <Button variant="link" onClick={openAddForm}>Mulai tambahkan field pertama</Button>
                            </div>
                        ) : (
                            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                                <SortableContext items={fields.map(f => f.id)} strategy={verticalListSortingStrategy}>
                                    {fields.map((field) => renderFieldWithChildren(field))}
                                </SortableContext>
                            </DndContext>
                        )}
                    </div>
                </div>

                {/* Form Sidebar */}
                {isFormOpen && (
                    <div className="bg-white p-6 rounded-xl border shadow-lg h-fit sticky top-6">
                        <h3 className="text-lg font-bold mb-4 border-b pb-2">
                            {editMode ? 'Edit Field' : 'Tambah Field Baru'}
                        </h3>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium mb-1">Label / Pertanyaan</label>
                                <input 
                                    type="text" 
                                    className="w-full border rounded-md p-2" 
                                    value={data.label}
                                    onChange={e => setData('label', e.target.value)}
                                    required
                                />
                                {errors.label && <div className="text-red-500 text-xs mt-1">{errors.label}</div>}
                            </div>

                            <div>
                                <label className="block text-sm font-medium mb-1">Tipe Field</label>
                                <select 
                                    className="w-full border rounded-md p-2"
                                    value={data.tipe_field}
                                    onChange={e => setData('tipe_field', e.target.value)}
                                >
                                    {tipeFields.map(tipe => (
                                        <option key={tipe} value={tipe}>{tipe.replace('_', ' ').toUpperCase()}</option>
                                    ))}
                                </select>
                                {errors.tipe_field && <div className="text-red-500 text-xs mt-1">{errors.tipe_field}</div>}
                            </div>

                            <div className="flex items-center gap-2">
                                <input 
                                    type="checkbox" 
                                    id="wajib" 
                                    checked={data.wajib}
                                    onChange={e => setData('wajib', e.target.checked)}
                                    className="rounded"
                                />
                                <label htmlFor="wajib" className="text-sm font-medium cursor-pointer">Wajib Diisi (Required)</label>
                            </div>

                            {tipeDenganOpsi.includes(data.tipe_field) && (
                                <div>
                                    <label className="block text-sm font-medium mb-1">Opsi Pilihan (Satu per baris)</label>
                                    <textarea 
                                        className="w-full border rounded-md p-2" 
                                        rows={4}
                                        placeholder="Opsi 1&#10;Opsi 2&#10;Opsi 3"
                                        value={data.opsiString}
                                        onChange={e => setData('opsiString', e.target.value)}
                                        required
                                    ></textarea>
                                </div>
                            )}

                            <div className="border-t pt-4 mt-4">
                                <h4 className="text-sm font-bold text-gray-600 mb-2">Logika Kondisional (Cabang)</h4>
                                
                                <div className="space-y-3">
                                    <div>
                                        <label className="block text-xs font-medium mb-1 text-gray-500">Tampil Jika Field Parent:</label>
                                        <select 
                                            className="w-full border rounded-md p-2 text-sm bg-gray-50"
                                            value={data.parent_field_id}
                                            onChange={e => setData('parent_field_id', e.target.value)}
                                        >
                                            <option value="">-- Tidak ada (Selalu Tampil) --</option>
                                            {allFields
                                                .filter(f => tipeDenganOpsi.includes(f.tipe_field) && f.id !== currentFieldId)
                                                .map(f => (
                                                <option key={f.id} value={f.id}>{f.label}</option>
                                            ))}
                                        </select>
                                    </div>
                                    
                                    {data.parent_field_id && (
                                        <div>
                                            <label className="block text-xs font-medium mb-1 text-gray-500">Nilai Parent Sama Dengan:</label>
                                            <input 
                                                type="text" 
                                                className="w-full border rounded-md p-2 text-sm bg-gray-50" 
                                                value={data.trigger_value}
                                                onChange={e => setData('trigger_value', e.target.value)}
                                                placeholder="Contoh: Ya"
                                                required={!!data.parent_field_id}
                                            />
                                            <p className="text-[10px] text-gray-400 mt-1">Field ini akan muncul jika user memilih nilai ini pada field parent.</p>
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div className="flex gap-2 pt-4 border-t">
                                <Button type="submit" disabled={processing} className="flex-1">
                                    {processing ? 'Menyimpan...' : 'Simpan Field'}
                                </Button>
                                <Button type="button" variant="outline" onClick={() => setIsFormOpen(false)}>
                                    Batal
                                </Button>
                            </div>
                        </form>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
