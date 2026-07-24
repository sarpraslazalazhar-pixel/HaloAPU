import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { DataTable } from '@/Components/DataTable';
import { Pagination } from '@/Components/Pagination';
import { StatusBadge } from '@/Components/StatusBadge';
import { Search, X, ExternalLink } from 'lucide-react';
import { formatTicketId } from '@/lib/utils';

interface RiwayatProps {
 tickets: any;
 filters: any;
 statuses: string[];
}

const columns = [
 { key: 'id', header: 'No. Tiket', render: (t: any) =>`#TKT-${formatTicketId(t.id)}`},
 { key: 'unit', header: 'Unit', render: (t: any) => t.unit?.nama_unit || '-' },
 { key: 'sub_unit', header: 'Layanan', render: (t: any) => t.sub_unit?.nama_layanan || '-' },
 { key: 'status', header: 'Status', render: (t: any) => <StatusBadge status={t.status} /> },
 { key: 'created_at', header: 'Tanggal', render: (t: any) => new Date(t.created_at).toLocaleDateString() },
 {
 key: 'aksi',
 header: 'Aksi',
 render: (t: any) => (
 <Link href={route('tiket.show', t.id)} className="text-blue-600 hover:underline inline-flex items-center gap-1 text-sm">
 Lihat Detail <ExternalLink className="w-3 h-3" />
 </Link>
 ),
 },
];

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
 });
 };

 const resetFilter = () => {
 setStatusFilter([]);
 setDateFrom('');
 setDateTo('');
 router.reload({
 data: { status: undefined, date_from: undefined, date_to: undefined },
 only: ['tickets', 'filters'],
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
 {statuses.map(s => {
 const labels: any = { open: 'Baru', on_proses: 'Diproses', pending: 'Tertunda', solve: 'Selesai', reject: 'Ditolak', dibatalkan: 'Dibatalkan', waiting_approval: 'Menunggu Review', need_revision: 'Butuh Revisi' };
 return (
 <Badge
 key={s}
 variant={statusFilter.includes(s) ? 'default' : 'outline'}
 className="cursor-pointer"
 onClick={() => toggleStatus(s)}
 >
 {labels[s] || s}
 </Badge>
 );
 })}
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
 <DataTable columns={columns} data={tickets.data || []} keyExtractor={(t: any) => t.id} emptyMessage="Belum ada tiket yang diajukan." />
 <Pagination links={tickets.links} />
 </CardContent>
 </Card>
 </div>
 </UserLayout>
 );
}
