import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { StatusBadge } from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import MonthlyUnitChart from '@/Components/Charts/MonthlyUnitChart';
import SubUnitChart from '@/Components/Charts/SubUnitChart';
import { AlertTriangle, Eye, Clock, Folder, Loader2, CheckCircle, XCircle } from 'lucide-react';
import ReactECharts from 'echarts-for-react';
const PIE_COLORS = ['#22c55e', '#ef4444', '#f97316'];

const STATUS_META: Record<string, { label: string; bg: string; icon: React.ElementType; anim: string }> = {
    open: { label: 'Open', bg: 'bg-gradient-to-br from-blue-400 to-blue-600', icon: Folder, anim: 'group-hover:-translate-y-2 group-hover:rotate-12 group-hover:opacity-100' },
    on_proses: { label: 'On Proses', bg: 'bg-gradient-to-br from-orange-400 to-orange-600', icon: Loader2, anim: 'animate-spin group-hover:scale-110 group-hover:opacity-100' },
    pending: { label: 'Pending', bg: 'bg-gradient-to-br from-slate-400 to-slate-600', icon: Clock, anim: 'group-hover:-rotate-12 group-hover:scale-110 group-hover:opacity-100' },
    solve: { label: 'Selesai', bg: 'bg-gradient-to-br from-green-400 to-green-600', icon: CheckCircle, anim: 'group-hover:scale-125 group-hover:opacity-100' },
    reject: { label: 'Ditolak', bg: 'bg-gradient-to-br from-red-400 to-red-600', icon: XCircle, anim: 'group-hover:rotate-90 group-hover:scale-110 group-hover:opacity-100' },
};

export default function DashboardIndex({ totalTickets, statusCounts, topUsers, followUpTickets, monthlyChartData, yearlyChartData, subUnitChartData, units, filters, slaStats, slaPieChartData, slaBarChartData, slaTrendData, slaFilters, topUsersAll, csatTrend, tiketBulanan }: any) {
    const [month, setMonth] = useState(filters?.month !== null && filters?.month !== undefined ? String(filters.month) : '');
    const [year, setYear] = useState(filters?.year !== null && filters?.year !== undefined ? String(filters.year) : '');
    const [selectedUnit, setSelectedUnit] = useState('');
    const [chartMode, setChartMode] = useState<'bulanan' | 'tahunan'>('bulanan');

    const chartData = chartMode === 'tahunan' ? yearlyChartData : monthlyChartData;

    const applyFilter = () => {
        const params: any = {};
        if (month) params.month = month;
        if (year) params.year = year;
        router.get(route('admin.dashboard'), params, { });
    };

    const currentSubUnitData = selectedUnit
        ? (subUnitChartData?.[selectedUnit] || [])
        : (subUnitChartData?.['_all'] || []);

    const months = [
        { value: '', label: 'Semua Bulan' },
        ...Array.from({ length: 12 }, (_, i) => ({ value: String(i + 1), label: new Date(0, i).toLocaleString('id', { month: 'long' }) })),
    ];

    const years = [
        { value: '', label: 'Semua Tahun' },
        ...Array.from({ length: 5 }, (_, i) => ({ value: String(new Date().getFullYear() - i), label: String(new Date().getFullYear() - i) })),
    ];

    return (
        <AdminLayout title="Dashboard Admin">
            <Head title="Dashboard Admin" />

            <div className="flex items-center justify-between mb-4">
                <h2 className="text-2xl font-bold">Dashboard Admin</h2>
                <div className="flex items-center gap-2">
                    <select className="rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={month} onChange={e => setMonth(e.target.value)}>
                        {months.map(m => <option key={m.value} value={m.value}>{m.label}</option>)}
                    </select>
                    <select className="rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={year} onChange={e => setYear(e.target.value)}>
                        {years.map(y => <option key={y.value} value={y.value}>{y.label}</option>)}
                    </select>
                    <Button onClick={applyFilter}>Terapkan</Button>
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                {Object.entries(STATUS_META).map(([key, meta]) => {
                    const Icon = meta.icon;
                    return (
                        <div key={key} className={`${meta.bg} group relative overflow-hidden rounded-xl shadow-md transition-transform duration-300 hover:-translate-y-1`}>
                            {/* Dekorasi Lingkaran Pudar (Premium Shine Effect) */}
                            <div className="absolute -top-6 -right-6 w-24 h-24 bg-white/20 rounded-full blur-xl pointer-events-none transition-transform duration-500 group-hover:scale-150" />
                            <div className="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full -translate-y-10 translate-x-10 pointer-events-none transition-transform duration-500 group-hover:-translate-x-4 group-hover:translate-y-4" />
                            
                            <div className="relative p-5 text-white flex flex-col h-full justify-between min-h-[110px]">
                                <div className="flex justify-between items-start">
                                    <span className="text-sm font-medium opacity-90">{meta.label}</span>
                                    <Icon className={`w-7 h-7 opacity-70 transition-all duration-500 ${meta.anim}`} />
                                </div>
                                <div className="mt-4">
                                    <span className="text-3xl font-bold">{statusCounts?.[key] ?? 0}</span>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <Card>
                    <CardHeader className="py-3 flex flex-row items-center gap-2">
                        <AlertTriangle className="w-5 h-5 text-orange-500" />
                        <CardTitle className="text-base">Tiket Perlu Ditindak Lanjuti</CardTitle>
                    </CardHeader>
                    <CardContent className="py-2">
                        {followUpTickets?.length > 0 ? (
                            <div className="space-y-2">
                                {followUpTickets.map((t: any) => (
                                    <div key={t.id} className="flex items-center justify-between p-2 border rounded-lg text-sm">
                                        <div className="flex items-center gap-2 min-w-0">
                                            <span className="font-medium shrink-0">#TKT-{t.id}</span>
                                            <StatusBadge status={t.status} />
                                            <span className="text-slate-500 truncate">{t.user?.username || '-'}</span>
                                            <span className="text-xs text-slate-400 truncate">{t.unit?.nama_unit} / {t.sub_unit?.nama_layanan}</span>
                                        </div>
                                        <Button variant="ghost" size="sm" onClick={() => router.get(route('admin.tiket.show', t.id))}>
                                            <Eye className="w-4 h-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-slate-500 py-4 text-center">Semua tiket sudah ditindak lanjuti.</p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="py-3"><CardTitle className="text-base">Top Pengaju Tiket</CardTitle></CardHeader>
                    <CardContent className="py-2">
                        {topUsers?.length > 0 ? (
                            <div className="space-y-2">
                                {topUsers.map((u: any, i: number) => (
                                    <div key={u.id} className="flex items-center gap-3 p-2 border rounded-lg text-sm">
                                        <span className="w-6 h-6 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs font-bold shrink-0">{i + 1}</span>
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium truncate">{u.username}</p>
                                            <p className="text-xs text-slate-500">{u.nama_divisi || '-'}</p>
                                        </div>
                                        <span className="text-lg font-bold text-slate-700">{u.total_tiket}</span>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-slate-500 py-4 text-center">Belum ada data tiket.</p>
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <Card>
                    <CardHeader className="py-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Grafik {chartMode === 'tahunan' ? 'Tahunan' : `Bulanan${year ? ` (${year})` : ''}`}</CardTitle>
                            <div className="flex gap-1">
                                <Button variant={chartMode === 'bulanan' ? 'default' : 'outline'} size="sm" onClick={() => setChartMode('bulanan')}>Bulanan</Button>
                                <Button variant={chartMode === 'tahunan' ? 'default' : 'outline'} size="sm" onClick={() => setChartMode('tahunan')}>Tahunan</Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {chartData?.length > 0
                            ? <MonthlyUnitChart data={chartData} xKey={chartMode === 'tahunan' ? 'tahun' : 'bulan'} />
                            : <p className="text-sm text-slate-400 text-center py-8">{chartMode === 'tahunan' ? 'Belum ada data tahunan.' : 'Pilih tahun untuk menampilkan grafik bulanan.'}</p>
                        }
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="py-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Grafik per Sub Unit</CardTitle>
                            <select className="rounded-md border border-input bg-transparent px-2 py-1 text-xs" value={selectedUnit} onChange={e => setSelectedUnit(e.target.value)}>
                                <option value="">Semua Unit</option>
                                {units?.map((u: any) => <option key={u.id} value={u.id}>{u.nama_unit}</option>)}
                            </select>
                        </div>
                    </CardHeader>
                    <CardContent><SubUnitChart data={currentSubUnitData} /></CardContent>
                </Card>
            </div>

            {/* Grafik Tambahan Fase 5 */}
            <section className="mt-8 space-y-6">
                <h2 className="text-xl font-semibold">Grafik Statistik</h2>
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Top 5 User */}
                    <Card>
                        <CardHeader><CardTitle className="text-base">Top 5 User (Paling Banyak Tiket)</CardTitle></CardHeader>
                        <CardContent>
                            {topUsersAll?.length > 0 ? (
                                <ReactECharts option={{
                                    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                                    grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
                                    xAxis: { type: 'value' },
                                    yAxis: { type: 'category', data: [...topUsersAll].reverse().map((u: any) => u.username) },
                                    series: [{
                                        name: 'Total Tiket',
                                        type: 'bar',
                                        itemStyle: { color: '#3b82f6', borderRadius: [0, 4, 4, 0] },
                                        data: [...topUsersAll].reverse().map((u: any) => u.total_tiket)
                                    }]
                                }} style={{ height: 250, width: '100%' }} />
                            ) : (
                                <p className="text-sm text-slate-400 text-center py-8">Belum ada data.</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* CSAT Trend */}
                    <Card>
                        <CardHeader><CardTitle className="text-base">Tren CSAT Bulanan</CardTitle></CardHeader>
                        <CardContent>
                            {csatTrend?.length > 0 ? (
                                <ReactECharts option={{
                                    tooltip: { trigger: 'axis' },
                                    grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
                                    xAxis: { type: 'category', data: csatTrend.map((d: any) => d.bulan) },
                                    yAxis: { type: 'value', min: 1, max: 5 },
                                    series: [{
                                        name: 'Rata-rata CSAT',
                                        type: 'line',
                                        smooth: true,
                                        itemStyle: { color: '#eab308' },
                                        symbolSize: 8,
                                        data: csatTrend.map((d: any) => d.rata_rata)
                                    }]
                                }} style={{ height: 250, width: '100%' }} />
                            ) : (
                                <p className="text-sm text-slate-400 text-center py-8">Belum ada data CSAT.</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Tiket Bulanan */}
                    <Card className="lg:col-span-2">
                        <CardHeader><CardTitle className="text-base">Tiket Bulanan (12 Bulan)</CardTitle></CardHeader>
                        <CardContent>
                            {tiketBulanan?.length > 0 ? (
                                <ReactECharts option={{
                                    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                                    legend: { bottom: 0 },
                                    grid: { left: '3%', right: '4%', bottom: '10%', containLabel: true },
                                    xAxis: { type: 'category', data: tiketBulanan.map((d: any) => d.bulan) },
                                    yAxis: { type: 'value' },
                                    series: [
                                        { name: 'Total Tiket', type: 'bar', itemStyle: { color: '#3b82f6', borderRadius: [4, 4, 0, 0] }, data: tiketBulanan.map((d: any) => d.total) },
                                        { name: 'Selesai', type: 'bar', itemStyle: { color: '#22c55e', borderRadius: [4, 4, 0, 0] }, data: tiketBulanan.map((d: any) => d.selesai) },
                                        { name: 'Aktif', type: 'bar', itemStyle: { color: '#f97316', borderRadius: [4, 4, 0, 0] }, data: tiketBulanan.map((d: any) => d.aktif) }
                                    ]
                                }} style={{ height: 300, width: '100%' }} />
                            ) : (
                                <p className="text-sm text-slate-400 text-center py-8">Belum ada data tiket bulanan.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </section>

            {/* Kepatuhan SLA */}
            <section className="mt-8 space-y-6">
                <div className="flex items-center gap-2">
                    <Clock className="w-5 h-5" />
                    <h2 className="text-xl font-semibold">Kepatuhan SLA</h2>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-slate-500">Kepatuhan SLA Respon</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold text-green-500">{slaStats?.responseCompliance ?? 100}%</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-slate-500">Kepatuhan SLA Penyelesaian</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold text-blue-500">{slaStats?.resolutionCompliance ?? 100}%</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-slate-500">Total Breach</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold text-red-500">{slaStats?.totalBreach ?? 0}</p>
                            <p className="text-sm text-slate-500">dari {slaStats?.totalAll ?? 0} tiket</p>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <Card>
                        <CardHeader><CardTitle className="text-base">Distribusi Kepatuhan SLA</CardTitle></CardHeader>
                        <CardContent>
                            {slaPieChartData?.length > 0 ? (
                                <ReactECharts option={{
                                    tooltip: { trigger: 'item', formatter: '{b} : {c} ({d}%)' },
                                    legend: { bottom: 0 },
                                    series: [
                                        {
                                            name: 'Kepatuhan SLA',
                                            type: 'pie',
                                            radius: [20, 100],
                                            center: ['50%', '50%'],
                                            roseType: 'radius',
                                            itemStyle: {
                                                borderRadius: 5
                                            },
                                            label: {
                                                show: false
                                            },
                                            data: (slaPieChartData || []).map((d: any, i: number) => ({
                                                value: d.value,
                                                name: d.name,
                                                itemStyle: { color: PIE_COLORS[i % PIE_COLORS.length] }
                                            }))
                                        }
                                    ]
                                }} style={{ height: 300, width: '100%' }} />
                            ) : (
                                <p className="text-sm text-slate-400 text-center py-8">Belum ada data SLA.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-base">Tren Kepatuhan SLA (12 Bulan)</CardTitle></CardHeader>
                        <CardContent>
                            {slaTrendData?.length > 0 ? (
                                <ReactECharts option={{
                                    tooltip: { trigger: 'axis', formatter: '{b}<br/>Kepatuhan SLA: {c}%' },
                                    grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
                                    xAxis: { type: 'category', data: slaTrendData.map((d: any) => d.bulan) },
                                    yAxis: { type: 'value', min: 0, max: 100, axisLabel: { formatter: '{value} %' } },
                                    series: [{
                                        name: 'Kepatuhan SLA (%)',
                                        type: 'line',
                                        smooth: true,
                                        itemStyle: { color: '#22c55e' },
                                        symbolSize: 8,
                                        data: slaTrendData.map((d: any) => d.persentase_sla)
                                    }]
                                }} style={{ height: 300, width: '100%' }} />
                            ) : (
                                <p className="text-sm text-slate-400 text-center py-8">Belum ada data tren SLA.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {slaBarChartData?.length > 0 && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">Kepatuhan SLA per Unit (Bulan Ini)</CardTitle></CardHeader>
                        <CardContent>
                            <ReactECharts option={{
                                tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                                legend: { bottom: 0 },
                                grid: { left: '3%', right: '4%', bottom: '10%', containLabel: true },
                                xAxis: { type: 'category', data: slaBarChartData.map((d: any) => d.unit_nama) },
                                yAxis: { type: 'value' },
                                series: [
                                    { name: 'Dalam SLA', type: 'bar', stack: 'total', itemStyle: { color: '#22c55e' }, data: slaBarChartData.map((d: any) => d.dalam_sla) },
                                    { name: 'Breach', type: 'bar', stack: 'total', itemStyle: { color: '#ef4444', borderRadius: [4, 4, 0, 0] }, data: slaBarChartData.map((d: any) => d.breach) }
                                ]
                            }} style={{ height: 300, width: '100%' }} />
                        </CardContent>
                    </Card>
                )}
            </section>
        </AdminLayout>
    );
}
