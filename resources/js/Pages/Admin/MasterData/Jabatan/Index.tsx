import React, { useState, useEffect } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { useForm, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { SearchInput } from '@/Components/SearchInput';
import Swal from 'sweetalert2';
import { GripVertical, Pencil, Trash2 } from 'lucide-react';
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

interface Jabatan {
    id: number;
    nama_jabatan: string;
    urutan: number;
}

function SortableRow({ item, index, openEdit, handleDelete }: { item: Jabatan, index: number, openEdit: any, handleDelete: any }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: item.id });
    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        zIndex: isDragging ? 10 : 1,
        position: 'relative' as 'relative',
    };

    return (
        <TableRow ref={setNodeRef} style={style} className={isDragging ? "bg-gray-100 shadow-sm" : "bg-white hover:bg-gray-50"}>
            <TableCell>
                <div className="flex items-center gap-2">
                    <div {...attributes} {...listeners} className="cursor-grab text-gray-400 hover:text-gray-600 p-1 rounded hover:bg-gray-200">
                        <GripVertical size={16} />
                    </div>
                    {index + 1}
                </div>
            </TableCell>
            <TableCell>{item.nama_jabatan}</TableCell>
            <TableCell className="space-x-2">
                <Button variant="outline" size="icon" onClick={() => openEdit(item)}>
                    <Pencil className="w-4 h-4" />
                </Button>
                <Button variant="outline" size="icon" className="text-red-500 hover:text-red-600 hover:bg-red-50" onClick={() => handleDelete(item.id)}>
                    <Trash2 className="w-4 h-4" />
                </Button>
            </TableCell>
        </TableRow>
    );
}

export default function JabatanIndex({ jabatans, filters }: { jabatans: Jabatan[]; filters?: { search?: string } }) {
    const [isAddOpen, setIsAddOpen] = useState(false);
    const [editItem, setEditItem] = useState<Jabatan | null>(null);
    const [items, setItems] = useState<Jabatan[]>(jabatans);

    useEffect(() => {
        setItems(jabatans);
    }, [jabatans]);

    const { data, setData, post, put, delete: destroy, reset, errors } = useForm({
        nama_jabatan: '',
    });

    const handleAdd = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('admin.master.jabatan.store'), {
            onSuccess: () => {
                setIsAddOpen(false);
                reset();
            }
        });
    };

    const handleEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editItem) {
            put(route('admin.master.jabatan.update', editItem.id), {
                onSuccess: () => {
                    setEditItem(null);
                    reset();
                }
            });
        }
    };

    const handleDelete = (id: number) => {
        Swal.fire({
            title: 'Yakin hapus?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'OK',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                destroy(route('admin.master.jabatan.destroy', id));
            }
        });
    };

    const openEdit = (item: Jabatan) => {
        setEditItem(item);
        setData({
            nama_jabatan: item.nama_jabatan,
        });
    };

    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
    );

    const handleDragEnd = (event: DragEndEvent) => {
        const { active, over } = event;
        if (active.id !== over?.id) {
            setItems((prev) => {
                const oldIndex = prev.findIndex((i) => i.id === active.id);
                const newIndex = prev.findIndex((i) => i.id === over?.id);
                const newItems = arrayMove(prev, oldIndex, newIndex);

                router.post(route('admin.master.jabatan.reorder'), {
                    order: newItems.map((item, i) => ({ id: item.id, urutan: i + 1 })),
                }, { preserveScroll: true, preserveState: true });

                return newItems;
            });
        }
    };

    const isSearching = !!filters?.search;

    return (
        <AdminLayout title="Master Jabatan">
            <div className="flex items-center justify-between mb-4">
                <h2 className="text-2xl font-bold">Master Data Jabatan</h2>
                <Dialog open={isAddOpen} onOpenChange={setIsAddOpen}>
                    <DialogTrigger asChild>
                        <Button onClick={() => reset()}>Tambah Jabatan</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Tambah Jabatan</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={handleAdd} className="space-y-4">
                            <div className="space-y-2">
                                <Label>Nama Jabatan</Label>
                                <Input value={data.nama_jabatan} onChange={e => setData('nama_jabatan', e.target.value)} />
                                {errors.nama_jabatan && <p className="text-red-500 text-sm">{errors.nama_jabatan}</p>}
                            </div>
                            <div className="flex justify-end pt-4"><Button type="submit">Simpan</Button></div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>

            <div className="mb-4">
                <SearchInput placeholder="Cari jabatan..." />
            </div>

            <div className="border rounded-md">
                <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-[100px]">No</TableHead>
                                <TableHead>Nama Jabatan</TableHead>
                                <TableHead className="w-[150px]">Aksi</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <SortableContext items={items.map(i => i.id)} strategy={verticalListSortingStrategy}>
                                {items.length > 0 ? items.map((item, i) => (
                                    isSearching ? (
                                        <TableRow key={item.id} className="bg-white">
                                            <TableCell className="pl-6">{i + 1}</TableCell>
                                            <TableCell>{item.nama_jabatan}</TableCell>
                                            <TableCell className="space-x-2">
                                                <Button variant="outline" size="icon" onClick={() => openEdit(item)}>
                                                    <Pencil className="w-4 h-4" />
                                                </Button>
                                                <Button variant="outline" size="icon" className="text-red-500 hover:text-red-600 hover:bg-red-50" onClick={() => handleDelete(item.id)}>
                                                    <Trash2 className="w-4 h-4" />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        <SortableRow 
                                            key={item.id} 
                                            item={item} 
                                            index={i} 
                                            openEdit={openEdit} 
                                            handleDelete={handleDelete} 
                                        />
                                    )
                                )) : (
                                    <TableRow>
                                        <TableCell colSpan={3} className="text-center py-4 text-slate-500">
                                            Tidak ada data
                                        </TableCell>
                                    </TableRow>
                                )}
                            </SortableContext>
                        </TableBody>
                    </Table>
                </DndContext>
            </div>

            <Dialog open={!!editItem} onOpenChange={(open) => !open && setEditItem(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit Jabatan</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleEdit} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Nama Jabatan</Label>
                            <Input value={data.nama_jabatan} onChange={e => setData('nama_jabatan', e.target.value)} />
                            {errors.nama_jabatan && <p className="text-red-500 text-sm">{errors.nama_jabatan}</p>}
                        </div>
                        <div className="flex justify-end pt-4"><Button type="submit">Update</Button></div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
