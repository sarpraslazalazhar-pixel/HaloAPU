import React, { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { SearchInput } from '@/Components/SearchInput';
import { Pagination } from '@/Components/Pagination';
import Swal from 'sweetalert2';
import { Pencil, Trash2 } from 'lucide-react';

interface Unit {
    id: number;
    nama_unit: string;
    deskripsi: string | null;
    aktif: boolean;
    sub_units_count: number;
    created_at: string;
}

export default function UnitIndex({ units, filters }: { units: any; filters?: { search?: string } }) {
    const [isAddOpen, setIsAddOpen] = useState(false);
    const [editUnit, setEditUnit] = useState<Unit | null>(null);

    const { data, setData, post, put, delete: destroy, reset, errors } = useForm({
        nama_unit: '',
        deskripsi: '',
        aktif: true,
    });

    const handleAdd = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('admin.master.unit.store'), {
            onSuccess: () => {
                setIsAddOpen(false);
                reset();
            }
        });
    };

    const handleEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editUnit) {
            put(route('admin.master.unit.update', editUnit.id), {
                onSuccess: () => {
                    setEditUnit(null);
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
                destroy(route('admin.master.unit.destroy', id));
            }
        });
    };

    const openEdit = (unit: Unit) => {
        setEditUnit(unit);
        setData({
            nama_unit: unit.nama_unit,
            deskripsi: unit.deskripsi || '',
            aktif: unit.aktif,
        });
    };

    return (
        <AdminLayout title="Master Unit">
            <div className="flex items-center justify-between mb-4">
                <h2 className="text-2xl font-bold">Master Data Unit</h2>
                <Dialog open={isAddOpen} onOpenChange={setIsAddOpen}>
                    <DialogTrigger asChild>
                        <Button onClick={() => reset()}>Tambah Unit</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Tambah Unit</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={handleAdd} className="space-y-4">
                            <div className="space-y-2">
                                <Label>Nama Unit</Label>
                                <Input value={data.nama_unit} onChange={e => setData('nama_unit', e.target.value)} />
                                {errors.nama_unit && <p className="text-red-500 text-sm">{errors.nama_unit}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label>Deskripsi</Label>
                                <Input value={data.deskripsi} onChange={e => setData('deskripsi', e.target.value)} />
                                {errors.deskripsi && <p className="text-red-500 text-sm">{errors.deskripsi}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label>Aktif</Label>
                                <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50" value={data.aktif ? '1' : '0'} onChange={e => setData('aktif', e.target.value === '1')}>
                                    <option value="1">Ya</option>
                                    <option value="0">Tidak</option>
                                </select>
                            </div>
                            <div className="flex justify-end pt-4"><Button type="submit">Simpan</Button></div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>

            <div className="mb-4">
                <SearchInput placeholder="Cari unit..." />
            </div>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>No</TableHead>
                        <TableHead>Nama Unit</TableHead>
                        <TableHead>Deskripsi</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Jml Sub Unit</TableHead>
                        <TableHead>Aksi</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {units.data?.length > 0 ? units.data.map((unit: Unit, i: number) => (
                        <TableRow key={unit.id}>
                            <TableCell>{units.from + i}</TableCell>
                            <TableCell>{unit.nama_unit}</TableCell>
                            <TableCell>{unit.deskripsi || '-'}</TableCell>
                            <TableCell>{unit.aktif ? 'Aktif' : 'Nonaktif'}</TableCell>
                            <TableCell>{unit.sub_units_count}</TableCell>
                            <TableCell className="space-x-2">
                                <Button variant="outline" size="icon" onClick={() => openEdit(unit)}>
                                    <Pencil className="w-4 h-4" />
                                </Button>
                                <Button variant="outline" size="icon" className="text-red-500 hover:text-red-600 hover:bg-red-50" onClick={() => handleDelete(unit.id)}>
                                    <Trash2 className="w-4 h-4" />
                                </Button>
                            </TableCell>
                        </TableRow>
                    )) : (
                        <TableRow>
                            <TableCell colSpan={6} className="text-center py-4 text-slate-500">
                                Tidak ada data
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </Table>

            <Pagination links={units.links} />

            <Dialog open={!!editUnit} onOpenChange={(open) => !open && setEditUnit(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit Unit</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleEdit} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Nama Unit</Label>
                            <Input value={data.nama_unit} onChange={e => setData('nama_unit', e.target.value)} />
                            {errors.nama_unit && <p className="text-red-500 text-sm">{errors.nama_unit}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label>Deskripsi</Label>
                            <Input value={data.deskripsi} onChange={e => setData('deskripsi', e.target.value)} />
                            {errors.deskripsi && <p className="text-red-500 text-sm">{errors.deskripsi}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label>Aktif</Label>
                            <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50" value={data.aktif ? '1' : '0'} onChange={e => setData('aktif', e.target.value === '1')}>
                                <option value="1">Ya</option>
                                <option value="0">Tidak</option>
                            </select>
                        </div>
                        <div className="flex justify-end pt-4"><Button type="submit">Update</Button></div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
