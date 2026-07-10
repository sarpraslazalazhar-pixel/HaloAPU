import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Pagination } from '@/Components/Pagination';
import { StatusBadge } from '@/Components/StatusBadge';
import { Search, X } from 'lucide-react';

interface RiwayatProps {
    tickets: any;
    filters: any;
    statuses: string[];
}

export default function Riwayat({ tickets, filters, statuses }: RiwayatProps) {
    const [showFilter, setShowFilter] = useState(false);
    const [statusFilter, setStatusFilter] = useState<string[]>(filters?.status || []);
    const [dateFrom, setDateFrom] = useState(filters?.date_from || '');
    const [dateTo, setDateTo] = useState(filters?.date_to || '');

    const toggleStatus = (s: string) => {
        const next = statusFilter.includes(s)
            ? statusFilter.filter(x => x !== s)
            : [...statusFilter, s];
        setStatusFilter(next);
    };

    const applyFilter = () => {
        router.reload({
            data: {
                status: statusFilter.length > 0 ? statusFilter : undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            only: ['tickets', 'filters'],
            preserveState: true,
        });
    };

    const resetFilter = () => {
        setStatusFilter([]);
        setDateFrom('');
        setDateTo('');
        router.reload({
            data: { status: undefined, date_from: undefined, date_to: undefined },
            only: ['tickets', 'filters'],
            preserveState: true,
        });
    };

    return (
        <UserLayout title="Riwayat Tiket">
            <div className="max-w-6xl mx-auto py-8 px-4">
                <Head title="Riwayat Tiket" />

                <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold">Riwayat Tiket</h1>
                <div className="flex gap-2">
                    <Button variant="outline" onClick={() => setShowFilter(!showFilter)}>
                        <Search className="h-4 w-4 mr-2" />
                        Filter
                    </Button>
                    <Link href={route('tiket.create')}>
                        <Button>Buat Tiket Baru</Button>
                    </Link>
                </div>
            </div>

            {showFilter && (
                <Card className="mb-6">
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle>Filter</CardTitle>
                            <Button variant="ghost" size="sm" onClick={resetFilter}>
                                <X className="h-4 w-4 mr-1" /> Reset
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-4">
                            <div>
                                <Label>Status</Label>
                                <div className="flex flex-wrap gap-2 mt-1">
                                    {statuses.map(s => (
                                        <Badge
                                            key={s}
                                            variant={statusFilter.includes(s) ? 'default' : 'outline'}
                                            className="cursor-pointer"
                                            onClick={() => toggleStatus(s)}
                                        >
                                            {s}
                                        </Badge>
                                    ))}
                                </div>
                            </div>
                            <div className="flex gap-4">
                                <div>
                                    <Label>Dari Tanggal</Label>
                                    <Input
                                        type="date"
                                        value={dateFrom}
                                        onChange={e => setDateFrom(e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label>Sampai Tanggal</Label>
                                    <Input
                                        type="date"
                                        value={dateTo}
                                        onChange={e => setDateTo(e.target.value)}
                                        className="mt-1"
                                    />
                                </div>
                            </div>
                            <Button onClick={applyFilter}>Terapkan Filter</Button>
                        </div>
                    </CardContent>
                </Card>
            )}

            <Card>
                <CardHeader>
                    <CardTitle>Daftar Tiket Anda</CardTitle>
                </CardHeader>
                <CardContent>
                    {tickets.data && tickets.data.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="border-b">
                                        <th className="py-3 px-4 font-medium text-slate-500">No. Tiket</th>
                                        <th className="py-3 px-4 font-medium text-slate-500">Unit</th>
                                        <th className="py-3 px-4 font-medium text-slate-500">Layanan</th>
                                        <th className="py-3 px-4 font-medium text-slate-500">Status</th>
                                        <th className="py-3 px-4 font-medium text-slate-500">Tanggal</th>
                                        <th className="py-3 px-4 font-medium text-slate-500">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {tickets.data.map((ticket: any) => (
                                        <tr key={ticket.id} className="border-b hover:bg-slate-50">
                                            <td className="py-3 px-4">#TKT-{ticket.id}</td>
                                            <td className="py-3 px-4">{ticket.unit?.nama_unit}</td>
                                            <td className="py-3 px-4">{ticket.sub_unit?.nama_layanan}</td>
                                            <td className="py-3 px-4">
                                                <StatusBadge status={ticket.status} />
                                            </td>
                                            <td className="py-3 px-4">{new Date(ticket.created_at).toLocaleDateString()}</td>
                                            <td className="py-3 px-4">
                                                <Link href={route('tiket.show', ticket.id)} className="text-blue-600 hover:underline">
                                                    Lihat Detail
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="text-center py-8 text-slate-500">
                            Belum ada tiket yang diajukan.
                        </div>
                    )}

                    <Pagination links={tickets.links} />
                </CardContent>
            </Card>
        </div>
        </UserLayout>
    );
}
