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
import { DynamicIcon, POPULAR_ICONS } from '@/Components/DynamicIcon';

interface Unit {
    id: number;
    nama_unit: string;
    icon?: string | null;
    deskripsi: string | null;
    aktif: boolean;
    sub_units_count: number;
    created_at: string;
}

function IconPicker({ selectedIcon, onSelect }: { selectedIcon: string; onSelect: (icon: string) => void }) {
    const [search, setSearch] = useState('');

    const filteredIcons = POPULAR_ICONS.filter(icon => 
        icon.toLowerCase().includes(search.toLowerCase())
    );

    return (
        <div className="space-y-2 border rounded-lg p-3 bg-muted/30">
            <div className="flex items-center justify-between gap-2">
                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Pilih Ikon Kanal Layanan</Label>
                {selectedIcon && (
                    <div className="flex items-center gap-1.5 text-xs text-sky-600 font-medium">
                        <span>Ikon Terpilih:</span>
                        <div className="p-1 bg-sky-500/10 rounded border border-sky-200">
                            <DynamicIcon name={selectedIcon} className="w-4 h-4" />
                        </div>
                    </div>
                )}
            </div>
            
            <Input 
                placeholder="Cari ikon (misal: Building, Wrench, Shield, Laptop)..." 
                value={search} 
                onChange={e => setSearch(e.target.value)} 
                className="h-8 text-xs mb-2"
            />

            <div className="grid grid-cols-7 sm:grid-cols-9 gap-1.5 max-h-36 overflow-y-auto p-1 border rounded bg-background">
                {filteredIcons.map(iconName => {
                    const isSelected = selectedIcon === iconName;
                    return (
                        <button
                            key={iconName}
                            type="button"
                            onClick={() => onSelect(isSelected ? '' : iconName)}
                            title={iconName}
                            className={`flex items-center justify-center p-2 rounded-md transition-all ${
                                isSelected 
                                    ? 'bg-sky-500 text-white shadow-sm ring-2 ring-sky-500 ring-offset-1' 
                                    : 'hover:bg-muted text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            <DynamicIcon name={iconName} className="w-4 h-4" />
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

export default function UnitIndex({ units, filters }: { units: any; filters?: { search?: string } }) {
    const [isAddOpen, setIsAddOpen] = useState(false);
    const [editUnit, setEditUnit] = useState<Unit | null>(null);

    const { data, setData, post, put, delete: destroy, reset, errors } = useForm({
        nama_unit: '',
        icon: '',
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
            icon: unit.icon || '',
            deskripsi: unit.deskripsi || '',
            aktif: unit.aktif,
        });
    };

    return (
        <AdminLayout title="Kanal Layanan">
            <div className="flex items-center justify-between mb-4">
                <h2 className="text-2xl font-bold">Kanal Layanan</h2>
                <Dialog open={isAddOpen} onOpenChange={setIsAddOpen}>
                    <DialogTrigger asChild>
                        <Button onClick={() => reset()}>Tambah Kanal Layanan</Button>
                    </DialogTrigger>
                    <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>Tambah Kanal Layanan</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={handleAdd} className="space-y-4">
                            <div className="space-y-2">
                                <Label>Nama Kanal Layanan</Label>
                                <Input value={data.nama_unit} onChange={e => setData('nama_unit', e.target.value)} />
                                {errors.nama_unit && <p className="text-red-500 text-sm">{errors.nama_unit}</p>}
                            </div>

                            <IconPicker selectedIcon={data.icon} onSelect={icon => setData('icon', icon)} />

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
                        <TableHead>Nama Kanal Layanan</TableHead>
                        <TableHead>Deskripsi</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Jml Jenis Layanan</TableHead>
                        <TableHead>Aksi</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {units.data?.length > 0 ? units.data.map((unit: Unit, i: number) => (
                        <TableRow key={unit.id}>
                            <TableCell>{units.from + i}</TableCell>
                            <TableCell>
                                <div className="flex items-center gap-2.5">
                                    <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-sky-500/10 text-sky-600 dark:bg-sky-500/20 dark:text-sky-400 border border-sky-200/50 dark:border-sky-800/50">
                                        <DynamicIcon name={unit.icon} fallback="Building" className="w-4 h-4" />
                                    </div>
                                    <span className="font-medium text-foreground">{unit.nama_unit}</span>
                                </div>
                            </TableCell>
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
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Edit Kanal Layanan</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleEdit} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Nama Kanal Layanan</Label>
                            <Input value={data.nama_unit} onChange={e => setData('nama_unit', e.target.value)} />
                            {errors.nama_unit && <p className="text-red-500 text-sm">{errors.nama_unit}</p>}
                        </div>

                        <IconPicker selectedIcon={data.icon} onSelect={icon => setData('icon', icon)} />

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
