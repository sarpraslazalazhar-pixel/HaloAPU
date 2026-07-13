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

interface Admin {
    id: number;
    name: string;
    username: string;
    email: string;
    no_wa: string | null;
    created_at: string;
    roles: { name: string }[];
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
    filters: {
        search?: string;
        role?: string;
    };
}

export default function ManajemenAdminIndex({ admins, filters }: Props) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Admin | null>(null);
    const [search, setSearch] = useState(filters?.search || '');
    const [roleFilter, setRoleFilter] = useState(filters?.role || '');

    const { data, setData, post, put, processing, errors, reset } = useForm({
        username: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: 'Operator',
        name: '',
        no_wa: '',
    });

    const openCreate = () => {
        setEditing(null);
        reset();
        setOpen(true);
    };

    const openEdit = (admin: Admin) => {
        setEditing(admin);
        setData({
            username: admin.username,
            email: admin.email,
            password: '',
            password_confirmation: '',
            role: admin.roles?.[0]?.name || 'Operator',
            name: admin.name || '',
            no_wa: admin.no_wa || '',
        });
        setOpen(true);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editing) {
            put(route('admin.manajemen-admin.update', editing.id), {
                onSuccess: () => { setOpen(false); reset(); setEditing(null); },
            });
        } else {
            post(route('admin.manajemen-admin.store'), {
                onSuccess: () => { setOpen(false); reset(); setEditing(null); },
            });
        }
    };

    const handleDelete = (admin: Admin) => {
        router.delete(route('admin.manajemen-admin.destroy', admin.id), {
            preserveScroll: true,
        });
    };

    const applyFilters = () => {
        router.get(route('admin.manajemen-admin.index'), {
            search: search || undefined,
            role: roleFilter || undefined,
        }, { preserveState: true });
    };

    const handleRoleFilter = (value: string) => {
        setRoleFilter(value);
        router.get(route('admin.manajemen-admin.index'), {
            search: search || undefined,
            role: value || undefined,
        }, { preserveState: true });
    };

    const resetFilters = () => {
        setSearch('');
        setRoleFilter('');
        router.get(route('admin.manajemen-admin.index'), {}, { preserveState: true });
    };

    return (
        <AdminLayout title="Manajemen Admin">
            <Head title="Manajemen Admin" />
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Manajemen Admin</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            Kelola akun admin dan operator sistem
                        </p>
                    </div>
                    <Button onClick={openCreate} className="gap-2">
                        <Plus className="h-4 w-4" />
                        Tambah Admin
                    </Button>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap gap-2 items-center">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                        <Input
                            placeholder="Cari username atau email..."
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
                        <option value="">Semua Role</option>
                        <option value="Admin">Admin</option>
                        <option value="Operator">Operator</option>
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
                                        <th className="p-3 font-medium">Nama</th>
                                        <th className="p-3 font-medium">Username</th>
                                        <th className="p-3 font-medium">Email</th>
                                        <th className="p-3 font-medium">No. WA</th>
                                        <th className="p-3 font-medium">Role</th>
                                        <th className="p-3 font-medium">Dibuat</th>
                                        <th className="p-3 font-medium w-24">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {admins?.data?.length === 0 && (
                                        <tr>
                                            <td colSpan={8} className="p-8 text-center text-muted-foreground">
                                                <div className="flex flex-col items-center gap-2">
                                                    <UserCog className="h-10 w-10 text-muted-foreground/50" />
                                                    <p className="font-medium">Tidak ada admin ditemukan</p>
                                                    <p className="text-xs">Coba ubah filter pencarian atau tambah admin baru</p>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                    {admins?.data?.map((admin: Admin, i: number) => (
                                        <tr key={admin.id} className="border-b last:border-0 hover:bg-muted/50 transition-colors">
                                            <td className="p-3 text-muted-foreground">
                                                {(admins.current_page - 1) * admins.per_page + i + 1}
                                            </td>
                                            <td className="p-3 font-medium">{admin.name || admin.username}</td>
                                            <td className="p-3 text-muted-foreground">{admin.username}</td>
                                            <td className="p-3">{admin.email}</td>
                                            <td className="p-3">
                                                {admin.no_wa ? (
                                                    <span className="flex items-center gap-1.5 text-muted-foreground">
                                                        <Phone className="h-3.5 w-3.5" />
                                                        {admin.no_wa}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground/50">-</span>
                                                )}
                                            </td>
                                            <td className="p-3">
                                                <Badge
                                                    variant={admin.roles?.[0]?.name === 'Admin' ? 'default' : 'secondary'}
                                                    className={admin.roles?.[0]?.name === 'Admin'
                                                        ? 'bg-blue-500/10 text-blue-700 border-blue-200 dark:text-blue-400 dark:border-blue-800'
                                                        : 'bg-green-500/10 text-green-700 border-green-200 dark:text-green-400 dark:border-green-800'
                                                    }
                                                >
                                                    <Shield className="h-3 w-3 mr-1" />
                                                    {admin.roles?.[0]?.name || '-'}
                                                </Badge>
                                            </td>
                                            <td className="p-3 text-xs text-muted-foreground">
                                                {new Date(admin.created_at).toLocaleDateString('id-ID', {
                                                    day: '2-digit',
                                                    month: 'short',
                                                    year: 'numeric',
                                                })}
                                            </td>
                                            <td className="p-3">
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
                                                                <AlertDialogTitle>Hapus Admin?</AlertDialogTitle>
                                                                <AlertDialogDescription>
                                                                    Apakah Anda yakin ingin menghapus admin <strong>"{admin.username}"</strong>? Tindakan ini tidak dapat dibatalkan.
                                                                </AlertDialogDescription>
                                                            </AlertDialogHeader>
                                                            <AlertDialogFooter>
                                                                <AlertDialogCancel>Batal</AlertDialogCancel>
                                                                <AlertDialogAction onClick={() => handleDelete(admin)} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
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
                    </CardContent>
                </Card>

                {/* Pagination */}
                {admins?.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <span className="text-sm text-muted-foreground">
                            Menampilkan {admins.from}–{admins.to} dari {admins.total} admin
                        </span>
                        <div className="flex gap-1">
                            {admins.links?.filter((l) => l.url).map((link, i: number) => (
                                <button
                                    key={i}
                                    onClick={() => router.get(link.url!, {}, { preserveState: true })}
                                    className={`px-3 py-1.5 rounded-md text-xs border transition-colors ${
                                        link.active
                                            ? 'bg-primary text-primary-foreground border-primary'
                                            : 'hover:bg-muted border-input'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Create/Edit Dialog */}
            <Dialog open={open} onOpenChange={(isOpen) => {
                setOpen(isOpen);
                if (!isOpen) { setEditing(null); reset(); }
            }}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Shield className="h-5 w-5" />
                            {editing ? 'Edit Admin' : 'Tambah Admin Baru'}
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-4" autoComplete="off">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="col-span-2">
                                <Label htmlFor="name">Nama Lengkap <span className="text-destructive">*</span></Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="Masukkan nama lengkap"
                                />
                                {errors.name && <p className="text-xs text-destructive mt-1">{errors.name}</p>}
                            </div>
                            <div>
                                <Label htmlFor="username">Username <span className="text-destructive">*</span></Label>
                                <Input
                                    id="username"
                                    value={data.username}
                                    onChange={(e) => setData('username', e.target.value)}
                                    placeholder="Masukkan username"
                                />
                                {errors.username && <p className="text-xs text-destructive mt-1">{errors.username}</p>}
                            </div>
                            <div>
                                <Label htmlFor="email">Email <span className="text-destructive">*</span></Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    placeholder="admin@example.com"
                                />
                                {errors.email && <p className="text-xs text-destructive mt-1">{errors.email}</p>}
                            </div>
                            <div>
                                <Label htmlFor="no_wa">No. WhatsApp</Label>
                                <Input
                                    id="no_wa"
                                    value={data.no_wa}
                                    onChange={(e) => setData('no_wa', e.target.value)}
                                    placeholder="628xxxxxxxxxx"
                                />
                                {errors.no_wa && <p className="text-xs text-destructive mt-1">{errors.no_wa}</p>}
                            </div>
                            <div>
                                <Label htmlFor="role">Role <span className="text-destructive">*</span></Label>
                                <select
                                    id="role"
                                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                    value={data.role}
                                    onChange={(e) => setData('role', e.target.value)}
                                >
                                    <option value="Admin">Admin</option>
                                    <option value="Operator">Operator</option>
                                </select>
                                {errors.role && <p className="text-xs text-destructive mt-1">{errors.role}</p>}
                            </div>
                            <div>
                                <Label htmlFor="password">
                                    Password {editing ? <span className="text-muted-foreground font-normal">(kosongkan jika tidak diubah)</span> : <span className="text-destructive">*</span>}
                                </Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    placeholder={editing ? '••••••••' : 'Min. 8 karakter'}
                                    autoComplete="new-password"
                                />
                                {errors.password && <p className="text-xs text-destructive mt-1">{errors.password}</p>}
                            </div>
                            <div>
                                <Label htmlFor="password_confirmation">Konfirmasi Password</Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    placeholder="Ulangi password"
                                    autoComplete="new-password"
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => { setOpen(false); setEditing(null); reset(); }}>
                                Batal
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Menyimpan...' : (editing ? 'Update' : 'Simpan')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
