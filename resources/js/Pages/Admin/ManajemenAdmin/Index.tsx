import React, { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Shield, Trash2 } from 'lucide-react';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger, DialogFooter,
} from '@/Components/ui/dialog';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from '@/Components/ui/alert-dialog';
import { Badge } from '@/Components/ui/badge';

export default function ManajemenAdminIndex({ admins, filters }: any) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<any>(null);
    const [search, setSearch] = useState(filters?.search || '');

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

    const openEdit = (admin: any) => {
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
        const method = editing ? put : post;
        const routeName = editing ? route('admin.manajemen-admin.update', editing.id) : route('admin.manajemen-admin.store');
        method(routeName, {
            onSuccess: () => { setOpen(false); reset(); setEditing(null); },
        });
    };

    const handleDelete = (admin: any) => {
        router.delete(route('admin.manajemen-admin.destroy', admin.id), {
            preserveScroll: true,
        });
    };

    const applySearch = () => {
        router.get(route('admin.manajemen-admin.index'), { search, role: filters?.role }, { });
    };

    return (
        <AdminLayout title="Manajemen Admin">
            <Head title="Manajemen Admin" />
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Manajemen Admin</h1>
                    <Dialog open={open} onOpenChange={setOpen}>
                        <DialogTrigger asChild>
                            <Button onClick={openCreate}>
                                <Shield className="h-4 w-4 mr-2" />
                                Tambah Admin
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="sm:max-w-lg">
                            <DialogHeader>
                                <DialogTitle>{editing ? 'Edit Admin' : 'Tambah Admin Baru'}</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <Label>Nama Lengkap</Label>
                                    <Input value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                    {errors.name && <p className="text-xs text-destructive mt-1">{errors.name}</p>}
                                </div>
                                <div>
                                    <Label>Username</Label>
                                    <Input value={data.username} onChange={(e) => setData('username', e.target.value)} />
                                    {errors.username && <p className="text-xs text-destructive mt-1">{errors.username}</p>}
                                </div>
                                <div>
                                    <Label>Email</Label>
                                    <Input type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                                    {errors.email && <p className="text-xs text-destructive mt-1">{errors.email}</p>}
                                </div>
                                <div>
                                    <Label>No. WhatsApp</Label>
                                    <Input value={data.no_wa} onChange={(e) => setData('no_wa', e.target.value)} />
                                </div>
                                <div>
                                    <Label>Password {editing && '(kosongkan jika tidak diubah)'}</Label>
                                    <Input type="password" value={data.password} onChange={(e) => setData('password', e.target.value)} />
                                    {errors.password && <p className="text-xs text-destructive mt-1">{errors.password}</p>}
                                </div>
                                <div>
                                    <Label>Konfirmasi Password</Label>
                                    <Input type="password" value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} />
                                </div>
                                <div>
                                    <Label>Role</Label>
                                    <select className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={data.role} onChange={(e) => setData('role', e.target.value)}>
                                        <option value="Admin">Admin</option>
                                        <option value="Operator">Operator</option>
                                    </select>
                                </div>
                                <DialogFooter>
                                    <Button type="button" variant="ghost" onClick={() => { setOpen(false); setEditing(null); }}>Batal</Button>
                                    <Button type="submit" disabled={processing}>{editing ? 'Update' : 'Simpan'}</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <div className="flex gap-2">
                    <Input
                        placeholder="Cari username atau email..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && applySearch()}
                        className="max-w-xs"
                    />
                    <Button variant="outline" onClick={applySearch}>Cari</Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <th className="p-3 font-medium">#</th>
                                        <th className="p-3 font-medium">Nama</th>
                                        <th className="p-3 font-medium">Username</th>
                                        <th className="p-3 font-medium">Email</th>
                                        <th className="p-3 font-medium">Role</th>
                                        <th className="p-3 font-medium">Dibuat</th>
                                        <th className="p-3 font-medium">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {admins?.data?.map((admin: any, i: number) => (
                                        <tr key={admin.id} className="border-b last:border-0 hover:bg-muted/50">
                                            <td className="p-3">{admin.id}</td>
                                            <td className="p-3 font-medium">{admin.name || admin.username}</td>
                                            <td className="p-3">{admin.username}</td>
                                            <td className="p-3">{admin.email}</td>
                                            <td className="p-3">
                                                <Badge variant={admin.roles?.[0]?.name === 'Admin' ? 'default' : 'secondary'}>
                                                    {admin.roles?.[0]?.name || '-'}
                                                </Badge>
                                            </td>
                                            <td className="p-3 text-xs">{new Date(admin.created_at).toLocaleDateString('id-ID')}</td>
                                            <td className="p-3">
                                                <div className="flex gap-1">
                                                    <Button variant="ghost" size="sm" onClick={() => openEdit(admin)}>Edit</Button>
                                                    <AlertDialog>
                                                        <AlertDialogTrigger asChild>
                                                            <Button variant="ghost" size="sm" className="text-destructive">
                                                                <Trash2 className="h-4 w-4" />
                                                            </Button>
                                                        </AlertDialogTrigger>
                                                        <AlertDialogContent>
                                                            <AlertDialogHeader>
                                                                <AlertDialogTitle>Hapus Admin?</AlertDialogTitle>
                                                                <AlertDialogDescription>
                                                                    Apakah Anda yakin ingin menghapus admin "{admin.username}"? Tindakan ini tidak dapat dibatalkan.
                                                                </AlertDialogDescription>
                                                            </AlertDialogHeader>
                                                            <AlertDialogFooter>
                                                                <AlertDialogCancel>Batal</AlertDialogCancel>
                                                                <AlertDialogAction onClick={() => handleDelete(admin)} className="bg-destructive text-destructive-foreground">Hapus</AlertDialogAction>
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

                {admins?.total > admins?.per_page && (
                    <div className="flex items-center justify-between">
                        <span className="text-sm text-muted-foreground">
                            {admins.from}-{admins.to} dari {admins.total}
                        </span>
                        <div className="flex gap-1">
                            {admins.links?.filter((l: any) => l.url).map((link: any, i: number) => (
                                <button
                                    key={i}
                                    onClick={() => router.get(link.url, {}, { })}
                                    className={`px-2 py-1 rounded text-xs border ${
                                        link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
