import React, { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { Plus, Edit2, Trash2, Shield } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle,
} from '@/Components/ui/dialog';
import Swal from 'sweetalert2';

interface Permission {
    id: number;
    name: string;
}

interface Role {
    id: number;
    name: string;
    permissions: Permission[];
}

interface Props {
    roles: Role[];
    permissions: Permission[];
}

export default function Index({ roles, permissions }: Props) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingRole, setEditingRole] = useState<Role | null>(null);

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        name: '',
        permissions: [] as string[],
    });

    const PERMISSION_GROUPS = [
        { label: 'Layanan (Kanal Layanan, Jenis Layanan)', value: 'akses-layanan' },
        { label: 'Struktur (Divisi, Sub Divisi, Jabatan)', value: 'akses-struktur' },
        { label: 'Konfigurasi (Form, SLA, Reminder, Sistem)', value: 'akses-konfigurasi' },
        { label: 'Laporan (CSAT, Tiket)', value: 'akses-laporan' },
        { label: 'Manajemen Akun (Peran, Operator, Pengguna)', value: 'akses-manajemen-akun' },
    ];

    const openModal = (role?: Role) => {
        clearErrors();
        if (role) {
            setEditingRole(role);
            setData({
                name: role.name,
                permissions: role.permissions.map(p => p.name),
            });
        } else {
            setEditingRole(null);
            reset();
        }
        setIsModalOpen(true);
    };

    const closeModal = () => {
        setIsModalOpen(false);
        setEditingRole(null);
        reset();
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingRole) {
            put(route('admin.manajemen-peran.update', editingRole.id), {
                onSuccess: () => closeModal(),
            });
        } else {
            post(route('admin.manajemen-peran.store'), {
                onSuccess: () => closeModal(),
            });
        }
    };

    const handleDelete = (role: Role) => {
        if (role.name === 'Super Admin') return;
        
        Swal.fire({
            title: 'Hapus Role?',
            text: `Apakah Anda yakin ingin menghapus role ${role.name}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(route('admin.manajemen-peran.destroy', role.id));
            }
        });
    };

    const handlePermissionChange = (permName: string, checked: boolean) => {
        if (checked) {
            setData('permissions', [...data.permissions, permName]);
        } else {
            setData('permissions', data.permissions.filter(p => p !== permName));
        }
    };

    return (
        <AdminLayout title="Manajemen Peran">
            <div className="space-y-6">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Manajemen Peran</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                            Kelola role dan hak akses operator ke modul-modul sistem.
                        </p>
                    </div>
                    <Button onClick={() => openModal()} className="w-full sm:w-auto gap-2">
                        <Plus className="h-4 w-4" />
                        Tambah Peran
                    </Button>
                </div>

                <div className="rounded-xl border bg-white dark:bg-zinc-950/50 shadow-sm overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm text-left">
                            <thead className="bg-slate-50 dark:bg-zinc-900/50 text-slate-600 dark:text-slate-400 font-medium border-b">
                                <tr>
                                    <th className="px-6 py-4">Nama Peran</th>
                                    <th className="px-6 py-4">Hak Akses (Modul)</th>
                                    <th className="px-6 py-4 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {roles.map((role) => (
                                    <tr key={role.id} className="hover:bg-slate-50 dark:hover:bg-zinc-900/50 transition-colors">
                                        <td className="px-6 py-4 font-medium text-slate-900 dark:text-slate-100">
                                            {role.name}
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex flex-wrap gap-2">
                                                {role.permissions.map(p => (
                                                    <span key={p.id} className="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-primary/10 text-primary border border-primary/20">
                                                        {p.name.replace('akses-', '')}
                                                    </span>
                                                ))}
                                                {role.permissions.length === 0 && <span className="text-slate-400 italic">Tidak ada akses khusus</span>}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            {role.name !== 'Super Admin' ? (
                                                <div className="flex justify-end gap-2">
                                                    <Button variant="ghost" size="icon" onClick={() => openModal(role)} className="h-8 w-8 text-slate-500 hover:text-blue-600">
                                                        <Edit2 className="h-4 w-4" />
                                                    </Button>
                                                    <Button variant="ghost" size="icon" onClick={() => handleDelete(role)} className="h-8 w-8 text-slate-500 hover:text-red-600">
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            ) : (
                                                <span className="text-xs text-slate-400 italic">Default</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {roles.length === 0 && (
                                    <tr>
                                        <td colSpan={3} className="px-6 py-8 text-center text-slate-500">
                                            Tidak ada data peran.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <Dialog open={isModalOpen} onOpenChange={setIsModalOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {editingRole ? 'Edit Peran' : 'Tambah Peran'}
                        </DialogTitle>
                        <p className="text-sm text-slate-500">Isi formulir di bawah ini untuk menyimpan data.</p>
                    </DialogHeader>

                    <form onSubmit={handleSubmit} className="space-y-6 pt-4">
                        <div className="space-y-2">
                            <label className="text-sm font-medium text-slate-700 dark:text-slate-300">Nama Peran <span className="text-red-500">*</span></label>
                            <Input
                                value={data.name}
                                onChange={e => setData('name', e.target.value)}
                                placeholder="Contoh: Manajer IT"
                                required
                            />
                            {errors.name && <p className="text-red-500 text-sm mt-1">{errors.name}</p>}
                        </div>

                        <div className="space-y-3">
                            <label className="text-sm font-medium text-slate-700 dark:text-slate-300">Hak Akses Modul</label>
                            <div className="grid gap-3 p-4 border rounded-lg bg-slate-50 dark:bg-zinc-900/50">
                                {PERMISSION_GROUPS.map((group) => (
                                    <label key={group.value} className="flex items-start gap-3 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            className="mt-1 rounded border-slate-300 text-primary focus:ring-primary"
                                            checked={data.permissions.includes(group.value)}
                                            onChange={(e) => handlePermissionChange(group.value, e.target.checked)}
                                        />
                                        <div>
                                            <p className="text-sm font-medium text-slate-900 dark:text-slate-100">{group.label}</p>
                                        </div>
                                    </label>
                                ))}
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 pt-4 border-t">
                            <Button type="button" variant="outline" onClick={closeModal}>Batal</Button>
                            <Button type="submit" disabled={processing}>Simpan</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
