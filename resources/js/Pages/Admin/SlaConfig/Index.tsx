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
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { RadioGroup, RadioGroupItem } from '@/Components/ui/radio-group';
import Swal from 'sweetalert2';
import { Pencil, Trash2 } from 'lucide-react';
import { Head } from '@inertiajs/react';

interface SubUnit {
 id: number;
 nama_layanan: string;
 unit?: { nama_unit: string };
}

interface SlaConfig {
 id: number;
 sub_unit_id: number | null;
 jenis: string;
 priority: string;
 threshold_minutes: number;
 sub_unit: SubUnit | null;
}

const PRIORITIES = ['Rendah', 'Sedang', 'Tinggi', 'Urgen'];
const JENIS_OPTIONS = [
 { value: 'respon', label: 'Respon' },
 { value: 'penyelesaian', label: 'Penyelesaian' }
];

export default function SlaConfigIndex({ configs, subUnits, filters }: { configs: any; subUnits: SubUnit[]; filters?: { search?: string } }) {
 const [isAddOpen, setIsAddOpen] = useState(false);
 const [editConfig, setEditConfig] = useState<SlaConfig | null>(null);
 const [tingkat, setTingkat] = useState<'global' | 'spesifik'>('global');

 const { data, setData, post, put, delete: destroy, reset, errors, transform } = useForm<any>({
 sub_unit_id: '',
 jenis: '',
 priority: '',
 threshold_minutes: 60,
 });

 const handleAdd = (e: React.FormEvent) => {
 e.preventDefault();
 
 transform((formData: any) => ({
 ...formData,
 sub_unit_id: tingkat === 'global' ? null : formData.sub_unit_id
 }));

 post(route('admin.sla-config.store'), {
 onSuccess: () => {
 setIsAddOpen(false);
 reset();
 setTingkat('global');
 }
 });
 };

 const handleEdit = (e: React.FormEvent) => {
 e.preventDefault();
 if (editConfig) {
 transform((formData: any) => ({
 ...formData,
 sub_unit_id: tingkat === 'global' ? null : formData.sub_unit_id
 }));

 put(route('admin.sla-config.update', editConfig.id), {
 onSuccess: () => {
 setEditConfig(null);
 reset();
 setTingkat('global');
 }
 });
 }
 };

 const handleDelete = (id: number) => {
 Swal.fire({
 title: 'Yakin hapus konfigurasi ini?',
 icon: 'warning',
 showCancelButton: true,
 confirmButtonColor: '#3085d6',
 cancelButtonColor: '#d33',
 confirmButtonText: 'Ya, Hapus',
 cancelButtonText: 'Batal'
 }).then((result) => {
 if (result.isConfirmed) {
 destroy(route('admin.sla-config.destroy', id));
 }
 });
 };

 const openEdit = (config: SlaConfig) => {
 setEditConfig(config);
 setTingkat(config.sub_unit_id === null ? 'global' : 'spesifik');
 setData({
 sub_unit_id: config.sub_unit_id ? config.sub_unit_id.toString() : '',
 jenis: config.jenis,
 priority: config.priority,
 threshold_minutes: config.threshold_minutes,
 });
 };

 return (
 <AdminLayout title="Konfigurasi SLA">
 <Head title="Konfigurasi SLA" />

 <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 gap-4">
 <h2 className="text-2xl font-bold">Konfigurasi SLA</h2>
 
 <div className="flex items-center gap-2">
 <SearchInput 
 placeholder="Cari SLA..." 
 />
 
 <Dialog open={isAddOpen} onOpenChange={setIsAddOpen}>
 <DialogTrigger asChild>
 <Button onClick={() => { reset(); setTingkat('global'); }}>Tambah Aturan SLA</Button>
 </DialogTrigger>
 <DialogContent>
 <DialogHeader>
 <DialogTitle>Tambah Aturan SLA</DialogTitle>
 </DialogHeader>
 <form onSubmit={handleAdd} className="space-y-4">
 {errors.message && (
 <div className="bg-red-50 text-red-600 p-3 rounded-md text-sm">
 {errors.message}
 </div>
 )}
 <div className="space-y-2">
 <Label>Tingkat Pengaturan</Label>
 <RadioGroup 
 value={tingkat} 
 onValueChange={(val: 'global' | 'spesifik') => setTingkat(val)} 
 className="flex gap-4"
 >
 <div className="flex items-center space-x-2">
 <RadioGroupItem value="global" id="r1" />
 <Label htmlFor="r1">Default Global</Label>
 </div>
 <div className="flex items-center space-x-2">
 <RadioGroupItem value="spesifik" id="r2" />
 <Label htmlFor="r2">Spesifik Sub Unit</Label>
 </div>
 </RadioGroup>
 </div>

 {tingkat === 'spesifik' && (
 <div className="space-y-2">
 <Label>Sub Unit</Label>
 <Select 
 value={data.sub_unit_id || ''} 
 onValueChange={val => setData('sub_unit_id', val)}
 >
 <SelectTrigger className="w-full">
 <SelectValue placeholder="Pilih Sub Unit" />
 </SelectTrigger>
 <SelectContent>
 {Object.entries(
 subUnits.reduce((acc, su) => {
 const unitName = su.unit?.nama_unit || 'Lainnya';
 if (!acc[unitName]) acc[unitName] = [];
 acc[unitName].push(su);
 return acc;
 }, {} as Record<string, typeof subUnits>)
 ).map(([unitName, items]) => (
 <SelectGroup key={unitName}>
 <SelectLabel>{unitName}</SelectLabel>
 {items.map(su => (
 <SelectItem key={su.id} value={su.id.toString()}>
 {su.nama_layanan}
 </SelectItem>
 ))}
 </SelectGroup>
 ))}
 </SelectContent>
 </Select>
 {errors.sub_unit_id && <p className="text-red-500 text-sm">{errors.sub_unit_id}</p>}
 </div>
 )}

 <div className="space-y-2">
 <Label>Jenis SLA</Label>
 <Select 
 value={data.jenis} 
 onValueChange={val => setData('jenis', val)}
 >
 <SelectTrigger>
 <SelectValue placeholder="Pilih Jenis" />
 </SelectTrigger>
 <SelectContent>
 {JENIS_OPTIONS.map(j => (
 <SelectItem key={j.value} value={j.value}>{j.label}</SelectItem>
 ))}
 </SelectContent>
 </Select>
 {errors.jenis && <p className="text-red-500 text-sm">{errors.jenis}</p>}
 </div>

 <div className="space-y-2">
 <Label>Prioritas</Label>
 <Select 
 value={data.priority} 
 onValueChange={val => setData('priority', val)}
 >
 <SelectTrigger>
 <SelectValue placeholder="Pilih Prioritas" />
 </SelectTrigger>
 <SelectContent>
 {PRIORITIES.map(p => (
 <SelectItem key={p} value={p}>{p}</SelectItem>
 ))}
 </SelectContent>
 </Select>
 {errors.priority && <p className="text-red-500 text-sm">{errors.priority}</p>}
 </div>

 <div className="space-y-2">
 <Label>Target Waktu (Menit)</Label>
 <Input 
 type="number" 
 min={1} 
 value={data.threshold_minutes} 
 onChange={e => setData('threshold_minutes', parseInt(e.target.value) || 0)} 
 />
 {errors.threshold_minutes && <p className="text-red-500 text-sm">{errors.threshold_minutes}</p>}
 </div>

 <Button type="submit" className="w-full">Simpan</Button>
 </form>
 </DialogContent>
 </Dialog>
 </div>
 </div>

 <div className="bg-white rounded-lg shadow border overflow-hidden">
 <Table>
 <TableHeader>
 <TableRow>
 <TableHead>Sub Unit</TableHead>
 <TableHead>Jenis SLA</TableHead>
 <TableHead>Prioritas</TableHead>
 <TableHead>Target Waktu (Menit)</TableHead>
 <TableHead className="w-[100px] text-right">Aksi</TableHead>
 </TableRow>
 </TableHeader>
 <TableBody>
 {configs.data.length > 0 ? (
 configs.data.map((config: SlaConfig) => (
 <TableRow key={config.id}>
 <TableCell className="font-medium">
 {config.sub_unit_id === null ? (
 <span className="inline-flex items-center rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-700/10">Default Global</span>
 ) : (
 <span>
 {config.sub_unit?.unit?.nama_unit} - {config.sub_unit?.nama_layanan}
 </span>
 )}
 </TableCell>
 <TableCell className="capitalize">{config.jenis}</TableCell>
 <TableCell>{config.priority}</TableCell>
 <TableCell>{config.threshold_minutes} Menit</TableCell>
 <TableCell className="text-right">
 <div className="flex items-center justify-end gap-2">
 <Button variant="outline" size="icon" onClick={() => openEdit(config)}>
 <Pencil className="w-4 h-4" />
 </Button>
 <Button variant="destructive" size="icon" onClick={() => handleDelete(config.id)}>
 <Trash2 className="w-4 h-4" />
 </Button>
 </div>
 </TableCell>
 </TableRow>
 ))
 ) : (
 <TableRow>
 <TableCell colSpan={5} className="h-24 text-center">
 Tidak ada data SLA.
 </TableCell>
 </TableRow>
 )}
 </TableBody>
 </Table>
 </div>

 {configs.total > configs.per_page && (
 <div className="mt-4">
 <Pagination links={configs.links} />
 </div>
 )}

 <Dialog open={!!editConfig} onOpenChange={(open) => !open && setEditConfig(null)}>
 <DialogContent>
 <DialogHeader>
 <DialogTitle>Edit Aturan SLA</DialogTitle>
 </DialogHeader>
 <form onSubmit={handleEdit} className="space-y-4">
 {errors.message && (
 <div className="bg-red-50 text-red-600 p-3 rounded-md text-sm">
 {errors.message}
 </div>
 )}
 <div className="space-y-2">
 <Label>Tingkat Pengaturan</Label>
 <RadioGroup 
 value={tingkat} 
 onValueChange={(val: 'global' | 'spesifik') => setTingkat(val)} 
 className="flex gap-4"
 >
 <div className="flex items-center space-x-2">
 <RadioGroupItem value="global" id="r3" />
 <Label htmlFor="r3">Default Global</Label>
 </div>
 <div className="flex items-center space-x-2">
 <RadioGroupItem value="spesifik" id="r4" />
 <Label htmlFor="r4">Spesifik Sub Unit</Label>
 </div>
 </RadioGroup>
 </div>

 {tingkat === 'spesifik' && (
 <div className="space-y-2">
 <Label>Sub Unit</Label>
 <Select 
 value={data.sub_unit_id || ''} 
 onValueChange={val => setData('sub_unit_id', val)}
 >
 <SelectTrigger className="w-full">
 <SelectValue placeholder="Pilih Sub Unit" />
 </SelectTrigger>
 <SelectContent>
 {Object.entries(
 subUnits.reduce((acc, su) => {
 const unitName = su.unit?.nama_unit || 'Lainnya';
 if (!acc[unitName]) acc[unitName] = [];
 acc[unitName].push(su);
 return acc;
 }, {} as Record<string, typeof subUnits>)
 ).map(([unitName, items]) => (
 <SelectGroup key={unitName}>
 <SelectLabel>{unitName}</SelectLabel>
 {items.map(su => (
 <SelectItem key={su.id} value={su.id.toString()}>
 {su.nama_layanan}
 </SelectItem>
 ))}
 </SelectGroup>
 ))}
 </SelectContent>
 </Select>
 {errors.sub_unit_id && <p className="text-red-500 text-sm">{errors.sub_unit_id}</p>}
 </div>
 )}

 <div className="space-y-2">
 <Label>Jenis SLA</Label>
 <Select 
 value={data.jenis} 
 onValueChange={val => setData('jenis', val)}
 >
 <SelectTrigger>
 <SelectValue placeholder="Pilih Jenis" />
 </SelectTrigger>
 <SelectContent>
 {JENIS_OPTIONS.map(j => (
 <SelectItem key={j.value} value={j.value}>{j.label}</SelectItem>
 ))}
 </SelectContent>
 </Select>
 {errors.jenis && <p className="text-red-500 text-sm">{errors.jenis}</p>}
 </div>

 <div className="space-y-2">
 <Label>Prioritas</Label>
 <Select 
 value={data.priority} 
 onValueChange={val => setData('priority', val)}
 >
 <SelectTrigger>
 <SelectValue placeholder="Pilih Prioritas" />
 </SelectTrigger>
 <SelectContent>
 {PRIORITIES.map(p => (
 <SelectItem key={p} value={p}>{p}</SelectItem>
 ))}
 </SelectContent>
 </Select>
 {errors.priority && <p className="text-red-500 text-sm">{errors.priority}</p>}
 </div>

 <div className="space-y-2">
 <Label>Target Waktu (Menit)</Label>
 <Input 
 type="number" 
 min={1} 
 value={data.threshold_minutes} 
 onChange={e => setData('threshold_minutes', parseInt(e.target.value) || 0)} 
 />
 {errors.threshold_minutes && <p className="text-red-500 text-sm">{errors.threshold_minutes}</p>}
 </div>

 <Button type="submit" className="w-full">Update</Button>
 </form>
 </DialogContent>
 </Dialog>

 </AdminLayout>
 );
}
