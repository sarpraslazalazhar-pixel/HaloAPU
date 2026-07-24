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
    form_fields?: any[];
    is_monitored: boolean;
    monitor_kategori?: string;
    monitor_asset_field_id?: number;
    monitor_date_field_id?: number;
    monitor_end_date_field_id?: number;
    monitor_start_field_id?: number;
    monitor_end_field_id?: number;
    is_revision_enabled: boolean;
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
        is_monitored: false,
        monitor_kategori: '',
        monitor_asset_field_id: '',
        monitor_date_field_id: '',
        monitor_end_date_field_id: '',
        monitor_start_field_id: '',
        monitor_end_field_id: '',
        is_revision_enabled: false,
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
            is_monitored: subUnit.is_monitored || false,
            monitor_kategori: subUnit.monitor_kategori || '',
            monitor_asset_field_id: subUnit.monitor_asset_field_id?.toString() || '',
            monitor_date_field_id: subUnit.monitor_date_field_id?.toString() || '',
            monitor_end_date_field_id: subUnit.monitor_end_date_field_id?.toString() || '',
            monitor_start_field_id: subUnit.monitor_start_field_id?.toString() || '',
            monitor_end_field_id: subUnit.monitor_end_field_id?.toString() || '',
            is_revision_enabled: subUnit.is_revision_enabled || false,
        });
    };

    const handleUnitFilter = (e: React.ChangeEvent<HTMLSelectElement>) => {
        const val = e.target.value;
        setFilterUnit(val);
        router.reload({ data: { unit_id: val || undefined }, only: ['subUnits', 'filters'], });
    };

    return (
        <AdminLayout title="Jenis Layanan">
            <div className="flex items-center justify-between mb-4">
                <h2 className="text-2xl font-bold">Jenis Layanan</h2>
                <Dialog open={isAddOpen} onOpenChange={setIsAddOpen}>
                    <DialogTrigger asChild>
                        <Button onClick={() => reset()}>Tambah Jenis Layanan</Button>
                    </DialogTrigger>
                    <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>Tambah Jenis Layanan</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={handleAdd} className="space-y-4">
                            <div className="space-y-2">
                                <Label>Kanal Layanan</Label>
                                <select className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50" value={data.unit_id} onChange={e => setData('unit_id', e.target.value)}>
                                    <option value="">Pilih Kanal Layanan</option>
                                    {units.map(u => (
                                        <option key={u.id} value={u.id}>{u.nama_unit}</option>
                                    ))}
                                </select>
                                {errors.unit_id && <p className="text-red-500 text-sm">{errors.unit_id}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label>Nama Jenis Layanan</Label>
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
                            <div className="space-y-2">
                                <Label>Aktifkan Fitur Revisi Pengajuan?</Label>
                                <p className="text-xs text-slate-500 mb-1">Jika ya, admin bisa meminta user review dan user bisa meminta revisi.</p>
                                <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring" value={data.is_revision_enabled ? '1' : '0'} onChange={e => setData('is_revision_enabled', e.target.value === '1')}>
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
                    <option value="">Semua Kanal Layanan</option>
                    {units.map(u => (
                        <option key={u.id} value={u.id}>{u.nama_unit}</option>
                    ))}
                </select>
            </div>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>No</TableHead>
                        <TableHead>Nama Jenis Layanan</TableHead>
                        <TableHead>Kanal Layanan</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Revisi</TableHead>
                        <TableHead>Live Monitor</TableHead>
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
                            <TableCell>{item.is_revision_enabled ? <span className="text-green-600 font-medium">Ya</span> : '-'}</TableCell>
                            <TableCell>{item.is_monitored ? <span className="bg-green-100 text-green-700 px-2 py-1 rounded text-xs">Aktif</span> : '-'}</TableCell>
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
                            <TableCell colSpan={7} className="text-center py-4 text-slate-500">
                                Tidak ada data
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </Table>

            <Pagination links={subUnits.links} />

            <Dialog open={!!editSubUnit} onOpenChange={(open) => !open && setEditSubUnit(null)}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Edit Jenis Layanan</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleEdit} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Kanal Layanan</Label>
                            <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50" value={data.unit_id} onChange={e => setData('unit_id', e.target.value)}>
                                <option value="">Pilih Kanal Layanan</option>
                                {units.map(u => (
                                    <option key={u.id} value={u.id}>{u.nama_unit}</option>
                                ))}
                            </select>
                            {errors.unit_id && <p className="text-red-500 text-sm">{errors.unit_id}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label>Nama Jenis Layanan</Label>
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
                        <div className="space-y-2">
                            <Label>Aktifkan Fitur Revisi Pengajuan?</Label>
                            <p className="text-xs text-slate-500 mb-1">Jika ya, admin bisa meminta user review dan user bisa meminta revisi.</p>
                            <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring" value={data.is_revision_enabled ? '1' : '0'} onChange={e => setData('is_revision_enabled', e.target.value === '1')}>
                                <option value="1">Ya</option>
                                <option value="0">Tidak</option>
                            </select>
                        </div>
                        
                        <div className="border-t pt-4 mt-4">
                            <h4 className="font-medium text-sm mb-4">Pengaturan Live Monitor</h4>
                            <div className="space-y-4">
                                <div className="space-y-2">
                                    <Label>Tampilkan di Live Monitor?</Label>
                                    <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring" value={data.is_monitored ? '1' : '0'} onChange={e => setData('is_monitored', e.target.value === '1')}>
                                        <option value="1">Ya</option>
                                        <option value="0">Tidak</option>
                                    </select>
                                </div>
                                
                                {data.is_monitored && (
                                    <>
                                        <div className="space-y-2">
                                            <Label>Kategori (misal: "Kendaraan", "Proyektor")</Label>
                                            <Input value={data.monitor_kategori} onChange={e => setData('monitor_kategori', e.target.value)} placeholder="Contoh: Ruang Rapat" />
                                        </div>
                                        {editSubUnit?.form_fields?.length ? (
                                            <>
                                                <div className="space-y-2">
                                                    <Label>Field Form: Nama Aset</Label>
                                                    <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring" value={data.monitor_asset_field_id} onChange={e => setData('monitor_asset_field_id', e.target.value)}>
                                                        <option value="">-- Pilih Field --</option>
                                                        {editSubUnit.form_fields.map((f: any) => (
                                                            <option key={f.id} value={f.id}>{f.label}</option>
                                                        ))}
                                                    </select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Field Form: Tanggal Pemakaian (Mulai)</Label>
                                                    <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring" value={data.monitor_date_field_id || ''} onChange={e => setData('monitor_date_field_id', e.target.value)}>
                                                        <option value="">-- Gunakan Waktu Mulai --</option>
                                                        {editSubUnit.form_fields.map((f: any) => (
                                                            <option key={f.id} value={f.id}>{f.label}</option>
                                                        ))}
                                                    </select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Field Form: Tanggal Selesai (Opsional)</Label>
                                                    <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring" value={data.monitor_end_date_field_id || ''} onChange={e => setData('monitor_end_date_field_id', e.target.value)}>
                                                        <option value="">-- Sama Dengan Tanggal Mulai --</option>
                                                        {editSubUnit.form_fields.map((f: any) => (
                                                            <option key={f.id} value={f.id}>{f.label}</option>
                                                        ))}
                                                    </select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Field Form: Waktu Mulai</Label>
                                                    <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring" value={data.monitor_start_field_id} onChange={e => setData('monitor_start_field_id', e.target.value)}>
                                                        <option value="">-- Pilih Field --</option>
                                                        {editSubUnit.form_fields.map((f: any) => (
                                                            <option key={f.id} value={f.id}>{f.label}</option>
                                                        ))}
                                                    </select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Field Form: Waktu Selesai</Label>
                                                    <select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring" value={data.monitor_end_field_id} onChange={e => setData('monitor_end_field_id', e.target.value)}>
                                                        <option value="">-- Pilih Field --</option>
                                                        {editSubUnit.form_fields.map((f: any) => (
                                                            <option key={f.id} value={f.id}>{f.label}</option>
                                                        ))}
                                                    </select>
                                                </div>
                                            </>
                                        ) : (
                                            <p className="text-sm text-amber-600 bg-amber-50 p-2 rounded">Silakan buat Form Field terlebih dahulu sebelum memilih mapping.</p>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>

                        <div className="flex justify-end pt-4"><Button type="submit">Update</Button></div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
