import React, { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';

interface Unit {
    id: number;
    nama_unit: string;
    sub_units: SubUnit[];
}

interface SubUnit {
    id: number;
    nama_layanan: string;
    form_fields_count?: number;
}

interface PeraturanFormIndexProps {
    units: Unit[];
    selectedUnitId?: number;
}

export default function Index({ units, selectedUnitId }: PeraturanFormIndexProps) {
    const [selectedUnit, setSelectedUnit] = useState<number | ''>(selectedUnitId || (units.length > 0 ? units[0].id : ''));

    const activeUnit = units.find(u => u.id === Number(selectedUnit));

    const handleUnitChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        const val = e.target.value;
        setSelectedUnit(val ? Number(val) : '');
        router.get(route('admin.peraturan-form.index'), { unit_id: val }, { replace: true });
    };

    return (
        <AdminLayout title="Peraturan Form">
            <div className="flex items-center justify-between space-y-2 mb-6">
                <h2 className="text-3xl font-bold tracking-tight">Peraturan Form</h2>
            </div>

            <div className="bg-white p-6 rounded-lg shadow border mb-6">
                <div className="mb-4 flex items-center gap-4">
                    <label className="font-semibold whitespace-nowrap">Pilih Unit:</label>
                    <select 
                        className="w-full md:w-1/3 p-2 border rounded-md"
                        value={selectedUnit}
                        onChange={handleUnitChange}
                    >
                        {units.length === 0 && <option value="">Belum ada unit aktif</option>}
                        {units.map((unit) => (
                            <option key={unit.id} value={unit.id}>{unit.nama_unit}</option>
                        ))}
                    </select>
                </div>
            </div>

            <div className="bg-white rounded-lg shadow border overflow-hidden">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-16">No</TableHead>
                            <TableHead>Nama Layanan (Sub Unit)</TableHead>
                            <TableHead className="w-32 text-center">Jumlah Field</TableHead>
                            <TableHead className="w-32 text-center">Aksi</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {!activeUnit || activeUnit.sub_units.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={4} className="text-center py-8 text-gray-500">
                                    Belum ada sub unit / layanan untuk unit ini.
                                </TableCell>
                            </TableRow>
                        ) : (
                            activeUnit.sub_units.map((subUnit, index) => (
                                <TableRow key={subUnit.id}>
                                    <TableCell>{index + 1}</TableCell>
                                    <TableCell className="font-medium">{subUnit.nama_layanan}</TableCell>
                                    <TableCell className="text-center">
                                        <span className="bg-gray-100 text-gray-700 px-2 py-1 rounded text-xs font-semibold">
                                            {subUnit.form_fields_count || 0}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-center">
                                        <Link href={route('admin.peraturan-form.builder', subUnit.id)}>
                                            <Button variant="outline" size="sm">
                                                Builder
                                            </Button>
                                        </Link>
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </div>
        </AdminLayout>
    );
}
