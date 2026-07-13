import React, { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { useForm, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/Components/ui/dialog';
import { SearchInput } from '@/Components/SearchInput';
import { Pagination } from '@/Components/Pagination';
import Swal from 'sweetalert2';
import { Pencil, Trash2 } from 'lucide-react';

interface Divisi {
    id: number;
    nama_divisi: string;
}

interface UnitOrganisasi {
    id: number;
    divisi_id: number;
    nama_unit_organisasi: string;
    divisi?: Divisi;
}

export default function UnitOrganisasiIndex({ unitOrganisasis, divisis, filters }: { unitOrganisasis: any; divisis: Divisi[]; filters?: { search?: string; divisi_id?: number } }) {
    const [isAddOpen, setIsAddOpen] = useState(false);
    const [editItem, setEditItem] = useState<UnitOrganisasi | null>(null);
    const [filterDivisi, setFilterDivisi] = useState(filters?.divisi_id?.toString() || '');

    const { data, setData, post, put, delete: destroy, reset, errors } = useForm({
        divisi_id: '',
        nama_unit_organisasi: '',
    });

    const handleAdd = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('admin.master.unit-organisasi.store'), {
            onSuccess: () => {
                setIsAddOpen(false);
                reset();
            }
        });
    };

    const handleEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editItem) {
            put(route('admin.master.unit-organisasi.update', editItem.id), {
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
                destroy(route('admin.master.unit-organisasi.destroy', id));
            }
        });
    };

    const openEdit = (item: UnitOrganisasi) => {
        setEditItem(item);
        setData({
            divisi_id: String(item.divisi_id),
            nama_unit_organisasi: item.nama_unit_organisasi,
        });
    };

    const handleDivisiFilter = (e: React.ChangeEvent<HTMLSelectElement>) => {
        const val = e.target.value;
        setFilterDivisi(val);
        router.reload({ data: { divisi_id: val || undefined }, only: ['unitOrganisasis', 'filters'], });
    };

    return (
        <AdminLayout title="Master Unit Organisasi">
            <div className="flex items-center justify-between mb-4">
                <h2 className="text-2xl font-bold">Master Data Unit Organisasi</h2>
                <Dialog open={isAddOpen} onOpenChange={setIsAddOpen}>
                    <DialogTrigger asChild>
                        <Button onClick={() => reset()}>Tambah Unit Organisasi</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Tambah Unit Organisasi</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={handleAdd} className="space-y-4">
                            <div className="space-y-2">
                                <Label>Divisi</Label>
                                <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50" value={data.divisi_id} onChange={e => setData('divisi_id', e.target.value)}>
                                    <option value="">Pilih Divisi</option>
                                    {divisis.map(d => (
                                        <option key={d.id} value={d.id}>{d.nama_divisi}</option>
                                    ))}
                                </select>
                                {errors.divisi_id && <p className="text-red-500 text-sm">{errors.divisi_id}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label>Nama Unit Organisasi</Label>
                                <Input value={data.nama_unit_organisasi} onChange={e => setData('nama_unit_organisasi', e.target.value)} />
                                {errors.nama_unit_organisasi && <p className="text-red-500 text-sm">{errors.nama_unit_organisasi}</p>}
                            </div>
                            <div className="flex justify-end pt-4"><Button type="submit">Simpan</Button></div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>

            <div className="flex items-center gap-4 mb-4">
                <SearchInput placeholder="Cari unit organisasi..." />
                <select
                    className="border rounded p-2 text-sm"
                    value={filterDivisi}
                    onChange={handleDivisiFilter}
                >
                    <option value="">Semua Divisi</option>
                    {divisis.map(d => (
                        <option key={d.id} value={d.id}>{d.nama_divisi}</option>
                    ))}
                </select>
            </div>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>No</TableHead>
                        <TableHead>Nama Unit Organisasi</TableHead>
                        <TableHead>Divisi</TableHead>
                        <TableHead>Aksi</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {unitOrganisasis.data?.length > 0 ? unitOrganisasis.data.map((item: UnitOrganisasi, i: number) => (
                        <TableRow key={item.id}>
                            <TableCell>{unitOrganisasis.from + i}</TableCell>
                            <TableCell>{item.nama_unit_organisasi}</TableCell>
                            <TableCell>{item.divisi?.nama_divisi}</TableCell>
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
                            <TableCell colSpan={4} className="text-center py-4 text-slate-500">
                                Tidak ada data
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </Table>

            <Pagination links={unitOrganisasis.links} />

            <Dialog open={!!editItem} onOpenChange={(open) => !open && setEditItem(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit Unit Organisasi</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleEdit} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Divisi</Label>
                            <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50" value={data.divisi_id} onChange={e => setData('divisi_id', e.target.value)}>
                                <option value="">Pilih Divisi</option>
                                {divisis.map(d => (
                                    <option key={d.id} value={d.id}>{d.nama_divisi}</option>
                                ))}
                            </select>
                            {errors.divisi_id && <p className="text-red-500 text-sm">{errors.divisi_id}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label>Nama Unit Organisasi</Label>
                            <Input value={data.nama_unit_organisasi} onChange={e => setData('nama_unit_organisasi', e.target.value)} />
                            {errors.nama_unit_organisasi && <p className="text-red-500 text-sm">{errors.nama_unit_organisasi}</p>}
                        </div>
                        <div className="flex justify-end pt-4"><Button type="submit">Update</Button></div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
