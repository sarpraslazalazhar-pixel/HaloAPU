import React, { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { useForm, router } from '@inertiajs/react';
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
}

interface SubUnit {
    id: number;
    unit_id: number;
    nama_layanan: string;
    deskripsi: string | null;
    aktif: boolean;
    unit: Unit;
    form_fields_count: number;
}

export default function SubUnitIndex({ subUnits, units, filters }: { subUnits: any; units: Unit[]; filters?: { search?: string; unit_id?: number } }) {
    const [isAddOpen, setIsAddOpen] = useState(false);
    const [editSubUnit, setEditSubUnit] = useState<SubUnit | null>(null);
    const [filterUnit, setFilterUnit] = useState(filters?.unit_id?.toString() || '');

    const { data, setData, post, put, delete: destroy, reset, errors } = useForm({
        unit_id: '',
        nama_layanan: '',
        deskripsi: '',
        aktif: true,
    });

    const handleAdd = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('admin.master.sub-unit.store'), {
            onSuccess: () => {
                setIsAddOpen(false);
                reset();
            }
        });
    };

    const handleEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editSubUnit) {
            put(route('admin.master.sub-unit.update', editSubUnit.id), {
                onSuccess: () => {
                    setEditSubUnit(null);
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
                destroy(route('admin.master.sub-unit.destroy', id));
            }
        });
    };

    const openEdit = (subUnit: SubUnit) => {
        setEditSubUnit(subUnit);
        setData({
            unit_id: String(subUnit.unit_id),
            nama_layanan: subUnit.nama_layanan,
            deskripsi: subUnit.deskripsi || '',
            aktif: subUnit.aktif,
        });
    };

    const handleUnitFilter = (e: React.ChangeEvent<HTMLSelectElement>) => {
        const val = e.target.value;
        setFilterUnit(val);
        router.reload({ data: { unit_id: val || undefined }, only: ['subUnits', 'filters'], preserveState: true });
    };

    return (
        <AdminLayout title="Master Sub Unit">
            <div className="flex items-center justify-between mb-4">
                <h2 className="text-2xl font-bold">Master Data Sub Unit</h2>
                <Dialog open={isAddOpen} onOpenChange={setIsAddOpen}>
                    <DialogTrigger asChild>
                        <Button onClick={() => reset()}>Tambah Sub Unit</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Tambah Sub Unit</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={handleAdd} className="space-y-4">
                            <div className="space-y-2">
                                <Label>Unit</Label>
                                <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50" value={data.unit_id} onChange={e => setData('unit_id', e.target.value)}>
                                    <option value="">Pilih Unit</option>
                                    {units.map(u => (
                                        <option key={u.id} value={u.id}>{u.nama_unit}</option>
                                    ))}
                                </select>
                                {errors.unit_id && <p className="text-red-500 text-sm">{errors.unit_id}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label>Nama Layanan</Label>
                                <Input value={data.nama_layanan} onChange={e => setData('nama_layanan', e.target.value)} />
                                {errors.nama_layanan && <p className="text-red-500 text-sm">{errors.nama_layanan}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label>Deskripsi</Label>
                                <Input value={data.deskripsi} onChange={e => setData('deskripsi', e.target.value)} />
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

            <div className="flex items-center gap-4 mb-4">
                <SearchInput placeholder="Cari layanan..." />
                <select
                    className="border rounded p-2 text-sm"
                    value={filterUnit}
                    onChange={handleUnitFilter}
                >
                    <option value="">Semua Unit</option>
                    {units.map(u => (
                        <option key={u.id} value={u.id}>{u.nama_unit}</option>
                    ))}
                </select>
            </div>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>No</TableHead>
                        <TableHead>Nama Layanan</TableHead>
                        <TableHead>Unit</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Jml Form Field</TableHead>
                        <TableHead>Aksi</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {subUnits.data?.length > 0 ? subUnits.data.map((item: SubUnit, i: number) => (
                        <TableRow key={item.id}>
                            <TableCell>{subUnits.from + i}</TableCell>
                            <TableCell>{item.nama_layanan}</TableCell>
                            <TableCell>{item.unit?.nama_unit}</TableCell>
                            <TableCell>{item.aktif ? 'Aktif' : 'Nonaktif'}</TableCell>
                            <TableCell>{item.form_fields_count}</TableCell>
                            <TableCell className="space-x-2">
                                <Button variant="outline" size="icon" onClick={() => openEdit(item)}>
                                    <Pencil className="w-4 h-4" />
                                </Button>
                                <Button variant="outline" size="icon" className="text-red-500 hover:text-red-600 hover:bg-red-50" onClick={() => handleDelete(item.id)}>
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

            <Pagination links={subUnits.links} />

            <Dialog open={!!editSubUnit} onOpenChange={(open) => !open && setEditSubUnit(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit Sub Unit</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleEdit} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Unit</Label>
                            <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50" value={data.unit_id} onChange={e => setData('unit_id', e.target.value)}>
                                <option value="">Pilih Unit</option>
                                {units.map(u => (
                                    <option key={u.id} value={u.id}>{u.nama_unit}</option>
                                ))}
                            </select>
                            {errors.unit_id && <p className="text-red-500 text-sm">{errors.unit_id}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label>Nama Layanan</Label>
                            <Input value={data.nama_layanan} onChange={e => setData('nama_layanan', e.target.value)} />
                            {errors.nama_layanan && <p className="text-red-500 text-sm">{errors.nama_layanan}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label>Deskripsi</Label>
                            <Input value={data.deskripsi} onChange={e => setData('deskripsi', e.target.value)} />
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
