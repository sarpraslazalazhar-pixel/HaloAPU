import React from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { DataTable } from '@/Components/DataTable';
import { StatusBadge } from '@/Components/StatusBadge';
import SlaBadge from '@/Components/SlaBadge';
import { Pagination } from '@/Components/Pagination';
import { Eye } from 'lucide-react';
import { formatTicketId } from '@/lib/utils';

interface Ticket {
    id: number;
    user: { id: number; username: string; divisi?: { nama_divisi: string } };
    sub_unit?: { nama_layanan: string; unit?: { nama_unit: string } };
    status: string;
    created_at: string;
    priority?: string;
    sla_tracking?: any;
}

interface Props {
    year: string | number;
    ticketsByStatus: any[];
    ticketsByMonth: any[];
    ticketsByLayanan: any[];
    tickets: {
        data: Ticket[];
        links: any[];
    };
}

export default function LaporanTiket({ year, ticketsByStatus, ticketsByMonth, ticketsByLayanan, tickets }: Props) {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    const getStatusLabel = (status: string) => {
        const map: Record<string, string> = {
            'open': 'Baru',
            'on_proses': 'Diproses',
            'pending': 'Tertunda',
            'solve': 'Selesai',
            'reject': 'Ditolak',
            'dibatalkan': 'Dibatalkan',
        };
        return map[status] || status;
    };

    const handleYearChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        router.get(route('admin.laporan.tiket'), { year: e.target.value }, { preserveState: true });
    };

    const columns = [
        {
            key: 'sla_tracking',
            header: 'SLA',
            render: (t: Ticket) => {
                const sla = t.sla_tracking;
                if (!sla) return <span className="text-slate-400">-</span>;
                return (
                    <SlaBadge
                        priority={t.priority || 'low'}
                        isBreached={sla.is_response_breached || sla.is_resolution_breached}
                        deadline={sla.sla_resolution_deadline}
                        respondedAt={sla.responded_at}
                        resolvedAt={sla.resolved_at}
                        pausedAt={sla.paused_at}
                        totalPausedMinutes={sla.total_paused_minutes}
                        ticketStatus={t.status}
                    />
                );
            },
        },
        { key: 'id', header: 'ID Tiket', render: (t: Ticket) => `#TKT-${formatTicketId(t.id)}` },
        { key: 'created_at', header: 'Tgl Pengajuan', render: (t: Ticket) => new Date(t.created_at).toLocaleDateString('id-ID') },
        {
            key: 'user',
            header: 'Pengaju',
            render: (t: Ticket) => (
                <div>
                    <p className="font-medium">{t.user?.username || '-'}</p>
                    <p className="text-xs text-slate-500">{t.user?.divisi?.nama_divisi || '-'}</p>
                </div>
            ),
        },
        {
            key: 'layanan',
            header: 'Layanan',
            render: (t: Ticket) => (
                <div>
                    <p className="font-medium">{t.sub_unit?.unit?.nama_unit || '-'}</p>
                    <p className="text-xs text-slate-500">{t.sub_unit?.nama_layanan || '-'}</p>
                </div>
            ),
        },
        { key: 'status', header: 'Status', render: (t: Ticket) => <StatusBadge status={t.status} /> },
        {
            key: 'aksi',
            header: 'Aksi',
            className: 'w-[100px]',
            render: (t: Ticket) => (
                <Button variant="outline" size="sm" onClick={() => router.get(route('admin.tiket.show', t.id))}>
                    <Eye className="w-4 h-4 mr-1" /> Detail
                </Button>
            ),
        },
    ];

    return (
        <AdminLayout title="Laporan Tiket">
            <Head title="Laporan Tiket" />
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Laporan Tiket</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            Ringkasan dan statistik tiket masuk berdasarkan layanan dan status.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <label className="text-sm font-medium">Tahun:</label>
                        <select 
                            value={year} 
                            onChange={handleYearChange}
                            className="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="2025">2025</option>
                            <option value="2026">2026</option>
                            <option value="2027">2027</option>
                        </select>
                        <Button variant="outline">Export PDF</Button>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">Tiket Berdasarkan Status</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-3">
                                {ticketsByStatus.map(t => (
                                    <li key={t.status} className="flex justify-between items-center">
                                        <span className="text-sm font-medium">{getStatusLabel(t.status)}</span>
                                        <span className="text-sm bg-primary/10 text-primary px-2 py-0.5 rounded-full">{t.count}</span>
                                    </li>
                                ))}
                                {ticketsByStatus.length === 0 && <p className="text-sm text-muted-foreground">Tidak ada data.</p>}
                            </ul>
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">Tiket Berdasarkan Jenis Layanan</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm text-left">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="pb-2 font-medium">Jenis Layanan</th>
                                            <th className="pb-2 font-medium text-right">Total Tiket</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {ticketsByLayanan.map((t, idx) => (
                                            <tr key={idx}>
                                                <td className="py-2">{t.layanan}</td>
                                                <td className="py-2 text-right">{t.count}</td>
                                            </tr>
                                        ))}
                                        {ticketsByLayanan.length === 0 && (
                                            <tr>
                                                <td colSpan={2} className="py-4 text-center text-muted-foreground">Tidak ada data.</td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Tren Tiket per Bulan ({year})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="h-64 flex items-end justify-between gap-2 pt-4">
                            {months.map((m, idx) => {
                                const monthData = ticketsByMonth.find(tm => tm.month === idx + 1);
                                const count = monthData ? monthData.count : 0;
                                const maxCount = Math.max(...ticketsByMonth.map(tm => tm.count), 10);
                                const height = `${(count / maxCount) * 100}%`;
                                
                                return (
                                    <div key={m} className="flex flex-col items-center gap-2 flex-1 group">
                                        <div className="w-full relative h-full flex flex-col justify-end bg-slate-100 rounded-t-sm dark:bg-zinc-800">
                                            <div 
                                                className="w-full bg-primary rounded-t-sm transition-all group-hover:bg-primary/80" 
                                                style={{ height: count > 0 ? height : '2px' }}
                                            ></div>
                                        </div>
                                        <span className="text-xs text-muted-foreground">{m}</span>
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                <div className="mt-8 space-y-4">
                    <h2 className="text-xl font-bold tracking-tight">Rincian Tiket</h2>
                    <DataTable columns={columns} data={tickets.data || []} keyExtractor={(t: Ticket) => t.id} />
                    <Pagination links={tickets.links} />
                </div>
            </div>
        </AdminLayout>
    );
}
