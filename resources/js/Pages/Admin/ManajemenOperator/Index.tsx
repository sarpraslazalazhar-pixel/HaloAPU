import React, { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Shield, Pencil, Trash2, Plus, Search, UserCog, Phone } from 'lucide-react';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter,
} from '@/Components/ui/dialog';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from '@/Components/ui/alert-dialog';
import { Badge } from '@/Components/ui/badge';

interface Role {
    id: number;
    name: string;
}

interface SubUnit {
    id: number;
    nama_layanan: string;
    unit?: {
        nama_unit: string;
    }
}

interface Unit {
    id: number;
    nama_unit: string;
}

interface Admin {
    id: number;
    name: string;
    username: string;
    email: string;
    no_wa: string | null;
    created_at: string;
    roles: Role[];
    sub_units: SubUnit[];
    units: Unit[];
}

interface PaginatedData {
    data: Admin[];
    total: number;
    per_page: number;
    from: number;
    to: number;
    current_page: number;
    last_page: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    admins: PaginatedData;
    roles: Role[];
    subUnits: SubUnit[];
    units: Unit[];
    filters: {
        search?: string;
        role?: string;
    };
}

export default function ManajemenOperatorIndex({ admins, roles, subUnits, units, filters }: Props) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Admin | null>(null);
    const [search, setSearch] = useState(filters?.search || '');
    const [roleFilter, setRoleFilter] = useState(filters?.role || '');

    const { data, setData, post, put, processing, errors, reset } = useForm({
        username: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: '',
        name: '',
        no_wa: '',
        sub_units: [] as number[],
        units: [] as number[],
    });

    const openCreate = () => {
        setEditing(null);
        reset();
        setData('role', roles.length > 0 ? roles[0].name : '');
        setOpen(true);
    };

    const openEdit = (admin: Admin) => {
        setEditing(admin);
        setData({
            username: admin.username,
            email: admin.email,
            password: '',
            password_confirmation: '',
            role: admin.roles?.[0]?.name || '',
            name: admin.name || '',
            no_wa: admin.no_wa || '',
            sub_units: admin.sub_units ? admin.sub_units.map(su => su.id) : [],
            units: admin.units ? admin.units.map(u => u.id) : [],
        });
        setOpen(true);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editing) {
            put(route('admin.manajemen-operator.update', editing.id), {
                onSuccess: () => { setOpen(false); reset(); setEditing(null); },
            });
        } else {
            post(route('admin.manajemen-operator.store'), {
                onSuccess: () => { setOpen(false); reset(); setEditing(null); },
            });
        }
    };

    const handleDelete = (admin: Admin) => {
        router.delete(route('admin.manajemen-operator.destroy', admin.id), {
            preserveScroll: true,
        });
    };

    const applyFilters = () => {
        router.get(route('admin.manajemen-operator.index'), {
            search: search || undefined,
            role: roleFilter || undefined,
        }, { preserveState: true });
    };

    const handleRoleFilter = (value: string) => {
        setRoleFilter(value);
        router.get(route('admin.manajemen-operator.index'), {
            search: search || undefined,
            role: value || undefined,
        }, { preserveState: true });
    };

    const resetFilters = () => {
        setSearch('');
        setRoleFilter('');
        router.get(route('admin.manajemen-operator.index'), {}, { preserveState: true });
    };

    const toggleSubUnit = (id: number) => {
        if (data.sub_units.includes(id)) {
            setData('sub_units', data.sub_units.filter(su => su !== id));
        } else {
            setData('sub_units', [...data.sub_units, id]);
        }
    };

    const toggleUnit = (id: number) => {
        if (data.units.includes(id)) {
            setData('units', data.units.filter(u => u !== id));
        } else {
            setData('units', [...data.units, id]);
        }
    };

    return (
        <AdminLayout title="Manajemen Operator">
            <Head title="Manajemen Operator" />
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Manajemen Operator</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            Kelola akun operator dan penugasan jenis layanannya
                        </p>
                    </div>
                    <Button onClick={openCreate} className="gap-2">
                        <Plus className="h-4 w-4" />
                        Tambah Operator
                    </Button>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap gap-2 items-center">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                        <Input
                            placeholder="Cari username, nama atau email..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                            className="pl-9 max-w-xs"
                        />
                    </div>
                    <select
                        className="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                        value={roleFilter}
                        onChange={(e) => handleRoleFilter(e.target.value)}
                    >
                        <option value="">Semua Peran</option>
                        {roles.map(r => (
                            <option key={r.id} value={r.name}>{r.name}</option>
                        ))}
                    </select>
                    <Button variant="outline" size="sm" onClick={applyFilters}>
                        Cari
                    </Button>
                    {(search || roleFilter) && (
                        <Button variant="ghost" size="sm" onClick={resetFilters}>
                            Reset
                        </Button>
                    )}
                </div>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/30 text-left text-muted-foreground">
                                        <th className="p-3 font-medium w-12">#</th>
                                        <th className="p-3 font-medium">Nama / Username</th>
                                        <th className="p-3 font-medium">Kontak</th>
                                        <th className="p-3 font-medium">Peran</th>
                                        <th className="p-3 font-medium">Jenis Layanan Diurus</th>
                                        <th className="p-3 font-medium w-24">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {admins?.data?.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="p-8 text-center text-muted-foreground">
                                                <div className="flex flex-col items-center gap-2">
                                                    <UserCog className="h-10 w-10 text-muted-foreground/50" />
                                                    <p className="font-medium">Tidak ada operator ditemukan</p>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                    {admins?.data?.map((admin: Admin, i: number) => (
                                        <tr key={admin.id} className="border-b last:border-0 hover:bg-muted/50 transition-colors">
                                            <td className="p-3 text-muted-foreground align-top">
                                                {(admins.current_page - 1) * admins.per_page + i + 1}
                                            </td>
                                            <td className="p-3 align-top">
                                                <p className="font-medium">{admin.name}</p>
                                                <p className="text-xs text-muted-foreground">{admin.username}</p>
                                            </td>
                                            <td className="p-3 align-top">
                                                <p>{admin.email}</p>
                                                {admin.no_wa && (
                                                    <p className="text-xs text-muted-foreground flex items-center gap-1 mt-1">
                                                        <Phone className="h-3 w-3" /> {admin.no_wa}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="p-3 align-top">
                                                <Badge variant="outline" className="bg-primary/5 text-primary border-primary/20">
                                                    <Shield className="h-3 w-3 mr-1" />
                                                    {admin.roles?.[0]?.name || '-'}
                                                </Badge>
                                            </td>
                                            <td className="p-3 align-top">
                                                <div className="flex flex-wrap gap-1">
                                                    {admin.sub_units?.map(su => (
                                                        <span key={su.id} className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-800 border border-slate-200">
                                                            {su.nama_layanan}
                                                        </span>
                                                    ))}
                                                    {(!admin.sub_units || admin.sub_units.length === 0) && (
                                                        <span className="text-xs text-slate-400 italic">Tidak ada</span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="p-3 align-top">
                                                <div className="flex gap-1">
                                                    <Button variant="outline" size="icon" onClick={() => openEdit(admin)} className="h-8 w-8">
                                                        <Pencil className="w-3.5 h-3.5" />
                                                    </Button>
                                                    <AlertDialog>
                                                        <AlertDialogTrigger asChild>
                                                            <Button variant="outline" size="icon" className="h-8 w-8 text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30">
                                                                <Trash2 className="w-3.5 h-3.5" />
                                                            </Button>
                                                        </AlertDialogTrigger>
                                                        <AlertDialogContent>
                                                            <AlertDialogHeader>
                                                                <AlertDialogTitle>Hapus Operator?</AlertDialogTitle>
                                                                <AlertDialogDescription>
                                                                    Tindakan ini tidak dapat dibatalkan. Operator {admin.name} akan dihapus dari sistem.
                                                                </AlertDialogDescription>
                                                            </AlertDialogHeader>
                                                            <AlertDialogFooter>
                                                                <AlertDialogCancel>Batal</AlertDialogCancel>
                                                                <AlertDialogAction
                                                                    onClick={() => handleDelete(admin)}
                                                                    className="bg-red-500 hover:bg-red-600 text-white"
                                                                >
                                                                    Hapus
                                                                </AlertDialogAction>
                                                            </AlertDialogFooter>
                                                        </AlertDialogContent>
                                                    </AlertDialog>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {/* Pagination placeholder if needed */}
                    </CardContent>
                </Card>
            </div>

            {/* Modal Form */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-md max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit Operator' : 'Tambah Operator'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nama Lengkap</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Cth: Budi Santoso"
                            />
                            {errors.name && <span className="text-sm text-red-500">{errors.name}</span>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="username">Username</Label>
                            <Input
                                id="username"
                                value={data.username}
                                onChange={(e) => setData('username', e.target.value)}
                                placeholder="Cth: budi123"
                                disabled={!!editing}
                            />
                            {errors.username && <span className="text-sm text-red-500">{errors.username}</span>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="Cth: budi@example.com"
                            />
                            {errors.email && <span className="text-sm text-red-500">{errors.email}</span>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="no_wa">Nomor WA</Label>
                            <Input
                                id="no_wa"
                                value={data.no_wa}
                                onChange={(e) => setData('no_wa', e.target.value)}
                                placeholder="Cth: 08123456789"
                            />
                            {errors.no_wa && <span className="text-sm text-red-500">{errors.no_wa}</span>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="role">Peran</Label>
                            <select
                                id="role"
                                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                value={data.role}
                                onChange={(e) => setData('role', e.target.value)}
                            >
                                <option value="" disabled>Pilih Peran</option>
                                {roles.map(r => (
                                    <option key={r.id} value={r.name}>{r.name}</option>
                                ))}
                            </select>
                            {errors.role && <span className="text-sm text-red-500">{errors.role}</span>}
                        </div>
                        
                        <div className="grid gap-2 border-t pt-4 mt-2">
                            <Label>Penugasan Jenis Layanan</Label>
                            <p className="text-xs text-muted-foreground mb-2">Pilih jenis layanan yang akan diurus oleh operator ini.</p>
                            <div className="max-h-40 overflow-y-auto space-y-2 border rounded-md p-3">
                                {subUnits.map(su => (
                                    <label key={su.id} className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            className="rounded border-slate-300 text-primary focus:ring-primary"
                                            checked={data.sub_units.includes(su.id)}
                                            onChange={() => toggleSubUnit(su.id)}
                                        />
                                        <span className="text-sm text-slate-700">{su.nama_layanan} <span className="text-xs text-slate-400">({su.unit?.nama_unit})</span></span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-2 border-t pt-4 mt-2">
                            <Label>Notifikasi Kanal Layanan (WA & Web)</Label>
                            <p className="text-xs text-muted-foreground mb-2">Pilih Kanal Layanan (Unit) mana saja yang tiketnya akan memicu notifikasi ke operator ini.</p>
                            <div className="max-h-40 overflow-y-auto space-y-2 border rounded-md p-3">
                                {units.map(u => (
                                    <label key={u.id} className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            className="rounded border-slate-300 text-primary focus:ring-primary"
                                            checked={data.units.includes(u.id)}
                                            onChange={() => toggleUnit(u.id)}
                                        />
                                        <span className="text-sm text-slate-700">{u.nama_unit}</span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-2 border-t pt-4 mt-2">
                            <Label htmlFor="password">{editing ? 'Password Baru (opsional)' : 'Password'}</Label>
                            <Input
                                id="password"
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                            />
                            {errors.password && <span className="text-sm text-red-500">{errors.password}</span>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">Konfirmasi Password</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Batal
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Menyimpan...' : 'Simpan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
