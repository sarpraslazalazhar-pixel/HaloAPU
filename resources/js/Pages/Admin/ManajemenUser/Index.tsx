import React, { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Users, Trash2 } from 'lucide-react';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger, DialogFooter,
} from '@/Components/ui/dialog';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from '@/Components/ui/alert-dialog';

export default function ManajemenUserIndex({ users, filters, divisiList, unitOrgList, jabatanList }: any) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<any>(null);
    const [search, setSearch] = useState(filters?.search || '');

    const { data, setData, post, put, processing, errors, reset } = useForm({
        username: '',
        email: '',
        password: '',
        password_confirmation: '',
        no_wa: '',
        divisi_id: '',
        org_unit_id: '',
        jabatan_id: '',
    });

    const openCreate = () => {
        setEditing(null);
        reset();
        setOpen(true);
    };

    const openEdit = (user: any) => {
        setEditing(user);
        setData({
            username: user.username,
            email: user.email,
            password: '',
            password_confirmation: '',
            no_wa: user.no_wa || '',
            divisi_id: user.divisi_id || '',
            org_unit_id: user.org_unit_id || '',
            jabatan_id: user.jabatan_id || '',
        });
        setOpen(true);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const method = editing ? put : post;
        const routeName = editing ? route('admin.manajemen-user.update', editing.id) : route('admin.manajemen-user.store');
        method(routeName, {
            onSuccess: () => { setOpen(false); reset(); setEditing(null); },
        });
    };

    const handleDelete = (user: any) => {
        router.delete(route('admin.manajemen-user.destroy', user.id), { preserveScroll: true });
    };

    const applySearch = () => {
        router.get(route('admin.manajemen-user.index'), { search }, { });
    };

    const applyFilter = (key: string, value: string) => {
        const params = { ...filters, [key]: value || undefined };
        router.get(route('admin.manajemen-user.index'), params, { });
    };

    return (
        <AdminLayout title="Manajemen User">
            <Head title="Manajemen User" />
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Manajemen User</h1>
                    <Dialog open={open} onOpenChange={setOpen}>
                        <DialogTrigger asChild>
                            <Button onClick={openCreate}>
                                <Users className="h-4 w-4 mr-2" />
                                Tambah User
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="sm:max-w-lg">
                            <DialogHeader>
                                <DialogTitle>{editing ? 'Edit User' : 'Tambah User Baru'}</DialogTitle>
                            </DialogHeader>
                            <form onSubmit={handleSubmit} className="grid grid-cols-2 gap-4">
                                <div className="col-span-2">
                                    <Label>Nama Lengkap</Label>
                                    <Input value={data.username} onChange={(e) => setData('username', e.target.value)} />
                                    {errors.username && <p className="text-xs text-destructive mt-1">{errors.username}</p>}
                                </div>
                                <div>
                                    <Label>Username</Label>
                                    <Input value={data.username} onChange={(e) => setData('username', e.target.value)} />
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
                                    <Label>Divisi</Label>
                                    <select className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={data.divisi_id} onChange={(e) => setData('divisi_id', e.target.value)}>
                                        <option value="">Pilih Divisi</option>
                                        {divisiList?.map((d: any) => (
                                            <option key={d.id} value={d.id}>{d.nama_divisi}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <Label>Unit Organisasi</Label>
                                    <select className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={data.org_unit_id} onChange={(e) => setData('org_unit_id', e.target.value)}>
                                        <option value="">Pilih Unit</option>
                                        {unitOrgList?.map((u: any) => (
                                            <option key={u.id} value={u.id}>{u.nama_unit_organisasi}</option>
                                        ))}
                                    </select>
                                </div>
                                <div className="col-span-2">
                                    <Label>Jabatan</Label>
                                    <select className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm" value={data.jabatan_id} onChange={(e) => setData('jabatan_id', e.target.value)}>
                                        <option value="">Pilih Jabatan</option>
                                        {jabatanList?.map((j: any) => (
                                            <option key={j.id} value={j.id}>{j.nama_jabatan}</option>
                                        ))}
                                    </select>
                                </div>
                                <DialogFooter className="col-span-2">
                                    <Button type="button" variant="ghost" onClick={() => { setOpen(false); setEditing(null); }}>Batal</Button>
                                    <Button type="submit" disabled={processing}>{editing ? 'Update' : 'Simpan'}</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                <div className="flex flex-wrap gap-2">
                    <Input
                        placeholder="Cari nama, email, atau username..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && applySearch()}
                        className="max-w-xs"
                    />
                    <select className="rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={filters?.divisi_id || ''} onChange={(e) => applyFilter('divisi_id', e.target.value)}>
                        <option value="">Semua Divisi</option>
                        {divisiList?.map((d: any) => (
                            <option key={d.id} value={d.id}>{d.nama_divisi}</option>
                        ))}
                    </select>
                    <Button variant="outline" onClick={() => router.get(route('admin.manajemen-user.index'), {}, { })}>Reset</Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <th className="p-3 font-medium">#</th>
                                        <th className="p-3 font-medium">Nama</th>
                                        <th className="p-3 font-medium">Email</th>
                                        <th className="p-3 font-medium">No. WA</th>
                                        <th className="p-3 font-medium">Divisi</th>
                                        <th className="p-3 font-medium">Unit</th>
                                        <th className="p-3 font-medium">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {users?.data?.map((user: any, i: number) => (
                                        <tr key={user.id} className="border-b last:border-0 hover:bg-muted/50">
                                            <td className="p-3">{user.id}</td>
                                            <td className="p-3 font-medium">{user.username}</td>
                                            <td className="p-3">{user.email}</td>
                                            <td className="p-3">{user.no_wa || '-'}</td>
                                            <td className="p-3">{user.divisi?.nama_divisi || '-'}</td>
                                            <td className="p-3">{user.org_unit?.nama_unit_organisasi || '-'}</td>
                                            <td className="p-3">
                                                <div className="flex gap-1">
                                                    <Button variant="ghost" size="sm" onClick={() => openEdit(user)}>Edit</Button>
                                                    <AlertDialog>
                                                        <AlertDialogTrigger asChild>
                                                            <Button variant="ghost" size="sm" className="text-destructive">
                                                                <Trash2 className="h-4 w-4" />
                                                            </Button>
                                                        </AlertDialogTrigger>
                                                        <AlertDialogContent>
                                                            <AlertDialogHeader>
                                                                <AlertDialogTitle>Hapus User?</AlertDialogTitle>
                                                                <AlertDialogDescription>
                                                                    Apakah Anda yakin ingin menghapus user "{user.username}"? Tindakan ini tidak dapat dibatalkan.
                                                                </AlertDialogDescription>
                                                            </AlertDialogHeader>
                                                            <AlertDialogFooter>
                                                                <AlertDialogCancel>Batal</AlertDialogCancel>
                                                                <AlertDialogAction onClick={() => handleDelete(user)} className="bg-destructive text-destructive-foreground">Hapus</AlertDialogAction>
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

                {users?.total > users?.per_page && (
                    <div className="flex items-center justify-between">
                        <span className="text-sm text-muted-foreground">{users.from}-{users.to} dari {users.total}</span>
                        <div className="flex gap-1">
                            {users.links?.filter((l: any) => l.url).map((link: any, i: number) => (
                                <button key={i} onClick={() => router.get(link.url, {}, { })}
                                    className={`px-2 py-1 rounded text-xs border ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}
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
