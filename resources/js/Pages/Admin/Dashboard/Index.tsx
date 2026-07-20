import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { StatusBadge } from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import MonthlyUnitChart from '@/Components/Charts/MonthlyUnitChart';
import SubUnitChart from '@/Components/Charts/SubUnitChart';
import { AlertTriangle, Eye, Clock, Folder, Loader2, CheckCircle, XCircle, ChevronDown, Calendar } from 'lucide-react';
import ReactECharts from 'echarts-for-react';

const PIE_COLORS = ['#22c55e', '#ef4444', '#f97316'];

const STATUS_META: Record<string, { label: string; bg: string; icon: React.ElementType; anim: string }> = {
    open: { label: 'Open', bg: 'from-blue-500 to-blue-600', icon: Folder, anim: 'group-hover:-translate-y-2 group-hover:rotate-12 group-hover:opacity-100' },
    on_proses: { label: 'On Proses', bg: 'from-orange-500 to-orange-600', icon: Clock, anim: 'group-hover:-rotate-12 group-hover:scale-110 group-hover:opacity-100' },
    pending: { label: 'Pending', bg: 'from-zinc-500 to-zinc-600', icon: Clock, anim: 'group-hover:-rotate-12 group-hover:scale-110 group-hover:opacity-100' },
    solve: { label: 'Selesai', bg: 'from-green-500 to-green-600', icon: CheckCircle, anim: 'group-hover:scale-125 group-hover:opacity-100' },
    reject: { label: 'Ditolak', bg: 'from-red-500 to-red-600', icon: XCircle, anim: 'group-hover:rotate-90 group-hover:scale-110 group-hover:opacity-100' },
};

export default function DashboardIndex({ totalTickets, statusCounts, topUsers, followUpTickets, monthlyChartData, yearlyChartData, dailyChartData, subUnitChartData, units, filters, slaStats, slaPieChartData, slaBarChartData, slaTrendData, slaFilters, topUsersAll, csatTrend, tiketBulanan }: any) {
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

            <div className="flex flex-col gap-1 mb-6">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Dashboard Admin</h1>
                        <p className="text-sm text-muted-foreground mt-0.5">Ringkasan dan statistik sistem layanan.</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <div className="flex items-center gap-1.5 rounded-lg border border-input bg-white dark:bg-zinc-950 px-3 py-1.5 text-sm shadow-sm">
                            <Calendar className="h-4 w-4 text-muted-foreground" />
                            <select className="bg-transparent border-0 p-0 text-sm focus:ring-0 outline-none" value={month} onChange={e => setMonth(e.target.value)}>
                                {months.map(m => <option key={m.value} value={m.value}>{m.label}</option>)}
                            </select>
                            <span className="text-muted-foreground/40">/</span>
                            <select className="bg-transparent border-0 p-0 text-sm focus:ring-0 outline-none" value={year} onChange={e => setYear(e.target.value)}>
                                {years.map(y => <option key={y.value} value={y.value}>{y.label}</option>)}
                            </select>
                        </div>
                        <Button onClick={applyFilter} size="sm">Terapkan</Button>
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                {Object.entries(STATUS_META).map(([key, meta]) => {
                    const Icon = meta.icon;
                    return (
                        <div
                            key={key}
                            className={`group relative overflow-hidden rounded-xl bg-gradient-to-br ${meta.bg} shadow-md transition-all duration-300 hover:shadow-lg hover:-translate-y-0.5`}
                        >
                            <div className="absolute -top-6 -right-6 h-24 w-24 rounded-full bg-white/20 blur-xl pointer-events-none transition-transform duration-500 group-hover:scale-150" />
                            <div className="absolute -bottom-4 -left-4 h-20 w-20 rounded-full bg-white/5 blur-lg pointer-events-none" />
                            <div className="relative p-5 text-white">
                                <div className="flex items-start justify-between">
                                    <span className="text-sm font-medium text-white/80">{meta.label}</span>
                                    <Icon className={`h-6 w-6 text-white opacity-70 transition-all duration-300 ${meta.anim}`} />
                                </div>
                                <p className="mt-3 text-3xl font-bold">{statusCounts?.[key] ?? 0}</p>
                            </div>
                        </div>
                    );
                })}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <Card>
                    <CardHeader className="flex flex-row items-center gap-2 pb-3">
                        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-orange-50 dark:bg-orange-950/30">
                            <AlertTriangle className="h-4 w-4 text-orange-500" />
                        </div>
                        <CardTitle className="text-sm font-semibold">Tiket Perlu Ditindak Lanjuti</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {followUpTickets?.length > 0 ? (
                            <div className="space-y-2">
                                {followUpTickets.map((t: any) => (
                                    <div key={t.id} className="flex items-center justify-between rounded-lg border p-3 text-sm transition-colors hover:bg-muted/50">
                                        <div className="flex items-center gap-3 min-w-0">
                                            <span className="shrink-0 font-semibold text-foreground">#TKT-{t.id}</span>
                                            <StatusBadge status={t.status} />
                                            <span className="text-muted-foreground truncate">{t.user?.username || '-'}</span>
                                            <span className="hidden md:inline text-xs text-muted-foreground/60 truncate">{t.unit?.nama_unit} / {t.sub_unit?.nama_layanan}</span>
                                        </div>
                                        <Button variant="ghost" size="icon" className="h-8 w-8 shrink-0" onClick={() => router.get(route('admin.tiket.show', t.id))}>
                                            <Eye className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center gap-2 py-8 text-center">
                                <CheckCircle className="h-8 w-8 text-green-400" />
                                <p className="text-sm text-muted-foreground">Semua tiket sudah ditindak lanjuti.</p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 dark:bg-blue-950/30">
                                <Folder className="h-4 w-4 text-blue-500" />
                            </div>
                            <CardTitle className="text-sm font-semibold">Top Pengaju Tiket</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {topUsers?.length > 0 ? (
                            <div className="space-y-2">
                                {topUsers.map((u: any, i: number) => (
                                    <div key={u.id} className="flex items-center gap-3 rounded-lg border p-3 text-sm">
                                        <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold ${
                                            i === 0 ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400' :
                                            i === 1 ? 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' :
                                            i === 2 ? 'bg-orange-100 text-orange-700 dark:bg-orange-950/40 dark:text-orange-400' :
                                            'bg-muted text-muted-foreground'
                                        }`}>{i + 1}</span>
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium truncate">{u.username}</p>
                                            <p className="text-xs text-muted-foreground">{u.nama_divisi || '-'}</p>
                                        </div>
                                        <span className="text-lg font-bold text-foreground">{u.total_tiket}</span>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center gap-2 py-8 text-center">
                                <Folder className="h-8 w-8 text-muted-foreground/40" />
                                <p className="text-sm text-muted-foreground">Belum ada data tiket.</p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-sm font-semibold">Grafik {chartMode === 'tahunan' ? 'Tahunan' : `Bulanan${year ? ` (${year})` : ''}`}</CardTitle>
                            <div className="flex gap-1">
                                <Button variant={chartMode === 'bulanan' ? 'default' : 'outline'} size="sm" onClick={() => setChartMode('bulanan')}>Bulanan</Button>
                                <Button variant={chartMode === 'tahunan' ? 'default' : 'outline'} size="sm" onClick={() => setChartMode('tahunan')}>Tahunan</Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {chartData?.length > 0
                            ? <MonthlyUnitChart data={chartData} xKey={chartMode === 'tahunan' ? 'tahun' : 'bulan'} />
                            : <p className="text-sm text-muted-foreground text-center py-8">{chartMode === 'tahunan' ? 'Belum ada data tahunan.' : 'Pilih tahun untuk menampilkan grafik bulanan.'}</p>
                        }
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-sm font-semibold">Grafik per Sub Unit</CardTitle>
                            <select className="rounded-md border border-input bg-transparent px-2 py-1 text-xs" value={selectedUnit} onChange={e => setSelectedUnit(e.target.value)}>
                                <option value="">Semua Unit</option>
                                {units?.map((u: any) => <option key={u.id} value={u.id}>{u.nama_unit}</option>)}
                            </select>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {currentSubUnitData?.length > 0
                            ? <SubUnitChart data={currentSubUnitData} />
                            : <p className="text-sm text-muted-foreground text-center py-8">Pilih unit untuk melihat grafik.</p>
                        }
                    </CardContent>
                </Card>
            </div>

            {/* Daily Chart */}
            <Card className="mb-8">
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-semibold">Grafik Tiket Harian (7 Hari Terakhir)</CardTitle>
                </CardHeader>
                <CardContent>
                    {dailyChartData?.length > 0 ? (
                        <ReactECharts option={{
                            tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                            legend: { bottom: 0 },
                            grid: { left: '3%', right: '4%', bottom: '10%', containLabel: true },
                            xAxis: { type: 'category', data: dailyChartData.map((d: any) => d.date) },
                            yAxis: { type: 'value' },
                            series: units.map((u: any, i: number) => ({
                                name: u.nama_unit,
                                type: 'line',
                                smooth: true,
                                data: dailyChartData.map((d: any) => d[u.nama_unit] || 0)
                            }))
                        }} style={{ height: 350, width: '100%' }} />
                    ) : (
                        <p className="text-sm text-muted-foreground text-center py-8">Belum ada data harian.</p>
                    )}
                </CardContent>
            </Card>

            <section className="mb-8 space-y-6">
                <div className="flex items-center gap-2">
                    <div className="h-5 w-1 rounded-full bg-primary" />
                    <h2 className="text-lg font-semibold">Grafik Statistik</h2>
                </div>
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <Card>
                        <CardHeader><CardTitle className="text-sm font-semibold">Top 5 User (Paling Banyak Tiket)</CardTitle></CardHeader>
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
                                <p className="text-sm text-muted-foreground text-center py-8">Belum ada data.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-sm font-semibold">Tren CSAT Bulanan</CardTitle></CardHeader>
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
                                <p className="text-sm text-muted-foreground text-center py-8">Belum ada data CSAT.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader><CardTitle className="text-sm font-semibold">Tiket Bulanan (12 Bulan)</CardTitle></CardHeader>
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
                                <p className="text-sm text-muted-foreground text-center py-8">Belum ada data tiket bulanan.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </section>

            <section className="space-y-6">
                <div className="flex items-center gap-2">
                    <div className="flex h-6 w-6 items-center justify-center rounded-md bg-primary/10">
                        <Clock className="h-3.5 w-3.5 text-primary" />
                    </div>
                    <h2 className="text-lg font-semibold">Kepatuhan SLA</h2>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Card>
                        <CardContent className="p-5">
                            <div className="flex items-start justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Kepatuhan SLA Respon</p>
                                    <p className="mt-2 text-3xl font-bold text-green-500">{slaStats?.responseCompliance ?? 100}%</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <div className="flex items-start justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Kepatuhan SLA Penyelesaian</p>
                                    <p className="mt-2 text-3xl font-bold text-primary">{slaStats?.resolutionCompliance ?? 100}%</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <div className="flex items-start justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Total Breach</p>
                                    <p className="mt-2 text-3xl font-bold text-red-500">{slaStats?.totalBreach ?? 0}</p>
                                    <p className="text-xs text-muted-foreground mt-0.5">dari {slaStats?.totalAll ?? 0} tiket</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <Card>
                        <CardHeader><CardTitle className="text-sm font-semibold">Distribusi Kepatuhan SLA</CardTitle></CardHeader>
                        <CardContent>
                            {slaPieChartData?.length > 0 ? (
                                <ReactECharts option={{
                                    tooltip: { trigger: 'item', formatter: '{b} : {c} ({d}%)' },
                                    legend: { bottom: 0 },
                                    series: [{
                                        name: 'Kepatuhan SLA',
                                        type: 'pie',
                                        radius: [20, 100],
                                        center: ['50%', '50%'],
                                        roseType: 'radius',
                                        itemStyle: { borderRadius: 5 },
                                        label: { show: false },
                                        data: (slaPieChartData || []).map((d: any, i: number) => ({
                                            value: d.value,
                                            name: d.name,
                                            itemStyle: { color: PIE_COLORS[i % PIE_COLORS.length] }
                                        }))
                                    }]
                                }} style={{ height: 300, width: '100%' }} />
                            ) : (
                                <p className="text-sm text-muted-foreground text-center py-8">Belum ada data SLA.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-sm font-semibold">Tren Kepatuhan SLA (12 Bulan)</CardTitle></CardHeader>
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
                                <p className="text-sm text-muted-foreground text-center py-8">Belum ada data tren SLA.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {slaBarChartData?.length > 0 && (
                    <Card>
                        <CardHeader><CardTitle className="text-sm font-semibold">Kepatuhan SLA per Unit (Bulan Ini)</CardTitle></CardHeader>
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
