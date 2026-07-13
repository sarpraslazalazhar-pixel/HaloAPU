import React, { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { useForm } from '@inertiajs/react';
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
    org_units_count: number;
}

export default function DivisiIndex({ divisis, filters }: { divisis: any; filters?: { search?: string } }) {
    const [isAddOpen, setIsAddOpen] = useState(false);
    const [editItem, setEditItem] = useState<Divisi | null>(null);

    const { data, setData, post, put, delete: destroy, reset, errors } = useForm({
        nama_divisi: '',
    });

    const handleAdd = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('admin.master.divisi.store'), {
            onSuccess: () => {
                setIsAddOpen(false);
                reset();
            }
        });
    };

    const handleEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editItem) {
            put(route('admin.master.divisi.update', editItem.id), {
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
                destroy(route('admin.master.divisi.destroy', id));
            }
        });
    };

    const openEdit = (item: Divisi) => {
        setEditItem(item);
        setData({
            nama_divisi: item.nama_divisi,
        });
    };

    return (
        <AdminLayout title="Master Divisi">
            <div className="flex items-center justify-between mb-4">
                <h2 className="text-2xl font-bold">Master Data Divisi</h2>
                <Dialog open={isAddOpen} onOpenChange={setIsAddOpen}>
                    <DialogTrigger asChild>
                        <Button onClick={() => reset()}>Tambah Divisi</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Tambah Divisi</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={handleAdd} className="space-y-4">
                            <div className="space-y-2">
                                <Label>Nama Divisi</Label>
                                <Input value={data.nama_divisi} onChange={e => setData('nama_divisi', e.target.value)} />
                                {errors.nama_divisi && <p className="text-red-500 text-sm">{errors.nama_divisi}</p>}
                            </div>
                            <div className="flex justify-end pt-4"><Button type="submit">Simpan</Button></div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>

            <div className="mb-4">
                <SearchInput placeholder="Cari divisi..." />
            </div>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>No</TableHead>
                        <TableHead>Nama Divisi</TableHead>
                        <TableHead>Jml Unit Organisasi</TableHead>
                        <TableHead>Aksi</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {divisis.data?.length > 0 ? divisis.data.map((item: Divisi, i: number) => (
                        <TableRow key={item.id}>
                            <TableCell>{divisis.from + i}</TableCell>
                            <TableCell>{item.nama_divisi}</TableCell>
                            <TableCell>{item.org_units_count}</TableCell>
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

            <Pagination links={divisis.links} />

            <Dialog open={!!editItem} onOpenChange={(open) => !open && setEditItem(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit Divisi</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleEdit} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Nama Divisi</Label>
                            <Input value={data.nama_divisi} onChange={e => setData('nama_divisi', e.target.value)} />
                            {errors.nama_divisi && <p className="text-red-500 text-sm">{errors.nama_divisi}</p>}
                        </div>
                        <div className="flex justify-end pt-4"><Button type="submit">Update</Button></div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
